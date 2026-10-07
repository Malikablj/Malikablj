<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Calendar\CalendarService;
use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Report\TimelineExcel;
use App\Report\TimelineExport;
use App\Report\TimelinePdf;
use App\Scheduling\WorkingCalendar;
use App\Timeline\PortfolioQuery;
use App\Timeline\TimelineService;
use App\Workflow\WorkflowEngine;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Timeline dua level, Gantt lintas project, Tracker, Kalender, export Timeline (PRD §6.6–6.8). */
final class TimelineTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private int $projectId;
    private int $mold;
    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        [, , $this->projectId] = $this->completedNpr($this->sales, $this->npd, $this->makeCustomer(), [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        [$this->mold, $this->sub] = array_map('intval', Db::column('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [$this->projectId]));
        // 6–14 Okt: N1, N3, S1 aktif; S1 (5 hk, rencana s/d 12 Okt) belum selesai pada 14 Okt → overdue 2 hk
        Clock::freeze('2026-10-06 07:00:00');
        (new WorkflowEngine())->activateReady($this->projectId);
        Clock::freeze('2026-10-14 10:00:00');
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    public function testRangeStartsOnMondayWithPadding(): void
    {
        [$from, $to] = TimelineService::range(['2026-10-08', '2026-11-20']);
        $this->assertSame('1', date('N', strtotime($from)));
        $this->assertLessThan('2026-10-08', $from);
        $this->assertGreaterThan('2026-11-20', $to);
    }

    public function testProjectLevelRowsAndOverdueFlags(): void
    {
        $data = (new TimelineService())->project($this->projectId);
        $this->assertSame(1, $data['level']);
        $kinds = array_column($data['rows'], 'kind');
        $this->assertSame(['project', 'project', 'project', 'project', 'part', 'part'], $kinds);
        $cap = $data['rows'][5];
        $this->assertSame('Cap', $cap['name']);
        $this->assertSame(2, $cap['overdue_days']);
        $this->assertSame(1, $cap['overdue_count']);
        $this->assertSame('2026-10-06', $cap['bar_start']);
        $this->assertNotNull($cap['baseline_start'], 'baseline v1 part');
        $this->assertSame('2026-10-14', $data['today']);
        $this->assertSame('2027-03-31', $data['target']);
    }

    public function testPartLevelRowsDependenciesCriticalAndBars(): void
    {
        $data = (new TimelineService())->part($this->mold);
        $this->assertSame(2, $data['level']);
        $rows = array_column($data['rows'], null, 'code');
        $this->assertCount(14, $data['rows']);
        // N5 bergantung N2 & N4 (FS)
        $this->assertEqualsCanonicalizing(['N2', 'N4'], array_column($rows['N5']['deps'], 'code'));
        // N1 berjalan: batang dari mulai aktual sampai forecast
        $this->assertSame('2026-10-06', $rows['N1']['bar_start']);
        $this->assertSame($rows['N1']['forecast_finish'], $rows['N1']['bar_end']);
        // N9 loop_only: tanpa batang, ditandai
        $this->assertNull($rows['N9']['bar_start']);
        $this->assertTrue($rows['N9']['dormant']);
        // jalur kritis berakhir di proses terakhir part (N14)
        $this->assertTrue($rows['N14']['critical']);
        $this->assertTrue($rows['N7']['critical']);
        $this->assertSame((int) $rows['N1']['id'], $rows['N2']['deps'][0]['id']);
    }

    public function testRenderedGanttHasPositionsAndDependencyData(): void
    {
        require_once APP_ROOT . '/includes/gantt.php';
        $this->assertSame(3, gantt_days('2026-10-05', '2026-10-08'));
        $this->assertSame('--s:7;--d:5', gantt_pos('2026-09-28', '2026-10-05', '2026-10-09'));
        $html = render_gantt((new TimelineService())->part($this->mold), ['deps' => true, 'critical' => true, 'link' => static fn ($r) => '/process.php?id=' . $r['id']]);
        $this->assertStringContainsString('data-deps="', $html);
        $this->assertStringContainsString('class="gantt-today"', $html);
        $this->assertStringContainsString('gantt-baseline', $html);
        $this->assertStringContainsString('data-gantt-toggle="critical"', $html);
        $this->assertSame(14, substr_count($html, 'class="gantt-row'));
    }

    public function testPortfolioGanttFiltersAndTracker(): void
    {
        $q = new PortfolioQuery();
        $projects = $q->gantt($this->npd, [], 'project');
        $this->assertSame(1, $projects['total']);
        $this->assertSame(2, $projects['rows'][0]['overdue_days']);
        $parts = $q->gantt($this->npd, ['part_type' => 'subcont'], 'part');
        $this->assertSame(1, $parts['total']);
        $this->assertSame('Cap', $parts['rows'][0]['name']);
        $this->assertSame(0, $q->gantt($this->npd, ['q' => 'tidak-ada-xyz'], 'project')['total']);
        $this->assertSame(1, $q->gantt($this->npd, ['pic_id' => $this->sales->id], 'project')['total']);

        $cols = $q->tracker($this->npd, []);
        $titles = array_column($cols, 'title');
        $this->assertContains('Masterbatch Development', $titles);
        $this->assertContains('3D Prototype Development', $titles);
        $this->assertContains('Artwork Development', $titles);
        $overdueOnly = $q->tracker($this->npd, ['overdue' => true]);
        // S1 (rencana s/d 12 Okt) terlambat 2 hk; N3 (6 hk, s/d 13 Okt) terlambat 1 hk
        $late = [];
        foreach ($overdueOnly as $c) {
            $late[$c['title']] = $c['cards'][0]['overdue_days'];
        }
        ksort($late);
        $this->assertSame(['3D Prototype Development' => 1, 'Artwork Development' => 2], $late);
    }

    public function testCalendarEventsAndAgendaPermissions(): void
    {
        $cal = new CalendarService();
        $events = $cal->events($this->npd, '2026-10-01', '2026-10-31');
        $flat = array_merge(...array_values($events));
        $this->assertNotEmpty($flat);
        $s1 = array_values(array_filter($flat, static fn ($e) => str_starts_with($e['title'], 'S1 ')));
        $this->assertTrue($s1[0]['overdue']);
        $approvals = $cal->events($this->npd, '2026-10-01', '2026-11-30', ['category' => 'approval']);
        foreach (array_merge(...array_values($approvals)) as $e) {
            $this->assertSame('approval', $e['category']);
        }
        $id = $cal->createAgenda($this->sales, ['title' => 'Kickoff', 'event_type' => 'meeting', 'event_date' => '2026-10-16', 'start_time' => '09:30', 'project_id' => (string) $this->projectId]);
        $agenda = $cal->events($this->sales, '2026-10-16', '2026-10-16', ['category' => 'agenda']);
        $this->assertSame('09:30 Kickoff', $agenda['2026-10-16'][0]['title']);
        $this->assertTrue($agenda['2026-10-16'][0]['can_delete']);
        try {
            $cal->createAgenda($this->sales, ['title' => '', 'event_date' => '2026-02-30']);
            $this->fail('validasi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
            $this->assertArrayHasKey('event_date', $e->errors());
        }
        try {
            $cal->deleteAgenda($this->npd, $id); // bukan pembuat & bukan Admin
            $this->fail('hanya pembuat/Admin');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $cal->deleteAgenda($this->sales, $id);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM calendar_events WHERE id = ?', [$id]));
        $this->expectException(AuthorizationException::class);
        $cal->createAgenda($this->makeUser('drafter'), ['title' => 'x', 'event_date' => '2026-10-16']);
    }

    public function testTimelinePdfHasDocNumberOnEveryPageAndOverdueRows(): void
    {
        if (!is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('pdftotext tidak tersedia');
        }
        $pdf = (new TimelinePdf())->build($this->npd, $this->projectId, 'all');
        $this->assertMatchesRegularExpression('/^Timeline_NPD-\d{4}-\d{3}_2026-10-14\.pdf$/', $pdf['filename']);
        $f = tempnam(sys_get_temp_dir(), 'tl');
        file_put_contents($f, $pdf['content']);
        $text = (string) shell_exec('/usr/bin/pdftotext -layout ' . escapeshellarg($f) . ' -');
        unlink($f);
        $pages = array_values(array_filter(explode("\f", $text), static fn ($p) => trim($p) !== ''));
        $this->assertGreaterThanOrEqual(6, count($pages), 'Level 1 + 2 part + 3 Gantt');
        foreach ($pages as $i => $page) {
            $this->assertStringContainsString(TimelineExport::DOC_NO, $page, 'No. Dokumen di halaman ' . ($i + 1));
            $this->assertStringContainsString('PROJECT TIMELINE', $page);
        }
        $this->assertStringNotContainsString('Revisi', $pages[0], 'tanpa nomor revisi');
        $this->assertStringContainsString('Overdue 2 hk', $text);
        $this->assertStringContainsString('Dicetak', $text);
        $this->assertStringContainsString('Gantt — Body', $text);
    }

    public function testTimelineExcelSheetsDatesFreezeFilterAndDocNumber(): void
    {
        $xlsx = (new TimelineExcel())->build($this->npd, $this->projectId, 'all');
        $this->assertStringEndsWith('.xlsx', $xlsx['filename']);
        $f = tempnam(sys_get_temp_dir(), 'tl') . '.xlsx';
        file_put_contents($f, $xlsx['content']);
        $book = IOFactory::load($f);
        unlink($f);
        $this->assertSame(['Level 1 - Project', 'Body', 'Cap'], $book->getSheetNames());
        foreach ($book->getAllSheets() as $sheet) {
            $topRight = $sheet->getCell($sheet->getHighestColumn(1) . '1')->getValue();
            $this->assertStringContainsString(TimelineExport::DOC_NO, (string) $topRight, $sheet->getTitle());
            $this->assertSame('C8', $sheet->getFreezePane());
            $this->assertNotSame('', $sheet->getAutoFilter()->getRange());
        }
        $cap = $book->getSheetByName('Cap');
        // baris S1: tanggal sungguhan (angka + format tanggal), terlambat ditandai
        $row = null;
        for ($r = 8; $r <= $cap->getHighestRow(); $r++) {
            if (str_starts_with((string) $cap->getCell('B' . $r)->getValue(), 'S1 ')) {
                $row = $r;
            }
        }
        $this->assertNotNull($row);
        $planned = $cap->getCell('F' . $row)->getValue();
        $this->assertIsNumeric($planned);
        $this->assertSame('2026-10-06', XlsDate::excelToDateTimeObject($planned)->format('Y-m-d'));
        $this->assertTrue(XlsDate::isDateTime($cap->getCell('F' . $row)));
        $this->assertSame(2, (int) $cap->getCell('K' . $row)->getValue());
        $this->assertSame('FFFDE7E9', $cap->getStyle('A' . $row)->getFill()->getStartColor()->getARGB());
    }

    public function testExportScopeValidation(): void
    {
        $this->expectException(ValidationException::class);
        (new TimelineExport())->build($this->npd, $this->projectId, 'semua');
    }
}
