<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Notification\OverdueService;
use App\Project\HoldService;
use App\Project\LifecycleService;
use App\Project\NextActionService;
use App\Project\ProjectQuery;
use App\Project\ProjectService;
use App\Report\AnalyticsService;
use App\Report\DashboardService;
use App\Report\KpiService;
use App\Report\ReportExports;
use App\Report\ReportPeriod;
use App\Report\WeeklyReport;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/**
 * Dashboard, Weekly Report, Analytics, KPI per PIC (PRD §10, FR-RPT-01..05, UAT-20) pada MySQL
 * dengan data yang dibentuk lewat service aplikasi (bukan angka palsu).
 * Skenario: feedback selesai Senin 5 Okt 2026; drafter = PIC part; proses pertama aktif Selasa 6 Okt.
 */
final class ReportsTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $admin;
    private User $drafter;
    private User $mgmt;
    private int $customer;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->admin = $this->makeUser('admin');
        $this->drafter = $this->makeUser('drafter');
        $this->mgmt = $this->makeUser('management');
        $this->customer = $this->makeCustomer();
        $this->engine = new WorkflowEngine();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    /** @param list<array{0:string,1:string}> $specs @return array{0:int,1:list<int>} */
    private function running(array $specs = [['body', 'new_mold'], ['cap', 'subcont']]): array
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, $specs, array_fill(0, count($specs), 'feasible'));
        $parts = array_map('intval', Db::column('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [$projectId]));
        foreach ($parts as $pt) {
            (new ProjectService())->assignPartPics($this->npd, $pt, ['drafter' => (string) $this->drafter->id]);
        }
        Clock::freeze('2026-10-06 06:00:00');
        $this->engine->activateReady($projectId);
        return [$projectId, $parts];
    }

    private function pid(int $projectId, ?int $partId, string $code): int
    {
        return (int) Db::value('SELECT id FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [$projectId, $partId, $code]);
    }

    private function completeOn(string $date, User $as, int $processId, array $input = []): void
    {
        Clock::freeze($date . ' 10:00:00');
        $this->engine->complete($as, $processId, $input);
    }

    /** @return array<string,mixed> */
    private function row(array $kpi, User $u): array
    {
        foreach ($kpi['rows'] as $r) {
            if ($r['pic_id'] === $u->id) {
                return $r;
            }
        }
        $this->fail('baris KPI untuk ' . $u->name . ' tidak ada');
    }

    // ------------------------------------------------------------------ KPI per PIC (§10.3, UAT-20)

    public function testKpiPerPicDefinitionsDrilldownAndTrend(): void
    {
        [$projectId, [$body, $cap]] = $this->running();
        $this->completeOn('2026-10-09', $this->drafter, $this->pid($projectId, $cap, 'S1'));   // rencana 6–12 Okt → tepat waktu, 4 hk
        Clock::freeze('2026-10-14 07:00:00');
        (new OverdueService())->scan('2026-10-14');                                          // N3 (rencana s/d 13 Okt) menjadi overdue
        $this->completeOn('2026-10-15', $this->drafter, $this->pid($projectId, $body, 'N3')); // terlambat, 8 hk vs 6
        Clock::freeze('2026-10-20 09:00:00');                                                 // N1 (NPD, rencana s/d 14 Okt) sedang overdue

        $period = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-10'], '2026-10-20');
        $svc = new KpiService();
        $kpi = $svc->build($this->admin, $period, KpiService::cleanFilters([]), '2026-10-20');

        $d = $this->row($kpi, $this->drafter);
        $this->assertSame(2, $d['completed']);
        $this->assertSame(1, $d['on_time']);
        $this->assertSame(50.0, $d['on_time_rate']);
        $this->assertSame(6.0, $d['avg_actual']);
        $this->assertSame(5.5, $d['avg_planned']);
        $this->assertSame(0.5, $d['avg_diff']);
        $this->assertSame(109.1, $d['ratio']);
        $this->assertSame(1, $d['overdue'], 'N3 menjadi overdue pada periode');

        $n = $this->row($kpi, $this->npd);
        $this->assertSame(1, $n['completed'], 'P2 NPD Feedback');
        $this->assertSame(100.0, $n['on_time_rate']);
        $this->assertSame(1, $n['overdue'], 'N1 sedang overdue');
        $this->assertSame($this->npd->id, $kpi['rows'][0]['pic_id'], 'peringkat: on-time tertinggi di atas');
        $sr = $this->row($kpi, $this->sales);
        $this->assertSame([0, null, 1], [$sr['completed'], $sr['on_time_rate'], $sr['overdue']], 'P1 (pembuatan NPR) tidak dihitung — OQ-29; S2 sedang overdue');
        $this->assertSame(3, $kpi['totals']['completed']);
        $this->assertSame(66.7, $kpi['totals']['on_time_rate']);

        // filter role & tipe proses
        $byRole = $svc->build($this->admin, $period, KpiService::cleanFilters(['role' => 'drafter']), '2026-10-20');
        $this->assertSame([$this->drafter->id], array_column($byRole['rows'], 'pic_id'));
        $byProc = $svc->build($this->admin, $period, KpiService::cleanFilters(['process' => 'N3']), '2026-10-20');
        $this->assertSame(0.0, $this->row($byProc, $this->drafter)['on_time_rate']);
        $this->assertSame([], $svc->build($this->admin, $period, KpiService::cleanFilters(['part_type' => 'subcont', 'role' => 'npd_staff']), '2026-10-20')['rows']);

        // drill-down & tren bulanan
        $drill = $svc->build($this->mgmt, $period, KpiService::cleanFilters(['pic' => (string) $this->drafter->id]), '2026-10-20');
        $this->assertCount(2, $drill['detail']['runs']);
        $this->assertSame(['N3', 'S1'], array_column($drill['detail']['runs'], 'code'));
        $this->assertFalse($drill['detail']['runs'][0]['on_time']);
        $this->assertCount(1, $drill['detail']['overdue']);
        $oct = array_values(array_filter($drill['trend'], static fn ($t) => $t['month'] === '2026-10'))[0];
        $this->assertSame([2, 1, 50.0], [$oct['completed'], $oct['on_time'], $oct['rate']], 'tren untuk PIC yang di-drill-down');
        $this->assertCount(6, $drill['trend']);

        // hanya Admin & Management
        foreach ([$this->sales, $this->npd, $this->drafter] as $u) {
            try {
                $svc->build($u, $period, KpiService::cleanFilters([]));
                $this->fail($u->roleCode . ' tidak boleh melihat KPI');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKpiExcludesHoldDaysAndUsesPlanAtActivation(): void
    {
        [$projectId, [, $cap]] = $this->running();
        $holds = new HoldService();
        Clock::freeze('2026-10-07 10:00:00');
        $holds->hold($this->npd, $projectId, null, 'Tunggu customer');
        Clock::freeze('2026-10-12 09:00:00');
        $holds->resume($this->npd, $projectId, null, ['target_finish' => '2027-03-31']); // S1: sisa 4 hk → 12–15 Okt
        $this->completeOn('2026-10-15', $this->drafter, $this->pid($projectId, $cap, 'S1'));

        $period = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-10'], '2026-10-15');
        $runs = (new KpiService())->completedRuns($period->from, $period->to, KpiService::cleanFilters(['process' => 'S1']));
        $this->assertCount(1, $runs);
        $this->assertSame(3, (int) $runs[0]['hold_working_days'], 'Hold 7–9 Okt');
        $this->assertSame(5, $runs[0]['actual_days'], '6–15 Okt = 8 hk − 3 hk Hold');
        $this->assertSame(5, $runs[0]['planned_days']);
        $this->assertTrue($runs[0]['on_time'], 'dinilai terhadap Planned Finish setelah Resume');
    }

    // ------------------------------------------------------------------ Dashboard (§10.1)

    public function testDashboardCardsPanelsChartsAndFilters(): void
    {
        [$projectId, [$body]] = $this->running();
        // Senin 12 Okt: N3 (s/d 13 Okt) & S1 (s/d 12 Okt) due soon (3 hk)
        Clock::freeze('2026-10-12 08:00:00');
        $svc = new DashboardService();
        $d = $svc->build($this->npd, DashboardService::cleanFilters([]), '2026-10-12');
        $this->assertSame(['projects' => 1, 'parts' => 2], $d['cards']['total']);
        $this->assertSame(['projects' => 1, 'parts' => 1], $d['cards']['new_mold']);
        $this->assertSame(['projects' => 1, 'parts' => 1], $d['cards']['subcont']);
        $this->assertSame(['projects' => 1, 'parts' => 2], $d['cards']['due_soon']);
        $this->assertSame(0, $d['cards']['overdue']['projects']);
        $q = new ProjectQuery();
        $this->assertSame($d['cards']['due_soon']['projects'], $q->list($this->admin, ['due_soon' => true])['total'], 'klik kartu → daftar terfilter yang sama');
        $this->assertSame(1, $q->list($this->admin, ['part_type' => 'subcont'])['total']);
        $this->assertSame(0, $q->list($this->admin, ['priority' => 'urgent'])['total']);

        // Selasa 20 Okt: N1, N3, S1 overdue
        $this->completeOn('2026-10-13', $this->drafter, $this->pid($projectId, $body, 'N3'));
        Clock::freeze('2026-10-20 08:00:00');
        $d = $svc->build($this->npd, DashboardService::cleanFilters([]), '2026-10-20');
        $this->assertSame(['projects' => 1, 'parts' => 2], $d['cards']['overdue']);
        $this->assertSame(1, $q->list($this->admin, ['overdue' => true])['total']);
        $this->assertCount(2, $d['overdue'], 'Panel Overdue: N1 & S1');
        $this->assertSame('S1', $d['overdue'][0]['code'], 'urut dari yang paling lama terlambat');
        $this->assertSame(['N1'], array_column($d['mine'], 'code'), 'Menunggu tindakan NPD: N1');
        $this->assertSame(['S1'], array_column($svc->build($this->drafter, DashboardService::cleanFilters([]), '2026-10-20')['mine'], 'code'));
        $this->assertCount(2, $d['attention']['overdue']);
        $this->assertSame(['New Mold', 'Subcont'], array_column($d['charts']['by_type'], 'label'));
        $this->assertSame(1, array_sum(array_column($d['charts']['by_status'], 'value')));
        $this->assertNotEmpty($d['charts']['by_process']);
        $this->assertNotEmpty($d['charts']['by_pic']);

        // filter jenis project: Subcont → hanya S1
        $sub = $svc->build($this->npd, DashboardService::cleanFilters(['part_type' => 'subcont']), '2026-10-20');
        $this->assertSame(['projects' => 1, 'parts' => 1], $sub['cards']['total']);
        $this->assertSame(['S1'], array_column($sub['overdue'], 'code'));
        $this->assertSame([], $sub['mine']);
        // filter tidak cocok → nol, bukan error
        $none = $svc->build($this->npd, DashboardService::cleanFilters(['customer_id' => '999999']), '2026-10-20');
        $this->assertSame(0, $none['cards']['total']['projects']);
        $this->assertSame([], $none['overdue']);

        // Hold mengecualikan overdue; arsip disembunyikan
        (new HoldService())->hold($this->npd, $projectId, null, 'Tunggu');
        $this->assertSame(0, $svc->build($this->npd, DashboardService::cleanFilters([]), '2026-10-20')['cards']['overdue']['projects']);
        (new LifecycleService())->archive($this->admin, $projectId, 'Arsip uji');
        $this->assertSame(0, $svc->build($this->npd, DashboardService::cleanFilters([]), '2026-10-20')['cards']['total']['projects']);
    }

    // ------------------------------------------------------------------ Weekly Report, Analytics, export

    public function testWeeklyReportAnalyticsAndExports(): void
    {
        [$projectId, [$body, $cap]] = $this->running();
        $this->completeOn('2026-10-09', $this->drafter, $this->pid($projectId, $cap, 'S1'));
        Clock::freeze('2026-10-12 07:00:00');
        $this->engine->activateReady($projectId);
        $this->completeOn('2026-10-12', $this->sales, $this->pid($projectId, $cap, 'S2'));
        Clock::freeze('2026-10-13 07:00:00');
        $this->engine->activateReady($projectId);
        $this->completeOn('2026-10-14', $this->sales, $this->pid($projectId, $cap, 'S3'), ['outcome' => 'not_approved', 'comment' => 'Warna logo kurang tegas']);
        (new NextActionService())->set($this->npd, $projectId, $cap, ['description' => 'Kirim revisi artwork ke customer', 'due_date' => '2026-10-16',
            'owner_user_id' => (string) $this->sales->id, 'waiting_for' => 'customer']);
        Clock::freeze('2026-10-16 09:00:00');

        $week = ReportPeriod::fromInput(['period' => 'week', 'week' => '2026-W42'], '2026-10-16'); // 12–18 Okt
        $svc = new WeeklyReport();
        $r = $svc->build($week, WeeklyReport::cleanFilters([]), '2026-10-16');
        $this->assertSame(2, $r['summary']['processes_completed'], 'S2 & S3 selesai minggu ini');
        $this->assertSame(1, $r['summary']['rejected']);
        $this->assertSame(1, $r['summary']['active_projects']);
        $this->assertCount(1, $r['problems'], 'loop Artwork');
        $this->assertSame('Warna logo kurang tegas', $r['problems'][0]['comment']);
        $this->assertStringContainsString('Not Approved', $r['problems'][0]['summary'], 'label keputusan, bukan kode mentah');
        $this->assertCount(1, $r['rejections']);
        $this->assertSame('Cap', $r['rejections'][0]['part_name']);
        $this->assertCount(1, $r['actions']);
        $this->assertSame('Cap', $r['actions'][0]['part_name']);
        $this->assertNotEmpty($r['overdue'], 'N1 & N3 overdue (16 Okt)');
        foreach ($r['overdue'] as $o) {
            $this->assertArrayHasKey('pic_name', $o);
            $this->assertNotNull($o['part_name'], 'item menyebut part');
        }
        $text = $svc->text($r);
        $code = (string) Db::value('SELECT code FROM projects WHERE id = ?', [$projectId]);
        $this->assertStringContainsString('WEEKLY NPD REPORT', $text);
        $this->assertStringContainsString($code . ' · Cap · Kirim revisi artwork ke customer · PIC: ' . $this->sales->name, $text);
        $this->assertStringContainsString('Warna logo kurang tegas', $text);
        // filter jenis New Mold: penolakan Subcont tidak muncul
        $nm = $svc->build($week, WeeklyReport::cleanFilters(['part_type' => 'new_mold']), '2026-10-16');
        $this->assertSame([], $nm['rejections']);
        $this->assertSame(0, $nm['summary']['processes_completed']);

        // Analytics: loop bernama + statistik per proses
        $month = ReportPeriod::fromInput(['period' => 'month', 'month' => '2026-10'], '2026-10-16');
        $a = (new AnalyticsService())->build($month, AnalyticsService::cleanFilters([]));
        $this->assertSame(1, $a['loops']['artwork_revisions']['count']);
        $this->assertSame(0, $a['loops']['t0_loops']['count']);
        $codes = array_column($a['stats'], 'code');
        foreach (['P2', 'S1', 'S2', 'S3'] as $c) {
            $this->assertContains($c, $codes);
        }
        $s3 = array_values(array_filter($a['stats'], static fn ($s) => $s['code'] === 'S3'))[0];
        $this->assertSame(1, $s3['rejections']);
        $this->assertSame(4, $a['totals']['count']);

        // Export: Excel sungguhan (dibaca ulang) & PDF
        $x = new ReportExports();
        $file = $x->weekly($this->admin, $week, WeeklyReport::cleanFilters([]));
        $this->assertStringStartsWith('PK', $file['content']);
        $book = $this->readXlsx($file['content']);
        $this->assertSame(['Ringkasan periode', 'Proses overdue', 'Proses bermasalah & loop', 'Penolakan', 'Action Required'], $book->getSheetNames());
        $this->assertSame('WEEKLY NPD REPORT', $book->getSheet(0)->getCell('C1')->getValue());
        $flat = json_encode($book->getSheetByName('Action Required')->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Kirim revisi artwork ke customer', (string) $flat);
        $book->disconnectWorksheets();
        $this->assertStringStartsWith('PK', $x->analytics($this->admin, $month, AnalyticsService::cleanFilters([]))['content']);
        $kx = $x->kpiXlsx($this->mgmt, $month, KpiService::cleanFilters([]));
        $kb = $this->readXlsx($kx['content']);
        $this->assertSame(['Peringkat PIC', 'Tren bulanan on-time rate', 'Rincian proses'], $kb->getSheetNames());
        $kb->disconnectWorksheets();
        $pdf = $x->kpiPdf($this->admin, $month, KpiService::cleanFilters(['pic' => (string) $this->drafter->id]));
        $this->assertStringStartsWith('%PDF', $pdf['content']);
        $this->assertSame('application/pdf', $pdf['mime']);
        $this->expectException(AuthorizationException::class);
        $x->kpiXlsx($this->sales, $month, KpiService::cleanFilters([]));
    }

    private function readXlsx(string $content): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($tmp, $content);
        $book = IOFactory::load($tmp);
        unlink($tmp);
        return $book;
    }
}
