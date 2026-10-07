<?php
declare(strict_types=1);

namespace App\Timeline;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Project\ProjectQuery;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;

/**
 * Data timeline dua level (PRD §6.6) untuk tabel, Gantt, dan export.
 * Level 1: baris proses level project + satu baris ringkasan per part.
 * Level 2: seluruh proses satu part (dependency, deviasi, baseline, jalur kritis).
 *
 * Batang Gantt: selesai = aktual; berjalan = mulai aktual → forecast (bagian lewat Planned Finish = overdue);
 * belum mulai = rencana; Tidak dijalankan = tanpa batang (baris bergaris).
 */
final class TimelineService
{
    public function __construct(
        private ProjectQuery $query = new ProjectQuery(),
        private ScheduleService $schedule = new ScheduleService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function project(int $projectId): array
    {
        $project = $this->query->find($projectId);
        $parts = $this->query->parts($projectId);
        $processes = $this->query->processes($projectId);
        $baseline = $this->baselineItems($projectId);
        $rows = [];
        $no = 0;
        foreach ($processes as $p) {
            if ($p['part_id'] === null) {
                $rows[] = $this->processRow($p, ++$no, $baseline, [], []);
            }
        }
        $byPart = [];
        foreach ($processes as $p) {
            if ($p['part_id'] !== null) {
                $byPart[(int) $p['part_id']][] = $p;
            }
        }
        foreach ($parts as $pt) {
            $rows[] = $this->partRow($pt, $byPart[(int) $pt['id']] ?? [], ++$no, $baseline);
        }
        return $this->wrap($project, $rows, null);
    }

    /** @return array<string,mixed> */
    public function part(int $partId): array
    {
        $part = Db::fetch('SELECT * FROM project_parts WHERE id = ?', [$partId]);
        if (!$part) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $projectId = (int) $part['project_id'];
        $project = $this->query->find($projectId);
        $processes = $this->query->processes($projectId, $partId, true);
        $baseline = $this->baselineItems($projectId);
        $deps = [];
        foreach (Db::fetchAll(
            'SELECT d.process_id, d.predecessor_id, d.dep_type, d.lag_days, p.code, p.part_id FROM process_dependencies d
             JOIN processes p ON p.id = d.predecessor_id JOIN processes s ON s.id = d.process_id WHERE s.part_id = ?',
            [$partId]
        ) as $d) {
            $deps[(int) $d['process_id']][] = $d;
        }
        $critical = $this->criticalInPart($projectId, $partId);
        $rows = [];
        $no = 0;
        foreach ($processes as $p) {
            $rows[] = $this->processRow($p, ++$no, $baseline, $deps[(int) $p['id']] ?? [], $critical);
        }
        $data = $this->wrap($project, $rows, $part);
        $data['part'] = $part;
        return $data;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function wrap(array $project, array $rows, ?array $part): array
    {
        $dates = [];
        foreach ($rows as $r) {
            foreach (['bar_start', 'bar_end', 'baseline_start', 'baseline_finish', 'planned_start', 'planned_finish'] as $k) {
                if (!empty($r[$k])) {
                    $dates[] = $r[$k];
                }
            }
        }
        $today = Clock::todayString();
        $dates[] = $today;
        if (!empty($project['target_finish'])) {
            $dates[] = $project['target_finish'];
        }
        [$from, $to] = self::range($dates);
        return [
            'project' => $project,
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'target' => $project['target_finish'],
            'holidays' => $this->holidays($from, $to),
            'level' => $part === null ? 1 : 2,
        ];
    }

    /**
     * Rentang tampilan: mulai Senin (agar arsiran akhir pekan rapi) dan diberi ruang.
     * @param list<string> $dates
     * @return array{0:string,1:string}
     */
    public static function range(array $dates): array
    {
        $dates = array_filter($dates);
        $min = $dates ? min($dates) : Clock::todayString();
        $max = $dates ? max($dates) : Clock::todayString();
        $start = new \DateTimeImmutable($min . ' 12:00:00');
        $start = $start->modify('-' . ((int) $start->format('N') - 1) . ' days')->modify('-7 days');
        $end = (new \DateTimeImmutable($max . ' 12:00:00'))->modify('+14 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** @return list<array{date:string,name:string}> */
    private function holidays(string $from, string $to): array
    {
        $cal = $this->query->calendar();
        $out = [];
        $names = [];
        foreach (Db::fetchAll('SELECT holiday_date, is_recurring, name FROM holidays') as $h) {
            $names[(int) $h['is_recurring'] === 1 ? substr((string) $h['holiday_date'], 5) : (string) $h['holiday_date']] = (string) $h['name'];
        }
        for ($d = $from; $d <= $to; $d = WorkingCalendar::shift($d, 1)) {
            if ($cal->isHoliday($d)) {
                $out[] = ['date' => $d, 'name' => $names[$d] ?? $names[substr($d, 5)] ?? ''];
            }
        }
        return $out;
    }

    /** Baseline aktif terbaru per proses. @return array<int,array{planned_start:?string,planned_finish:?string,version:int}> */
    private function baselineItems(int $projectId): array
    {
        $out = [];
        foreach (Db::fetchAll(
            'SELECT i.process_id, i.planned_start, i.planned_finish, b.version_no FROM schedule_baseline_items i
             JOIN schedule_baselines b ON b.id = i.baseline_id WHERE b.project_id = ? AND b.is_active = 1 ORDER BY b.created_at, b.id',
            [$projectId]
        ) as $r) {
            $out[(int) $r['process_id']] = ['planned_start' => $r['planned_start'], 'planned_finish' => $r['planned_finish'], 'version' => (int) $r['version_no']];
        }
        return $out;
    }

    /** @return array<int,bool> jalur kritis proses dalam part (rantai predecessor dengan selesai terakhir) */
    private function criticalInPart(int $projectId, int $partId): array
    {
        $graph = $this->schedule->loadGraph($projectId);
        try {
            $result = $this->schedule->compute($graph, 'plan')['processes'];
        } catch (\Throwable) {
            return [];
        }
        $preds = [];
        foreach ($graph['deps'] as $d) {
            $preds[$d['process_id']][] = $d['predecessor_id'];
        }
        $end = null;
        $endId = null;
        foreach ($result as $id => $r) {
            if (($graph['nodes'][$id]['part_id'] ?? null) === $partId && $r['forecast_finish'] !== null && ($end === null || $r['forecast_finish'] > $end)) {
                [$end, $endId] = [$r['forecast_finish'], $id];
            }
        }
        $path = [];
        for ($guard = 0; $endId !== null && $guard < 500; $guard++) {
            $path[$endId] = true;
            $next = null;
            $best = null;
            foreach ($preds[$endId] ?? [] as $q) {
                $r = $result[$q] ?? null;
                if ($r && ($graph['nodes'][$q]['part_id'] ?? null) === $partId && $r['forecast_finish'] !== null && ($best === null || $r['forecast_finish'] > $best)) {
                    [$best, $next] = [$r['forecast_finish'], $q];
                }
            }
            $endId = $next;
        }
        return $path;
    }

    /**
     * @param array<string,mixed> $p
     * @param array<int,array<string,mixed>> $baseline
     * @param list<array<string,mixed>> $deps
     * @param array<int,bool> $critical
     * @return array<string,mixed>
     */
    private function processRow(array $p, int $no, array $baseline, array $deps, array $critical): array
    {
        $status = (string) $p['status'];
        $dormant = $p['activation'] === 'loop_only' && $status === 'not_started';
        [$bs, $be] = match (true) {
            $status === 'completed' => [$p['actual_start'] ?? $p['planned_start'], $p['actual_finish'] ?? $p['planned_finish']],
            in_array($status, ProjectQuery::ACTIVE, true) => [$p['actual_start'] ?? $p['planned_start'], $p['forecast_finish'] ?? $p['planned_finish']],
            $status === 'skipped' || $dormant => [null, null],
            default => [$p['planned_start'], $p['planned_finish']],
        };
        if ($bs !== null && $be !== null && $be < $bs) {
            $be = $bs;
        }
        $remark = [];
        if ($status === 'skipped' && $p['skip_reason']) {
            $remark[] = I18n::t('status.skipped') . ': ' . $p['skip_reason'];
        }
        if ($p['outcome_comment'] && !in_array($status, ['not_started'], true)) {
            $remark[] = (string) $p['outcome_comment'];
        }
        if ($p['schedule_warning'] && I18n::has('sched.warning.' . $p['schedule_warning'])) {
            $remark[] = I18n::t('sched.warning.' . $p['schedule_warning']);
        }
        if ($dormant) {
            $remark[] = I18n::t('process.loop_only_hint');
        }
        if ((int) $p['iteration'] > 1) {
            $remark[] = I18n::t('process.iteration_n', ['n' => (int) $p['iteration']]);
        }
        $b = $baseline[(int) $p['id']] ?? null;
        return [
            'kind' => $p['part_id'] === null ? 'project' : 'process',
            'id' => (int) $p['id'],
            'no' => $no,
            'code' => (string) $p['code'],
            'name' => ProjectQuery::processName($p),
            'pic' => $p['pic_name'] ?? null,
            'pic_role' => (string) $p['pic_role_code'],
            'status' => $status,
            'duration' => $status === 'skipped' ? 0 : (int) $p['duration'],
            'planned_start' => $p['planned_start'],
            'planned_finish' => $p['planned_finish'],
            'forecast_finish' => $p['forecast_finish'],
            'actual_start' => $p['actual_start'],
            'actual_finish' => $p['actual_finish'],
            'deviation' => $p['deviation'],
            'overdue_days' => (int) $p['overdue_days'],
            'due_soon' => (bool) $p['due_soon'],
            'held' => (bool) $p['held'],
            'manual' => $p['manual_start'] !== null || $p['manual_finish'] !== null,
            'skipped' => $status === 'skipped',
            'dormant' => $dormant,
            'critical' => isset($critical[(int) $p['id']]),
            'bar_start' => $bs,
            'bar_end' => $be,
            'baseline_start' => $b['planned_start'] ?? null,
            'baseline_finish' => $b['planned_finish'] ?? null,
            'deps' => array_map(static fn ($d) => [
                'id' => (int) $d['predecessor_id'], 'code' => (string) $d['code'], 'type' => (string) $d['dep_type'],
                'lag' => (int) $d['lag_days'], 'external' => $d['part_id'] === null,
            ], $deps),
            'remark' => implode(' · ', $remark),
            'editable' => $status === 'not_started' && !$dormant,
            'lock_version' => (int) $p['lock_version'],
            'pic_user_id' => $p['pic_user_id'] !== null ? (int) $p['pic_user_id'] : null,
            'pic_role_id' => (int) $p['pic_role_id'],
            'manual_start' => $p['manual_start'],
            'manual_finish' => $p['manual_finish'],
        ];
    }

    /**
     * Baris ringkasan part (Level 1).
     * @param array<string,mixed> $pt
     * @param list<array<string,mixed>> $procs
     * @param array<int,array<string,mixed>> $baseline
     * @return array<string,mixed>
     */
    private function partRow(array $pt, array $procs, int $no, array $baseline): array
    {
        $starts = [];
        $ends = [];
        $planned = [];
        $bStart = [];
        $bEnd = [];
        $overdue = 0;
        $maxOverdue = 0;
        foreach ($procs as $p) {
            if ($p['status'] === 'skipped' || ($p['activation'] === 'loop_only' && $p['status'] === 'not_started')) {
                continue;
            }
            $s = $p['actual_start'] ?? $p['planned_start'];
            $e = $p['status'] === 'completed' ? ($p['actual_finish'] ?? $p['planned_finish']) : ($p['forecast_finish'] ?? $p['planned_finish']);
            if ($s) {
                $starts[] = $s;
            }
            if ($e) {
                $ends[] = $e;
            }
            if ($p['planned_finish']) {
                $planned[] = $p['planned_finish'];
            }
            if ($p['overdue_days'] > 0) {
                $overdue++;
                $maxOverdue = max($maxOverdue, (int) $p['overdue_days']);
            }
            $b = $baseline[(int) $p['id']] ?? null;
            if ($b && $b['planned_start']) {
                $bStart[] = $b['planned_start'];
                $bEnd[] = $b['planned_finish'];
            }
        }
        $active = array_map(static fn ($a) => $a['code'] . ' ' . ProjectQuery::processName($a), $pt['active_processes']);
        $pics = array_values(array_unique(array_filter(array_map(static fn ($a) => $a['pic_name'] ?? null, $pt['active_processes']))));
        $total = (int) $pt['proc_total'];
        $remark = $pt['cancelled_at'] ? I18n::t('project.part_cancelled', ['reason' => (string) $pt['cancel_reason']]) : implode(', ', $active);
        return [
            'kind' => 'part',
            'id' => (int) $pt['id'],
            'no' => $no,
            'code' => '',
            'name' => (string) $pt['name'],
            'part_type' => (string) $pt['part_type'],
            'pic' => $pics ? implode(', ', $pics) : null,
            'status' => (string) $pt['status'],
            'progress' => $total > 0 ? (int) round(100 * (int) $pt['proc_done'] / $total) : 0,
            'done' => (int) $pt['proc_done'],
            'total' => $total,
            'duration' => null,
            'planned_start' => $starts ? min($starts) : null,
            'planned_finish' => $planned ? max($planned) : null,
            'forecast_finish' => $pt['forecast_finish'],
            'actual_start' => $pt['start_date'],
            'actual_finish' => $pt['completed_at'] ? substr((string) $pt['completed_at'], 0, 10) : null,
            'deviation' => null,
            'overdue_days' => $maxOverdue,
            'overdue_count' => $overdue,
            'due_soon' => false,
            'held' => (int) $pt['is_on_hold'] === 1,
            'manual' => false,
            'skipped' => false,
            'dormant' => false,
            'critical' => false,
            'bar_start' => $starts ? min($starts) : null,
            'bar_end' => $ends ? max($ends) : null,
            'baseline_start' => $bStart ? min($bStart) : null,
            'baseline_finish' => $bEnd ? max($bEnd) : null,
            'deps' => [],
            'remark' => $remark,
            'editable' => false,
            'cancelled' => $pt['cancelled_at'] !== null,
        ];
    }
}
