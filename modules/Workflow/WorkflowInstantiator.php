<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\User;
use App\Scheduling\ScheduleService;

/**
 * Instansiasi workflow dari template berversi (PRD §5.1, §5.8, FR-WF-09).
 * Atribut step disalin ke proses (snapshot) sehingga perubahan template tidak mengubah project berjalan.
 */
final class WorkflowInstantiator
{
    public function __construct(private ScheduleService $schedule = new ScheduleService())
    {
    }

    /** @return array<string,mixed> template + versi aktif */
    public function currentVersion(string $templateCode): array
    {
        $row = Db::fetch(
            'SELECT t.id AS template_id, t.code, t.gate_enabled, v.id AS version_id, v.version_no
             FROM workflow_templates t JOIN workflow_template_versions v ON v.id = t.current_version_id
             WHERE t.code = ? AND t.is_active = 1',
            [$templateCode]
        );
        if (!$row) {
            throw new BusinessRuleException('Template workflow "' . $templateCode . '" belum tersedia');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> step aktif + dependency bawaan */
    public function steps(int $versionId): array
    {
        $steps = Db::fetchAll('SELECT s.*, r.code AS pic_role_code FROM workflow_steps s JOIN roles r ON r.id = s.pic_role_id WHERE s.template_version_id = ? AND s.is_active = 1 ORDER BY s.sort_order, s.id', [$versionId]);
        $deps = Db::fetchAll('SELECT d.* FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ?', [$versionId]);
        $byStep = [];
        foreach ($deps as $d) {
            $byStep[(int) $d['step_id']][] = $d;
        }
        foreach ($steps as &$s) {
            $s['deps'] = $byStep[(int) $s['id']] ?? [];
        }
        unset($s);
        return $steps;
    }

    /**
     * Proses level project saat NPR pertama kali dikirim: P1 (selesai), P2 (aktif), G1 (bila gate aktif), PF.
     */
    public function createProjectProcesses(int $projectId, string $nprCreatedAt, ?User $actor = null): void
    {
        $tpl = $this->currentVersion('project');
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $cal = $this->schedule->calendar();
        $now = Clock::now();
        $today = $now->format('Y-m-d');
        Db::update('projects', ['gate_enabled' => (int) $tpl['gate_enabled']], ['id' => $projectId]);
        $ids = [];
        foreach ($this->steps((int) $tpl['version_id']) as $step) {
            if ($step['step_type'] === 'gate' && !(int) $tpl['gate_enabled']) {
                continue; // gate dinonaktifkan di template
            }
            $pic = $this->defaultPic((string) $step['pic_role_code'], $project, null);
            $data = [];
            $duration = (int) $step['default_duration'];
            if ($step['code'] === 'P1') {
                $start = substr($nprCreatedAt, 0, 10);
                $ps = $cal->nextWorkingDay($start);
                $data = ['status' => 'completed', 'actual_start' => $start, 'actual_finish' => $today, 'planned_start' => $ps,
                         'planned_finish' => $cal->finishFromStart($ps, $duration), 'activated_at' => $nprCreatedAt,
                         'completed_at' => $now->format('Y-m-d H:i:s'), 'completed_by' => $actor?->id, 'outcome' => 'submitted'];
            } elseif ($step['code'] === 'P2') {
                $ps = $cal->nextWorkingDay($today);
                $data = ['status' => 'current', 'actual_start' => $today, 'planned_start' => $ps,
                         'planned_finish' => $cal->finishFromStart($ps, $duration), 'activated_at' => $now->format('Y-m-d H:i:s')];
            }
            $ids[$step['code']] = $this->insertProcess($step, $projectId, null, $pic, $data);
        }
        foreach ($this->steps((int) $tpl['version_id']) as $step) {
            foreach ($step['deps'] as $d) {
                if (isset($ids[$step['code']], $ids[$d['predecessor_code']])) {
                    $this->addDep($ids[$step['code']], $ids[$d['predecessor_code']], (string) $d['dep_type'], (int) $d['lag_days']);
                }
            }
        }
        // run KPI: P1 selesai (PIC Sales), P2 dibuka
        foreach (['P1', 'P2'] as $code) {
            if (isset($ids[$code])) {
                $p = Db::fetch('SELECT * FROM processes WHERE id = ?', [$ids[$code]]);
                ProcessRuns::open($p, $p['activated_at'] ?? $now->format('Y-m-d H:i:s'));
                if ($code === 'P1') {
                    ProcessRuns::close((int) $p['id'], 'completed', (string) $p['actual_start'], $today, 'submitted', $p['pic_user_id'] !== null ? (int) $p['pic_user_id'] : $actor?->id, $actor?->id);
                }
            }
        }
        Db::update('projects', ['status' => 'on_progress'], ['id' => $projectId]);
        $this->schedule->recalculate($projectId, 'initial', null, null, $actor, 'plan');
    }

    /**
     * Mulai part yang diterima NPD (PRD §5.4 #1): instansiasi proses template New Mold / Subcont,
     * dependency bawaan, lewati Masterbatch bila tidak perlu, kaitkan ke gate & Project Finish,
     * hitung jadwal, kunci Baseline v1 part.
     */
    public function startPart(int $projectPartId, ?User $actor = null, ?string $startDate = null): void
    {
        $part = Db::fetch('SELECT * FROM project_parts WHERE id = ? FOR UPDATE', [$projectPartId]);
        if (!$part || $part['cancelled_at'] !== null) {
            return;
        }
        if ((int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [$projectPartId]) > 0) {
            return; // sudah diinstansiasi
        }
        $projectId = (int) $part['project_id'];
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $tpl = $this->currentVersion((string) $part['part_type']);
        $start = $startDate ?? Clock::todayString();
        Db::update('project_parts', ['workflow_template_version_id' => (int) $tpl['version_id'], 'start_date' => $start, 'status' => 'on_progress'], ['id' => $projectPartId]);

        $steps = $this->steps((int) $tpl['version_id']);
        $ids = [];
        $milestone = null;
        $finish = null;
        foreach ($steps as $step) {
            $pic = $this->defaultPic((string) $step['pic_role_code'], $project, $part);
            $data = [];
            if ($step['skip_group'] === 'MB' && $part['needs_new_masterbatch'] !== null && (int) $part['needs_new_masterbatch'] === 0) {
                $data = ['status' => 'skipped', 'skip_reason' => I18n::t('wf.skip_no_masterbatch', [], 'id'), 'skipped_at' => Clock::nowString(), 'skipped_by' => $actor?->id];
            }
            $ids[$step['code']] = $this->insertProcess($step, $projectId, $projectPartId, $pic, $data);
            if ((int) $step['is_gate_milestone'] === 1) {
                $milestone = $ids[$step['code']];
            }
            if ($step['step_type'] === 'finish') {
                $finish = $ids[$step['code']];
            }
        }
        $projectLevel = [];
        foreach (Db::fetchAll('SELECT id, code FROM processes WHERE project_id = ? AND part_id IS NULL', [$projectId]) as $r) {
            $projectLevel[(string) $r['code']] = (int) $r['id'];
        }
        $gateOn = (int) $project['gate_enabled'] === 1 && isset($projectLevel['G1']);
        foreach ($steps as $step) {
            foreach ($step['deps'] as $d) {
                $code = (string) $d['predecessor_code'];
                if ((int) $d['only_when_gate'] === 1 && !$gateOn) {
                    continue;
                }
                $predId = $ids[$code] ?? $projectLevel[$code] ?? null;
                if ($predId !== null) {
                    $this->addDep($ids[$step['code']], $predId, (string) $d['dep_type'], (int) $d['lag_days']);
                }
            }
        }
        if ($gateOn && $milestone !== null) {
            $this->addDep($projectLevel['G1'], $milestone, 'FS', 0); // gate menunggu milestone tiap part aktif
        }
        if (isset($projectLevel['PF']) && $finish !== null) {
            $this->addDep($projectLevel['PF'], $finish, 'FS', 0);
        }
        if (isset($projectLevel['PF'], $projectLevel['G1']) && $gateOn) {
            $exists = Db::value('SELECT id FROM process_dependencies WHERE process_id = ? AND predecessor_id = ?', [$projectLevel['PF'], $projectLevel['G1']]);
            if (!$exists) {
                $this->addDep($projectLevel['PF'], $projectLevel['G1'], 'FS', 0);
            }
        }
        AuditLogger::log('part.start', 'project_part', $projectPartId, null, ['template' => $part['part_type'], 'version' => (int) $tpl['version_no'], 'start_date' => $start, 'processes' => count($ids)], null, $projectId, $actor);
        $this->schedule->recalculate($projectId, 'initial', null, null, $actor, 'plan');
        $this->schedule->createBaseline($projectId, $projectPartId, I18n::t('sched.baseline_v1', [], 'id'), $actor);
    }

    /** Lepas part yang dibatalkan dari gate & Project Finish (PRD §5.7: predecessor gate = part tidak dibatalkan). */
    public function detachPart(int $projectPartId): void
    {
        Db::execute(
            'DELETE d FROM process_dependencies d JOIN processes s ON s.id = d.process_id JOIN processes p ON p.id = d.predecessor_id
             WHERE p.part_id = ? AND s.part_id IS NULL',
            [$projectPartId]
        );
    }

    /**
     * Kaitkan kembali part yang dibuka kembali ke gate & Project Finish (kebalikan detachPart).
     * Gate yang sudah diputuskan tidak dikaitkan ulang agar tidak menunggu part ini.
     */
    public function attachPart(int $projectPartId): void
    {
        $part = Db::fetch('SELECT pp.project_id, pj.gate_enabled FROM project_parts pp JOIN projects pj ON pj.id = pp.project_id WHERE pp.id = ?', [$projectPartId]);
        if (!$part) {
            return;
        }
        $projectId = (int) $part['project_id'];
        $level = [];
        foreach (Db::fetchAll('SELECT id, code, status FROM processes WHERE project_id = ? AND part_id IS NULL', [$projectId]) as $r) {
            $level[(string) $r['code']] = $r;
        }
        $milestone = Db::value('SELECT id FROM processes WHERE part_id = ? AND is_gate_milestone = 1 ORDER BY sort_order LIMIT 1', [$projectPartId]);
        $finish = Db::value("SELECT id FROM processes WHERE part_id = ? AND step_type = 'finish' ORDER BY sort_order LIMIT 1", [$projectPartId]);
        if ((int) $part['gate_enabled'] === 1 && isset($level['G1']) && $milestone && $level['G1']['status'] === 'not_started') {
            $this->addDep((int) $level['G1']['id'], (int) $milestone, 'FS', 0);
        }
        if (isset($level['PF']) && $finish && !in_array($level['PF']['status'], ['completed', 'skipped'], true)) {
            $this->addDep((int) $level['PF']['id'], (int) $finish, 'FS', 0);
        }
    }

    public function addDep(int $processId, int $predId, string $type, int $lag, string $source = 'template', ?int $userId = null): void
    {
        if ($processId === $predId) {
            return;
        }
        Db::execute(
            'INSERT INTO process_dependencies (process_id, predecessor_id, dep_type, lag_days, source, created_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE dep_type = VALUES(dep_type), lag_days = VALUES(lag_days)',
            [$processId, $predId, $type, $lag, $source, $userId]
        );
    }

    /**
     * PIC bawaan per peran: Sales → Sales PIC project; NPD Staff → NPD PIC; Drafter/Purchasing/
     * Production/Quality → PIC peran di part (bila sudah ditetapkan NPD).
     * @param array<string,mixed> $project
     * @param array<string,mixed>|null $part
     */
    public function defaultPic(string $roleCode, array $project, ?array $part): ?int
    {
        $v = match ($roleCode) {
            'admin_sales' => $project['sales_pic_id'],
            'npd_staff' => $project['npd_pic_id'],
            'drafter' => $part['drafter_pic_id'] ?? null,
            'purchasing' => $part['purchasing_pic_id'] ?? null,
            'production' => $part['production_pic_id'] ?? null,
            'quality' => $part['quality_pic_id'] ?? null,
            default => null,
        };
        return $v !== null ? (int) $v : null;
    }

    /** @param array<string,mixed> $step @param array<string,mixed> $data */
    private function insertProcess(array $step, int $projectId, ?int $partId, ?int $pic, array $data): int
    {
        return Db::insert('processes', array_merge([
            'project_id' => $projectId,
            'part_id' => $partId,
            'workflow_step_id' => (int) $step['id'],
            'code' => (string) $step['code'],
            'name' => (string) $step['name'],
            'name_en' => $step['name_en'],
            'step_type' => (string) $step['step_type'],
            'sort_order' => (int) $step['sort_order'],
            'pic_role_id' => (int) $step['pic_role_id'],
            'pic_user_id' => $pic,
            'status' => 'not_started',
            'activation' => (string) $step['activation'],
            'is_mandatory' => (int) $step['is_mandatory'],
            'is_skippable' => (int) $step['is_skippable'],
            'skip_group' => $step['skip_group'],
            'is_external' => (int) $step['is_external'],
            'is_customer_approval' => (int) $step['is_customer_approval'],
            'approval_type' => $step['approval_type'],
            'approval_giver' => $step['approval_giver'],
            'on_complete_reopen_code' => $step['on_complete_reopen_code'],
            'decision_options_json' => $step['decision_options_json'],
            'required_doc_types_json' => $step['required_doc_types_json'],
            'uploader_roles_json' => $step['uploader_roles_json'],
            'record_type' => $step['record_type'],
            'calendar_category' => $step['calendar_category'],
            'is_gate_milestone' => (int) $step['is_gate_milestone'],
            'duration' => max(1, (int) $step['default_duration']),
        ], $data));
    }
}
