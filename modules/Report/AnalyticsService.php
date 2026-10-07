<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Db;
use App\Project\ProjectQuery;
use App\Scheduling\WorkingCalendar;

/**
 * Analytics (PRD §10.2): statistik proses (rata-rata durasi aktual vs rencana, on-time) dan loop
 * (Artwork Revisions, T0 Loops, Trial Rejection Loops) pada periode; filter jenis project & customer.
 * Sampel = run selesai (per iterasi), durasi aktual tanpa hari Hold — sama dengan KPI per PIC.
 */
final class AnalyticsService
{
    /** Loop bernama PRD §10.2: kode proses pemutus + keputusan yang memicu loop. */
    public const LOOPS = [
        'artwork_revisions' => ['S3', 'not_approved'],
        't0_loops' => ['N8', 't0_not_ok'],
        'trial_rejections' => ['S7', 'not_approved'],
    ];

    public function __construct(private ?WorkingCalendar $cal = null)
    {
    }

    /** @param array<string,mixed> $in @return array{role:null,part_type:?string,customer_id:?int,process:null,pic:null} */
    public static function cleanFilters(array $in): array
    {
        $f = KpiService::cleanFilters($in);
        return ['role' => null, 'part_type' => $f['part_type'], 'customer_id' => $f['customer_id'], 'process' => null, 'pic' => null];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    public function build(ReportPeriod $period, array $f): array
    {
        $runs = (new KpiService($this->cal))->completedRuns($period->from, $period->to, $f);
        $steps = [];
        foreach (Db::fetchAll('SELECT code, MIN(sort_order) AS s FROM workflow_steps GROUP BY code') as $s) {
            $steps[(string) $s['code']] = (int) $s['s'];
        }
        $by = [];
        foreach ($runs as $r) {
            $k = (string) $r['code'];
            $by[$k] ??= ['code' => $k, 'name' => ProjectQuery::processName($r), 'part_type' => $r['part_type'], 'count' => 0, 'on_time' => 0, 'rated' => 0,
                'actual_sum' => 0, 'planned_sum' => 0, 'rejections' => 0, 'max_iteration' => 0];
            $g = &$by[$k];
            $g['count']++;
            $g['actual_sum'] += $r['actual_days'];
            $g['planned_sum'] += $r['planned_days'];
            if ($r['on_time'] !== null) {
                $g['rated']++;
                $g['on_time'] += $r['on_time'] ? 1 : 0;
            }
            if (in_array((string) $r['outcome'], ['not_approved', 't0_not_ok', 'ng', 'fail'], true)) {
                $g['rejections']++;
            }
            $g['max_iteration'] = max($g['max_iteration'], (int) $r['iteration']);
            unset($g);
        }
        $stats = array_map(static fn ($g) => [
            'code' => $g['code'], 'name' => $g['name'], 'part_type' => $g['part_type'], 'count' => $g['count'],
            'avg_actual' => round($g['actual_sum'] / $g['count'], 1), 'avg_planned' => round($g['planned_sum'] / $g['count'], 1),
            'avg_diff' => round(($g['actual_sum'] - $g['planned_sum']) / $g['count'], 1),
            'on_time_rate' => $g['rated'] > 0 ? round(100 * $g['on_time'] / $g['rated'], 1) : null,
            'rejections' => $g['rejections'], 'max_iteration' => $g['max_iteration'],
        ], array_values($by));
        usort($stats, static fn ($a, $b) => [$a['part_type'] ?? '', $steps[$a['code']] ?? 999, $a['code']] <=> [$b['part_type'] ?? '', $steps[$b['code']] ?? 999, $b['code']]);

        $loops = [];
        foreach (self::LOOPS as $key => [$code, $outcome]) {
            $hits = array_values(array_filter($runs, static fn ($r) => $r['code'] === $code && $r['outcome'] === $outcome));
            $loops[$key] = ['count' => count($hits), 'parts' => count(array_unique(array_map(static fn ($r) => (int) $r['part_id'], $hits))), 'items' => $hits];
        }
        $n = count($runs);
        $actual = array_sum(array_column($runs, 'actual_days'));
        $planned = array_sum(array_column($runs, 'planned_days'));
        $rated = array_filter($runs, static fn ($r) => $r['on_time'] !== null);
        return [
            'stats' => $stats,
            'loops' => $loops,
            'totals' => [
                'count' => $n,
                'avg_actual' => $n ? round($actual / $n, 1) : null,
                'avg_planned' => $n ? round($planned / $n, 1) : null,
                'on_time_rate' => $rated ? round(100 * count(array_filter($rated, static fn ($r) => $r['on_time'])) / count($rated), 1) : null,
            ],
        ];
    }
}
