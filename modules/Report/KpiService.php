<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\User;
use App\Notification\OverdueService;
use App\Project\ProjectQuery;
use App\Scheduling\WorkingCalendar;

/**
 * KPI per PIC (PRD §10.3, FR-RPT-03/04) — hanya Admin & Management (izin `kpi.view`, diperiksa di sini).
 *  - Sampel = run proses yang SELESAI pada periode (per iterasi, OQ-15); Skipped/reset tidak dihitung;
 *    proses "request" (P1, pembuatan NPR) dikecualikan (OQ-29).
 *  - PIC = PIC yang tercatat saat proses selesai (process_runs.pic_user_id).
 *  - On-time = Actual Finish ≤ Planned Finish SAAT AKTIVASI (diperbarui hanya oleh Resume — masa Hold tidak merugikan).
 *  - Durasi aktual = hari kerja Actual Start..Finish dikurangi hari kerja Hold; rencana = durasi saat aktivasi.
 *  - Overdue = run yang menjadi overdue pada periode (overdue_since) ∪ yang sedang overdue saat ini.
 *  - Project arsip tidak dihitung (laporan bawaan).
 */
final class KpiService
{
    public const ROLES = ['admin', 'admin_sales', 'npd_staff', 'drafter', 'purchasing', 'production', 'quality'];

    public function __construct(private ?WorkingCalendar $cal = null)
    {
    }

    private function cal(): WorkingCalendar
    {
        return $this->cal ??= WorkingCalendar::fromDb();
    }

    /** @param array<string,mixed> $in @return array{role:?string,part_type:?string,customer_id:?int,process:?string,pic:?int} */
    public static function cleanFilters(array $in): array
    {
        $digit = static fn ($v) => isset($v) && ctype_digit((string) $v) && (int) $v > 0 ? (int) $v : null;
        $process = isset($in['process']) && preg_match('/^[A-Z][A-Z0-9]{0,9}$/', (string) $in['process']) ? (string) $in['process'] : null;
        return [
            'role' => in_array($in['role'] ?? null, self::ROLES, true) ? (string) $in['role'] : null,
            'part_type' => in_array($in['part_type'] ?? null, ['new_mold', 'subcont'], true) ? (string) $in['part_type'] : null,
            'customer_id' => $digit($in['customer_id'] ?? null),
            'process' => $process,
            'pic' => $digit($in['pic'] ?? null),
        ];
    }

    /** Pilihan tipe proses (kode + nama) untuk filter. @return list<array{code:string,name:string}> */
    public static function processOptions(): array
    {
        return Db::fetchAll(
            "SELECT s.code, MIN(s.name) AS name FROM workflow_steps s WHERE s.step_type <> 'request' GROUP BY s.code ORDER BY MIN(s.sort_order), s.code"
        );
    }

    /**
     * @param array{role:?string,part_type:?string,customer_id:?int,process:?string,pic:?int} $f
     * @return array{rows:list<array<string,mixed>>,totals:array<string,mixed>,trend:list<array<string,mixed>>,detail:?array<string,mixed>}
     */
    public function build(User $viewer, ReportPeriod $period, array $f, ?string $today = null): array
    {
        Gate::authorize($viewer, 'kpi.view');
        $today ??= Clock::todayString();
        $runs = $this->completedRuns($period->from, $period->to, $f);
        $overdue = $this->overdueRuns($period, $f, $today);

        $by = [];
        $add = static function (array &$by, int $uid, string $name, string $role): void {
            $by[$uid] ??= ['pic_id' => $uid, 'name' => $name, 'role' => $role, 'completed' => 0, 'on_time' => 0, 'rated' => 0,
                'actual_sum' => 0, 'planned_sum' => 0, 'overdue' => 0];
        };
        foreach ($runs as $r) {
            $add($by, (int) $r['pic_user_id'], (string) $r['pic_name'], (string) $r['role_code']);
            $u = &$by[(int) $r['pic_user_id']];
            $u['completed']++;
            if ($r['on_time'] !== null) {
                $u['rated']++;
                $u['on_time'] += $r['on_time'] ? 1 : 0;
            }
            $u['actual_sum'] += $r['actual_days'];
            $u['planned_sum'] += $r['planned_days'];
            unset($u);
        }
        foreach ($overdue as $o) {
            $add($by, (int) $o['pic_user_id'], (string) $o['pic_name'], (string) $o['role_code']);
            $by[(int) $o['pic_user_id']]['overdue']++;
        }
        $rows = array_map(fn ($u) => $this->metrics($u), array_values($by));
        usort($rows, static fn ($a, $b) => [$b['on_time_rate'] === null ? -1 : $b['on_time_rate'], $b['completed'], $a['name']]
            <=> [$a['on_time_rate'] === null ? -1 : $a['on_time_rate'], $a['completed'], $b['name']]);

        $tot = ['completed' => 0, 'on_time' => 0, 'rated' => 0, 'actual_sum' => 0, 'planned_sum' => 0, 'overdue' => count($overdue), 'name' => '', 'role' => '', 'pic_id' => 0];
        foreach ($by as $u) {
            foreach (['completed', 'on_time', 'rated', 'actual_sum', 'planned_sum'] as $k) {
                $tot[$k] += $u[$k];
            }
        }
        $detail = null;
        if ($f['pic'] !== null) {
            $name = Db::fetch('SELECT u.id, u.name, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$f['pic']]);
            if ($name) {
                $detail = [
                    'pic' => $name,
                    'metrics' => $this->metrics($by[$f['pic']] ?? ['pic_id' => $f['pic'], 'name' => $name['name'], 'role' => $name['role_code'], 'completed' => 0, 'on_time' => 0,
                        'rated' => 0, 'actual_sum' => 0, 'planned_sum' => 0, 'overdue' => 0]),
                    'runs' => array_values(array_filter($runs, static fn ($r) => (int) $r['pic_user_id'] === $f['pic'])),
                    'overdue' => array_values(array_filter($overdue, static fn ($o) => (int) $o['pic_user_id'] === $f['pic'])),
                ];
            }
        }
        return ['rows' => $rows, 'totals' => $this->metrics($tot), 'trend' => $this->trend($period, $f), 'detail' => $detail];
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    private function metrics(array $u): array
    {
        $avgA = $u['completed'] > 0 ? round($u['actual_sum'] / $u['completed'], 1) : null;
        $avgP = $u['completed'] > 0 ? round($u['planned_sum'] / $u['completed'], 1) : null;
        return [
            'pic_id' => $u['pic_id'], 'name' => $u['name'], 'role' => $u['role'],
            'completed' => $u['completed'], 'on_time' => $u['on_time'],
            'on_time_rate' => $u['rated'] > 0 ? round(100 * $u['on_time'] / $u['rated'], 1) : null,
            'avg_actual' => $avgA, 'avg_planned' => $avgP,
            'avg_diff' => $avgA !== null ? round($avgA - $avgP, 1) : null,
            'ratio' => $avgA !== null && $avgP > 0 ? round(100 * $avgA / $avgP, 1) : null,
            'overdue' => $u['overdue'],
        ];
    }

    /**
     * Run selesai pada rentang (sampel KPI) dengan durasi aktual/rencana & status tepat waktu.
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    public function completedRuns(string $from, string $to, array $f): array
    {
        [$w, $p] = $this->where($f, 'r.pic_user_id');
        $rows = Db::fetchAll(
            "SELECT r.id AS run_id, r.process_id, r.iteration, r.activated_at, r.planned_finish_at_activation, r.planned_start_at_activation, r.planned_duration_at_activation,
                    r.actual_start, r.actual_finish, r.hold_working_days, r.pic_user_id, r.outcome, r.overdue_since,
                    pr.code, pr.name, pr.name_en, pr.part_id, pj.id AS project_id, pj.code AS project_code, pj.name AS project_name, pp.name AS part_name, pp.part_type,
                    u.name AS pic_name, ro.code AS role_code
             FROM process_runs r JOIN processes pr ON pr.id = r.process_id JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id
             JOIN users u ON u.id = r.pic_user_id JOIN roles ro ON ro.id = u.role_id
             WHERE r.status = 'completed' AND r.actual_finish BETWEEN ? AND ? AND $w
             ORDER BY r.actual_finish DESC, r.id DESC",
            array_merge([$from, $to], $p)
        );
        foreach ($rows as &$r) {
            $start = (string) ($r['actual_start'] ?? substr((string) $r['activated_at'], 0, 10));
            $r['actual_days'] = max(1, $this->cal()->countWorkingDays($start, (string) $r['actual_finish']) - (int) $r['hold_working_days']);
            $r['planned_days'] = (int) ($r['planned_duration_at_activation']
                ?? ($r['planned_start_at_activation'] && $r['planned_finish_at_activation']
                    ? $this->cal()->countWorkingDays((string) $r['planned_start_at_activation'], (string) $r['planned_finish_at_activation']) : 1));
            $r['on_time'] = $r['planned_finish_at_activation'] !== null ? $r['actual_finish'] <= $r['planned_finish_at_activation'] : null;
            $r['label'] = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['code'] . ' ' . ProjectQuery::processName($r);
        }
        unset($r);
        return $rows;
    }

    /**
     * Run overdue: menjadi overdue pada periode ∪ sedang overdue (dedupe per run).
     * PIC run terbuka = PIC proses saat ini; run tertutup = PIC saat selesai.
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    private function overdueRuns(ReportPeriod $period, array $f, string $today): array
    {
        [$w, $p] = $this->where($f, "CASE WHEN r.status = 'open' THEN pr.pic_user_id ELSE r.pic_user_id END");
        $sel = "SELECT r.id AS run_id, r.process_id, r.iteration, r.status AS run_status, r.overdue_since, r.planned_finish_at_activation, r.actual_finish,
                       pr.code, pr.name, pr.name_en, pr.planned_finish, pj.id AS project_id, pj.code AS project_code, pp.name AS part_name,
                       u.id AS pic_user_id, u.name AS pic_name, ro.code AS role_code
                FROM process_runs r JOIN processes pr ON pr.id = r.process_id JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id
                JOIN users u ON u.id = (CASE WHEN r.status = 'open' THEN pr.pic_user_id ELSE r.pic_user_id END) JOIN roles ro ON ro.id = u.role_id";
        $out = [];
        foreach (Db::fetchAll("$sel WHERE r.overdue_since BETWEEN ? AND ? AND r.status IN ('open', 'completed') AND $w", array_merge([$period->from, $period->to], $p)) as $r) {
            $r['current'] = false;
            $out[(int) $r['run_id']] = $r;
        }
        $now = (new OverdueService($this->cal()))->overdueProcesses([], $today);
        $days = [];
        foreach ($now as $o) {
            $days[(int) $o['id']] = (int) $o['overdue_days'];
        }
        if ($days) {
            $ids = array_keys($days);
            foreach (Db::fetchAll("$sel WHERE r.status = 'open' AND r.process_id IN " . Db::in($ids) . " AND $w", array_merge($ids, $p)) as $r) {
                $r['current'] = true;
                $r['overdue_days'] = $days[(int) $r['process_id']] ?? 0;
                $out[(int) $r['run_id']] = array_merge($out[(int) $r['run_id']] ?? [], $r);
            }
        }
        foreach ($out as &$r) {
            $r['label'] = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['code'] . ' ' . ProjectQuery::processName($r);
        }
        unset($r);
        return array_values($out);
    }

    /** Tren bulanan on-time rate (6 bulan s/d akhir periode). @param array<string,mixed> $f @return list<array<string,mixed>> */
    private function trend(ReportPeriod $period, array $f): array
    {
        $months = $period->trendMonths(6);
        $from = $months[0] . '-01';
        $to = (new \DateTimeImmutable(end($months) . '-01'))->format('Y-m-t');
        $runs = $this->completedRuns($from, $to, $f);
        $out = [];
        foreach ($months as $m) {
            $in = array_filter($runs, static fn ($r) => str_starts_with((string) $r['actual_finish'], $m) && $r['on_time'] !== null
                && ($f['pic'] === null || (int) $r['pic_user_id'] === $f['pic']));
            $on = count(array_filter($in, static fn ($r) => $r['on_time']));
            $out[] = ['month' => $m, 'completed' => count($in), 'on_time' => $on, 'rate' => $in ? round(100 * $on / count($in), 1) : null];
        }
        return $out;
    }

    /** @param array<string,mixed> $f @return array{0:string,1:list<mixed>} */
    private function where(array $f, string $picExpr): array
    {
        $w = ['pj.is_archived = 0', "pr.step_type <> 'request'"];
        $p = [];
        if ($f['role'] !== null) {
            $w[] = "EXISTS (SELECT 1 FROM users fu JOIN roles fr ON fr.id = fu.role_id WHERE fu.id = $picExpr AND fr.code = ?)";
            $p[] = $f['role'];
        }
        if ($f['part_type'] !== null) {
            $w[] = 'pp.part_type = ?';
            $p[] = $f['part_type'];
        }
        if ($f['customer_id'] !== null) {
            $w[] = 'pj.customer_id = ?';
            $p[] = $f['customer_id'];
        }
        if ($f['process'] !== null) {
            $w[] = 'pr.code = ?';
            $p[] = $f['process'];
        }
        return [implode(' AND ', $w), $p];
    }
}
