<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Scheduling\CycleException;
use App\Scheduling\Scheduler;
use App\Scheduling\WorkingCalendar;
use Tests\Support\TestCase;

/**
 * FR-SCH-01..05, FR-SCH-10, SCH-11..13, WF-14: mesin penjadwalan murni.
 * Termasuk contoh perhitungan PRD §6.4 (NPD Feedback selesai Rabu 30-09-2026).
 */
final class SchedulerTest extends TestCase
{
    private WorkingCalendar $cal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cal = new WorkingCalendar();
    }

    /** @param array<string,mixed> $o */
    private static function node(int $id, ?int $part, string $status, int $duration, array $o = []): array
    {
        return array_merge([
            'id' => $id, 'part_id' => $part, 'status' => $status, 'activation' => 'auto', 'duration' => $duration,
            'manual_start' => null, 'manual_finish' => null, 'planned_start' => null, 'planned_finish' => null,
            'actual_start' => null, 'actual_finish' => null, 'loop_after_id' => null, 'excluded' => false,
        ], $o);
    }

    private static function dep(int $p, int $q, string $t = 'FS', int $lag = 0): array
    {
        return ['process_id' => $p, 'predecessor_id' => $q, 'type' => $t, 'lag' => $lag];
    }

    /**
     * Contoh PRD §6.4: P2 (NPD Feedback) selesai 30-09; MB (1) & 3D (2) masing-masing 3 hk FS P2;
     * 2D (3) 3 hk FS MB & 3D; Validasi (4) 2 hk FS 2D.
     * @param array<int,array<string,mixed>> $override
     */
    private function prdExample(array $override = [], string $today = '2026-09-30', string $mode = 'plan', bool $pull = true): array
    {
        $nodes = [
            100 => self::node(100, null, 'completed', 3, ['actual_start' => '2026-09-28', 'actual_finish' => '2026-09-30']),
            1 => self::node(1, 10, 'not_started', 3),
            2 => self::node(2, 10, 'not_started', 3),
            3 => self::node(3, 10, 'not_started', 3),
            4 => self::node(4, 10, 'not_started', 2),
        ];
        foreach ($override as $id => $o) {
            $nodes[$id] = array_merge($nodes[$id], $o);
        }
        $deps = [self::dep(1, 100), self::dep(2, 100), self::dep(3, 1), self::dep(3, 2), self::dep(4, 3)];
        return (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-09-30'], '2026-09-28', $today, $pull, $mode))->run();
    }

    public function testPrdInitialPlan(): void
    {
        $r = $this->prdExample()['processes'];
        $this->assertSame(['2026-10-01', '2026-10-05'], [$r[1]['planned_start'], $r[1]['planned_finish']], 'Develop MB 1–5 Okt');
        $this->assertSame(['2026-10-01', '2026-10-05'], [$r[2]['planned_start'], $r[2]['planned_finish']], '3D Prototype 1–5 Okt (paralel)');
        $this->assertSame(['2026-10-06', '2026-10-08'], [$r[3]['planned_start'], $r[3]['planned_finish']], '2D Drawing 6–8 Okt (join)');
        $this->assertSame(['2026-10-09', '2026-10-12'], [$r[4]['planned_start'], $r[4]['planned_finish']]);
    }

    public function testUat07DelayedFinishShiftsDependentsOnly(): void
    {
        // Develop MB selesai aktual 7 Okt (terlambat 2 hk); 3D selesai 5 Okt sesuai rencana
        $r = $this->prdExample([
            1 => ['status' => 'completed', 'actual_start' => '2026-10-01', 'actual_finish' => '2026-10-07', 'planned_start' => '2026-10-01', 'planned_finish' => '2026-10-05'],
            2 => ['status' => 'completed', 'actual_start' => '2026-10-01', 'actual_finish' => '2026-10-05', 'planned_start' => '2026-10-01', 'planned_finish' => '2026-10-05'],
            3 => ['planned_start' => '2026-10-06', 'planned_finish' => '2026-10-08'],
            4 => ['planned_start' => '2026-10-09', 'planned_finish' => '2026-10-12'],
        ], '2026-10-07', 'event')['processes'];
        $this->assertSame(['2026-10-01', '2026-10-07'], [$r[1]['planned_start'], $r[1]['planned_finish']], 'aktual dipakai');
        $this->assertSame(['2026-10-01', '2026-10-05'], [$r[2]['planned_start'], $r[2]['planned_finish']], '3D tidak bergeser');
        $this->assertSame(['2026-10-08', '2026-10-12'], [$r[3]['planned_start'], $r[3]['planned_finish']], '2D 8–12 Okt (+2 hk)');
        $this->assertSame(['2026-10-13', '2026-10-14'], [$r[4]['planned_start'], $r[4]['planned_finish']], 'berantai +2 hk');
        $this->assertSame(2, $this->cal->deviation('2026-10-06', $r[3]['planned_start']), 'pergeseran +2 hari kerja');
    }

    public function testRunningOverdueProcessDoesNotShiftPlanButForecastMoves(): void
    {
        // 7 Okt: Develop MB masih berjalan (planned finish 5 Okt) → Overdue; jadwal turunan TIDAK digeser,
        // tetapi forecast menghitung "langsung"
        $r = $this->prdExample([
            1 => ['status' => 'current', 'actual_start' => '2026-10-01', 'planned_start' => '2026-10-01', 'planned_finish' => '2026-10-05'],
            2 => ['status' => 'completed', 'actual_start' => '2026-10-01', 'actual_finish' => '2026-10-05'],
        ], '2026-10-07')['processes'];
        $this->assertSame('2026-10-05', $r[1]['planned_finish'], 'planned finish proses berjalan dipertahankan');
        $this->assertSame('2026-10-07', $r[1]['forecast_finish'], 'forecast = max(planned finish, hari ini)');
        $this->assertSame(['2026-10-06', '2026-10-08'], [$r[3]['planned_start'], $r[3]['planned_finish']], 'planned turunan tidak bergeser');
        $this->assertSame(['2026-10-08', '2026-10-12'], [$r[3]['forecast_start'], $r[3]['forecast_finish']], 'forecast turunan bergeser');
    }

    public function testProjectAndPartForecastFinish(): void
    {
        $res = $this->prdExample();
        $this->assertSame('2026-10-12', $res['parts'][10]);
        $this->assertSame('2026-10-12', $res['project']);
    }

    public function testEarlyFinishPullsForwardByDefaultButNotWhenDisabled(): void
    {
        $base = [
            1 => ['status' => 'completed', 'actual_start' => '2026-10-01', 'actual_finish' => '2026-10-01'],
            2 => ['status' => 'completed', 'actual_start' => '2026-10-01', 'actual_finish' => '2026-10-01'],
            3 => ['planned_start' => '2026-10-06', 'planned_finish' => '2026-10-08'],
        ];
        $pull = $this->prdExample($base, '2026-10-01', 'event', true)['processes'];
        $this->assertSame('2026-10-02', $pull[3]['planned_start'], 'tarik maju aktif (bawaan)');
        $noPull = $this->prdExample($base, '2026-10-01', 'event', false)['processes'];
        $this->assertSame('2026-10-06', $noPull[3]['planned_start'], 'tarik maju nonaktif: tidak maju');
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string,3:?string}> */
    public static function manualPlanning(): array
    {
        // Tabel PRD §6.1 — proses 3 (2D, durasi bawaan 3 hk), predecessor mengizinkan mulai 6 Okt
        return [
            'kosong: ikut predecessor + durasi bawaan' => [[], '2026-10-06', '2026-10-08', null],
            'hanya durasi' => [['duration' => 5], '2026-10-06', '2026-10-12', null],
            'hanya planned start (tidak mulai sebelum)' => [['manual_start' => '2026-10-12'], '2026-10-12', '2026-10-14', null],
            'hanya planned finish (hitung mundur)' => [['manual_finish' => '2026-10-16'], '2026-10-14', '2026-10-16', null],
            'start & finish (durasi diturunkan)' => [['manual_start' => '2026-10-12', 'manual_finish' => '2026-10-20'], '2026-10-12', '2026-10-20', null],
            'start manual lebih awal dari dependency → peringatan' => [['manual_start' => '2026-10-02'], '2026-10-06', '2026-10-08', 'manual_before_dependency'],
            'start & finish, dependency menggeser start → durasi dipertahankan' => [['manual_start' => '2026-10-01', 'manual_finish' => '2026-10-02'], '2026-10-06', '2026-10-07', 'manual_before_dependency'],
            'start manual di akhir pekan → hari kerja berikutnya' => [['manual_start' => '2026-10-10'], '2026-10-12', '2026-10-14', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manualPlanning')]
    public function testManualPlanningTable(array $o, string $start, string $finish, ?string $warning): void
    {
        $r = $this->prdExample([3 => $o])['processes'];
        $this->assertSame($start, $r[3]['planned_start']);
        $this->assertSame($finish, $r[3]['planned_finish']);
        $this->assertSame($warning, $r[3]['warning']);
        if ($warning) {
            $this->assertContains($r[3]['blocking_id'], [1, 2], 'menyebut predecessor penghalang');
        }
    }

    public function testUat08ManualStartEarlierThanDependencyUsesDependencyDate(): void
    {
        $r = $this->prdExample([3 => ['manual_start' => '2026-10-01']])['processes'];
        $this->assertSame('2026-10-06', $r[3]['planned_start']);
        $this->assertSame('manual_before_dependency', $r[3]['warning']);
    }

    public function testPlannedDatesAlwaysWorkingDays(): void
    {
        $r = $this->prdExample([3 => ['manual_start' => '2026-10-04', 'duration' => 7]])['processes'];
        foreach ($r as $p) {
            foreach (['planned_start', 'planned_finish'] as $k) {
                if ($p[$k] !== null) {
                    $this->assertTrue($this->cal->isWorkingDay($p[$k]), $k . ' ' . $p[$k]);
                }
            }
        }
    }

    public function testStartToStartWithLag(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 5), 2 => self::node(2, 10, 'not_started', 3)];
        $r = (new Scheduler($this->cal, $nodes, [self::dep(2, 1, 'SS', 2)], [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-01', $r[1]['planned_start']);
        $this->assertSame('2026-10-05', $r[2]['planned_start'], 'SS +2 hk dari mulai predecessor');
    }

    public function testFinishToFinishExtendsDuration(): void
    {
        // B (2 hk) tidak boleh selesai sebelum A (5 hk) selesai
        $nodes = [1 => self::node(1, 10, 'not_started', 5), 2 => self::node(2, 10, 'not_started', 2)];
        $r = (new Scheduler($this->cal, $nodes, [self::dep(2, 1, 'FF')], [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-07', $r[1]['planned_finish']);
        $this->assertSame('2026-10-01', $r[2]['planned_start']);
        $this->assertSame('2026-10-07', $r[2]['planned_finish'], 'durasi diperpanjang mengikuti FF');
        $this->assertSame(5, $r[2]['duration']);
    }

    public function testPositiveAndNegativeLag(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 3), 2 => self::node(2, 10, 'not_started', 2), 3 => self::node(3, 10, 'not_started', 2)];
        $deps = [self::dep(2, 1, 'FS', 2), self::dep(3, 1, 'FS', -1)];
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-05', $r[1]['planned_finish']);
        $this->assertSame('2026-10-08', $r[2]['planned_start'], 'FS +2: jeda 2 hari kerja');
        $this->assertSame('2026-10-05', $r[3]['planned_start'], 'FS −1: mulai 1 hari sebelum predecessor selesai');
    }

    public function testNegativeLagNeverBeforePartOrPredecessorStart(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 2), 2 => self::node(2, 10, 'not_started', 2)];
        $r = (new Scheduler($this->cal, $nodes, [self::dep(2, 1, 'FS', -10)], [10 => '2026-10-05'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-05', $r[2]['planned_start']);
        $ss = (new Scheduler($this->cal, $nodes, [self::dep(2, 1, 'SS', -5)], [10 => '2026-10-05'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-05', $ss[2]['planned_start']);
    }

    public function testParallelHasNoConstraint(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 10), 2 => self::node(2, 10, 'not_started', 2)];
        $r = (new Scheduler($this->cal, $nodes, [self::dep(2, 1, 'PARALLEL')], [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-01', $r[2]['planned_start']);
    }

    public function testSkippedProcessHasZeroDurationAndPassesDatesThrough(): void
    {
        // MB (1) & approval MB (5) dilewati; 2D (3) hanya menunggu 3D (2)
        $nodes = [
            100 => self::node(100, null, 'completed', 3, ['actual_start' => '2026-09-28', 'actual_finish' => '2026-09-30']),
            1 => self::node(1, 10, 'skipped', 7),
            5 => self::node(5, 10, 'skipped', 4),
            2 => self::node(2, 10, 'not_started', 6),
            3 => self::node(3, 10, 'not_started', 4),
        ];
        $deps = [self::dep(1, 100), self::dep(5, 1), self::dep(2, 100), self::dep(3, 5), self::dep(3, 2)];
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-09-30'], '2026-09-28', '2026-09-30'))->run()['processes'];
        $this->assertNull($r[1]['planned_start']);
        $this->assertNull($r[5]['planned_finish']);
        $this->assertSame('2026-10-08', $r[2]['planned_finish']);
        $this->assertSame('2026-10-09', $r[3]['planned_start'], 'hanya ditentukan 3D');
        // bila 3D juga dilewati, 2D mulai sejak part mulai (hari kerja setelah NPD Feedback)
        $nodes[2]['status'] = 'skipped';
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-09-30'], '2026-09-28', '2026-09-30'))->run()['processes'];
        $this->assertSame('2026-10-01', $r[3]['planned_start']);
    }

    public function testLoopOnlyProcessIsDormantUntilActivated(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 3), 9 => self::node(9, 10, 'not_started', 10, ['activation' => 'loop_only']), 2 => self::node(2, 10, 'not_started', 2)];
        $deps = [self::dep(9, 1), self::dep(2, 1)];
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run();
        $this->assertNull($r['processes'][9]['planned_start'], 'Mold Correction tidak dijadwalkan sebelum T0 Not OK');
        $this->assertSame('2026-10-07', $r['project'], 'tidak menambah perkiraan selesai');
    }

    public function testLoopAfterWaitsForCorrectionProcess(): void
    {
        // Mold Machining (7) dibuka kembali, menunggu Mold Correction (9) yang sedang berjalan
        $nodes = [
            6 => self::node(6, 10, 'completed', 4, ['actual_start' => '2026-09-01', 'actual_finish' => '2026-09-04']),
            9 => self::node(9, 10, 'current', 10, ['activation' => 'loop_only', 'actual_start' => '2026-10-01', 'planned_start' => '2026-10-01', 'planned_finish' => '2026-10-14']),
            7 => self::node(7, 10, 'not_started', 30, ['loop_after_id' => 9]),
        ];
        $r = (new Scheduler($this->cal, $nodes, [self::dep(7, 6)], [10 => '2026-08-03'], '2026-08-03', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-15', $r[7]['planned_start']);
    }

    public function testExcludedPartsAndNotStartedPartsAreNotScheduled(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 3), 2 => self::node(2, 20, 'not_started', 3), 3 => self::node(3, 30, 'not_started', 3, ['excluded' => true])];
        $res = (new Scheduler($this->cal, $nodes, [], [10 => '2026-10-01', 20 => null], '2026-10-01', '2026-10-01'))->run();
        $this->assertNotNull($res['processes'][1]['planned_start']);
        $this->assertNull($res['processes'][2]['planned_start'], 'part belum diterima NPD');
        $this->assertNull($res['processes'][3]['planned_start'], 'part dibatalkan');
        $this->assertSame(['10' => '2026-10-05'], array_map('strval', array_combine(array_map('strval', array_keys($res['parts'])), $res['parts'])));
    }

    public function testCycleDetected(): void
    {
        $nodes = [1 => self::node(1, 10, 'not_started', 1), 2 => self::node(2, 10, 'not_started', 1), 3 => self::node(3, 10, 'not_started', 1)];
        try {
            (new Scheduler($this->cal, $nodes, [self::dep(2, 1), self::dep(3, 2), self::dep(1, 3)], [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run();
            $this->fail('siklus harus terdeteksi');
        } catch (CycleException $e) {
            $this->assertEqualsCanonicalizing([1, 2, 3], $e->processIds());
        }
    }

    public function testHolidayShiftsSchedule(): void
    {
        $cal = new WorkingCalendar([], [['date' => '2026-10-05', 'recurring' => false]]);
        $nodes = [1 => self::node(1, 10, 'not_started', 3)];
        $r = (new Scheduler($cal, $nodes, [], [10 => '2026-10-01'], '2026-10-01', '2026-10-01'))->run()['processes'];
        $this->assertSame('2026-10-06', $r[1]['planned_finish'], 'libur Senin 5 Okt dilewati');
    }

    public function testCriticalPath(): void
    {
        $s = new Scheduler($this->cal, [
            1 => self::node(1, 10, 'not_started', 3), 2 => self::node(2, 10, 'not_started', 8), 3 => self::node(3, 10, 'not_started', 2),
        ], [self::dep(3, 1), self::dep(3, 2)], [10 => '2026-10-01'], '2026-10-01', '2026-10-01');
        $this->assertSame([2, 3], $s->criticalPath($s->run()['processes']));
    }

    public function testPerformanceTenPartsTwentyProcesses(): void
    {
        $nodes = [0 => self::node(0, null, 'completed', 1, ['actual_start' => '2026-10-01', 'actual_finish' => '2026-10-01'])];
        $deps = [];
        $id = 1;
        $parts = [];
        for ($p = 1; $p <= 10; $p++) {
            $parts[$p] = '2026-10-01';
            $prev = 0;
            for ($i = 0; $i < 20; $i++) {
                $nodes[$id] = self::node($id, $p, 'not_started', ($i % 7) + 1);
                $deps[] = self::dep($id, $prev, $i % 5 === 3 ? 'SS' : 'FS', $i % 3 === 0 ? 1 : 0);
                $prev = $id++;
            }
        }
        $t = microtime(true);
        $res = (new Scheduler($this->cal, $nodes, $deps, $parts, '2026-10-01', '2026-10-01'))->run();
        $elapsed = microtime(true) - $t;
        $this->assertCount(201, $res['processes']);
        $this->assertLessThan(1.0, $elapsed, 'hitung ulang ≤ 1 detik (PRD §13.3)');
    }

    /** Edge pemicu ke proses loop_only (mis. N8 → N9) bukan syarat jadwal; tunggu loop tidak membentuk siklus. */
    public function testLoopOnlyTriggerEdgeDoesNotCreateCycle(): void
    {
        // 7 = Mold Machining (menunggu 9), 8 = T0 (FS 7), 9 = Mold Correction (loop_only, "FS" 8) aktif sejak 05-10
        $nodes = [
            7 => self::node(7, 10, 'not_started', 30, ['loop_after_id' => 9]),
            8 => self::node(8, 10, 'not_started', 3),
            9 => self::node(9, 10, 'current', 10, ['activation' => 'loop_only', 'planned_start' => '2026-10-05', 'planned_finish' => '2026-10-16', 'actual_start' => '2026-10-05']),
        ];
        $deps = [self::dep(8, 7), self::dep(9, 8)];
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-09-01'], '2026-09-01', '2026-10-05'))->run()['processes'];
        $this->assertSame('2026-10-05', $r[9]['planned_start']);
        $this->assertSame('2026-10-19', $r[7]['planned_start'], 'Mold Machining dibuka kembali setelah Mold Correction');
        $this->assertSame($this->cal->addWorkingDays('2026-10-19', 30), $r[8]['planned_start']);
        // dormant (belum dipicu) → tidak dijadwalkan
        $nodes[9] = self::node(9, 10, 'not_started', 10, ['activation' => 'loop_only']);
        $nodes[7]['loop_after_id'] = null;
        $r = (new Scheduler($this->cal, $nodes, $deps, [10 => '2026-09-01'], '2026-09-01', '2026-10-05'))->run()['processes'];
        $this->assertNull($r[9]['planned_start']);
    }
}
