<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Approval\ApprovalService;
use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\ConflictException;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\NumberSequence;
use App\Core\User;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Notification\Notifier;
use App\Project\RevisionHistory;
use App\Project\StatusService;
use App\Scheduling\ScheduleService;

/**
 * Mesin workflow (PRD §5.2–5.7): aktivasi otomatis, penyelesaian, keputusan & loop, "Tidak dijalankan",
 * gate, koreksi manual. Setiap perubahan status memicu penjadwalan ulang (server) dan penurunan status.
 */
final class WorkflowEngine
{
    public const ACTIVE = ['current', 'revision', 'problem'];

    public function __construct(
        private ScheduleService $schedule = new ScheduleService(),
        private StatusService $status = new StatusService(),
    ) {
    }

    // ================================================================ baca & hak akses

    /** @return array<string,mixed> proses + konteks project/part */
    public function load(int $processId, bool $forUpdate = false): array
    {
        $p = Db::fetch(
            'SELECT pr.*, r.code AS pic_role_code, pj.code AS project_code, pj.name AS project_name, pj.sales_pic_id, pj.npd_pic_id,
                    pj.is_on_hold AS project_on_hold, pj.status AS project_status, pj.finished_at AS project_finished_at,
                    pj.cancelled_at AS project_cancelled_at, pj.is_archived AS project_archived,
                    pp.name AS part_name, pp.is_on_hold AS part_on_hold, pp.cancelled_at AS part_cancelled_at, pp.start_date AS part_start_date, pp.part_type
             FROM processes pr
             JOIN roles r ON r.id = pr.pic_role_id
             JOIN projects pj ON pj.id = pr.project_id
             LEFT JOIN project_parts pp ON pp.id = pr.part_id
             WHERE pr.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$processId]
        );
        if (!$p) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $p;
    }

    /** PIC/role berhak mengerjakan proses (PRD §2.2–2.3). */
    public function canExecute(User $user, array $p): bool
    {
        $owners = [$p['pic_user_id']];
        if ($p['pic_role_code'] === 'admin_sales') {
            $owners[] = $p['sales_pic_id']; // Sales PIC project mengerjakan proses Sales
        }
        return Gate::can($user, 'process.execute', ['owner_ids' => $owners]);
    }

    public function isOnHold(array $p): bool
    {
        return (int) $p['project_on_hold'] === 1 || (int) ($p['part_on_hold'] ?? 0) === 1;
    }

    /** @return list<array<string,mixed>> opsi keputusan proses */
    public function decisionOptions(array $p): array
    {
        $d = $p['decision_options_json'] ? json_decode((string) $p['decision_options_json'], true) : null;
        return is_array($d) ? $d : [];
    }

    /** Predecessor FF yang belum selesai (FR-WF-03: FF memblokir penyelesaian dini). @return list<array<string,mixed>> */
    public function ffBlockers(int $processId): array
    {
        return Db::fetchAll(
            "SELECT p.id, p.code, p.name, p.status FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id
             WHERE d.process_id = ? AND d.dep_type = 'FF' AND p.status NOT IN ('completed', 'skipped')
               AND NOT (p.activation = 'loop_only' AND p.status = 'not_started')",
            [$processId]
        );
    }

    /** Tipe dokumen wajib yang belum diunggah (PRD §5.4 #5). @return list<string> */
    public function missingDocuments(array $p): array
    {
        $required = $p['required_doc_types_json'] ? (json_decode((string) $p['required_doc_types_json'], true) ?: []) : [];
        $missing = [];
        foreach ($required as $type) {
            $has = (int) Db::value(
                'SELECT COUNT(*) FROM documents WHERE process_id = ? AND doc_type_code = ? AND is_removed = 0 AND current_version_id IS NOT NULL',
                [(int) $p['id'], (string) $type]
            );
            if ($has === 0) {
                $missing[] = (string) $type;
            }
        }
        return $missing;
    }

    /** Syarat dependency untuk mulai terpenuhi? (FS: selesai/dilewati; SS: sudah mulai). */
    public function dependenciesSatisfied(int $processId): bool
    {
        $rows = Db::fetchAll(
            'SELECT d.dep_type, p.status, p.activation, p.actual_start FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id
             LEFT JOIN project_parts pp ON pp.id = p.part_id
             WHERE d.process_id = ? AND pp.cancelled_at IS NULL',
            [$processId]
        );
        foreach ($rows as $r) {
            $done = in_array($r['status'], ['completed', 'skipped'], true) || ($r['activation'] === 'loop_only' && $r['status'] === 'not_started');
            if ($r['dep_type'] === 'FS' && !$done) {
                return false;
            }
            if ($r['dep_type'] === 'SS' && !$done && $r['actual_start'] === null) {
                return false;
            }
        }
        $wait = Db::fetch('SELECT w.status FROM processes p JOIN processes w ON w.id = p.loop_after_process_id WHERE p.id = ?', [$processId]);
        return !$wait || $wait['status'] === 'completed';
    }

    // ================================================================ aktivasi

    /**
     * Aktifkan proses yang syarat dependency-nya terpenuhi dan Planned Start ≤ hari ini (PRD §5.4 #2).
     * Diulang sampai stabil (aktivasi SS dapat berantai pada hari yang sama).
     * @return list<int> proses yang diaktifkan
     */
    public function activateReady(int $projectId, ?User $actor = null): array
    {
        $project = Db::fetch('SELECT is_on_hold, finished_at, cancelled_at FROM projects WHERE id = ?', [$projectId]);
        if (!$project || (int) $project['is_on_hold'] === 1 || $project['finished_at'] !== null || $project['cancelled_at'] !== null) {
            return [];
        }
        $today = Clock::todayString();
        $activated = [];
        for ($round = 0; $round < 50; $round++) {
            $candidates = Db::fetchAll(
                "SELECT pr.id FROM processes pr LEFT JOIN project_parts pp ON pp.id = pr.part_id
                 WHERE pr.project_id = ? AND pr.status = 'not_started' AND pr.activation = 'auto'
                   AND pr.step_type NOT IN ('request', 'feedback')
                   AND pr.planned_start IS NOT NULL AND pr.planned_start <= ?
                   AND (pr.part_id IS NULL OR (pp.start_date IS NOT NULL AND pp.cancelled_at IS NULL AND pp.is_on_hold = 0 AND pp.completed_at IS NULL))
                 ORDER BY pr.planned_start, pr.sort_order, pr.id",
                [$projectId, $today]
            );
            $changed = false;
            foreach ($candidates as $c) {
                if ($this->dependenciesSatisfied((int) $c['id'])) {
                    $this->activate((int) $c['id'], 'current', $actor);
                    $activated[] = (int) $c['id'];
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }
        if ($activated) {
            // tanggal mulai aktual memengaruhi successor (SS, dan FS tidak sebelum predecessor mulai)
            $this->schedule->recalculate($projectId, 'auto_shift', null, null, $actor);
            $this->status->refresh($projectId);
        }
        return $activated;
    }

    /** Jadikan proses aktif: tanggal mulai aktual, run KPI baru, notifikasi PIC. */
    private function activate(int $processId, string $status, ?User $actor, bool $replan = false): void
    {
        $p = $this->load($processId);
        $today = Clock::todayString();
        $cal = $this->schedule->calendar();
        $prevRuns = (int) Db::value('SELECT COALESCE(MAX(iteration), 0) FROM process_runs WHERE process_id = ?', [$processId]);
        $iteration = $prevRuns > 0 ? $prevRuns + 1 : (int) $p['iteration'];
        $data = [
            'status' => $status,
            'actual_start' => $today,
            'actual_finish' => null,
            'activated_at' => Clock::nowString(),
            'iteration' => $iteration,
            'completed_at' => null,
            'completed_by' => null,
        ];
        if ($replan || $p['planned_start'] === null || $p['planned_finish'] === null) {
            $ps = $cal->nextWorkingDay($today);
            $data['planned_start'] = $ps;
            $data['planned_finish'] = $cal->finishFromStart($ps, (int) $p['duration']);
        }
        Db::update('processes', $data, ['id' => $processId]);
        $p = array_merge($p, $data);
        ProcessRuns::open($p, (string) $data['activated_at']);
        ApprovalService::requestFor($p, $actor); // approval Pending untuk iterasi ini (PRD §9.2)
        $this->notifyAssigned($p, $actor);
    }

    /** @param array<string,mixed> $p */
    private function notifyAssigned(array $p, ?User $actor): void
    {
        $where = ($p['part_name'] ? $p['part_name'] . ' › ' : '') . $p['name'];
        if ($p['pic_user_id']) {
            Notifier::send([(int) $p['pic_user_id']], 'project_assigned', 'notif.process_active.title', 'notif.process_active.body',
                ['project' => (string) $p['project_code'], 'process' => $where, 'finish' => (string) ($p['planned_finish'] ?? '-')],
                'process.php?id=' . $p['id'], (int) $p['project_id'], (int) $p['id'], 'assigned:' . $p['id'] . ':' . $p['iteration'], $actor?->id);
        } elseif ($p['npd_pic_id']) {
            Notifier::send([(int) $p['npd_pic_id']], 'project_assigned', 'notif.process_no_pic.title', 'notif.process_no_pic.body',
                ['project' => (string) $p['project_code'], 'process' => $where],
                'process.php?id=' . $p['id'], (int) $p['project_id'], (int) $p['id'], 'nopic:' . $p['id'] . ':' . $p['iteration'], $actor?->id);
        }
    }

    /** Mulai lebih awal dari Planned Start (dependency harus sudah terpenuhi). */
    public function startEarly(User $actor, int $processId): void
    {
        Db::transaction(function () use ($actor, $processId): void {
            $p = $this->load($processId, true);
            if (!$this->canExecute($actor, $p)) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            if ($p['status'] !== 'not_started' || $p['activation'] !== 'auto' || in_array($p['step_type'], ['request', 'feedback'], true)) {
                throw new BusinessRuleException(I18n::t('wf.cannot_start'));
            }
            $this->assertNotHeld($p);
            if ($p['part_id'] !== null && $p['part_start_date'] === null) {
                throw new BusinessRuleException(I18n::t('wf.part_not_started'));
            }
            if (!$this->dependenciesSatisfied($processId)) {
                throw new BusinessRuleException(I18n::t('wf.deps_not_met'));
            }
            $this->activate($processId, 'current', $actor);
            AuditLogger::log('process.start_early', 'process', $processId, ['status' => 'not_started'], ['status' => 'current'], null, (int) $p['project_id'], $actor);
            $this->schedule->recalculate((int) $p['project_id'], 'auto_shift', $processId, null, $actor);
            $this->activateReady((int) $p['project_id'], $actor);
            $this->status->refresh((int) $p['project_id']);
        });
    }

    // ================================================================ penyelesaian & keputusan

    /**
     * Selesaikan proses aktif.
     * @param array<string,mixed> $input actual_finish, outcome, comment, loop_to, gate_parts[partId=>processId], lock_version
     * @return array{effect:string,activated:list<int>}
     */
    public function complete(User $actor, int $processId, array $input = []): array
    {
        return Db::transaction(function () use ($actor, $processId, $input): array {
            $p = $this->load($processId, true);
            if (isset($input['lock_version']) && $input['lock_version'] !== null && (int) $input['lock_version'] !== (int) $p['lock_version']) {
                throw new ConflictException(I18n::t('error.conflict'));
            }
            if (!$this->canExecute($actor, $p)) {
                throw new AuthorizationException(I18n::t('error.forbidden'));
            }
            if (in_array($p['step_type'], ['request', 'feedback'], true)) {
                throw new BusinessRuleException(I18n::t('wf.complete_via_npr'));
            }
            if (!in_array($p['status'], self::ACTIVE, true)) {
                throw new BusinessRuleException(I18n::t('wf.not_active'));
            }
            $this->assertNotHeld($p);
            $blockers = $this->ffBlockers($processId);
            if ($blockers) {
                throw new BusinessRuleException(I18n::t('wf.ff_blocked', ['list' => implode(', ', array_map(static fn ($b) => $b['code'] . ' ' . $b['name'], $blockers))]));
            }
            $missing = $this->missingDocuments($p);
            if ($missing) {
                throw new BusinessRuleException(I18n::t('wf.missing_docs', ['list' => implode(', ', array_map(static fn ($t) => MasterService::label('document_type', $t), $missing))]));
            }
            if ($p['step_type'] === 'finish' && $p['part_id'] === null) {
                $open = (int) Db::value("SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND cancelled_at IS NULL AND completed_at IS NULL", [(int) $p['project_id']]);
                if ($open > 0) {
                    throw new BusinessRuleException(I18n::t('wf.parts_not_finished'));
                }
                Gate::authorize($actor, 'project.finish');
            }
            // keputusan
            $options = $this->decisionOptions($p);
            $outcome = isset($input['outcome']) ? trim((string) $input['outcome']) : null;
            $comment = isset($input['comment']) ? trim((string) $input['comment']) : null;
            $option = null;
            if ($options) {
                foreach ($options as $o) {
                    if ($o['code'] === $outcome) {
                        $option = $o;
                    }
                }
                if ($option === null) {
                    throw new ValidationException(['outcome' => I18n::t('wf.outcome_required')]);
                }
                if (!empty($option['comment_required']) && ($comment === null || $comment === '')) {
                    throw new ValidationException(['comment' => I18n::t('wf.comment_required')]);
                }
            }
            $today = Clock::todayString();
            $finish = isset($input['actual_finish']) && $input['actual_finish'] !== '' ? (string) $input['actual_finish'] : $today;
            $fd = \DateTimeImmutable::createFromFormat('!Y-m-d', $finish);
            if (!$fd || $fd->format('Y-m-d') !== $finish || $finish > $today) {
                throw new ValidationException(['actual_finish' => I18n::t('wf.finish_date_invalid')]);
            }
            if ($p['actual_start'] !== null && $finish < $p['actual_start']) {
                throw new ValidationException(['actual_finish' => I18n::t('wf.finish_before_start')]);
            }
            $effect = $option['effect'] ?? 'continue';
            $projectId = (int) $p['project_id'];
            $picAtCompletion = $p['pic_user_id'] !== null ? (int) $p['pic_user_id'] : $actor->id;
            ProcessRuns::close($processId, 'completed', $p['actual_start'], $finish, $outcome ?? 'completed', $picAtCompletion, $actor->id);
            if ($p['record_type'] === 'validation' && $outcome !== null) {
                \App\Record\RecordService::syncValidationResult($processId, (int) $p['iteration'], $outcome, $actor->id);
            }
            if ($p['approval_type'] && $p['approval_type'] !== 'npr') {
                $this->recordApproval($p, $option, $comment, $actor, isset($input['decision_maker']) ? (string) $input['decision_maker'] : null,
                    !empty($input['evidence_document_id']) ? (int) $input['evidence_document_id'] : null);
            }
            $changeType = 'auto_shift';
            switch ($effect) {
                case 'repeat':
                    $this->applyRepeat($p, (string) ($option['repeat_status'] ?? 'problem'), $outcome, $comment, $actor);
                    $changeType = 'loop';
                    break;
                case 'loop':
                    $choices = (array) ($option['loop_to'] ?? []);
                    $target = isset($input['loop_to']) && in_array($input['loop_to'], $choices, true) ? (string) $input['loop_to'] : (string) ($choices[0] ?? $p['code']);
                    $this->applyLoop($p, $target, $outcome, $comment, $actor);
                    $changeType = 'loop';
                    break;
                case 'activate':
                    $this->applyActivate($p, (string) $option['activate'], (string) $option['reopen'], $outcome, $comment, $actor);
                    $changeType = 'loop';
                    break;
                case 'gate_fail':
                    $this->applyGateFail($p, is_array($input['gate_parts'] ?? null) ? $input['gate_parts'] : [], $comment, $actor);
                    $changeType = 'loop';
                    break;
                default:
                    $this->markCompleted($p, $finish, $outcome, $comment, $actor);
                    if ($p['step_type'] === 'gate') {
                        $this->recordGate($p, 'pass', $comment, [], $actor);
                    }
            }
            AuditLogger::log('process.complete', 'process', $processId,
                ['status' => $p['status']],
                ['status' => $effect === 'continue' ? 'completed' : $effect, 'outcome' => $outcome, 'actual_finish' => $finish, 'iteration' => (int) $p['iteration']],
                $comment, $projectId, $actor);
            Db::update('projects', ['last_activity_at' => Clock::nowString()], ['id' => $projectId]);
            if ($p['part_id'] !== null) {
                Db::update('project_parts', ['last_activity_at' => Clock::nowString()], ['id' => (int) $p['part_id']]);
            }
            $this->schedule->recalculate($projectId, $changeType, $processId, $comment, $actor);
            $activated = $this->activateReady($projectId, $actor);
            $this->status->refresh($projectId);
            return ['effect' => $effect, 'activated' => $activated];
        });
    }

    /** @param array<string,mixed> $p */
    private function markCompleted(array $p, string $finish, ?string $outcome, ?string $comment, User $actor): void
    {
        Db::update('processes', [
            'status' => 'completed', 'actual_finish' => $finish, 'completed_at' => Clock::nowString(), 'completed_by' => $actor->id,
            'outcome' => $outcome, 'outcome_comment' => $comment, 'loop_after_process_id' => null,
        ], ['id' => (int) $p['id']]);
        $this->bumpLock((int) $p['id']);
        if ($p['step_type'] === 'finish') {
            if ($p['part_id'] !== null) {
                Db::update('project_parts', ['completed_at' => Clock::nowString(), 'status' => 'completed'], ['id' => (int) $p['part_id']]);
                AuditLogger::log('part.complete', 'project_part', (int) $p['part_id'], null, ['status' => 'completed'], null, (int) $p['project_id'], $actor);
            } else {
                Db::update('projects', ['finished_at' => Clock::nowString(), 'finished_by' => $actor->id, 'status' => 'completed'], ['id' => (int) $p['project_id']]);
                AuditLogger::log('project.finish', 'project', (int) $p['project_id'], null, ['status' => 'completed'], null, (int) $p['project_id'], $actor);
            }
        }
    }

    /** NG / FAIL / Not Approved (ulang proses yang sama): status Problem/Revision, iterasi +1. */
    private function applyRepeat(array $p, string $repeatStatus, ?string $outcome, ?string $comment, User $actor): void
    {
        $repeatStatus = in_array($repeatStatus, ['problem', 'revision'], true) ? $repeatStatus : 'problem';
        Db::update('processes', ['outcome' => $outcome, 'outcome_comment' => $comment, 'loop_count' => (int) $p['loop_count'] + 1], ['id' => (int) $p['id']]);
        $this->activate((int) $p['id'], $repeatStatus, $actor, true);
        $this->bumpLock((int) $p['id']);
        RevisionHistory::record('loop', I18n::t('wf.rev.repeat', ['process' => $p['name'], 'outcome' => $this->outcomeLabel($p, $outcome)], 'id'),
            ['outcome' => $outcome, 'comment' => $comment, 'iteration' => (int) $p['iteration'] + 1], null, (int) $p['project_id'], $p['part_id'] !== null ? (int) $p['part_id'] : null, (int) $p['id'], $actor->id);
    }

    /** Not Approved → kembali ke proses penyusun; turunan direset ke Not Started (PRD §5.2). */
    private function applyLoop(array $p, string $targetCode, ?string $outcome, ?string $comment, User $actor): void
    {
        $target = $this->sibling($p, $targetCode);
        if ($target === null) {
            throw new BusinessRuleException(I18n::t('wf.loop_target_missing'));
        }
        $tid = (int) $target['id'];
        Db::update('processes', ['outcome' => $outcome, 'outcome_comment' => $comment, 'loop_count' => (int) $p['loop_count'] + 1], ['id' => (int) $p['id']]);
        $reset = array_values(array_unique(array_merge([(int) $p['id']], $this->descendantsInScope($tid, $p['part_id'] !== null ? (int) $p['part_id'] : null))));
        $this->resetProcesses(array_diff($reset, [$tid]), $actor);
        $this->activate($tid, 'revision', $actor, true);
        $this->bumpLock($tid);
        RevisionHistory::record('loop', I18n::t('wf.rev.loop', ['process' => $p['name'], 'outcome' => $this->outcomeLabel($p, $outcome), 'target' => $target['name']], 'id'),
            ['outcome' => $outcome, 'comment' => $comment, 'target_process_id' => $tid, 'reset' => array_values(array_diff($reset, [$tid]))],
            null, (int) $p['project_id'], $p['part_id'] !== null ? (int) $p['part_id'] : null, (int) $p['id'], $actor->id);
    }

    /** T0 Not OK → Mold Correction aktif; Mold Machining dibuka kembali menunggu koreksi; T0 diulang. */
    private function applyActivate(array $p, string $activateCode, string $reopenCode, ?string $outcome, ?string $comment, User $actor): void
    {
        $x = $this->sibling($p, $activateCode);
        $r = $this->sibling($p, $reopenCode);
        if ($x === null || $r === null) {
            throw new BusinessRuleException(I18n::t('wf.loop_target_missing'));
        }
        Db::update('processes', ['outcome' => $outcome, 'outcome_comment' => $comment, 'loop_count' => (int) $p['loop_count'] + 1], ['id' => (int) $p['id']]);
        $reset = array_values(array_unique(array_merge([(int) $r['id'], (int) $p['id']], $this->descendantsInScope((int) $r['id'], (int) $p['part_id']))));
        $reset = array_values(array_diff($reset, [(int) $x['id']]));
        $this->resetProcesses($reset, $actor);
        Db::update('processes', ['loop_after_process_id' => (int) $x['id']], ['id' => (int) $r['id']]);
        $this->activate((int) $x['id'], 'current', $actor, true);
        RevisionHistory::record('loop', I18n::t('wf.rev.activate', ['process' => $p['name'], 'outcome' => $this->outcomeLabel($p, $outcome), 'activate' => $x['name'], 'reopen' => $r['name']], 'id'),
            ['outcome' => $outcome, 'comment' => $comment, 'activated' => (int) $x['id'], 'reopened' => (int) $r['id']],
            null, (int) $p['project_id'], (int) $p['part_id'], (int) $p['id'], $actor->id);
    }

    /**
     * Gate Fail (PRD §5.7): wajib catatan + pemilihan part yang diulang; proses part dibuka kembali.
     * @param array<int|string,mixed> $gateParts partId => process id yang dibuka kembali
     */
    private function applyGateFail(array $p, array $gateParts, ?string $comment, User $actor): void
    {
        if ($gateParts === []) {
            throw new ValidationException(['gate_parts' => I18n::t('wf.gate_choose_parts')]);
        }
        $failed = [];
        foreach ($gateParts as $partId => $reopenId) {
            $reopen = Db::fetch('SELECT * FROM processes WHERE id = ? AND part_id = ? AND project_id = ?', [(int) $reopenId, (int) $partId, (int) $p['project_id']]);
            if (!$reopen) {
                throw new ValidationException(['gate_parts' => I18n::t('validation.invalid')]);
            }
            $reset = $this->descendantsInScope((int) $reopen['id'], (int) $partId);
            $this->resetProcesses(array_diff($reset, [(int) $reopen['id']]), $actor);
            $this->activate((int) $reopen['id'], 'revision', $actor, true);
            $failed[] = (int) $partId;
        }
        // gate menunggu milestone part kembali
        $this->resetProcesses([(int) $p['id']], $actor);
        Db::update('processes', ['outcome' => 'fail', 'outcome_comment' => $comment, 'loop_count' => (int) $p['loop_count'] + 1], ['id' => (int) $p['id']]);
        $this->recordGate($p, 'fail', $comment, $failed, $actor);
        RevisionHistory::record('loop', I18n::t('wf.rev.gate_fail', ['gate' => $p['name'], 'count' => count($failed)], 'id'),
            ['comment' => $comment, 'parts' => $failed], null, (int) $p['project_id'], null, (int) $p['id'], $actor->id);
    }

    /** @param list<int> $partIds */
    private function recordGate(array $p, string $result, ?string $note, array $partIds, User $actor): void
    {
        Db::insert('project_gates', [
            'project_id' => (int) $p['project_id'], 'process_id' => (int) $p['id'], 'name' => (string) $p['name'], 'iteration' => (int) $p['iteration'], 'result' => $result,
            'result_note' => $note, 'failed_part_ids_json' => $partIds ? json_encode($partIds) : null, 'decided_by' => $actor->id, 'decided_at' => Clock::nowString(),
        ]);
    }

    /** Catat approval (customer dicatat Sales/NPD/Admin; internal oleh NPD/Admin) — PRD §9.2. */
    private function recordApproval(array $p, ?array $option, ?string $comment, User $actor, ?string $decisionMaker, ?int $evidenceDocId = null): void
    {
        $status = (string) ($option['approval_status'] ?? 'approved');
        if ($p['approval_giver'] === 'customer') {
            Gate::authorize($actor, 'approval.record_customer', ['owner_ids' => [$p['sales_pic_id']]]);
        } else {
            Gate::authorize($actor, 'approval.decide_internal');
        }
        if ($evidenceDocId !== null && !Db::value('SELECT id FROM documents WHERE id = ? AND process_id = ?', [$evidenceDocId, (int) $p['id']])) {
            $evidenceDocId = null; // bukti harus dokumen proses ini
        }
        // revisi dokumen yang dinilai: dokumen terbaru proses selain bukti approval
        $docVersion = Db::value(
            "SELECT d.current_version_id FROM documents d WHERE d.process_id = ? AND d.is_removed = 0 AND d.current_version_id IS NOT NULL AND d.id <> ?
             ORDER BY d.doc_type_code = 'approval', d.updated_at DESC, d.id DESC LIMIT 1",
            [(int) $p['id'], $evidenceDocId ?? 0]
        );
        if ($docVersion !== null) {
            Db::update('document_versions', ['status' => $status === 'approved' ? 'approved' : 'rejected'], ['id' => (int) $docVersion]);
        }
        $now = Clock::nowString();
        $pending = Db::fetch("SELECT id FROM approvals WHERE process_id = ? AND iteration = ? AND status = 'pending'", [(int) $p['id'], (int) $p['iteration']]);
        if ($pending) {
            $id = (int) $pending['id'];
            Db::update('approvals', ['status' => $status, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_maker_name' => $decisionMaker, 'comment' => $comment,
                'document_version_id' => $docVersion !== null ? (int) $docVersion : null, 'evidence_document_id' => $evidenceDocId], ['id' => $id]);
        } else {
            $id = Db::insert('approvals', [
                'code' => NumberSequence::nextApprovalCode(Clock::now()),
                'project_id' => (int) $p['project_id'], 'part_id' => $p['part_id'] !== null ? (int) $p['part_id'] : null, 'process_id' => (int) $p['id'],
                'approval_type' => (string) $p['approval_type'], 'giver' => (string) ($p['approval_giver'] ?? 'internal'), 'iteration' => (int) $p['iteration'],
                'document_version_id' => $docVersion !== null ? (int) $docVersion : null, 'status' => $status, 'requested_by' => $actor->id,
                'requested_at' => $now, 'decided_by' => $actor->id, 'decided_at' => $now, 'decision_maker_name' => $decisionMaker, 'comment' => $comment,
                'evidence_document_id' => $evidenceDocId,
            ]);
        }
        Db::insert('approval_history', ['approval_id' => $id, 'action' => 'decide', 'status_from' => 'pending', 'status_to' => $status, 'comment' => $comment, 'user_id' => $actor->id, 'created_at' => $now]);
        $notify = array_filter([(int) $p['npd_pic_id'], (int) $p['sales_pic_id'], $p['pic_user_id'] !== null ? (int) $p['pic_user_id'] : 0]);
        Notifier::send($notify, $status === 'approved' ? 'approval_approved' : 'approval_rejected',
            $status === 'approved' ? 'notif.approval_approved.title' : 'notif.approval_rejected.title', 'notif.approval_decided.body',
            ['project' => (string) $p['project_code'], 'process' => (string) $p['name'], 'comment' => (string) $comment],
            'process.php?id=' . $p['id'], (int) $p['project_id'], (int) $p['id'], 'approval:' . $id . ':' . $status, $actor->id);
    }

    /** Reset proses ke Not Started (loop): tanggal aktual dikosongkan, run terbuka ditutup "reset". @param iterable<int> $ids */
    private function resetProcesses(iterable $ids, User $actor): void
    {
        foreach ($ids as $id) {
            $row = Db::fetch('SELECT id, status, activation FROM processes WHERE id = ?', [$id]);
            if (!$row || $row['status'] === 'skipped') {
                continue;
            }
            if ($row['status'] === 'not_started') {
                continue;
            }
            ProcessRuns::close((int) $id, 'reset', null, null, 'reset', null, $actor->id);
            ApprovalService::withdrawPending((int) $id, $actor, 'loop');
            Db::update('processes', [
                'status' => 'not_started', 'actual_start' => null, 'actual_finish' => null, 'completed_at' => null, 'completed_by' => null,
            ], ['id' => (int) $id]);
            $this->bumpLock((int) $id);
        }
    }

    /** Proses lain dalam part yang sama (atau level project) berdasarkan kode. @return array<string,mixed>|null */
    private function sibling(array $p, string $code): ?array
    {
        return Db::fetch('SELECT * FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [(int) $p['project_id'], $p['part_id'], $code]);
    }

    /**
     * Turunan (dependency + loop_after) sebuah proses dalam part yang sama, termasuk dirinya.
     * @return list<int>
     */
    public function descendantsInScope(int $processId, ?int $partId): array
    {
        $seen = [$processId => true];
        $stack = [$processId];
        while ($stack) {
            $x = array_pop($stack);
            $succ = Db::column(
                'SELECT d.process_id FROM process_dependencies d JOIN processes p ON p.id = d.process_id WHERE d.predecessor_id = ? AND p.part_id <=> ?
                 UNION SELECT id FROM processes WHERE loop_after_process_id = ? AND part_id <=> ?',
                [$x, $partId, $x, $partId]
            );
            foreach ($succ as $s) {
                $s = (int) $s;
                if (!isset($seen[$s])) {
                    $seen[$s] = true;
                    $stack[] = $s;
                }
            }
        }
        return array_map('intval', array_keys($seen));
    }

    // ================================================================ Tidak dijalankan

    /** "Tidak dijalankan" (PRD §5.6): hanya proses yang diizinkan template, belum dimulai, alasan wajib, berpasangan. */
    public function skip(User $actor, int $processId, string $reason): void
    {
        Gate::authorize($actor, 'process.skip');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        Db::transaction(function () use ($actor, $processId, $reason): void {
            $p = $this->load($processId, true);
            if (!(int) $p['is_skippable']) {
                throw new BusinessRuleException(I18n::t('wf.not_skippable'));
            }
            $this->assertNotHeld($p);
            $group = $this->skipGroup($p);
            foreach ($group as $g) {
                if (!$this->notYetWorked($g)) {
                    throw new BusinessRuleException(I18n::t('wf.skip_only_not_started', ['process' => $g['name']]));
                }
            }
            $now = Clock::nowString();
            foreach ($group as $g) {
                if (in_array($g['status'], self::ACTIVE, true)) {
                    // aktif otomatis tanpa pekerjaan tercatat (OQ-26): run ditutup "skipped" — tidak dihitung KPI
                    ProcessRuns::close((int) $g['id'], 'skipped', null, null, 'skipped', null, $actor->id);
                    ApprovalService::withdrawPending((int) $g['id'], $actor, 'skipped');
                }
                Db::update('processes', [
                    'status' => 'skipped', 'skip_reason' => $reason, 'skipped_by' => $actor->id, 'skipped_at' => $now,
                    'actual_start' => null, 'actual_finish' => null,
                ], ['id' => (int) $g['id']]);
                $this->bumpLock((int) $g['id']);
            }
            $names = implode(', ', array_map(static fn ($g) => $g['name'], $group));
            RevisionHistory::record('skip', I18n::t('wf.rev.skip', ['list' => $names], 'id'), ['reason' => $reason, 'processes' => array_column($group, 'id')],
                null, (int) $p['project_id'], $p['part_id'] !== null ? (int) $p['part_id'] : null, $processId, $actor->id);
            AuditLogger::log('process.skip', 'process', $processId, ['status' => 'not_started'], ['status' => 'skipped', 'group' => array_column($group, 'code')], $reason, (int) $p['project_id'], $actor);
            $this->schedule->recalculate((int) $p['project_id'], 'skip', $processId, $reason, $actor, 'plan');
            $this->activateReady((int) $p['project_id'], $actor);
            $this->status->refresh((int) $p['project_id']);
        });
    }

    /** Jalankan kembali proses yang dilewati: NPD selama proses sesudahnya belum mulai; selebihnya Admin. */
    public function unskip(User $actor, int $processId, ?string $reason = null): void
    {
        Gate::authorize($actor, 'process.skip');
        Db::transaction(function () use ($actor, $processId, $reason): void {
            $p = $this->load($processId, true);
            $this->assertNotHeld($p);
            if ($p['status'] !== 'skipped') {
                throw new BusinessRuleException(I18n::t('wf.not_skipped'));
            }
            $group = array_values(array_filter($this->skipGroup($p), static fn ($g) => $g['status'] === 'skipped'));
            $started = false;
            foreach ($group as $g) {
                foreach ($this->descendantsInScope((int) $g['id'], $p['part_id'] !== null ? (int) $p['part_id'] : null) as $d) {
                    if ($d === (int) $g['id']) {
                        continue;
                    }
                    $st = Db::fetch('SELECT status, actual_start FROM processes WHERE id = ?', [$d]);
                    if ($st && ($st['actual_start'] !== null || !in_array($st['status'], ['not_started', 'skipped'], true))) {
                        $started = true;
                    }
                }
            }
            if ($started && !Gate::can($actor, 'process.unskip_any')) {
                throw new BusinessRuleException(I18n::t('wf.unskip_admin_only'));
            }
            foreach ($group as $g) {
                Db::update('processes', ['status' => 'not_started', 'skip_reason' => null, 'skipped_by' => null, 'skipped_at' => null], ['id' => (int) $g['id']]);
                $this->bumpLock((int) $g['id']);
            }
            RevisionHistory::record('unskip', I18n::t('wf.rev.unskip', ['list' => implode(', ', array_column($group, 'name'))], 'id'), ['reason' => $reason],
                null, (int) $p['project_id'], $p['part_id'] !== null ? (int) $p['part_id'] : null, $processId, $actor->id);
            AuditLogger::log('process.unskip', 'process', $processId, ['status' => 'skipped'], ['status' => 'not_started'], $reason, (int) $p['project_id'], $actor);
            $this->schedule->recalculate((int) $p['project_id'], 'unskip', $processId, $reason, $actor, 'plan');
            $this->activateReady((int) $p['project_id'], $actor);
            $this->status->refresh((int) $p['project_id']);
        });
    }

    /**
     * "Belum dimulai" untuk keperluan Tidak dijalankan (PRD §5.6, OQ-26): status Not Started, atau aktif
     * otomatis pada iterasi pertama tanpa pekerjaan tercatat (dokumen, approval, record, komentar).
     * @param array<string,mixed> $g
     */
    public function notYetWorked(array $g): bool
    {
        if ($g['status'] === 'not_started') {
            return true;
        }
        // aktif otomatis berarti mulai pada/after Planned Start; "Mulai lebih awal" = pekerjaan sudah dimulai
        if ($g['status'] !== 'current' || (int) $g['loop_count'] > 0 || $g['planned_start'] === null || $g['actual_start'] < $g['planned_start']
            || (int) Db::value("SELECT COUNT(*) FROM process_runs WHERE process_id = ? AND status = 'completed'", [(int) $g['id']]) > 0) {
            return false;
        }
        $id = (int) $g['id'];
        $evidence = (int) Db::value(
            'SELECT (SELECT COUNT(*) FROM documents WHERE process_id = ? AND is_removed = 0)
                  + (SELECT COUNT(*) FROM approvals WHERE process_id = ?)
                  + (SELECT COUNT(*) FROM trial_records WHERE process_id = ?)
                  + (SELECT COUNT(*) FROM material_requests WHERE process_id = ?)
                  + (SELECT COUNT(*) FROM validation_records WHERE process_id = ?)
                  + (SELECT COUNT(*) FROM comments WHERE process_id = ?)',
            [$id, $id, $id, $id, $id, $id]
        );
        return $evidence === 0;
    }

    /** Tombol "Tidak dijalankan" relevan: proses boleh dilewati dan seluruh grup pasangannya belum dikerjakan. */
    public function canSkipNow(array $p): bool
    {
        if (!(int) $p['is_skippable'] || $this->isOnHold($p)) {
            return false;
        }
        foreach ($this->skipGroup($p) as $g) {
            if (!$this->notYetWorked($g)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> proses dalam grup pasangan lewat (atau dirinya saja) */
    private function skipGroup(array $p): array
    {
        if ($p['skip_group'] === null || $p['skip_group'] === '') {
            return [Db::fetch('SELECT * FROM processes WHERE id = ?', [(int) $p['id']])];
        }
        return Db::fetchAll('SELECT * FROM processes WHERE project_id = ? AND part_id <=> ? AND skip_group = ? ORDER BY sort_order', [(int) $p['project_id'], $p['part_id'], $p['skip_group']]);
    }

    // ================================================================ koreksi manual & planning

    /** Koreksi manual proses aktif oleh Admin (PRD §5.4 #7) — alasan wajib, tercatat di audit. */
    public function manualMove(User $actor, int $processId, string $target, string $reason, ?string $manualStart = null): void
    {
        Gate::authorize($actor, 'process.manual_move');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        if (!in_array($target, ['current', 'completed', 'not_started'], true)) {
            throw new ValidationException(['target' => I18n::t('validation.invalid')]);
        }
        if ($manualStart !== null && $manualStart !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $manualStart);
            if (!$d || $d->format('Y-m-d') !== $manualStart) {
                throw new ValidationException(['manual_start' => I18n::t('validation.date')]);
            }
        }
        Db::transaction(function () use ($actor, $processId, $target, $reason, $manualStart): void {
            $p = $this->load($processId, true);
            $this->assertNotClosed($p);
            $old = $p['status'];
            if ($old === $target) {
                return;
            }
            $today = Clock::todayString();
            if ($target === 'current') {
                $this->activate($processId, 'current', $actor);
            } elseif ($target === 'completed') {
                ProcessRuns::close($processId, 'completed', $p['actual_start'] ?? $today, $today, 'manual', $p['pic_user_id'] !== null ? (int) $p['pic_user_id'] : null, $actor->id);
                $this->markCompleted($p + ['actual_start' => $p['actual_start'] ?? $today], $today, 'manual', $reason, $actor);
                if ($p['actual_start'] === null) {
                    Db::update('processes', ['actual_start' => $today], ['id' => $processId]);
                }
            } else {
                // Kembali ke Not Started: tanpa tanggal manual proses akan aktif lagi bila dependency terpenuhi.
                $this->resetProcesses([$processId], $actor);
                if ($manualStart !== null && $manualStart !== '') {
                    Db::update('processes', ['manual_start' => $manualStart], ['id' => $processId]);
                }
            }
            RevisionHistory::record('manual_move', I18n::t('wf.rev.manual', ['process' => $p['name'], 'from' => $old, 'to' => $target], 'id'), ['reason' => $reason],
                null, (int) $p['project_id'], $p['part_id'] !== null ? (int) $p['part_id'] : null, $processId, $actor->id);
            AuditLogger::log('process.manual_move', 'process', $processId, ['status' => $old], ['status' => $target], $reason, (int) $p['project_id'], $actor);
            $this->schedule->recalculate((int) $p['project_id'], 'manual_plan', $processId, $reason, $actor);
            $this->activateReady((int) $p['project_id'], $actor);
            $this->status->refresh((int) $p['project_id']);
        });
    }

    /**
     * Normalisasi input planning (FR-SCH-01). @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function cleanPlan(array $p, array $input): array
    {
        $data = [];
        $errors = [];
        $isNotStarted = $p['status'] === 'not_started';
        foreach (['manual_start', 'manual_finish'] as $k) {
            if (array_key_exists($k, $input)) {
                $v = trim((string) $input[$k]);
                if ($v === '') {
                    $data[$k] = null;
                } elseif (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v) {
                    $data[$k] = $v;
                } else {
                    $errors[$k] = I18n::t('validation.date');
                }
            }
        }
        if (array_key_exists('duration', $input) && trim((string) $input['duration']) !== '') {
            $v = trim((string) $input['duration']);
            if (!preg_match('/^\d{1,3}$/', $v) || (int) $v < 1 || (int) $v > 365) {
                $errors['duration'] = I18n::t('wf.duration_invalid');
            } else {
                $data['duration'] = (int) $v;
                $data['duration_is_manual'] = 1;
            }
        }
        if (array_key_exists('planned_finish', $input) && trim((string) $input['planned_finish']) !== '' && !$isNotStarted) {
            $v = trim((string) $input['planned_finish']);
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v || ($p['actual_start'] !== null && $v < $p['actual_start'])) {
                $errors['planned_finish'] = I18n::t('validation.date');
            } else {
                $data['planned_finish'] = $this->schedule->calendar()->nextWorkingDay($v);
            }
        }
        if (isset($data['manual_start'], $data['manual_finish']) && $data['manual_finish'] < $data['manual_start']) {
            $errors['manual_finish'] = I18n::t('wf.finish_before_start');
        }
        if (!$isNotStarted && (isset($data['manual_start']) || isset($data['manual_finish']) || isset($data['duration']))) {
            $errors['manual_start'] = I18n::t('wf.plan_only_not_started');
        }
        if (array_key_exists('pic_user_id', $input)) {
            $uid = trim((string) $input['pic_user_id']);
            if ($uid === '') {
                $data['pic_user_id'] = null;
            } else {
                $u = Db::fetch('SELECT u.id, r.id AS role_id, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1', [(int) $uid]);
                if (!$u || ((int) $u['role_id'] !== (int) $p['pic_role_id'] && $u['role_code'] !== 'admin')) {
                    $errors['pic_user_id'] = I18n::t('wf.pic_role_mismatch');
                } else {
                    $data['pic_user_id'] = (int) $u['id'];
                }
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    /** Pratinjau planning sebelum simpan. @param array<string,mixed> $input @return array<string,mixed> */
    public function previewPlan(User $actor, int $processId, array $input): array
    {
        Gate::authorize($actor, 'schedule.plan');
        $p = $this->load($processId);
        $data = $this->cleanPlan($p, $input);
        return $this->schedule->preview((int) $p['project_id'], static function (array &$g) use ($processId, $data): void {
            foreach (['manual_start', 'manual_finish', 'duration', 'planned_finish'] as $k) {
                if (array_key_exists($k, $data)) {
                    $g['nodes'][$processId][$k] = $data[$k];
                }
            }
        });
    }

    /**
     * Simpan planning proses (durasi, Planned Start/Finish manual, PIC) — PRD §6.1.
     * @param array<string,mixed> $input
     */
    public function plan(User $actor, int $processId, array $input, ?string $reason = null): void
    {
        Gate::authorize($actor, 'schedule.plan');
        Db::transaction(function () use ($actor, $processId, $input, $reason): void {
            $p = $this->load($processId, true);
            if (isset($input['lock_version']) && $input['lock_version'] !== null && $input['lock_version'] !== '' && (int) $input['lock_version'] !== (int) $p['lock_version']) {
                throw new ConflictException(I18n::t('error.conflict'));
            }
            $this->assertNotClosed($p);
            if (in_array($p['status'], ['completed', 'skipped'], true)) {
                throw new BusinessRuleException(I18n::t('wf.plan_closed'));
            }
            $data = $this->cleanPlan($p, $input);
            if (isset($data['planned_finish']) && trim((string) $reason) === '') {
                throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
            }
            $old = array_intersect_key($p, $data);
            [$o, $n] = AuditLogger::diff($old, $data);
            if (!$n) {
                return;
            }
            Db::update('processes', $data, ['id' => $processId]);
            $this->bumpLock($processId);
            AuditLogger::log('process.plan', 'process', $processId, $o, $n, $reason, (int) $p['project_id'], $actor);
            if (array_key_exists('pic_user_id', $n) && $n['pic_user_id'] !== null && in_array($p['status'], self::ACTIVE, true)) {
                $this->notifyAssigned(array_merge($p, ['pic_user_id' => $n['pic_user_id']]), $actor);
            }
            if (array_intersect_key($n, ['manual_start' => 1, 'manual_finish' => 1, 'duration' => 1, 'planned_finish' => 1])) {
                $this->schedule->recalculate((int) $p['project_id'], 'manual_plan', $processId, $reason, $actor, 'plan');
                $this->activateReady((int) $p['project_id'], $actor);
            }
            $this->status->refresh((int) $p['project_id']);
        });
    }

    // ================================================================ kaitan alur NPR

    /** NPR dikembalikan ke Sales: P1 aktif kembali (Revision), P2 menunggu. */
    public function onNprReturned(int $projectId, User $actor): void
    {
        $p1 = Db::fetch("SELECT id FROM processes WHERE project_id = ? AND part_id IS NULL AND code = 'P1'", [$projectId]);
        $p2 = Db::fetch("SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND code = 'P2'", [$projectId]);
        if ($p2 && in_array($p2['status'], self::ACTIVE, true)) {
            ProcessRuns::close((int) $p2['id'], 'completed', $p2['actual_start'], Clock::todayString(), 'returned', $actor->id, $actor->id);
            Db::update('processes', ['status' => 'not_started', 'actual_start' => null, 'outcome' => 'returned'], ['id' => (int) $p2['id']]);
        }
        if ($p1) {
            $this->activate((int) $p1['id'], 'revision', $actor, true);
        }
        $this->schedule->recalculate($projectId, 'loop', $p2 ? (int) $p2['id'] : null, null, $actor);
        $this->status->refresh($projectId);
    }

    /** NPR dikirim ulang: P1 selesai, P2 aktif kembali. */
    public function onNprResubmitted(int $projectId, User $actor): void
    {
        $p1 = Db::fetch("SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND code = 'P1'", [$projectId]);
        $p2 = Db::fetch("SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND code = 'P2'", [$projectId]);
        $today = Clock::todayString();
        if ($p1 && in_array($p1['status'], self::ACTIVE, true)) {
            ProcessRuns::close((int) $p1['id'], 'completed', $p1['actual_start'], $today, 'resubmitted', $p1['pic_user_id'] !== null ? (int) $p1['pic_user_id'] : $actor->id, $actor->id);
            Db::update('processes', ['status' => 'completed', 'actual_finish' => $today, 'completed_at' => Clock::nowString(), 'completed_by' => $actor->id, 'outcome' => 'resubmitted'], ['id' => (int) $p1['id']]);
        }
        if ($p2 && $p2['status'] === 'not_started') {
            $this->activate((int) $p2['id'], 'current', $actor, true);
        }
        $this->schedule->recalculate($projectId, 'loop', $p1 ? (int) $p1['id'] : null, null, $actor);
        $this->status->refresh($projectId);
    }

    /**
     * Feedback NPR selesai: P2 selesai, part yang diterima mulai (PRD §4.1 #4, §5.4 #1).
     * @param list<int> $acceptedProjectPartIds
     */
    public function onFeedbackCompleted(int $projectId, array $acceptedProjectPartIds, User $actor): void
    {
        $p2 = Db::fetch("SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND code = 'P2'", [$projectId]);
        $today = Clock::todayString();
        if ($p2 && in_array($p2['status'], self::ACTIVE, true)) {
            $pic = $p2['pic_user_id'] !== null ? (int) $p2['pic_user_id'] : $actor->id; // OQ-04: penyelesai feedback
            ProcessRuns::close((int) $p2['id'], 'completed', $p2['actual_start'], $today, 'completed', $pic, $actor->id);
            Db::update('processes', ['status' => 'completed', 'actual_finish' => $today, 'completed_at' => Clock::nowString(), 'completed_by' => $actor->id, 'outcome' => 'completed', 'pic_user_id' => $pic], ['id' => (int) $p2['id']]);
        }
        if (Db::value('SELECT npd_pic_id FROM projects WHERE id = ?', [$projectId]) === null) {
            // OQ-04: NPD PIC bawaan = pengguna yang menyelesaikan feedback; proses NPD level project mengikuti
            Db::update('projects', ['npd_pic_id' => $actor->id], ['id' => $projectId]);
            Db::execute(
                "UPDATE processes pr JOIN roles r ON r.id = pr.pic_role_id SET pr.pic_user_id = ?
                 WHERE pr.project_id = ? AND pr.part_id IS NULL AND pr.pic_user_id IS NULL AND r.code = 'npd_staff' AND pr.status NOT IN ('completed', 'skipped')",
                [$actor->id, $projectId]
            );
        }
        $instantiator = new WorkflowInstantiator($this->schedule);
        foreach ($acceptedProjectPartIds as $ppId) {
            $instantiator->startPart($ppId, $actor, $today);
        }
        $this->schedule->recalculate($projectId, 'auto_shift', $p2 ? (int) $p2['id'] : null, null, $actor);
        $this->activateReady($projectId, $actor);
        $this->status->refresh($projectId);
    }

    /** Part dibatalkan setelah berjalan: lepas dari gate/PF, jadwal & status dihitung ulang. */
    public function onPartCancelled(int $projectPartId, User $actor): void
    {
        $projectId = (int) Db::value('SELECT project_id FROM project_parts WHERE id = ?', [$projectPartId]);
        foreach (Db::fetchAll("SELECT id FROM processes WHERE part_id = ? AND status IN ('current','revision','problem')", [$projectPartId]) as $r) {
            ProcessRuns::close((int) $r['id'], 'reset', null, null, 'cancelled', null, $actor->id);
        }
        (new WorkflowInstantiator($this->schedule))->detachPart($projectPartId);
        $this->schedule->recalculate($projectId, 'auto_shift', null, null, $actor);
        $this->activateReady($projectId, $actor);
        $this->status->refresh($projectId);
    }

    /** Label keputusan (bahasa Indonesia, untuk Revision History yang tersimpan) — bukan kode mentah. */
    private function outcomeLabel(array $p, ?string $outcome): string
    {
        foreach ($this->decisionOptions($p) as $o) {
            if (($o['code'] ?? null) === $outcome) {
                return (string) ($o['label_id'] ?? $outcome);
            }
        }
        return (string) $outcome;
    }

    /** Project selesai/batal/arsip atau part batal: proses tidak dapat diubah lagi (PRD §8.4). */
    public function isClosed(array $p): bool
    {
        return $p['project_finished_at'] !== null || $p['project_cancelled_at'] !== null || (int) $p['project_archived'] === 1 || ($p['part_cancelled_at'] ?? null) !== null;
    }

    private function assertNotClosed(array $p): void
    {
        if ($this->isClosed($p)) {
            throw new BusinessRuleException(I18n::t('wf.closed'));
        }
    }

    private function assertNotHeld(array $p): void
    {
        $this->assertNotClosed($p);
        if ($this->isOnHold($p)) {
            throw new BusinessRuleException(I18n::t('wf.on_hold'));
        }
    }

    private function bumpLock(int $processId): void
    {
        Db::execute('UPDATE processes SET lock_version = lock_version + 1 WHERE id = ?', [$processId]);
    }
}
