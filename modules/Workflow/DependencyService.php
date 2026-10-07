<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\ConflictException;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Project\RevisionHistory;
use App\Project\StatusService;
use App\Scheduling\CycleException;
use App\Scheduling\ScheduleService;

/**
 * Penyesuaian dependency per project oleh NPD/Admin (PRD §5.5, FR-WF-04/05):
 * validasi (tipe, lag, lintas part, siklus) → pratinjau jadwal → simpan + audit sebelum/sesudah.
 */
final class DependencyService
{
    public const TYPES = ['FS', 'SS', 'FF', 'PARALLEL'];
    public const LAG_MIN = -30;
    public const LAG_MAX = 90;

    public function __construct(
        private ScheduleService $schedule = new ScheduleService(),
        private StatusService $status = new StatusService(),
    ) {
    }

    /** @return array{predecessors:list<array<string,mixed>>,successors:list<array<string,mixed>>} */
    public function forProcess(int $processId): array
    {
        $pred = Db::fetchAll(
            'SELECT d.id, d.predecessor_id, d.dep_type, d.lag_days, d.source, p.code, p.name, p.name_en, p.status, p.part_id, pp.name AS part_name
             FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id LEFT JOIN project_parts pp ON pp.id = p.part_id
             WHERE d.process_id = ? ORDER BY p.part_id IS NOT NULL, p.sort_order, p.id',
            [$processId]
        );
        $succ = Db::fetchAll(
            'SELECT d.id, d.process_id, d.dep_type, d.lag_days, d.source, p.code, p.name, p.name_en, p.status, p.part_id, pp.name AS part_name
             FROM process_dependencies d JOIN processes p ON p.id = d.process_id LEFT JOIN project_parts pp ON pp.id = p.part_id
             WHERE d.predecessor_id = ? ORDER BY p.part_id IS NOT NULL, p.sort_order, p.id',
            [$processId]
        );
        return ['predecessors' => $pred, 'successors' => $succ];
    }

    /** Kandidat predecessor: proses lain di part yang sama + proses level project (atau semua, untuk proses level project). @return list<array<string,mixed>> */
    public function candidates(array $process): array
    {
        $rows = Db::fetchAll(
            'SELECT p.id, p.code, p.name, p.name_en, p.status, p.part_id, pp.name AS part_name
             FROM processes p LEFT JOIN project_parts pp ON pp.id = p.part_id
             WHERE p.project_id = ? AND p.id <> ? AND (pp.id IS NULL OR pp.cancelled_at IS NULL)
             ORDER BY p.part_id IS NOT NULL, pp.sort_order, p.sort_order, p.id',
            [(int) $process['project_id'], (int) $process['id']]
        );
        return array_values(array_filter($rows, fn ($r) => $this->scopeAllowed($process, $r)));
    }

    /**
     * Normalisasi & validasi daftar predecessor baru.
     * @param list<array<string,mixed>>|array<int|string,array<string,mixed>> $input [{predecessor_id, type, lag}]
     * @return list<array{predecessor_id:int,type:string,lag:int}>
     */
    public function clean(array $process, array $input): array
    {
        $out = [];
        $errors = [];
        $seen = [];
        $i = 0;
        foreach ($input as $row) {
            $i++;
            if (!is_array($row) || trim((string) ($row['predecessor_id'] ?? '')) === '') {
                continue; // baris kosong pada form
            }
            $key = 'deps.' . $i;
            $pid = (int) $row['predecessor_id'];
            $type = strtoupper(trim((string) ($row['type'] ?? 'FS')));
            $lagRaw = trim((string) ($row['lag'] ?? '0'));
            if ($pid === (int) $process['id']) {
                $errors[$key] = I18n::t('dep.self');
                continue;
            }
            $pred = Db::fetch('SELECT p.id, p.project_id, p.part_id, pp.cancelled_at FROM processes p LEFT JOIN project_parts pp ON pp.id = p.part_id WHERE p.id = ?', [$pid]);
            if (!$pred || (int) $pred['project_id'] !== (int) $process['project_id'] || $pred['cancelled_at'] !== null) {
                $errors[$key] = I18n::t('validation.invalid');
                continue;
            }
            if (!$this->scopeAllowed($process, $pred)) {
                $errors[$key] = I18n::t('dep.cross_part');
                continue;
            }
            if (!in_array($type, self::TYPES, true)) {
                $errors[$key] = I18n::t('dep.invalid_type');
                continue;
            }
            if (!preg_match('/^-?\d{1,3}$/', $lagRaw) || (int) $lagRaw < self::LAG_MIN || (int) $lagRaw > self::LAG_MAX) {
                $errors[$key] = I18n::t('dep.lag_invalid');
                continue;
            }
            if (isset($seen[$pid])) {
                $errors[$key] = I18n::t('dep.exists');
                continue;
            }
            $seen[$pid] = true;
            $out[] = ['predecessor_id' => $pid, 'type' => $type, 'lag' => $type === 'PARALLEL' ? 0 : (int) $lagRaw];
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /**
     * Pratinjau jadwal dengan dependency baru (tanpa menyimpan). Lingkaran → CycleException.
     * @param list<array<string,mixed>> $input
     * @return array<string,mixed>
     */
    public function preview(User $actor, int $processId, array $input): array
    {
        Gate::authorize($actor, 'dependency.edit');
        $process = $this->process($processId);
        $this->assertEditable($process);
        $deps = $this->clean($process, $input);
        $preview = $this->schedule->preview((int) $process['project_id'], static function (array &$g) use ($processId, $deps): void {
            $g['deps'] = array_values(array_filter($g['deps'], static fn ($d) => $d['process_id'] !== $processId));
            foreach ($deps as $d) {
                $g['deps'][] = ['process_id' => $processId, 'predecessor_id' => $d['predecessor_id'], 'type' => $d['type'], 'lag' => $d['lag']];
            }
        });
        $preview['deps'] = $deps;
        return $preview;
    }

    /**
     * Simpan dependency proses (mengganti seluruh daftar predecessor). Audit: sebelum/sesudah, alasan opsional.
     * @param list<array<string,mixed>> $input
     * @return array{changes:list<array<string,mixed>>,project_forecast:?string}
     */
    public function save(User $actor, int $processId, array $input, ?string $reason = null, ?int $lockVersion = null): array
    {
        Gate::authorize($actor, 'dependency.edit');
        return Db::transaction(function () use ($actor, $processId, $input, $reason, $lockVersion): array {
            $process = $this->process($processId, true);
            Db::fetch('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) $process['project_id']]);
            if ($lockVersion !== null && $lockVersion !== (int) $process['lock_version']) {
                throw new ConflictException(I18n::t('error.conflict'));
            }
            $this->assertEditable($process);
            $deps = $this->clean($process, $input);
            // validasi lingkaran pada graf hasil (pesan berisi proses yang terlibat)
            $this->preview($actor, $processId, $input);

            $before = $this->describe($processId);
            $old = [];
            foreach (Db::fetchAll('SELECT predecessor_id, dep_type, lag_days FROM process_dependencies WHERE process_id = ?', [$processId]) as $r) {
                $old[(int) $r['predecessor_id']] = $r;
            }
            $new = [];
            foreach ($deps as $d) {
                $new[$d['predecessor_id']] = $d;
            }
            foreach (array_diff_key($old, $new) as $pid => $_) {
                Db::execute('DELETE FROM process_dependencies WHERE process_id = ? AND predecessor_id = ?', [$processId, $pid]);
            }
            foreach ($new as $pid => $d) {
                $o = $old[$pid] ?? null;
                if ($o === null) {
                    Db::insert('process_dependencies', [
                        'process_id' => $processId, 'predecessor_id' => $pid, 'dep_type' => $d['type'], 'lag_days' => $d['lag'],
                        'source' => 'override', 'created_by' => $actor->id,
                    ]);
                } elseif ($o['dep_type'] !== $d['type'] || (int) $o['lag_days'] !== $d['lag']) {
                    Db::execute(
                        "UPDATE process_dependencies SET dep_type = ?, lag_days = ?, source = 'override' WHERE process_id = ? AND predecessor_id = ?",
                        [$d['type'], $d['lag'], $processId, $pid]
                    );
                }
            }
            $after = $this->describe($processId);
            if ($before === $after) {
                return ['changes' => [], 'project_forecast' => null];
            }
            Db::execute('UPDATE processes SET lock_version = lock_version + 1 WHERE id = ?', [$processId]);
            $projectId = (int) $process['project_id'];
            $partId = $process['part_id'] !== null ? (int) $process['part_id'] : null;
            AuditLogger::log('dependency.update', 'process', $processId, ['dependencies' => $before], ['dependencies' => $after], $reason, $projectId, $actor);
            RevisionHistory::record('dependency', I18n::t('dep.rev.changed', ['process' => $process['name']], 'id'),
                ['before' => $before, 'after' => $after, 'reason' => $reason], null, $projectId, $partId, $processId, $actor->id);
            $result = $this->schedule->recalculate($projectId, 'dependency_change', $processId, $reason, $actor, 'plan');
            (new WorkflowEngine($this->schedule, $this->status))->activateReady($projectId, $actor);
            $this->status->refresh($projectId);
            Db::update('projects', ['last_activity_at' => Clock::nowString()], ['id' => $projectId]);
            return ['changes' => $result['changes'], 'project_forecast' => $result['project_forecast']];
        });
    }

    /** Dependency boleh: sesama part, atau salah satunya proses level project (gate, finish, P1/P2). */
    private function scopeAllowed(array $process, array $pred): bool
    {
        return $process['part_id'] === null || $pred['part_id'] === null || (int) $process['part_id'] === (int) $pred['part_id'];
    }

    private function assertEditable(array $process): void
    {
        if (in_array($process['status'], ['completed', 'skipped'], true) || in_array($process['step_type'], ['request', 'feedback'], true)) {
            throw new BusinessRuleException(I18n::t('dep.started'));
        }
        if ($process['part_cancelled_at'] !== null) {
            throw new BusinessRuleException(I18n::t('dep.started'));
        }
    }

    /** @return array<string,mixed> */
    private function process(int $processId, bool $forUpdate = false): array
    {
        $p = Db::fetch(
            'SELECT pr.*, pp.cancelled_at AS part_cancelled_at FROM processes pr LEFT JOIN project_parts pp ON pp.id = pr.part_id WHERE pr.id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$processId]
        );
        if (!$p) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $p;
    }

    /** Ringkasan dependency untuk audit: ["N4 FS+0", ...]. @return list<string> */
    private function describe(int $processId): array
    {
        $list = array_map(
            static fn ($r) => ($r['part_name'] !== null ? $r['part_name'] . '/' : '') . $r['code'] . ' ' . $r['dep_type'] . ($r['dep_type'] === 'PARALLEL' ? '' : sprintf('%+d', (int) $r['lag_days'])),
            Db::fetchAll(
                'SELECT p.code, pp.name AS part_name, d.dep_type, d.lag_days FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id
                 LEFT JOIN project_parts pp ON pp.id = p.part_id WHERE d.process_id = ?',
                [$processId]
            )
        );
        sort($list);
        return $list;
    }
}
