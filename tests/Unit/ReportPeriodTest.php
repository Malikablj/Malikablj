<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Report\ReportPeriod;
use PHPUnit\Framework\TestCase;

/** Periode laporan Mingguan/Bulanan/Rentang (PRD §10.2). */
final class ReportPeriodTest extends TestCase
{
    public function testDefaultIsCurrentWeekMondayToSunday(): void
    {
        $p = ReportPeriod::fromInput([], '2026-10-08'); // Kamis
        $this->assertSame(['week', '2026-10-05', '2026-10-11', '2026-W41'], [$p->type, $p->from, $p->to, $p->week]);
        $sun = ReportPeriod::fromInput([], '2026-10-11'); // Minggu tetap minggu yang sama
        $this->assertSame('2026-10-05', $sun->from);
    }

    public function testIsoWeekInput(): void
    {
        $p = ReportPeriod::fromInput(['period' => 'week', 'week' => '2026-W01'], '2026-10-08');
        $this->assertSame(['2025-12-29', '2026-01-04'], [$p->from, $p->to]);
        $this->assertSame(['period' => 'week', 'week' => '2026-W01'], $p->query());
        $this->assertSame('2026-10-05', ReportPeriod::fromInput(['period' => 'week', 'week' => '2026-W99'], '2026-10-08')->from, 'minggu tidak valid → minggu berjalan');
    }

    public function testMonth(): void
    {
        $p = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-02'], '2026-10-08');
        $this->assertSame(['month', '2026-02-01', '2026-02-28'], [$p->type, $p->from, $p->to]);
        $d = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-13'], '2026-10-08');
        $this->assertSame(['2026-10-01', '2026-10-31'], [$d->from, $d->to]);
    }

    public function testRangeSwapsAndCapsAndFallsBack(): void
    {
        $p = ReportPeriod::fromInput(['period' => 'range', 'from' => '2026-10-20', 'to' => '2026-10-01'], '2026-10-08');
        $this->assertSame(['range', '2026-10-01', '2026-10-20'], [$p->type, $p->from, $p->to]);
        $big = ReportPeriod::fromInput(['period' => 'range', 'from' => '2020-01-01', 'to' => '2026-12-31'], '2026-10-08');
        $this->assertSame('2022-01-01', $big->to, 'rentang dibatasi ±2 tahun');
        $bad = ReportPeriod::fromInput(['period' => 'range', 'from' => '2026-02-30', 'to' => 'x'], '2026-10-08');
        $this->assertSame('week', $bad->type);
    }

    public function testShiftAndTrend(): void
    {
        $w = ReportPeriod::fromInput(['period' => 'week', 'week' => '2026-W41'], '2026-10-08');
        $this->assertSame('2026-09-28', $w->shift(-1)->from);
        $this->assertSame('2026-10-18', $w->shift(1)->to);
        $m = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-01'], '2026-10-08');
        $this->assertSame(['2025-12-01', '2025-12-31'], [$m->shift(-1)->from, $m->shift(-1)->to]);
        $this->assertSame(['2026-02-01', '2026-02-28'], [$m->shift(1)->from, $m->shift(1)->to]);
        $r = ReportPeriod::fromInput(['period' => 'range', 'from' => '2026-10-01', 'to' => '2026-10-10'], '2026-10-08');
        $this->assertSame(['2026-10-11', '2026-10-20'], [$r->shift(1)->from, $r->shift(1)->to]);
        $this->assertSame(['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02'], $m->shift(1)->trendMonths(6));
    }
}
