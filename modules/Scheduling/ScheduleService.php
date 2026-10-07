<?php
declare(strict_types=1);

namespace App\Scheduling;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\Settings;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;
use App\Project\RevisionHistory;

/**
 * Lapisan persistensi mesin penjadwalan: memuat graf project, menjalankan Scheduler, menyimpan
 * hasil dalam SATU transaksi dengan penguncian baris project, mencatat penggeseran (schedule_changes),
 * audit, dan notifikasi (schedule_shifted, target_at_risk).
 *
 * Aturan simpan: Planned Start/Finish hanya ditulis untuk proses yang BELUM dimulai (proses berjalan
 * mempertahankan planned-nya; proses selesai memakai tanggal aktual untuk perhitungan turunan).
 */
final class ScheduleService
{
    public function __construct(private ?WorkingCalendar $calendar = null)
    {
    }

    public function calendar(): WorkingCalendar
    {
        return $this->calendar ??= WorkingCalendar::fromDb();
    }

    /**
     * @return array{project:array<string,mixed>,rows:array<int,array<string,mixed>>,nodes:array<int,array<string,mixed>>,deps:list<array<string,mixed>>,partStart:array<int,?string>,frozen:array<int,bool>}
     */
    public function loadGraph(int $projectId, bool $forUpdate = false): array
    {
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$projectId]);
        if (!$project) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $parts = Db::fetchAll('SELECT id, status, start_date, is_on_hold, cancelled_at FROM project_parts WHERE project_id = ?', [$projectId]);
        $partStart = [];
        $cancelled = [];
        $held = [];
        foreach ($parts as $p) {
            $partStart[(int) $p['id']] = $p['start_date'];
            if ($p['cancelled_at'] !== null) {
                $cancelled[(int) $p['id']] = true;
            }
            if ((int) $p['is_on_hold'] === 1) {
                $held[(int) $p['id']] = true;
            }
        }
        $rows = [];
        $nodes = [];
        $frozen = [];
        foreach (Db::fetchAll('SELECT * FROM processes WHERE project_id = ? ORDER BY sort_order, id', [$projectId]) as $r) {
            $id = (int) $r['id'];
            $partId = $r['part_id'] !== null ? (int) $r['part_id'] : null;
            $rows[$id] = $r;
            $nodes[$id] = [
                'id' => $id,
                'part_id' => $partId,
                'status' => (string) $r['status'],
                'activation' => (string) $r['activation'],
                'duration' => (int) $r['duration'],
                'manual_start' => $r['manual_start'],
                'manual_finish' => $r['manual_finish'],
                'planned_start' => $r['planned_start'],
                'planned_finish' => $r['planned_finish'],
                'actual_start' => $r['actual_start'],
                'actual_finish' => $r['actual_finish'],
                'loop_after_id' => $r['loop_after_process_id'] !== null ? (int) $r['loop_after_process_id'] : null,
                'excluded' => $partId !== null && isset($cancelled[$partId]),
            ];
            // Selama Hold, jadwal part/project dibekukan (PRD §8.1)
            if ((int) $project['is_on_hold'] === 1 || ($partId !== null && isset($held[$partId]))) {
                $frozen[$id] = true;
            }
        }
        $deps = array_map(static fn ($d) => [
            'process_id' => (int) $d['process_id'], 'predecessor_id' => (int) $d['predecessor_id'],
            'type' => (string) $d['dep_type'], 'lag' => (int) $d['lag_days'],
        ], Db::fetchAll('SELECT d.* FROM process_dependencies d JOIN processes p ON p.id = d.process_id WHERE p.project_id = ?', [$projectId]));
        return ['project' => $project, 'rows' => $rows, 'nodes' => $nodes, 'deps' => $deps, 'partStart' => $partStart, 'frozen' => $frozen];
    }

    /** @param array<string,mixed> $graph @return array<string,mixed> */
    public function compute(array $graph, string $mode = 'plan', ?string $today = null): array
    {
        $scheduler = new Scheduler(
            $this->calendar(),
            $graph['nodes'],
            $graph['deps'],
            $graph['partStart'],
            (string) $graph['project']['start_date'],
            $today ?? Clock::todayString(),
            Settings::bool('schedule.pull_forward_on_early_finish', true),
            $mode,
        );
        $result = $scheduler->run();
        $result['critical'] = $scheduler->criticalPath($result['processes']);
        return $result;
    }

    /**
     * Perubahan planned untuk proses yang belum dimulai (dasar pratinjau & log).
     * @param array<string,mixed> $graph
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    public function diff(array $graph, array $result, ?array $before = null): array
    {
        $changes = [];
        foreach ($result['processes'] as $id => $r) {
            $row = $graph['rows'][$id] ?? null;
            if ($row === null || !empty($graph['frozen'][$id]) || $r['excluded']) {
                continue;
            }
            $status = (string) ($graph['nodes'][$id]['status'] ?? $row['status']);
            if (!in_array($status, ['not_started', 'skipped'], true)) {
                continue;
            }
            // pembanding: hasil hitung "sebelum" (pratinjau) atau nilai tersimpan (simpan)
            $oldS = $before !== null ? ($before['processes'][$id]['planned_start'] ?? null) : $row['planned_start'];
            $oldF = $before !== null ? ($before['processes'][$id]['planned_finish'] ?? null) : $row['planned_finish'];
            if ($oldS === $r['planned_start'] && $oldF === $r['planned_finish']) {
                continue;
            }
            $shift = ($oldF !== null && $r['planned_finish'] !== null) ? $this->calendar()->deviation((string) $oldF, (string) $r['planned_finish']) : null;
            $changes[] = [
                'process_id' => (int) $id,
                'part_id' => $row['part_id'] !== null ? (int) $row['part_id'] : null,
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'pic_user_id' => $row['pic_user_id'] !== null ? (int) $row['pic_user_id'] : null,
                'old_start' => $oldS,
                'old_finish' => $oldF,
                'new_start' => $r['planned_start'],
                'new_finish' => $r['planned_finish'],
                'shift' => $shift,
                'warning' => $r['warning'],
            ];
        }
        return $changes;
    }

    /**
     * Hitung ulang & simpan jadwal project (dalam transaksi, baris project dikunci).
     * @return array{changes:list<array<string,mixed>>,project_forecast:?string,batch:string}
     */
    public function recalculate(int $projectId, string $changeType = 'auto_shift', ?int $causeProcessId = null, ?string $reason = null, ?User $actor = null, string $mode = 'event'): array
    {
        return Db::transaction(function () use ($projectId, $changeType, $causeProcessId, $reason, $actor, $mode): array {
            $graph = $this->loadGraph($projectId, true);
            $result = $this->compute($graph, $mode);
            $changes = $this->diff($graph, $result);
            $batch = bin2hex(random_bytes(8));
            $this->persist($graph, $result);
            foreach ($changes as $c) {
                Db::insert('schedule_changes', [
                    'batch_id' => $batch,
                    'project_id' => $projectId,
                    'part_id' => $c['part_id'],
                    'process_id' => $c['process_id'],
                    'cause_process_id' => $causeProcessId,
                    'change_type' => $changeType,
                    'old_start' => $c['old_start'],
                    'old_finish' => $c['old_finish'],
                    'new_start' => $c['new_start'],
                    'new_finish' => $c['new_finish'],
                    'shift_working_days' => $c['shift'],
                    'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                    'user_id' => $actor?->id,
                    'created_at' => Clock::nowString(),
                ]);
            }
            $shifted = array_values(array_filter($changes, static fn ($c) => $c['shift'] !== null && $c['shift'] !== 0));
            if ($changes && $changeType !== 'initial') {
                AuditLogger::log('schedule.' . $changeType, 'project', $projectId,
                    null,
                    ['batch' => $batch, 'cause_process_id' => $causeProcessId, 'changed' => count($changes),
                     'shifts' => array_map(static fn ($c) => ['process' => $c['code'] . ' ' . $c['name'], 'part_id' => $c['part_id'], 'from' => $c['old_start'] . '..' . $c['old_finish'], 'to' => $c['new_start'] . '..' . $c['new_finish'], 'shift' => $c['shift']], array_slice($changes, 0, 50))],
                    $reason, $projectId, $actor);
            }
            if ($shifted && $changeType !== 'initial') {
                $this->notifyShift($graph['project'], $shifted, $batch, $actor);
            }
            $this->checkTargetRisk($graph['project'], $result['project']);
            return ['changes' => $changes, 'project_forecast' => $result['project'], 'batch' => $batch];
        });
    }

    /**
     * Pratinjau tanpa menyimpan. $mutate menerima graf (by reference) untuk menerapkan usulan perubahan.
     * @param callable(array<string,mixed>&):void $mutate
     * @return array{changes:list<array<string,mixed>>,result:array<string,mixed>,project_forecast_old:?string,project_forecast_new:?string,target_finish:?string}
     */
    public function preview(int $projectId, callable $mutate, string $mode = 'plan'): array
    {
        $graph = $this->loadGraph($projectId);
        $before = $this->compute($graph, $mode);
        $mutate($graph);
        $result = $this->compute($graph, $mode);
        return [
            // hanya perubahan akibat usulan (bukan selisih dengan data tersimpan yang mungkin belum dihitung ulang)
            'changes' => $this->diff($graph, $result, $before),
            'result' => $result,
            'project_forecast_old' => $before['project'],
            'project_forecast_new' => $result['project'],
            'target_finish' => $graph['project']['target_finish'],
        ];
    }

    /** @param array<string,mixed> $graph @param array<string,mixed> $result */
    private function persist(array $graph, array $result): void
    {
        foreach ($result['processes'] as $id => $r) {
            if (!empty($graph['frozen'][$id]) || !isset($graph['rows'][$id])) {
                continue;
            }
            $row = $graph['rows'][$id];
            $status = (string) $graph['nodes'][$id]['status'];
            $data = [
                'forecast_start' => $r['forecast_start'],
                'forecast_finish' => $r['forecast_finish'],
                'schedule_warning' => $r['warning'],
            ];
            if (in_array($status, ['not_started', 'skipped'], true) && !$r['excluded']) {
                $data['planned_start'] = $r['planned_start'];
                $data['planned_finish'] = $r['planned_finish'];
            }
            $changed = false;
            foreach ($data as $k => $v) {
                if ($row[$k] !== $v) {
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                Db::update('processes', $data, ['id' => $id]);
            }
        }
        foreach ($graph['partStart'] as $partId => $_) {
            Db::update('project_parts', ['forecast_finish' => $result['parts'][$partId] ?? null], ['id' => $partId]);
        }
        Db::update('projects', ['forecast_finish' => $result['project']], ['id' => (int) $graph['project']['id']]);
    }

    /** @param array<string,mixed> $project @param list<array<string,mixed>> $shifted */
    private function notifyShift(array $project, array $shifted, string $batch, ?User $actor): void
    {
        $byUser = [];
        foreach ($shifted as $c) {
            if ($c['pic_user_id']) {
                $byUser[$c['pic_user_id']][] = $c;
            }
        }
        foreach ($byUser as $uid => $list) {
            $first = $list[0];
            Notifier::send([(int) $uid], 'schedule_shifted', 'notif.schedule_shifted.title', 'notif.schedule_shifted.body',
                ['project' => (string) $project['code'], 'process' => (string) $first['name'], 'count' => count($list), 'shift' => (string) $first['shift'], 'date' => (string) $first['new_start']],
                'project.php?id=' . $project['id'] . '&tab=timeline', (int) $project['id'], (int) $first['process_id'], 'schedule_shifted:' . $batch, $actor?->id);
        }
        if ($project['npd_pic_id']) {
            Notifier::send([(int) $project['npd_pic_id']], 'schedule_shifted', 'notif.schedule_shifted_npd.title', 'notif.schedule_shifted_npd.body',
                ['project' => (string) $project['code'], 'count' => count($shifted)],
                'project.php?id=' . $project['id'] . '&tab=timeline', (int) $project['id'], null, 'schedule_shifted_npd:' . $batch, $actor?->id);
        }
    }

    /** Perkiraan selesai melewati Target Finish → notifikasi NPD PIC & Admin (dedupe per tanggal perkiraan). */
    public function checkTargetRisk(array $project, ?string $forecast): void
    {
        if (!$forecast || !$project['target_finish'] || $forecast <= $project['target_finish'] || in_array($project['status'], ['completed', 'cancelled'], true)) {
            return;
        }
        $recipients = Notifier::usersWithRole('admin');
        if ($project['npd_pic_id']) {
            $recipients[] = (int) $project['npd_pic_id'];
        }
        Notifier::send($recipients, 'target_at_risk', 'notif.target_at_risk.title', 'notif.target_at_risk.body',
            ['project' => (string) $project['code'], 'name' => (string) $project['name'], 'forecast' => $forecast, 'target' => (string) $project['target_finish']],
            'project.php?id=' . $project['id'], (int) $project['id'], null, 'target_at_risk:' . $project['id'] . ':' . $forecast);
    }

    /** Baseline baru (per part atau seluruh project). */
    public function createBaseline(int $projectId, ?int $partId, string $reason, ?User $actor = null): int
    {
        return Db::transaction(function () use ($projectId, $partId, $reason, $actor): int {
            $version = (int) Db::value('SELECT COALESCE(MAX(version_no), 0) FROM schedule_baselines WHERE project_id = ? AND part_id <=> ?', [$projectId, $partId]) + 1;
            Db::execute('UPDATE schedule_baselines SET is_active = 0 WHERE project_id = ? AND part_id <=> ?', [$projectId, $partId]);
            $id = Db::insert('schedule_baselines', [
                'project_id' => $projectId,
                'part_id' => $partId,
                'version_no' => $version,
                'reason' => mb_substr($reason, 0, 500),
                'is_active' => 1,
                'target_finish' => Db::value('SELECT target_finish FROM projects WHERE id = ?', [$projectId]),
                'created_by' => $actor?->id,
                'created_at' => Clock::nowString(),
            ]);
            $rows = $partId === null
                ? Db::fetchAll('SELECT id, planned_start, planned_finish, duration, status FROM processes WHERE project_id = ?', [$projectId])
                : Db::fetchAll('SELECT id, planned_start, planned_finish, duration, status FROM processes WHERE part_id = ?', [$partId]);
            foreach ($rows as $r) {
                Db::insert('schedule_baseline_items', [
                    'baseline_id' => $id, 'process_id' => (int) $r['id'], 'planned_start' => $r['planned_start'],
                    'planned_finish' => $r['planned_finish'], 'duration' => (int) $r['duration'], 'status' => $r['status'],
                ]);
            }
            RevisionHistory::record('baseline', I18n::t('sched.rev.baseline', ['version' => $version], 'id'), ['reason' => $reason, 'baseline_id' => $id], null, $projectId, $partId, null, $actor?->id);
            AuditLogger::log('schedule.baseline', 'project', $projectId, null, ['baseline_id' => $id, 'part_id' => $partId, 'version' => $version], $reason, $projectId, $actor);
            return $id;
        });
    }

    /** NPD/Admin menetapkan baseline baru dengan alasan (PRD §6.5). */
    public function setBaseline(User $actor, int $projectId, ?int $partId, string $reason): int
    {
        Gate::authorize($actor, 'baseline.create');
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        return $this->createBaseline($projectId, $partId, $reason, $actor);
    }

    /**
     * Setujui Target Finish baru (PRD §6.4): alasan wajib, tercatat; TIDAK mengubah baseline.
     */
    public function changeTarget(User $actor, int $projectId, string $date, string $reason): void
    {
        Gate::authorize($actor, 'target.change');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new ValidationException(['target_finish' => I18n::t('validation.date')]);
        }
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        Db::transaction(function () use ($actor, $projectId, $date, $reason): void {
            $project = Db::fetch('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if (!$project) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $old = $project['target_finish'];
            if ($old === $date) {
                return;
            }
            Db::update('projects', ['target_finish' => $date], ['id' => $projectId]);
            Db::insert('schedule_changes', [
                'batch_id' => bin2hex(random_bytes(8)), 'project_id' => $projectId, 'change_type' => 'target_change',
                'old_finish' => $old, 'new_finish' => $date, 'reason' => mb_substr($reason, 0, 500), 'user_id' => $actor->id, 'created_at' => Clock::nowString(),
            ]);
            RevisionHistory::record('target_change', I18n::t('sched.rev.target', ['old' => $old ?? '–', 'new' => $date], 'id'), ['reason' => $reason], null, $projectId, null, null, $actor->id);
            AuditLogger::log('project.target_change', 'project', $projectId, ['target_finish' => $old], ['target_finish' => $date], $reason, $projectId, $actor);
        });
    }
}
