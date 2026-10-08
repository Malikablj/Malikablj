<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\NumberSequence;
use App\Core\User;
use App\Core\ValidationException;
use App\Import\LegacyFormat;
use App\Import\LegacyImportService;
use App\Import\LegacyTemplate;
use App\Report\KpiService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DbTestCase;
use Tests\Support\LegacyImportFixtures;

/**
 * Impor data project lama dari Excel: template, validasi per baris tanpa menyimpan, impor project
 * berjalan/selesai/hold/batal, penomoran, pengecualian KPI, dan penolakan impor ulang.
 */
final class LegacyImportTest extends DbTestCase
{
    use LegacyImportFixtures;

    private User $admin;
    private User $sales;
    private User $npd;
    private User $drafter;
    private string $customer;
    private LegacyImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-07 09:00:00'); // Rabu
        WorkingCalendar::flush();
        $this->admin = $this->makeUser('admin');
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->drafter = $this->makeUser('drafter');
        $this->customer = 'LG' . random_int(1000, 9999);
        Db::insert('customers', ['code' => $this->customer, 'name' => 'PT Uji Impor', 'invoice_address' => 'Jl. Invoice', 'shipping_address' => 'Jl. Kirim', 'phone' => '021-9']);
        $this->svc = new LegacyImportService();
    }

    protected function tearDown(): void
    {
        $this->cleanLegacyFiles();
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function project(string $ref, array $over = []): array
    {
        return array_merge([
            'ref' => $ref, 'name' => 'Botol Serum ' . $ref, 'customer' => $this->customer, 'sales' => $this->sales->email, 'npd' => $this->npd->email,
            'npr_date' => '2026-03-02', 'feedback_date' => '2026-03-06', 'target' => '2026-12-18', 'status' => 'Berjalan',
        ], $over);
    }

    /** @return array<string,mixed> */
    private function proc(int $projectId, ?string $part, string $code): array
    {
        $row = $part === null
            ? Db::fetch('SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND code = ?', [$projectId, $code])
            : Db::fetch('SELECT pr.* FROM processes pr JOIN project_parts pp ON pp.id = pr.part_id WHERE pr.project_id = ? AND pp.name = ? AND pr.code = ?', [$projectId, $part, $code]);
        $this->assertNotNull($row, "proses $code");
        return $row;
    }

    /** @param list<array<string,mixed>> $errors */
    private function assertHasError(array $errors, string $sheet, int $row, string $needle): void
    {
        foreach ($errors as $e) {
            if ($e['sheet'] === $sheet && $e['row'] === $row && str_contains($e['message'], $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("Kesalahan \"$needle\" pada $sheet baris $row tidak ditemukan. Ada: " . json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function testTemplateHasSheetsHeadersDropdownsAndActiveProcesses(): void
    {
        $path = $this->scratch('xlsx');
        file_put_contents($path, LegacyTemplate::build());
        $book = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Project', 'Part', 'Proses', 'Daftar'], $book->getSheetNames());
        $project = $book->getSheetByName('Project');
        $this->assertSame('Ref Project *', $project->getCell('A1')->getValue());
        $this->assertSame('Kode Customer *', $project->getCell('C1')->getValue());
        foreach (['Project', 'Part', 'Proses'] as $name) {
            $sheet = $book->getSheetByName($name);
            foreach ($sheet->getCellCollection()->getCoordinates() as $coord) {
                if (!str_ends_with($coord, '1') || preg_match('/\d{2,}$/', $coord)) {
                    $this->assertNull($sheet->getCell($coord)->getValue(), "sheet $name tidak boleh berisi contoh yang ikut terimpor ($coord)");
                }
            }
        }
        $this->assertNotNull($project->getComment('A1')->getText()->getPlainText());
        $dv = $project->getDataValidation('C2'); // ditulis untuk rentang C2:C2000
        $this->assertStringContainsString("'Daftar'!", $dv->getFormula1());
        $lists = $book->getSheetByName('Daftar');
        $values = [];
        foreach ($lists->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $values[] = (string) $cell->getValue();
            }
        }
        $this->assertContains($this->customer, $values);
        $this->assertContains($this->sales->email, $values);
        $this->assertContains($this->drafter->email, $values);
        $this->assertContains('N3 — 3D Prototype Development', $values);
        $this->assertContains('S3 — Customer Artwork Approval', $values);
        $this->assertNotContains('N9 — Mold Correction', $values, 'proses loop_only tidak dapat diimpor');
        $this->assertNotContains('P1 — Project Request (NPR)', $values);
        // format kolom (berlaku untuk sel baru di Excel): tanggal dd/mm/yyyy, teks '@'
        $this->assertSame('dd/mm/yyyy', $book->getCellXfByIndex($project->getColumnDimension('G')->getXfIndex())->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $book->getCellXfByIndex($project->getColumnDimension('A')->getXfIndex())->getNumberFormat()->getFormatCode());
        $book->disconnectWorksheets();
    }

    public function testAnalyzeReportsEveryProblemPerRowAndWritesNothing(): void
    {
        $before = (int) Db::value('SELECT COUNT(*) FROM projects');
        Db::insert('npr', ['npr_number' => 'OLD/77', 'status' => 'draft', 'created_by' => $this->admin->id, 'sales_pic_id' => $this->sales->id]);
        $path = $this->legacyFile(
            [
                $this->project('A1', ['customer' => 'TIDAKADA', 'sales' => $this->npd->email]),                // row 2
                $this->project('A1'),                                                                           // row 3: ref kembar
                $this->project('A2', ['npr_date' => '2027-01-05', 'status' => 'Selesai', 'npr_number' => 'OLD/77']), // row 4
                $this->project('A3', ['npd' => 'siapa@x.test', 'status' => 'Hold']),                           // row 5: tanpa alasan, tanpa part
                $this->project('A4', ['npr_date' => ['text' => '31/02/2026']]),                               // row 6: tanggal tidak valid
            ],
            [
                ['ref' => 'A1', 'part' => 'Body', 'type' => 'New Mold', 'drafter' => $this->sales->email],      // row 2
                ['ref' => 'A1', 'part' => 'Cap', 'type' => 'Subcont'],                                          // row 3
                ['ref' => 'ZZ', 'part' => 'Body', 'type' => 'New Mold'],                                        // row 4
                ['ref' => 'A2', 'part' => 'Body', 'type' => 'Botol'],                                           // row 5
                ['ref' => 'A4', 'part' => 'Body', 'type' => 'Subcont'],                                         // row 6
            ],
            [
                ['ref' => 'A1', 'part' => 'Body', 'process' => 'N5 — 2D Drawing', 'status' => 'Berjalan', 'start' => '2026-04-01'],                 // row 2: N2/N4 belum
                ['ref' => 'A1', 'part' => 'Body', 'process' => 'N1', 'status' => 'Dilewati'],                                                       // row 3: grup MB parsial
                ['ref' => 'A1', 'part' => 'Body', 'process' => 'N6', 'status' => 'Dilewati'],                                                       // row 4: tidak boleh dilewati
                ['ref' => 'A1', 'part' => 'Body', 'process' => 'N9', 'status' => 'Berjalan', 'start' => '2026-04-01'],                              // row 5: loop_only
                ['ref' => 'A1', 'part' => 'Cap', 'process' => 'S1', 'status' => 'Berjalan', 'start' => '2026-04-01', 'finish' => '2026-04-03'],     // row 6
                ['ref' => 'A1', 'part' => '', 'process' => 'P2', 'status' => 'Selesai', 'start' => '2026-03-02', 'finish' => '2026-03-06'],         // row 7
                ['ref' => 'A1', 'part' => 'Lid', 'process' => 'S1', 'status' => 'Selesai'],                                                         // row 8
                ['ref' => 'A1', 'part' => 'Cap', 'process' => 'Xyz', 'status' => 'Selesai'],                                                        // row 9
                ['ref' => 'A1', 'part' => 'Cap', 'process' => 'S2', 'status' => 'Selesai', 'start' => '2026-04-10', 'finish' => '2026-04-08'],     // row 10
                ['ref' => 'A1', 'part' => 'Cap', 'process' => 'S1', 'status' => 'Selesai', 'start' => '2026-04-01', 'finish' => '2026-04-03'],     // row 11: kembar
                ['ref' => 'A1', 'part' => 'Cap', 'process' => 'S4', 'status' => 'Berjalan', 'start' => '2026-04-01', 'pic' => $this->drafter->email], // row 12: PIC role salah
            ]
        );
        $a = $this->svc->analyze($path);
        $e = $a['errors'];
        $this->assertHasError($e, 'Project', 2, 'Kode customer "TIDAKADA" tidak ditemukan');
        $this->assertHasError($e, 'Project', 2, 'bukan Admin Sales');
        $this->assertHasError($e, 'Project', 3, 'dipakai lebih dari sekali');
        $this->assertHasError($e, 'Project', 4, 'Tidak boleh di masa depan');
        $this->assertHasError($e, 'Project', 4, 'sudah ada di aplikasi');
        $this->assertHasError($e, 'Project', 5, 'siapa@x.test tidak ditemukan');
        $this->assertHasError($e, 'Project', 5, 'Wajib diisi bila Status Project = Hold');
        $this->assertHasError($e, 'Project', 5, 'belum punya part');
        $this->assertHasError($e, 'Project', 6, 'Tanggal tidak valid');
        $this->assertHasError($e, 'Part', 2, 'bukan Drafter');
        $this->assertHasError($e, 'Part', 4, 'Ref Project "ZZ" tidak ada');
        $this->assertHasError($e, 'Part', 5, 'Wajib diisi: New Mold / Subcont');
        $this->assertHasError($e, 'Proses', 2, 'pendahulunya N2 Customer Masterbatch Approval belum ditulis');
        $this->assertHasError($e, 'Proses', 3, 'harus dilewati bersama');
        $this->assertHasError($e, 'Proses', 4, 'tidak boleh dilewati');
        $this->assertHasError($e, 'Proses', 5, 'tidak dapat diimpor');
        $this->assertHasError($e, 'Proses', 6, 'tidak punya Tanggal Selesai');
        $this->assertHasError($e, 'Proses', 7, 'diisi otomatis dari sheet Project');
        $this->assertHasError($e, 'Proses', 8, 'Part "Lid" tidak ada');
        $this->assertHasError($e, 'Proses', 9, 'Proses "Xyz" tidak ada');
        $this->assertHasError($e, 'Proses', 10, 'Tidak boleh sebelum Tanggal Mulai');
        $this->assertHasError($e, 'Proses', 11, 'sudah ditulis');
        $this->assertHasError($e, 'Proses', 12, 'bukan NPD Staff');
        $this->assertSame($before, (int) Db::value('SELECT COUNT(*) FROM projects'), 'analisis tidak menyimpan apa pun');
        $this->expectException(ValidationException::class);
        $this->svc->commit($this->admin, $path, 'salah.xlsx');
    }

    public function testRunningProjectIsImportedAndContinuesInSystemWithoutKpi(): void
    {
        $notifBefore = (int) Db::value('SELECT COUNT(*) FROM notifications');
        $mailBefore = (int) Db::value('SELECT COUNT(*) FROM notification_deliveries');
        $path = $this->legacyFile(
            [$this->project('PRJ-25-014', ['priority' => 'Tinggi', 'qty_month' => 50000, 'note' => 'Data dari tracker 2025'])],
            [
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'type' => 'New Mold', 'supplier' => 'PT Mold Jaya', 'drafter' => $this->drafter->email],
                ['ref' => 'PRJ-25-014', 'part' => 'Tutup Flip', 'type' => 'Subcont'],
            ],
            [
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N1 — Masterbatch Development', 'status' => 'Dilewati', 'note' => 'Pakai masterbatch lama'],
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N2', 'status' => 'Dilewati'],
                ['ref' => 'PRJ-25-014', 'part' => 'body', 'process' => '3D Prototype Development', 'status' => 'Selesai', 'start' => '2026-03-09', 'finish' => '2026-03-20'],
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N4', 'status' => 'Selesai', 'start' => '2026-03-23', 'finish' => ['text' => '27/03/2026']],
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N5', 'status' => 'Selesai', 'start' => '2026-03-30', 'finish' => '2026-04-03'],
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N6', 'status' => 'Selesai', 'start' => '2026-04-06', 'finish' => '2026-04-10'],
                ['ref' => 'PRJ-25-014', 'part' => 'Body', 'process' => 'N7 — Mold Machining', 'status' => 'Berjalan', 'start' => '2026-09-28', 'plan_finish' => '2026-10-09', 'note' => 'Mold maker: progres 80%'],
                ['ref' => 'PRJ-25-014', 'part' => 'Tutup Flip', 'process' => 'S1', 'status' => 'Selesai', 'start' => '2026-03-09', 'finish' => '2026-03-13'],
                ['ref' => 'PRJ-25-014', 'part' => 'Tutup Flip', 'process' => 'S2', 'status' => 'Selesai', 'start' => '2026-03-16', 'finish' => '2026-03-16'],
                ['ref' => 'PRJ-25-014', 'part' => 'Tutup Flip', 'process' => 'S3', 'status' => 'Berjalan', 'start' => '2026-03-17'],
            ]
        );
        $a = $this->svc->analyze($path);
        $this->assertSame([], $a['errors']);
        $this->assertSame(1, $a['summary']['projects']);
        $this->assertSame(2, $a['summary']['parts']);
        $this->assertSame(6, $a['summary']['processes_completed']);
        $this->assertSame(2, $a['summary']['processes_current']);
        $this->assertSame(2, $a['summary']['processes_skipped']);
        // S3 berjalan sejak Maret: direncanakan selesai jauh di masa lalu → diperingatkan
        $this->assertNotEmpty(array_filter($a['warnings'], static fn ($w) => str_contains($w['message'], 'S3 direncanakan selesai')));

        $res = $this->svc->commit($this->admin, $path, 'data-lama.xlsx');
        $this->assertCount(1, $res['projects']);
        $pid = $res['projects'][0]['id'];
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$pid]);
        $this->assertSame('PRJ-25-014', $project['legacy_ref']);
        $this->assertNotNull($project['imported_at']);
        $this->assertMatchesRegularExpression('/^NPD-2026-\d{3}$/', $project['code']);
        $this->assertSame('2026-03-02', $project['start_date']);
        $this->assertSame('2026-10-07', $project['schedule_floor']);
        $this->assertSame('high', $project['priority']);
        $this->assertSame($this->npd->id, (int) $project['npd_pic_id']);
        $this->assertSame('waiting', $project['status'], 'S3 menunggu approval customer');
        $npr = Db::fetch('SELECT * FROM npr WHERE id = ?', [(int) $project['npr_id']]);
        $this->assertSame('feedback_completed', $npr['status']);
        $this->assertMatchesRegularExpression('#^\d{3}/PIK/NPR/III/2026$#', $npr['npr_number']);
        $this->assertSame(50000, (int) $npr['qty_per_month']);
        $this->assertSame('Jl. Invoice', $npr['invoice_address']);
        $this->assertSame('2026-03-06 00:00:00', $npr['received_at']);
        $parts = Db::fetchAll('SELECT pp.*, np.part_name_code, np.part_name_custom, f.decision, f.needs_new_masterbatch AS fb_mb FROM project_parts pp JOIN npr_parts np ON np.id = pp.npr_part_id JOIN npr_feedback f ON f.npr_part_id = np.id WHERE pp.project_id = ? ORDER BY pp.sort_order', [$pid]);
        $this->assertSame(['Body', 'Tutup Flip'], array_column($parts, 'name'));
        $this->assertSame('body', $parts[0]['part_name_code'], 'nama part cocok dengan master');
        $this->assertSame('__other', $parts[1]['part_name_code']);
        $this->assertSame('0', (string) $parts[0]['needs_new_masterbatch'], 'grup Masterbatch dilewati');
        $this->assertSame('PT Mold Jaya', $parts[0]['mold_supplier']);
        $this->assertSame('2026-03-06', $parts[0]['start_date']);
        $this->assertSame('feasible', $parts[0]['decision']);

        $p1 = $this->proc($pid, null, 'P1');
        $p2 = $this->proc($pid, null, 'P2');
        $this->assertSame(['completed', '2026-03-02', '2026-03-02'], [$p1['status'], $p1['actual_start'], $p1['actual_finish']]);
        $this->assertSame(['completed', '2026-03-06', $this->npd->id], [$p2['status'], $p2['actual_finish'], (int) $p2['pic_user_id']]);
        $n3 = $this->proc($pid, 'Body', 'N3');
        $this->assertSame(['completed', '2026-03-09', '2026-03-20'], [$n3['status'], $n3['actual_start'], $n3['actual_finish']]);
        $this->assertNull($n3['planned_finish'], 'rencana lama tidak dikarang');
        $this->assertSame($this->drafter->id, (int) $n3['pic_user_id']);
        $this->assertSame('2026-03-27', $this->proc($pid, 'Body', 'N4')['actual_finish'], 'tanggal teks dd/mm/yyyy');
        $n1 = $this->proc($pid, 'Body', 'N1');
        $this->assertSame(['skipped', 'Pakai masterbatch lama'], [$n1['status'], $n1['skip_reason']]);
        $n7 = $this->proc($pid, 'Body', 'N7');
        $this->assertSame(['current', '2026-09-28', '2026-10-09', $this->npd->id], [$n7['status'], $n7['actual_start'], $n7['planned_finish'], (int) $n7['pic_user_id']]);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM comments WHERE process_id = ? AND body LIKE '[Data lama]%'", [(int) $n7['id']]));
        $n8 = $this->proc($pid, 'Body', 'N8');
        $this->assertSame('not_started', $n8['status']);
        $this->assertGreaterThan('2026-10-09', $n8['planned_start'], 'dijadwalkan setelah N7 direncanakan selesai');
        // approval customer yang sedang berjalan menunggu keputusan
        $s3 = $this->proc($pid, 'Tutup Flip', 'S3');
        $this->assertSame('pending', Db::value('SELECT status FROM approvals WHERE process_id = ?', [(int) $s3['id']]));
        // run data lama ditandai & tidak masuk KPI
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM process_runs r JOIN processes p ON p.id = r.process_id WHERE p.project_id = ? AND r.is_imported = 0', [$pid]));
        $this->assertSame(10, (int) Db::value('SELECT COUNT(*) FROM process_runs r JOIN processes p ON p.id = r.process_id WHERE p.project_id = ?', [$pid]), 'P1, P2, N3–N6, S1, S2 selesai + N7, S3 berjalan');
        $kpi = new KpiService();
        $this->assertSame([], array_filter($kpi->completedRuns('2026-01-01', '2026-12-31', KpiService::cleanFilters([])), static fn ($r) => (int) $r['project_id'] === $pid));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ? AND part_id = ?', [$pid, (int) $parts[0]['id']]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'project.import' AND entity_id = ?", [$pid]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'import.legacy'"));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'import'", [$pid]));
        $this->assertSame($notifBefore, (int) Db::value('SELECT COUNT(*) FROM notifications'), 'impor tidak mengirim notifikasi');
        $this->assertSame($mailBefore, (int) Db::value('SELECT COUNT(*) FROM notification_deliveries'));

        // dilanjutkan di sistem: N7 selesai → N8 aktif → run baru dihitung KPI
        $engine = new WorkflowEngine();
        $engine->complete($this->npd, (int) $n7['id'], ['actual_finish' => '2026-10-06']);
        $this->assertSame(1, (int) Db::value('SELECT is_imported FROM process_runs WHERE process_id = ?', [(int) $n7['id']]), 'proses yang sudah berjalan sebelum impor tetap data lama');
        $n8 = $this->proc($pid, 'Body', 'N8');
        $this->assertSame('current', $n8['status']);
        $this->assertSame(0, (int) Db::value('SELECT is_imported FROM process_runs WHERE process_id = ?', [(int) $n8['id']]));
        $engine->complete($this->npd, (int) $n8['id'], ['outcome' => 't0_ok', 'actual_finish' => '2026-10-07']);
        $runs = array_filter($kpi->completedRuns('2026-01-01', '2026-12-31', KpiService::cleanFilters([])), static fn ($r) => (int) $r['project_id'] === $pid);
        $this->assertSame(['N8'], array_values(array_column($runs, 'code')));
    }

    public function testCompletedHoldAndCancelledProjectsAndLegacyNumbers(): void
    {
        $subcontAll = [];
        $d = new \DateTimeImmutable('2026-01-05');
        foreach (['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11'] as $i => $code) {
            $subcontAll[] = ['ref' => 'DONE', 'part' => 'Label', 'process' => $code, 'status' => 'Selesai',
                'start' => $d->modify('+' . ($i * 7) . ' days')->format('Y-m-d'), 'finish' => $d->modify('+' . ($i * 7 + 4) . ' days')->format('Y-m-d')];
        }
        $path = $this->legacyFile(
            [
                $this->project('DONE', ['npr_date' => '2026-01-02', 'feedback_date' => '2026-01-02', 'status' => 'Selesai', 'finish_date' => '2026-04-10',
                    'npr_number' => '041/PIK/NPR/I/2026', 'code' => 'NPD-2026-090']),
                $this->project('HOLD', ['status' => 'Hold', 'reason' => 'Customer menunda (sejak 01/08/2026)', 'npr_number' => 'NPR-LAMA/2025/7']),
                $this->project('BATAL', ['status' => 'Batal', 'reason' => 'Customer membatalkan']),
                $this->project('PARTIAL'),
            ],
            [
                ['ref' => 'DONE', 'part' => 'Label', 'type' => 'Subcont'],
                ['ref' => 'HOLD', 'part' => 'Body', 'type' => 'New Mold'],
                ['ref' => 'BATAL', 'part' => 'Body', 'type' => 'Subcont'],
                ['ref' => 'PARTIAL', 'part' => 'Body', 'type' => 'Subcont'],
                ['ref' => 'PARTIAL', 'part' => 'Cap', 'type' => 'Subcont', 'status' => 'Batal', 'reason' => 'Diganti cap standar'],
            ],
            array_merge($subcontAll, [
                ['ref' => 'DONE', 'part' => '', 'process' => 'G1 — Assembly / Fit Test', 'status' => 'Selesai', 'start' => '2026-02-23', 'finish' => '2026-02-24'],
                ['ref' => 'HOLD', 'part' => 'Body', 'process' => 'N3', 'status' => 'Berjalan', 'start' => '2026-09-01'],
                ['ref' => 'BATAL', 'part' => 'Body', 'process' => 'S1', 'status' => 'Berjalan', 'start' => '2026-09-01'],
            ])
        );
        $a = $this->svc->analyze($path);
        $this->assertSame([], $a['errors']);
        $res = $this->svc->commit($this->admin, $path, 'campur.xlsx');
        $byRef = array_column($res['projects'], null, 'ref');

        $done = Db::fetch('SELECT * FROM projects WHERE id = ?', [$byRef['DONE']['id']]);
        $this->assertSame(['completed', 'NPD-2026-090', '2026-04-10 00:00:00'], [$done['status'], $done['code'], $done['finished_at']]);
        $this->assertNull($done['schedule_floor']);
        $this->assertSame('completed', $this->proc((int) $done['id'], null, 'PF')['status']);
        $this->assertSame('completed', Db::value('SELECT status FROM project_parts WHERE project_id = ?', [(int) $done['id']]));
        $this->assertSame('041/PIK/NPR/I/2026', $byRef['DONE']['npr_number']);
        $this->assertSame([2026, 41], array_map('intval', array_values(Db::fetch('SELECT seq_year, seq_no FROM npr WHERE npr_number = ?', ['041/PIK/NPR/I/2026']))));
        // penomoran otomatis melanjutkan dari nomor format sistem tertinggi pada file (HOLD/BATAL/PARTIAL)
        $this->assertSame(['NPD-2026-091', 'NPD-2026-092', 'NPD-2026-093'], [$byRef['HOLD']['code'], $byRef['BATAL']['code'], $byRef['PARTIAL']['code']]);
        $this->assertSame(['042/PIK/NPR/III/2026', '043/PIK/NPR/III/2026'], [$byRef['BATAL']['npr_number'], $byRef['PARTIAL']['npr_number']]);
        $this->assertSame('NPD-2026-094', NumberSequence::nextProjectCode(Clock::now()));
        $this->assertStringStartsWith('044/PIK/NPR/', NumberSequence::nextNprNumber(Clock::now())['number']);

        $hold = Db::fetch('SELECT * FROM projects WHERE id = ?', [$byRef['HOLD']['id']]);
        $this->assertSame(['hold', 1], [$hold['status'], (int) $hold['is_on_hold']]);
        $this->assertSame('NPR-LAMA/2025/7', $byRef['HOLD']['npr_number']);
        $this->assertNull(Db::value('SELECT seq_no FROM npr WHERE npr_number = ?', ['NPR-LAMA/2025/7']), 'nomor non-standar tidak memengaruhi penomoran');
        $this->assertSame('Customer menunda (sejak 01/08/2026)', Db::value('SELECT reason FROM hold_history WHERE project_id = ? AND resumed_at IS NULL', [(int) $hold['id']]));
        $this->assertSame('not_started', $this->proc((int) $hold['id'], 'Body', 'N4')['status'], 'project Hold tidak mengaktifkan proses baru');

        $batal = Db::fetch('SELECT * FROM projects WHERE id = ?', [$byRef['BATAL']['id']]);
        $this->assertSame(['cancelled', 'Customer membatalkan'], [$batal['status'], $batal['cancel_reason']]);

        $partial = (int) $byRef['PARTIAL']['id'];
        $parts = Db::fetchAll('SELECT name, status, cancel_reason FROM project_parts WHERE project_id = ? ORDER BY sort_order', [$partial]);
        $this->assertSame([['Body', 'on_progress', null], ['Cap', 'cancelled', 'Diganti cap standar']], array_map('array_values', $parts));
        $this->assertSame('current', $this->proc($partial, 'Body', 'S1')['status'], 'part tanpa proses berjalan dimulai pada tanggal impor');
        $this->assertSame('2026-10-07', $this->proc($partial, 'Body', 'S1')['actual_start']);
    }

    public function testReimportIsRefusedAndFormulaWithoutValueIsRejected(): void
    {
        $file = fn () => $this->legacyFile([$this->project('ULANG')], [['ref' => 'ULANG', 'part' => 'Body', 'type' => 'Subcont']]);
        $this->svc->commit($this->admin, $file(), 'a.xlsx');
        $again = $this->svc->analyze($file());
        $this->assertHasError($again['errors'], 'Project', 2, 'sudah pernah diimpor');

        $f = $this->legacyFile([$this->project('RUMUS', ['name' => '="Botol "&"Rumus"'])], [['ref' => 'RUMUS', 'part' => 'Body', 'type' => 'Subcont']], [], null, false);
        $this->assertHasError($this->svc->analyze($f)['errors'], 'Project', 2, 'Paste Values');
        $ok = $this->legacyFile([$this->project('RUMUS', ['name' => '="Botol "&"Rumus"'])], [['ref' => 'RUMUS', 'part' => 'Body', 'type' => 'Subcont']]);
        $a = $this->svc->analyze($ok);
        $this->assertSame([], $a['errors']);
        $this->assertSame('Botol Rumus', $a['plan'][0]['name'], 'nilai tersimpan dari Excel dipakai');
    }

    public function testWrongFileAndPermissionAreRejected(): void
    {
        $txt = $this->scratch('xlsx');
        file_put_contents($txt, "bukan excel");
        $this->assertStringContainsString('bukan Excel', $this->svc->analyze($txt)['errors'][0]['message']);
        $path = $this->legacyFile([$this->project('X1')], [['ref' => 'X1', 'part' => 'Body', 'type' => 'Subcont']]);
        $this->expectException(AuthorizationException::class);
        $this->svc->commit($this->npd, $path, 'x.xlsx');
    }

    public function testMissingColumnsAndSheetsAreReported(): void
    {
        $tpl = $this->scratch('xlsx');
        file_put_contents($tpl, LegacyTemplate::build());
        $book = IOFactory::load($tpl);
        $book->getSheetByName('Project')->setCellValue('C1', 'Customer');
        $book->removeSheetByIndex($book->getIndex($book->getSheetByName('Proses')));
        $path = $this->scratch('xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $errors = $this->svc->analyze($path)['errors'];
        $this->assertSame('Proses', $errors[0]['sheet']);
        $this->assertStringContainsString('tidak ditemukan', $errors[0]['message']);

        $book = IOFactory::load($tpl);
        $book->getSheetByName('Project')->setCellValue('C1', 'Customer');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $this->assertHasError($this->svc->analyze($path)['errors'], 'Project', 1, 'Kode Customer');
        $this->assertSame('Kode Customer', LegacyFormat::COLUMNS['Project']['customer'][0]);
    }
}
