<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Approval\ApprovalService;
use App\Calendar\CalendarService;
use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Record\RecordService;
use App\Scheduling\ScheduleService;

/**
 * Pengaturan Workflow oleh Admin (PRD §5.8, §5.5, WF-15/16, FR-WF-04).
 *  - Template berversi: perubahan dilakukan pada DRAF (salinan versi terbit), lalu diterbitkan. Part baru
 *    memakai versi terbit terbaru; part berjalan tetap pada versinya (project menyimpan versi yang dipakai).
 *  - Atribut per proses: nama, nama singkat, deskripsi, jenis (khusus), PIC role, role pelaksana/pengunggah,
 *    mandatory, boleh dilewati + grup, durasi, dependency (predecessor, tipe, lag), dokumen wajib/disarankan,
 *    approval (tipe & pemberi), keputusan & tujuan loop, form proses (record), kategori kalender.
 *  - Proses bawaan hanya dapat dinonaktifkan; proses khusus dapat ditambah/dihapus.
 *  - Lingkaran dependency ditolak saat menyimpan dependency maupun saat menerbitkan.
 *  - Admin dapat menerapkan versi baru ke proses yang BELUM DIMULAI pada part berjalan, dengan pratinjau.
 */
final class WorkflowTemplateService
{
    public const STRUCTURAL = ['request', 'feedback', 'gate', 'finish'];
    public const CUSTOM_TYPES = ['task', 'approval', 'decision'];
    public const DEP_TYPES = ['FS', 'SS', 'FF', 'PARALLEL'];
    private const PROJECT_LEVEL_CODES = ['P2', 'G1'];

    public function __construct(private ScheduleService $schedule = new ScheduleService())
    {
    }

    /** @return list<array<string,mixed>> */
    public function templates(): array
    {
        return Db::fetchAll(
            "SELECT t.*, cv.version_no AS current_no, cv.published_at,
                    (SELECT v.id FROM workflow_template_versions v WHERE v.template_id = t.id AND v.status = 'draft' ORDER BY v.id DESC LIMIT 1) AS draft_id,
                    (SELECT COUNT(*) FROM workflow_steps s WHERE s.template_version_id = t.current_version_id AND s.is_active = 1) AS active_steps,
                    (SELECT COUNT(*) FROM project_parts pp JOIN projects pj ON pj.id = pp.project_id
                      WHERE t.part_type IS NOT NULL AND pp.part_type = t.part_type AND pp.workflow_template_version_id <> t.current_version_id
                        AND pp.cancelled_at IS NULL AND pp.completed_at IS NULL AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL AND pj.is_archived = 0) AS outdated_parts
             FROM workflow_templates t LEFT JOIN workflow_template_versions cv ON cv.id = t.current_version_id ORDER BY FIELD(t.code, 'project', 'new_mold', 'subcont'), t.id"
        );
    }

    /** @return array<string,mixed> */
    public function template(int $templateId): array
    {
        $t = Db::fetch('SELECT * FROM workflow_templates WHERE id = ?', [$templateId]);
        if (!$t) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $t;
    }

    /** @return array<string,mixed> versi + template */
    public function version(int $versionId): array
    {
        $v = Db::fetch('SELECT v.*, t.code AS template_code, t.name AS template_name, t.scope, t.part_type, t.current_version_id FROM workflow_template_versions v
                        JOIN workflow_templates t ON t.id = v.template_id WHERE v.id = ?', [$versionId]);
        if (!$v) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $v;
    }

    /** @return list<array<string,mixed>> semua proses versi (termasuk nonaktif) beserta dependency */
    public function steps(int $versionId): array
    {
        $steps = Db::fetchAll('SELECT s.*, r.code AS pic_role_code FROM workflow_steps s JOIN roles r ON r.id = s.pic_role_id WHERE s.template_version_id = ? ORDER BY s.sort_order, s.id', [$versionId]);
        $deps = [];
        foreach (Db::fetchAll('SELECT d.* FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ? ORDER BY d.id', [$versionId]) as $d) {
            $deps[(int) $d['step_id']][] = $d;
        }
        foreach ($steps as &$s) {
            $s['deps'] = $deps[(int) $s['id']] ?? [];
            foreach (['executor_roles_json', 'uploader_roles_json', 'required_doc_types_json', 'suggested_doc_types_json', 'decision_options_json'] as $k) {
                $s[substr($k, 0, -5)] = $s[$k] ? (json_decode((string) $s[$k], true) ?: []) : [];
            }
        }
        unset($s);
        return $steps;
    }

    /** Draf aktif template (dibuat dari versi terbit bila belum ada). */
    public function draftFor(User $actor, int $templateId): int
    {
        Gate::authorize($actor, 'settings.manage');
        return Db::transaction(function () use ($actor, $templateId): int {
            $t = Db::fetch('SELECT * FROM workflow_templates WHERE id = ? FOR UPDATE', [$templateId]);
            if (!$t) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $draft = Db::value("SELECT id FROM workflow_template_versions WHERE template_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$templateId]);
            if ($draft) {
                return (int) $draft;
            }
            $no = (int) Db::value('SELECT COALESCE(MAX(version_no), 0) FROM workflow_template_versions WHERE template_id = ?', [$templateId]) + 1;
            $id = Db::insert('workflow_template_versions', ['template_id' => $templateId, 'version_no' => $no, 'status' => 'draft', 'created_by' => $actor->id]);
            if ($t['current_version_id']) {
                $map = [];
                foreach (Db::fetchAll('SELECT * FROM workflow_steps WHERE template_version_id = ? ORDER BY sort_order, id', [(int) $t['current_version_id']]) as $s) {
                    $old = (int) $s['id'];
                    unset($s['id'], $s['created_at'], $s['updated_at']);
                    $s['template_version_id'] = $id;
                    $map[$old] = Db::insert('workflow_steps', $s);
                }
                foreach (Db::fetchAll('SELECT d.* FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ?', [(int) $t['current_version_id']]) as $d) {
                    Db::insert('workflow_step_dependencies', ['step_id' => $map[(int) $d['step_id']], 'predecessor_code' => $d['predecessor_code'], 'dep_type' => $d['dep_type'],
                        'lag_days' => (int) $d['lag_days'], 'only_when_gate' => (int) $d['only_when_gate']]);
                }
            }
            AuditLogger::log('workflow.draft', 'workflow_template', $templateId, null, ['version_id' => $id, 'version_no' => $no], null, null, $actor);
            return $id;
        });
    }

    /** @param array<string,mixed> $in */
    public function updateStep(User $actor, int $versionId, int $stepId, array $in): void
    {
        Gate::authorize($actor, 'settings.manage');
        Db::transaction(function () use ($actor, $versionId, $stepId, $in): void {
            $v = $this->draft($versionId);
            $step = $this->step($versionId, $stepId);
            $data = $this->cleanStep($step, $in, $versionId, $v);
            [$o, $n] = AuditLogger::diff(array_intersect_key($step, $data), $data);
            if (!$n) {
                return;
            }
            Db::update('workflow_steps', $data, ['id' => $stepId]);
            AuditLogger::log('workflow.step_update', 'workflow_step', $stepId, $o, $n + ['code' => $step['code']], null, null, $actor);
        });
    }

    /**
     * Ganti dependency bawaan proses (predecessor kode, tipe, lag). Lingkaran ditolak.
     * @param list<array<string,mixed>> $deps
     */
    public function setDependencies(User $actor, int $versionId, int $stepId, array $deps, ?string $reason = null): void
    {
        Gate::authorize($actor, 'settings.manage');
        Db::transaction(function () use ($actor, $versionId, $stepId, $deps, $reason): void {
            $v = $this->draft($versionId);
            $step = $this->step($versionId, $stepId);
            $codes = array_column(Db::fetchAll('SELECT code FROM workflow_steps WHERE template_version_id = ?', [$versionId]), 'code');
            $allowed = array_merge($codes, $v['scope'] === 'part' ? self::PROJECT_LEVEL_CODES : []);
            $clean = [];
            $errors = [];
            foreach (array_values($deps) as $i => $d) {
                $code = strtoupper(trim((string) ($d['predecessor_code'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $type = strtoupper((string) ($d['dep_type'] ?? 'FS'));
                $lag = trim((string) ($d['lag_days'] ?? '0'));
                if (!in_array($code, $allowed, true) || $code === $step['code']) {
                    $errors['deps.' . $i] = I18n::t('wft.dep_invalid_pred', ['code' => $code]);
                } elseif (!in_array($type, self::DEP_TYPES, true)) {
                    $errors['deps.' . $i] = I18n::t('validation.invalid');
                } elseif (!preg_match('/^-?\d{1,2}$/', $lag) || abs((int) $lag) > 30) {
                    $errors['deps.' . $i] = I18n::t('wft.lag_invalid');
                } elseif (isset($clean[$code])) {
                    $errors['deps.' . $i] = I18n::t('wft.dep_duplicate', ['code' => $code]);
                } else {
                    $clean[$code] = ['predecessor_code' => $code, 'dep_type' => $type, 'lag_days' => (int) $lag, 'only_when_gate' => !empty($d['only_when_gate']) ? 1 : 0];
                }
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            $before = array_map(static fn ($d) => $d['predecessor_code'] . ' ' . $d['dep_type'] . ($d['lag_days'] ? sprintf('%+d', $d['lag_days']) : ''),
                Db::fetchAll('SELECT * FROM workflow_step_dependencies WHERE step_id = ? ORDER BY id', [$stepId]));
            Db::execute('DELETE FROM workflow_step_dependencies WHERE step_id = ?', [$stepId]);
            foreach ($clean as $d) {
                Db::insert('workflow_step_dependencies', $d + ['step_id' => $stepId]);
            }
            $cycle = $this->findCycle($versionId);
            if ($cycle) {
                throw new BusinessRuleException(I18n::t('wft.cycle', ['path' => implode(' → ', $cycle)]));
            }
            $after = array_map(static fn ($d) => $d['predecessor_code'] . ' ' . $d['dep_type'] . ($d['lag_days'] ? sprintf('%+d', $d['lag_days']) : ''), array_values($clean));
            AuditLogger::log('workflow.dependency_update', 'workflow_step', $stepId, ['deps' => $before], ['deps' => $after, 'code' => $step['code']], $reason, null, $actor);
        });
    }

    /** Tambah proses khusus setelah proses tertentu (FS ke proses tsb). @param array<string,mixed> $in */
    public function addStep(User $actor, int $versionId, array $in): int
    {
        Gate::authorize($actor, 'settings.manage');
        return Db::transaction(function () use ($actor, $versionId, $in): int {
            $v = $this->draft($versionId);
            $after = $this->step($versionId, (int) ($in['after_step_id'] ?? 0));
            $name = trim((string) ($in['name'] ?? ''));
            $type = (string) ($in['step_type'] ?? 'task');
            $role = (string) ($in['pic_role'] ?? '');
            $dur = trim((string) ($in['default_duration'] ?? '1'));
            $errors = [];
            if ($name === '' || mb_strlen($name) > 160) {
                $errors['name'] = I18n::t('validation.required_max', ['max' => 160]);
            }
            if (!in_array($type, self::CUSTOM_TYPES, true)) {
                $errors['step_type'] = I18n::t('validation.invalid');
            }
            $roleId = $this->roleId($role);
            if ($roleId === null) {
                $errors['pic_role'] = I18n::t('validation.invalid');
            }
            if (!preg_match('/^\d{1,3}$/', $dur) || (int) $dur < 1 || (int) $dur > 365) {
                $errors['default_duration'] = I18n::t('wf.duration_invalid');
            }
            if ($errors) {
                throw new ValidationException($errors);
            }
            // kode unik di seluruh versi template (agar tidak bentrok dengan proses project lama)
            $used = array_flip(Db::column('SELECT s.code FROM workflow_steps s JOIN workflow_template_versions v ON v.id = s.template_version_id WHERE v.template_id = ?', [(int) $v['template_id']]));
            $n = 1;
            while (isset($used['C' . $n])) {
                $n++;
            }
            $code = 'C' . $n;
            $sort = (int) $after['sort_order'];
            Db::execute('UPDATE workflow_steps SET sort_order = sort_order + 1 WHERE template_version_id = ? AND sort_order > ?', [$versionId, $sort]);
            $options = null;
            if ($type !== 'task') {
                $options = json_encode([
                    ['code' => 'approved', 'label_id' => 'Approved', 'label_en' => 'Approved', 'effect' => 'continue', 'approval_status' => 'approved'],
                    ['code' => 'not_approved', 'label_id' => 'Not Approved', 'label_en' => 'Not Approved', 'effect' => 'loop', 'loop_to' => [$after['code']], 'comment_required' => true, 'approval_status' => 'rejected'],
                ], JSON_UNESCAPED_UNICODE);
            }
            $id = Db::insert('workflow_steps', [
                'template_version_id' => $versionId, 'code' => $code, 'name' => $name, 'name_en' => $name, 'short_name' => mb_substr($name, 0, 40),
                'step_type' => $type, 'pic_role_id' => $roleId, 'default_duration' => (int) $dur, 'is_mandatory' => 1, 'is_skippable' => 0,
                'is_customer_approval' => $type === 'approval' ? 1 : 0, 'approval_type' => $type === 'task' ? null : 'other',
                'approval_giver' => $type === 'approval' ? 'customer' : ($type === 'decision' ? 'internal' : null),
                'decision_options_json' => $options, 'activation' => 'auto', 'sort_order' => $sort + 1, 'is_active' => 1, 'is_builtin' => 0,
            ]);
            Db::insert('workflow_step_dependencies', ['step_id' => $id, 'predecessor_code' => $after['code'], 'dep_type' => 'FS', 'lag_days' => 0, 'only_when_gate' => 0]);
            AuditLogger::log('workflow.step_add', 'workflow_step', $id, null, ['code' => $code, 'name' => $name, 'type' => $type, 'after' => $after['code']], null, null, $actor);
            return $id;
        });
    }

    /** Hapus proses khusus (proses bawaan hanya dapat dinonaktifkan). Penerus diarahkan ke predecessor-nya. */
    public function removeStep(User $actor, int $versionId, int $stepId): void
    {
        Gate::authorize($actor, 'settings.manage');
        Db::transaction(function () use ($actor, $versionId, $stepId): void {
            $this->draft($versionId);
            $step = $this->step($versionId, $stepId);
            if ((int) $step['is_builtin'] === 1) {
                throw new BusinessRuleException(I18n::t('wft.builtin_no_delete'));
            }
            foreach ($this->steps($versionId) as $s) {
                foreach ($s['decision_options'] as $o) {
                    if (in_array($step['code'], (array) ($o['loop_to'] ?? []), true) || ($o['activate'] ?? null) === $step['code'] || ($o['reopen'] ?? null) === $step['code']) {
                        throw new BusinessRuleException(I18n::t('wft.used_as_loop_target', ['code' => $s['code']]));
                    }
                }
            }
            $own = Db::fetchAll('SELECT * FROM workflow_step_dependencies WHERE step_id = ?', [$stepId]);
            foreach (Db::fetchAll('SELECT d.* FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ? AND d.predecessor_code = ?', [$versionId, $step['code']]) as $succ) {
                Db::execute('DELETE FROM workflow_step_dependencies WHERE id = ?', [(int) $succ['id']]);
                foreach ($own as $o) {
                    Db::execute('INSERT IGNORE INTO workflow_step_dependencies (step_id, predecessor_code, dep_type, lag_days, only_when_gate) VALUES (?, ?, ?, ?, ?)',
                        [(int) $succ['step_id'], $o['predecessor_code'], $succ['dep_type'], (int) $succ['lag_days'], (int) $o['only_when_gate']]);
                }
            }
            Db::execute('DELETE FROM workflow_steps WHERE id = ?', [$stepId]);
            AuditLogger::log('workflow.step_remove', 'workflow_step', $stepId, ['code' => $step['code'], 'name' => $step['name']], null, null, null, $actor);
        });
    }

    public function moveStep(User $actor, int $versionId, int $stepId, int $dir): void
    {
        Gate::authorize($actor, 'settings.manage');
        Db::transaction(function () use ($versionId, $stepId, $dir): void {
            $this->draft($versionId);
            $ids = array_map('intval', Db::column('SELECT id FROM workflow_steps WHERE template_version_id = ? ORDER BY sort_order, id', [$versionId]));
            $i = array_search($stepId, $ids, true);
            $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $k => $id) {
                Db::update('workflow_steps', ['sort_order' => ($k + 1) * 10], ['id' => $id]);
            }
        });
    }

    /** Validasi sebelum terbit. @return list<string> pesan masalah (kosong = siap) */
    public function validate(int $versionId): array
    {
        $steps = $this->steps($versionId);
        $byCode = array_column($steps, null, 'code');
        $problems = [];
        $cycle = $this->findCycle($versionId);
        if ($cycle) {
            $problems[] = I18n::t('wft.cycle', ['path' => implode(' → ', $cycle)]);
        }
        $active = array_filter($steps, static fn ($s) => (int) $s['is_active'] === 1);
        if (!$active) {
            $problems[] = I18n::t('wft.no_active');
        }
        foreach ($steps as $s) {
            if ((int) $s['is_active'] !== 1) {
                continue;
            }
            foreach ($s['decision_options'] as $o) {
                foreach (array_merge((array) ($o['loop_to'] ?? []), array_filter([$o['activate'] ?? null, $o['reopen'] ?? null])) as $target) {
                    if (!isset($byCode[$target]) || (int) $byCode[$target]['is_active'] !== 1) {
                        $problems[] = I18n::t('wft.loop_target_inactive', ['code' => $s['code'], 'target' => (string) $target]);
                    }
                }
            }
            if ($s['on_complete_reopen_code'] && (!isset($byCode[$s['on_complete_reopen_code']]) || (int) $byCode[$s['on_complete_reopen_code']]['is_active'] !== 1)) {
                $problems[] = I18n::t('wft.loop_target_inactive', ['code' => $s['code'], 'target' => (string) $s['on_complete_reopen_code']]);
            }
        }
        return array_values(array_unique($problems));
    }

    public function publish(User $actor, int $versionId, string $notes = ''): int
    {
        Gate::authorize($actor, 'settings.manage');
        return Db::transaction(function () use ($actor, $versionId, $notes): int {
            $v = $this->draft($versionId);
            $problems = $this->validate($versionId);
            if ($problems) {
                throw new BusinessRuleException(implode(' ', $problems));
            }
            $now = Clock::nowString();
            Db::execute("UPDATE workflow_template_versions SET status = 'retired' WHERE template_id = ? AND status = 'published'", [(int) $v['template_id']]);
            Db::update('workflow_template_versions', ['status' => 'published', 'published_by' => $actor->id, 'published_at' => $now, 'notes' => mb_substr(trim($notes), 0, 500) ?: null], ['id' => $versionId]);
            Db::update('workflow_templates', ['current_version_id' => $versionId], ['id' => (int) $v['template_id']]);
            AuditLogger::log('workflow.publish', 'workflow_template', (int) $v['template_id'], ['version_id' => $v['current_version_id']], ['version_id' => $versionId, 'version_no' => (int) $v['version_no']], $notes ?: null, null, $actor);
            return (int) $v['version_no'];
        });
    }

    public function discard(User $actor, int $versionId): void
    {
        Gate::authorize($actor, 'settings.manage');
        Db::transaction(function () use ($actor, $versionId): void {
            $v = $this->draft($versionId);
            Db::execute('DELETE FROM workflow_template_versions WHERE id = ?', [$versionId]);
            AuditLogger::log('workflow.draft_discard', 'workflow_template', (int) $v['template_id'], ['version_id' => $versionId], null, null, null, $actor);
        });
    }

    // ------------------------------------------------------------------ Terapkan ke part berjalan (§5.5)

    /**
     * Pratinjau penerapan versi terbit ke proses BELUM DIMULAI pada part berjalan yang memakai versi lama.
     * @return list<array<string,mixed>> per part: perubahan proses + perkiraan selesai project lama/baru
     */
    public function previewApply(User $actor, int $templateId): array
    {
        Gate::authorize($actor, 'settings.manage');
        $t = $this->template($templateId);
        if ($t['scope'] !== 'part' || !$t['current_version_id']) {
            return [];
        }
        $out = [];
        foreach ($this->outdatedParts($t) as $part) {
            $plan = $this->planFor($part, (int) $t['current_version_id']);
            if (!$plan['changes'] && !$plan['add'] && !$plan['skip']) {
                continue;
            }
            $preview = $this->schedule->preview((int) $part['project_id'], fn (array &$g) => $this->mutateGraph($g, $plan, (int) $part['id']));
            $out[] = ['part' => $part, 'plan' => $plan, 'forecast_old' => $preview['project_forecast_old'], 'forecast_new' => $preview['project_forecast_new'],
                'target' => $preview['target_finish'], 'shifted' => count(array_filter($preview['changes'], static fn ($c) => $c['shift'] !== null && $c['shift'] !== 0))];
        }
        return $out;
    }

    /** Terapkan versi terbit ke part berjalan (proses belum dimulai saja). @param list<int> $partIds @return int jumlah part */
    public function apply(User $actor, int $templateId, array $partIds): int
    {
        Gate::authorize($actor, 'settings.manage');
        return Db::transaction(function () use ($actor, $templateId, $partIds): int {
            $t = $this->template($templateId);
            if ($t['scope'] !== 'part' || !$t['current_version_id']) {
                throw new BusinessRuleException(I18n::t('validation.invalid'));
            }
            $versionId = (int) $t['current_version_id'];
            $want = array_flip(array_map('intval', $partIds));
            $done = 0;
            foreach ($this->outdatedParts($t) as $part) {
                if (!isset($want[(int) $part['id']])) {
                    continue;
                }
                Db::fetch('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) $part['project_id']]);
                $plan = $this->planFor($part, $versionId);
                $this->applyPlan($actor, $part, $plan, $versionId);
                $done++;
            }
            return $done;
        });
    }

    /** @return list<array<string,mixed>> */
    private function outdatedParts(array $t): array
    {
        return Db::fetchAll(
            'SELECT pp.*, pj.code AS project_code, pj.name AS project_name, v.version_no FROM project_parts pp JOIN projects pj ON pj.id = pp.project_id
             LEFT JOIN workflow_template_versions v ON v.id = pp.workflow_template_version_id
             WHERE pp.part_type = ? AND pp.workflow_template_version_id IS NOT NULL AND pp.workflow_template_version_id <> ? AND pp.cancelled_at IS NULL AND pp.completed_at IS NULL
               AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL AND pj.is_archived = 0 ORDER BY pj.code, pp.sort_order',
            [(string) $t['part_type'], (int) $t['current_version_id']]
        );
    }

    /**
     * Rencana perubahan untuk satu part: proses belum dimulai yang berubah atributnya, dependency bawaan baru,
     * proses khusus baru (ditambahkan bila belum ada), dan proses yang dinonaktifkan (dilewati).
     * @return array{changes:list<array<string,mixed>>,add:list<array<string,mixed>>,skip:list<array<string,mixed>>,steps:array<string,array<string,mixed>>,deps:array<string,list<array<string,mixed>>>}
     */
    private function planFor(array $part, int $versionId): array
    {
        $steps = [];
        foreach ($this->steps($versionId) as $s) {
            $steps[(string) $s['code']] = $s;
        }
        $procs = [];
        foreach (Db::fetchAll('SELECT * FROM processes WHERE part_id = ?', [(int) $part['id']]) as $p) {
            $procs[(string) $p['code']] = $p;
        }
        $deps = self::resolvedDeps($steps);
        $changes = [];
        $skip = [];
        $add = [];
        foreach ($procs as $code => $p) {
            if ($p['status'] !== 'not_started') {
                continue;
            }
            $s = $steps[$code] ?? null;
            if ($s === null) {
                continue;
            }
            if ((int) $s['is_active'] !== 1) {
                $skip[] = ['code' => $code, 'name' => $p['name'], 'process_id' => (int) $p['id']];
                continue;
            }
            $diff = [];
            $map = ['name' => 'name', 'name_en' => 'name_en', 'is_mandatory' => 'is_mandatory', 'is_skippable' => 'is_skippable', 'skip_group' => 'skip_group',
                'is_external' => 'is_external', 'is_customer_approval' => 'is_customer_approval', 'approval_type' => 'approval_type', 'approval_giver' => 'approval_giver',
                'decision_options_json' => 'decision_options_json', 'required_doc_types_json' => 'required_doc_types_json', 'uploader_roles_json' => 'uploader_roles_json',
                'record_type' => 'record_type', 'calendar_category' => 'calendar_category', 'on_complete_reopen_code' => 'on_complete_reopen_code', 'pic_role_id' => 'pic_role_id'];
            foreach ($map as $pk => $sk) {
                $a = $p[$pk];
                $b = $s[$sk];
                if (str_ends_with($pk, '_json')) {
                    $a = $a === null ? null : json_encode(json_decode((string) $a, true));
                    $b = $b === null ? null : json_encode(json_decode((string) $b, true));
                }
                if ((string) $a !== (string) $b) {
                    $diff[$pk] = [$p[$pk], $s[$sk]];
                }
            }
            if ((int) $p['duration_is_manual'] === 0 && (int) $p['duration'] !== (int) $s['default_duration']) {
                $diff['duration'] = [(int) $p['duration'], (int) $s['default_duration']];
            }
            $cur = array_map(static fn ($d) => $d['code'] . ' ' . $d['dep_type'] . ' ' . (int) $d['lag_days'], Db::fetchAll(
                "SELECT pr.code, d.dep_type, d.lag_days FROM process_dependencies d JOIN processes pr ON pr.id = d.predecessor_id WHERE d.process_id = ? AND d.source = 'template' ORDER BY pr.code",
                [(int) $p['id']]
            ));
            $new = array_map(static fn ($d) => $d['code'] . ' ' . $d['dep_type'] . ' ' . (int) $d['lag_days'], $deps[$code] ?? []);
            sort($cur);
            sort($new);
            if ($cur !== $new) {
                $diff['deps'] = [implode(', ', $cur), implode(', ', $new)];
            }
            if ($diff) {
                $changes[] = ['code' => $code, 'name' => $p['name'], 'process_id' => (int) $p['id'], 'diff' => $diff, 'step_id' => (int) $s['id']];
            }
        }
        foreach ($steps as $code => $s) {
            if (!isset($procs[$code]) && (int) $s['is_active'] === 1 && (int) $s['is_builtin'] === 0) {
                $add[] = ['code' => $code, 'name' => $s['name'], 'step_id' => (int) $s['id']];
            }
        }
        return ['changes' => $changes, 'add' => $add, 'skip' => $skip, 'steps' => $steps, 'deps' => $deps];
    }

    /**
     * Dependency efektif per kode proses aktif: predecessor yang dinonaktifkan dijembatani ke predecessor-nya.
     * @param array<string,array<string,mixed>> $steps
     * @return array<string,list<array{code:string,dep_type:string,lag_days:int,only_when_gate:int}>>
     */
    public static function resolvedDeps(array $steps): array
    {
        $resolve = static function (string $code, array $seen) use (&$resolve, $steps): array {
            $s = $steps[$code] ?? null;
            if ($s === null || (int) $s['is_active'] === 1 || isset($seen[$code])) {
                return [['code' => $code]];
            }
            $seen[$code] = true;
            $out = [];
            foreach ($s['deps'] as $d) {
                foreach ($resolve((string) $d['predecessor_code'], $seen) as $r) {
                    $out[] = $r + ['only_when_gate' => (int) $d['only_when_gate']];
                }
            }
            return $out;
        };
        $res = [];
        foreach ($steps as $code => $s) {
            if ((int) $s['is_active'] !== 1) {
                continue;
            }
            $list = [];
            foreach ($s['deps'] as $d) {
                foreach ($resolve((string) $d['predecessor_code'], []) as $r) {
                    $list[$r['code']] = ['code' => $r['code'], 'dep_type' => (string) $d['dep_type'], 'lag_days' => (int) $d['lag_days'],
                        'only_when_gate' => max((int) $d['only_when_gate'], (int) ($r['only_when_gate'] ?? 0))];
                }
            }
            $res[$code] = array_values($list);
        }
        return $res;
    }

    /** Terapkan rencana ke graf pratinjau (tanpa menyimpan). */
    private function mutateGraph(array &$g, array $plan, int $partId): void
    {
        $byCode = [];
        foreach ($g['rows'] as $id => $r) {
            if ((int) ($r['part_id'] ?? 0) === $partId || $r['part_id'] === null) {
                $byCode[($r['part_id'] === null ? 'P:' : '') . $r['code']] = $id;
            }
        }
        foreach ($plan['changes'] as $c) {
            $id = $c['process_id'];
            if (isset($c['diff']['duration'])) {
                $g['nodes'][$id]['duration'] = $c['diff']['duration'][1];
            }
            if (isset($c['diff']['deps'])) {
                $g['deps'] = array_values(array_filter($g['deps'], static fn ($d) => $d['process_id'] !== $id));
                foreach ($plan['deps'][$c['code']] ?? [] as $d) {
                    $pred = $byCode[$d['code']] ?? $byCode['P:' . $d['code']] ?? null;
                    if ($pred !== null) {
                        $g['deps'][] = ['process_id' => $id, 'predecessor_id' => $pred, 'type' => $d['dep_type'], 'lag' => $d['lag_days']];
                    }
                }
            }
        }
        foreach ($plan['skip'] as $s) {
            $g['nodes'][$s['process_id']]['status'] = 'skipped';
        }
        $fake = -1;
        foreach ($plan['add'] as $a) {
            $s = $plan['steps'][$a['code']];
            $g['nodes'][$fake] = ['id' => $fake, 'part_id' => $partId, 'status' => 'not_started', 'activation' => 'auto', 'duration' => (int) $s['default_duration'],
                'manual_start' => null, 'manual_finish' => null, 'planned_start' => null, 'planned_finish' => null, 'actual_start' => null, 'actual_finish' => null,
                'loop_after_id' => null, 'excluded' => false];
            $byCode[$a['code']] = $fake;
            foreach ($plan['deps'][$a['code']] ?? [] as $d) {
                $pred = $byCode[$d['code']] ?? $byCode['P:' . $d['code']] ?? null;
                if ($pred !== null) {
                    $g['deps'][] = ['process_id' => $fake, 'predecessor_id' => $pred, 'type' => $d['dep_type'], 'lag' => $d['lag_days']];
                }
            }
            foreach ($plan['deps'] as $succCode => $list) {
                foreach ($list as $d) {
                    if ($d['code'] === $a['code'] && isset($byCode[$succCode]) && $byCode[$succCode] > 0) {
                        $g['deps'][] = ['process_id' => $byCode[$succCode], 'predecessor_id' => $fake, 'type' => $d['dep_type'], 'lag' => $d['lag_days']];
                    }
                }
            }
            $fake--;
        }
    }

    private function applyPlan(User $actor, array $part, array $plan, int $versionId): void
    {
        $partId = (int) $part['id'];
        $projectId = (int) $part['project_id'];
        $inst = new WorkflowInstantiator($this->schedule);
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $ids = [];
        foreach (Db::fetchAll('SELECT id, code FROM processes WHERE part_id = ?', [$partId]) as $r) {
            $ids[(string) $r['code']] = (int) $r['id'];
        }
        $level = [];
        foreach (Db::fetchAll('SELECT id, code FROM processes WHERE project_id = ? AND part_id IS NULL', [$projectId]) as $r) {
            $level[(string) $r['code']] = (int) $r['id'];
        }
        $gateOn = (int) $project['gate_enabled'] === 1 && isset($level['G1']);
        foreach ($plan['add'] as $a) {
            $s = $plan['steps'][$a['code']];
            $ids[$a['code']] = $inst->insertStepProcess($s, $projectId, $partId, $inst->defaultPic((string) $s['pic_role_code'], $project, $part));
        }
        $touch = array_merge(array_column($plan['changes'], 'code'), array_column($plan['add'], 'code'));
        foreach ($plan['changes'] as $c) {
            $s = $plan['steps'][$c['code']];
            $data = ['workflow_step_id' => (int) $s['id']];
            foreach ($c['diff'] as $k => [, $new]) {
                if ($k !== 'deps') {
                    $data[$k] = $new;
                }
            }
            if (isset($data['pic_role_id'])) {
                $data['pic_user_id'] = $inst->defaultPic((string) $s['pic_role_code'], $project, $part);
            }
            Db::update('processes', $data, ['id' => $c['process_id']]);
            Db::execute('UPDATE processes SET lock_version = lock_version + 1 WHERE id = ?', [$c['process_id']]);
        }
        // dependency bawaan baru untuk proses yang disentuh + penerus proses baru; dependency override NPD dipertahankan
        $succOfAdded = [];
        foreach ($plan['deps'] as $succCode => $list) {
            foreach ($list as $d) {
                if (in_array($d['code'], array_column($plan['add'], 'code'), true)) {
                    $succOfAdded[] = $succCode;
                }
            }
        }
        foreach (array_unique(array_merge($touch, $succOfAdded)) as $code) {
            if (!isset($ids[$code])) {
                continue;
            }
            $st = Db::value('SELECT status FROM processes WHERE id = ?', [$ids[$code]]);
            if ($st !== 'not_started' && !in_array($code, array_column($plan['add'], 'code'), true)) {
                continue;
            }
            Db::execute("DELETE FROM process_dependencies WHERE process_id = ? AND source = 'template'", [$ids[$code]]);
            foreach ($plan['deps'][$code] ?? [] as $d) {
                if ($d['only_when_gate'] && !$gateOn) {
                    continue;
                }
                $pred = $ids[$d['code']] ?? $level[$d['code']] ?? null;
                if ($pred !== null) {
                    $inst->addDep($ids[$code], $pred, $d['dep_type'], $d['lag_days']);
                }
            }
        }
        foreach ($plan['skip'] as $s) {
            Db::update('processes', ['status' => 'skipped', 'skip_reason' => I18n::t('wft.skip_reason', [], 'id'), 'skipped_at' => Clock::nowString(), 'skipped_by' => $actor->id], ['id' => $s['process_id']]);
        }
        Db::update('project_parts', ['workflow_template_version_id' => $versionId], ['id' => $partId]);
        $this->schedule->recalculate($projectId, 'template_apply', null, I18n::t('wft.apply_reason', ['version' => (int) Db::value('SELECT version_no FROM workflow_template_versions WHERE id = ?', [$versionId])], 'id'), $actor, 'plan');
        AuditLogger::log('workflow.apply', 'project_part', $partId, ['version' => (int) $part['version_no']], [
            'version_id' => $versionId, 'changed' => array_column($plan['changes'], 'code'), 'added' => array_column($plan['add'], 'code'), 'skipped' => array_column($plan['skip'], 'code'),
        ], null, $projectId, $actor);
        (new WorkflowEngine($this->schedule))->activateReady($projectId, $actor);
    }

    // ------------------------------------------------------------------ helper

    /** @return array<string,mixed> */
    private function draft(int $versionId): array
    {
        $v = Db::fetch('SELECT v.*, t.scope, t.current_version_id FROM workflow_template_versions v JOIN workflow_templates t ON t.id = v.template_id WHERE v.id = ? FOR UPDATE', [$versionId]);
        if (!$v) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        if ($v['status'] !== 'draft') {
            throw new BusinessRuleException(I18n::t('wft.not_draft'));
        }
        return $v;
    }

    /** @return array<string,mixed> */
    private function step(int $versionId, int $stepId): array
    {
        $s = Db::fetch('SELECT s.*, r.code AS pic_role_code FROM workflow_steps s JOIN roles r ON r.id = s.pic_role_id WHERE s.id = ? AND s.template_version_id = ?', [$stepId, $versionId]);
        if (!$s) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $s;
    }

    private function roleId(string $code): ?int
    {
        if ($code === 'management') {
            return null; // Management read-only (PRD §2.3)
        }
        $id = Db::value('SELECT id FROM roles WHERE code = ?', [$code]);
        return $id !== null ? (int) $id : null;
    }

    /** @param array<string,mixed> $step @param array<string,mixed> $in @return array<string,mixed> */
    private function cleanStep(array $step, array $in, int $versionId, array $v): array
    {
        $errors = [];
        $data = [];
        $text = static function (string $k, int $max, bool $required) use ($in, &$errors, &$data): void {
            if (!array_key_exists($k, $in)) {
                return;
            }
            $val = trim((string) $in[$k]);
            if (($required && $val === '') || mb_strlen($val) > $max) {
                $errors[$k] = I18n::t($required ? 'validation.required_max' : 'validation.max', ['max' => $max]);
                return;
            }
            $data[$k] = $val === '' ? null : $val;
        };
        $text('name', 160, true);
        $text('name_en', 160, false);
        $text('short_name', 40, false);
        $text('description', 500, false);
        $builtinStructural = in_array($step['step_type'], self::STRUCTURAL, true);
        if (array_key_exists('step_type', $in) && (string) $in['step_type'] !== (string) $step['step_type']) {
            if ((int) $step['is_builtin'] === 1 || !in_array($in['step_type'], self::CUSTOM_TYPES, true)) {
                $errors['step_type'] = I18n::t('wft.type_locked');
            } else {
                $data['step_type'] = (string) $in['step_type'];
            }
        }
        if (array_key_exists('pic_role', $in)) {
            $rid = $this->roleId((string) $in['pic_role']);
            $rid === null ? $errors['pic_role'] = I18n::t('validation.invalid') : $data['pic_role_id'] = $rid;
        }
        foreach (['executor_roles', 'uploader_roles'] as $k) {
            if (array_key_exists($k, $in)) {
                $vals = array_values(array_unique(array_filter(array_map('strval', (array) $in[$k]))));
                foreach ($vals as $r) {
                    if ($this->roleId($r) === null) {
                        $errors[$k] = I18n::t('validation.invalid');
                    }
                }
                $data[$k . '_json'] = $vals ? json_encode($vals) : null;
            }
        }
        if (array_key_exists('default_duration', $in)) {
            $d = trim((string) $in['default_duration']);
            if (!preg_match('/^\d{1,3}$/', $d) || (int) $d < 1 || (int) $d > 365) {
                $errors['default_duration'] = I18n::t('wf.duration_invalid');
            } else {
                $data['default_duration'] = (int) $d;
            }
        }
        foreach (['is_mandatory', 'is_skippable', 'is_external', 'is_customer_approval'] as $k) {
            if (array_key_exists($k, $in)) {
                $data[$k] = !empty($in[$k]) ? 1 : 0;
            }
        }
        if (array_key_exists('skip_group', $in)) {
            $g = trim((string) $in['skip_group']);
            if ($g !== '' && !preg_match('/^[A-Za-z0-9_-]{1,30}$/', $g)) {
                $errors['skip_group'] = I18n::t('validation.invalid');
            }
            $data['skip_group'] = $g === '' ? null : $g;
        }
        foreach (['required_doc_types', 'suggested_doc_types'] as $k) {
            if (array_key_exists($k, $in)) {
                $vals = array_values(array_unique(array_filter(array_map('strval', (array) $in[$k]))));
                foreach ($vals as $t) {
                    if (!MasterService::isValid('document_type', $t)) {
                        $errors[$k] = I18n::t('validation.invalid');
                    }
                }
                $data[$k . '_json'] = $vals ? json_encode($vals) : null;
            }
        }
        if (array_key_exists('approval_type', $in)) {
            $t = (string) $in['approval_type'];
            if ($t !== '' && !in_array($t, ApprovalService::TYPES, true)) {
                $errors['approval_type'] = I18n::t('validation.invalid');
            }
            $data['approval_type'] = $t === '' ? null : $t;
        }
        if (array_key_exists('approval_giver', $in)) {
            $gv = (string) $in['approval_giver'];
            if ($gv !== '' && !in_array($gv, ['customer', 'internal'], true)) {
                $errors['approval_giver'] = I18n::t('validation.invalid');
            }
            $data['approval_giver'] = $gv === '' ? null : $gv;
        }
        if (array_key_exists('calendar_category', $in)) {
            $c = (string) $in['calendar_category'];
            if ($c !== '' && (!in_array($c, CalendarService::CATEGORIES, true) || $c === 'agenda')) {
                $errors['calendar_category'] = I18n::t('validation.invalid');
            }
            $data['calendar_category'] = $c === '' ? null : $c;
        }
        if (array_key_exists('record_type', $in)) {
            $r = (string) $in['record_type'];
            if ($r !== '' && !isset(RecordService::KINDS[$r])) {
                $errors['record_type'] = I18n::t('validation.invalid');
            }
            $data['record_type'] = $r === '' ? null : $r;
        }
        if (array_key_exists('is_active', $in)) {
            $active = !empty($in['is_active']) ? 1 : 0;
            if ($active === 0 && $builtinStructural) {
                $errors['is_active'] = I18n::t('wft.structural_required');
            } else {
                $data['is_active'] = $active;
            }
        }
        // keputusan: label, wajib komentar, tujuan loop (kode proses aktif di versi ini)
        if (array_key_exists('options', $in) && is_array($in['options'])) {
            $opts = $step['decision_options_json'] ? (json_decode((string) $step['decision_options_json'], true) ?: []) : [];
            $codes = array_column(Db::fetchAll('SELECT code FROM workflow_steps WHERE template_version_id = ?', [$versionId]), 'code');
            foreach ($opts as $i => &$o) {
                $inO = $in['options'][$i] ?? null;
                if (!is_array($inO)) {
                    continue;
                }
                foreach (['label_id', 'label_en'] as $lk) {
                    if (isset($inO[$lk])) {
                        $lv = trim((string) $inO[$lk]);
                        if ($lv === '' || mb_strlen($lv) > 60) {
                            $errors['options.' . $i] = I18n::t('validation.required_max', ['max' => 60]);
                        } else {
                            $o[$lk] = $lv;
                        }
                    }
                }
                if (array_key_exists('comment_required', $inO)) {
                    $o['comment_required'] = !empty($inO['comment_required']);
                }
                if (($o['effect'] ?? null) === 'loop' && isset($inO['loop_to'])) {
                    $target = strtoupper(trim((string) $inO['loop_to']));
                    if (!in_array($target, $codes, true)) {
                        $errors['options.' . $i] = I18n::t('wft.loop_target_invalid');
                    } else {
                        $rest = array_values(array_diff((array) ($o['loop_to'] ?? []), [$target]));
                        $o['loop_to'] = array_merge([$target], $rest);
                    }
                }
            }
            unset($o);
            $data['decision_options_json'] = $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    /** Lingkaran dependency dalam versi (DFS). @return list<string> jalur kode, kosong bila tidak ada */
    public function findCycle(int $versionId): array
    {
        $adj = [];
        foreach (Db::fetchAll('SELECT s.code, d.predecessor_code FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ?', [$versionId]) as $r) {
            $adj[(string) $r['predecessor_code']][] = (string) $r['code'];
        }
        $state = [];
        $stack = [];
        $found = [];
        $visit = function (string $n) use (&$visit, &$state, &$stack, &$found, $adj): bool {
            $state[$n] = 1;
            $stack[] = $n;
            foreach ($adj[$n] ?? [] as $m) {
                if (($state[$m] ?? 0) === 1) {
                    $found = array_merge(array_slice($stack, (int) array_search($m, $stack, true)), [$m]);
                    return true;
                }
                if (($state[$m] ?? 0) === 0 && $visit($m)) {
                    return true;
                }
            }
            array_pop($stack);
            $state[$n] = 2;
            return false;
        };
        foreach (array_keys($adj) as $n) {
            if (($state[$n] ?? 0) === 0 && $visit((string) $n)) {
                return $found;
            }
        }
        return [];
    }
}
