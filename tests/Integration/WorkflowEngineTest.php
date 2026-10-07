<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Npr\NprFeedbackService;
use App\Npr\NprService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/**
 * Mesin workflow end-to-end pada MySQL: NPR → project → part → proses, aktivasi otomatis,
 * keputusan & loop, Tidak dijalankan, gate, status turunan, run KPI, audit.
 */
final class WorkflowEngineTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $admin;
    private User $drafter;
    private int $customer;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00'); // Senin
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->admin = $this->makeUser('admin');
        $this->drafter = $this->makeUser('drafter');
        $this->customer = $this->makeCustomer();
        $this->engine = new WorkflowEngine();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function proc(int $projectId, ?int $partId, string $code): array
    {
        $row = Db::fetch('SELECT * FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [$projectId, $partId, $code]);
        $this->assertNotNull($row, "proses $code tidak ada");
        return $row;
    }

    private function partId(int $projectId, int $index = 0): int
    {
        return (int) Db::column('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [$projectId])[$index];
    }

    /** Selesaikan proses (sebagai NPD kecuali ditentukan) setelah proses aktif. @param array<string,mixed> $input */
    private function done(int $projectId, ?int $partId, string $code, array $input = [], ?User $as = null): array
    {
        $this->reach($projectId, $partId, $code);
        return $this->engine->complete($as ?? $this->npd, (int) $this->proc($projectId, $partId, $code)['id'], $input);
    }

    /** Proses aktif setelah Planned Start tercapai (aktivasi otomatis). */
    private function assertBecomesActive(int $projectId, ?int $partId, string $code, string $message = ''): void
    {
        $this->reach($projectId, $partId, $code);
        $this->assertSame('current', $this->proc($projectId, $partId, $code)['status'], $message ?: $code);
    }

    /** Majukan jam ke Planned Start lalu jalankan aktivasi (simulasi cron pagi) sampai proses aktif. */
    private function reach(int $projectId, ?int $partId, string $code): void
    {
        for ($i = 0; $i < 3; $i++) {
            $p = $this->proc($projectId, $partId, $code);
            if (in_array($p['status'], WorkflowEngine::ACTIVE, true) || $p['status'] !== 'not_started' || $p['planned_start'] === null) {
                return;
            }
            if ($p['planned_start'] > Clock::todayString()) {
                Clock::freeze($p['planned_start'] . ' 08:00:00');
            }
            $this->engine->activateReady($projectId);
        }
    }

    private function uploadDoc(int $processId, string $type): void
    {
        $p = Db::fetch('SELECT project_id, part_id FROM processes WHERE id = ?', [$processId]);
        $docId = Db::insert('documents', [
            'project_id' => (int) $p['project_id'], 'part_id' => $p['part_id'], 'process_id' => $processId,
            'doc_type_code' => $type, 'title' => $type, 'created_by' => $this->npd->id,
        ]);
        $verId = Db::insert('document_versions', [
            'document_id' => $docId, 'version_no' => 1, 'original_name' => $type . '.pdf', 'stored_path' => 'test/' . bin2hex(random_bytes(8)),
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->npd->id,
        ]);
        Db::update('documents', ['current_version_id' => $verId], ['id' => $docId]);
    }

    // ------------------------------------------------------------------ NPR → proses level project

    public function testSubmitCreatesProjectLevelProcesses(): void
    {
        [$nprId] = $this->submittedNpr($this->sales, $this->customer, [['body', 'new_mold']]);
        $projectId = (int) Db::value('SELECT id FROM projects WHERE npr_id = ?', [$nprId]);

        $p1 = $this->proc($projectId, null, 'P1');
        $p2 = $this->proc($projectId, null, 'P2');
        $g1 = $this->proc($projectId, null, 'G1');
        $pf = $this->proc($projectId, null, 'PF');
        $this->assertSame('completed', $p1['status']);
        $this->assertSame('2026-10-05', $p1['actual_finish']);
        $this->assertSame((string) $this->sales->id, (string) $p1['pic_user_id']);
        $this->assertSame('current', $p2['status']);
        $this->assertSame('2026-10-05', $p2['actual_start']);
        $this->assertSame('not_started', $g1['status'], 'gate tidak boleh aktif sebelum ada part');
        $this->assertSame('not_started', $pf['status']);
        $this->assertSame('on_progress', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        // dependency bawaan G1 ← P2, PF ← P2 & G1
        $deps = Db::fetchAll('SELECT process_id, predecessor_id FROM process_dependencies WHERE process_id IN (?, ?)', [(int) $g1['id'], (int) $pf['id']]);
        $this->assertCount(3, $deps);
        // run KPI: P1 selesai, P2 terbuka
        $this->assertSame('completed', Db::value('SELECT status FROM process_runs WHERE process_id = ?', [(int) $p1['id']]));
        $this->assertSame('open', Db::value('SELECT status FROM process_runs WHERE process_id = ?', [(int) $p2['id']]));
        // P2 berdurasi 3 hk mulai Senin → Rabu
        $this->assertSame('2026-10-07', $p2['planned_finish']);
    }

    public function testReturnAndResubmitLoopP1AndP2(): void
    {
        [$nprId] = $this->submittedNpr($this->sales, $this->customer, [['body', 'new_mold']]);
        $projectId = (int) Db::value('SELECT id FROM projects WHERE npr_id = ?', [$nprId]);
        Clock::freeze('2026-10-06 10:00:00');
        (new NprFeedbackService())->returnToSales($this->npd, $nprId, 'Volume belum jelas');
        $this->assertSame('revision', $this->proc($projectId, null, 'P1')['status']);
        $this->assertSame('not_started', $this->proc($projectId, null, 'P2')['status']);

        Clock::freeze('2026-10-07 10:00:00');
        (new NprService())->submit($this->sales, $nprId);
        $p1 = $this->proc($projectId, null, 'P1');
        $p2 = $this->proc($projectId, null, 'P2');
        $this->assertSame('completed', $p1['status']);
        $this->assertSame('current', $p2['status']);
        $this->assertSame('2026-10-07', $p2['actual_start']);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM process_runs WHERE process_id = ?', [(int) $p1['id']]), 'P1: 2 iterasi');
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM process_runs WHERE process_id = ?', [(int) $p2['id']]), 'P2: 2 iterasi');
    }

    // ------------------------------------------------------------------ mulai part

    public function testFeedbackCompletedStartsAcceptedPartsWithBaseline(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        $mold = $this->partId($projectId, 0);
        $sub = $this->partId($projectId, 1);

        $this->assertSame('completed', $this->proc($projectId, null, 'P2')['status']);
        $this->assertSame($this->npd->id, (int) Db::value('SELECT npd_pic_id FROM projects WHERE id = ?', [$projectId]), 'OQ-04: NPD PIC = penyelesai feedback');
        $this->assertSame($this->npd->id, (int) $this->proc($projectId, null, 'G1')['pic_user_id']);
        $this->assertSame(14, (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [$mold]));
        $this->assertSame(11, (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [$sub]));

        // FS: P2 → proses pertama part mulai hari kerja berikutnya (contoh PRD §6.4, OQ-25)
        foreach ([[$mold, 'N1'], [$mold, 'N3'], [$sub, 'S1']] as [$part, $code]) {
            $p = $this->proc($projectId, $part, $code);
            $this->assertSame('not_started', $p['status'], $code);
            $this->assertSame('2026-10-06', $p['planned_start'], $code);
        }
        // N1 7 hk: Sel 6 Okt → Rabu 14 Okt; N2 4 hk mulai Kamis 15 → Selasa 20
        $this->assertSame('2026-10-14', $this->proc($projectId, $mold, 'N1')['planned_finish']);
        $n2 = $this->proc($projectId, $mold, 'N2');
        $this->assertSame(['2026-10-15', '2026-10-20'], [$n2['planned_start'], $n2['planned_finish']]);
        // N5 menunggu N2 (20 Okt) dan N4 (N3 6 hk → 13 Okt; N4 14–19 Okt) → 21 Okt
        $this->assertSame('2026-10-21', $this->proc($projectId, $mold, 'N5')['planned_start']);
        // N9 loop_only tidak dijadwalkan
        $this->assertNull($this->proc($projectId, $mold, 'N9')['planned_start']);

        // status turunan: part sudah mulai → on_progress; pagi berikutnya proses pertama aktif (cron)
        $this->assertSame('on_progress', Db::value('SELECT status FROM project_parts WHERE id = ?', [$mold]));
        Clock::freeze('2026-10-06 06:00:00');
        $this->engine->activateReady($projectId);
        foreach ([[$mold, 'N1'], [$mold, 'N3'], [$sub, 'S1']] as [$part, $code]) {
            $this->assertSame('current', $this->proc($projectId, $part, $code)['status'], $code);
            $this->assertSame('2026-10-06', $this->proc($projectId, $part, $code)['actual_start'], $code);
        }
        $this->assertSame('on_progress', Db::value('SELECT status FROM project_parts WHERE id = ?', [$mold]));
        $this->assertSame('on_progress', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        // Gate menunggu milestone tiap part: N8 & S7
        $g1 = (int) $this->proc($projectId, null, 'G1')['id'];
        $preds = Db::column('SELECT p.code FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? ORDER BY p.code', [$g1]);
        $this->assertSame(['N8', 'P2', 'S7'], $preds);
        // N12 / S9 bergantung G1 (gate aktif)
        $n12 = (int) $this->proc($projectId, $mold, 'N12')['id'];
        $this->assertContains((string) $g1, array_map('strval', Db::column('SELECT predecessor_id FROM process_dependencies WHERE process_id = ?', [$n12])));
        // Baseline v1 per part
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ? AND version_no = 1', [$projectId]));
        $this->assertSame(14, (int) Db::value('SELECT COUNT(*) FROM schedule_baseline_items i JOIN schedule_baselines b ON b.id = i.baseline_id WHERE b.part_id = ?', [$mold]));
        // forecast project terisi
        $this->assertNotNull(Db::value('SELECT forecast_finish FROM projects WHERE id = ?', [$projectId]));
        // N3 & S1 (drafter) belum punya PIC → NPD PIC diberi tahu saat proses aktif
        $this->assertNull($this->proc($projectId, $mold, 'N3')['pic_user_id']);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND project_id = ? AND dedupe_key LIKE ?', [$this->npd->id, $projectId, 'nopic:%']));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$this->npd->id, 'assigned:' . $this->proc($projectId, $mold, 'N1')['id'] . ':1']));
    }

    public function testNoMasterbatchSkipsGroupAndPlanFollows(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N1')['status']);
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N2')['status']);
        $this->assertNotEmpty($this->proc($projectId, $part, 'N1')['skip_reason']);
        // N5 hanya menunggu N4: N3 6–13 Okt, N4 14–19 Okt → N5 20 Okt
        $this->assertSame('2026-10-20', $this->proc($projectId, $part, 'N5')['planned_start']);
    }

    public function testNotFeasiblePartIsNotStarted(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'not_feasible']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [$this->partId($projectId, 1)]));
        $this->assertSame('cancelled', Db::value('SELECT status FROM project_parts WHERE id = ?', [$this->partId($projectId, 1)]));
        $this->assertSame('on_progress', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
    }

    // ------------------------------------------------------------------ penyelesaian, dokumen, approval, loop

    public function testCompleteActivatesSuccessorAndShiftsLateSchedule(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        Db::update('project_parts', ['drafter_pic_id' => $this->drafter->id], ['id' => $part]);
        Db::execute("UPDATE processes SET pic_user_id = ? WHERE part_id = ? AND code IN ('N3','N5')", [$this->drafter->id, $part]);

        // N3 (6 hk, rencana 6–13 Okt) selesai terlambat 15 Okt → N4 & turunannya bergeser 2 hk
        $this->reach($projectId, $part, 'N3');
        $oldN4 = $this->proc($projectId, $part, 'N4');
        Clock::freeze('2026-10-15 16:00:00');
        $result = $this->done($projectId, $part, 'N3', [], $this->drafter);
        $this->assertSame('continue', $result['effect']);
        $n4 = $this->proc($projectId, $part, 'N4');
        // FS: mulai hari kerja berikutnya setelah selesai (PRD §6.3) — aktif otomatis pada Planned Start (OQ-10)
        $this->assertSame('not_started', $n4['status']);
        $this->assertSame('2026-10-16', $n4['planned_start']);
        Clock::freeze('2026-10-16 06:00:00');
        $this->assertSame([(int) $n4['id']], $this->engine->activateReady($projectId)); // cron pagi, tanpa aktor
        $this->assertSame('current', $this->proc($projectId, $part, 'N4')['status']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND dedupe_key = ?', [$this->sales->id, 'assigned:' . $n4['id'] . ':1']));
        $this->assertSame(2, (new WorkingCalendar([1 => true, 2 => true, 3 => true, 4 => true, 5 => true], []))->deviation((string) $oldN4['planned_start'], (string) $n4['planned_start']));
        $this->assertGreaterThan(0, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'auto_shift'", [$projectId]));
        $this->assertSame('waiting_approval', Db::value('SELECT status FROM project_parts WHERE id = ?', [$part]), 'proses aktif approval customer');
        $this->assertSame('waiting', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'process.complete' AND entity_id = ?", [(int) $this->proc($projectId, $part, 'N3')['id']]));
        // KPI run N3: planned finish saat aktivasi 13 Okt, aktual 15 Okt, PIC drafter
        $run = Db::fetch('SELECT * FROM process_runs WHERE process_id = ?', [(int) $this->proc($projectId, $part, 'N3')['id']]);
        $this->assertSame(['2026-10-13', '2026-10-15', 'completed', $this->drafter->id], [$run['planned_finish_at_activation'], $run['actual_finish'], $run['status'], (int) $run['pic_user_id']]);
    }

    public function testDrafterCannotCompleteOthersProcess(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $this->expectException(AuthorizationException::class);
        $this->done($projectId, $this->partId($projectId), 'N3', [], $this->drafter); // PIC belum ditetapkan → bukan miliknya
    }

    public function testCustomerApprovalNotApprovedLoopsBackAndRecordsApproval(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $this->done($projectId, $part, 'N3');
        try {
            $this->done($projectId, $part, 'N4', ['outcome' => 'not_approved'], $this->sales);
            $this->fail('komentar wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->errors());
        }
        $r = $this->done($projectId, $part, 'N4', ['outcome' => 'not_approved', 'comment' => 'Bentuk leher kurang ramping'], $this->sales);
        $this->assertSame('loop', $r['effect']);
        $n3 = $this->proc($projectId, $part, 'N3');
        $n4 = $this->proc($projectId, $part, 'N4');
        $this->assertSame('revision', $n3['status']);
        $this->assertSame(2, (int) $n3['iteration']);
        $this->assertSame('not_started', $n4['status']);
        $this->assertSame('rejected', Db::value('SELECT status FROM approvals WHERE process_id = ?', [(int) $n4['id']]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'loop'", [$projectId]));
        // iterasi kedua disetujui → N5 aktif
        $this->done($projectId, $part, 'N3');
        $this->done($projectId, $part, 'N4', ['outcome' => 'approved'], $this->sales);
        $this->assertBecomesActive($projectId, $part, 'N5');
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM approvals WHERE process_id = ?', [(int) $n4['id']]));
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM process_runs WHERE process_id = ? AND status = 'completed'", [(int) $n3['id']]));
    }

    /** UAT-14: proses FF tidak dapat diselesaikan sebelum predecessor FF selesai. */
    public function testFinishToFinishBlocksEarlyCompletion(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $part = $this->partId($projectId);
        $n1 = (int) $this->proc($projectId, $part, 'N1')['id'];
        $n3 = (int) $this->proc($projectId, $part, 'N3')['id'];
        (new \App\Workflow\DependencyService())->save($this->npd, $n3, [['predecessor_id' => (string) $n1, 'type' => 'FF', 'lag' => '0']]);
        $this->reach($projectId, $part, 'N3');
        try {
            $this->engine->complete($this->npd, $n3);
            $this->fail('FF harus memblokir');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('N1', $e->getMessage());
        }
        $this->done($projectId, $part, 'N1');
        $this->done($projectId, $part, 'N3');
        $this->assertSame('completed', $this->proc($projectId, $part, 'N3')['status']);
    }

    /** UAT-15: Customer Artwork Approval Not Approved → kembali ke Artwork, iterasi +1, jadwal dihitung ulang. */
    public function testArtworkNotApprovedReturnsToArtwork(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['cap', 'subcont']], ['feasible']);
        $part = $this->partId($projectId);
        $this->done($projectId, $part, 'S1');
        $this->done($projectId, $part, 'S2', [], $this->sales);
        $before = $this->proc($projectId, $part, 'S4')['planned_start'];
        $this->done($projectId, $part, 'S3', ['outcome' => 'not_approved', 'comment' => 'Warna logo kurang tegas'], $this->sales);
        $s1 = $this->proc($projectId, $part, 'S1');
        $this->assertSame(['revision', 2], [$s1['status'], (int) $s1['iteration']]);
        $this->assertSame('not_started', $this->proc($projectId, $part, 'S2')['status']);
        $this->assertSame('not_started', $this->proc($projectId, $part, 'S3')['status']);
        $this->assertGreaterThan($before, $this->proc($projectId, $part, 'S4')['planned_start'], 'jadwal turunan bergeser');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'loop' AND process_id = ?", [$projectId, (int) $this->proc($projectId, $part, 'S4')['id']]));
    }

    public function testRequiredDocumentBlocksCompletion(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $this->done($projectId, $part, 'N3');
        $this->done($projectId, $part, 'N4', ['outcome' => 'approved']);
        try {
            $this->done($projectId, $part, 'N5');
            $this->fail('dokumen wajib');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Layout', $e->getMessage());
        }
        $this->uploadDoc((int) $this->proc($projectId, $part, 'N5')['id'], 'layout_decoration');
        $this->done($projectId, $part, 'N5');
        $this->assertBecomesActive($projectId, $part, 'N6');
    }

    public function testT0NotOkActivatesCorrectionAndReopensMachining(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $this->driveToT0($projectId, $part);
        $r = $this->done($projectId, $part, 'N8', ['outcome' => 't0_not_ok', 'comment' => 'Flash di parting line']);
        $this->assertSame('activate', $r['effect']);
        $this->assertSame('current', $this->proc($projectId, $part, 'N9')['status']);
        $n7 = $this->proc($projectId, $part, 'N7');
        $this->assertSame('not_started', $n7['status']);
        $this->assertSame((int) $this->proc($projectId, $part, 'N9')['id'], (int) $n7['loop_after_process_id']);
        $this->assertSame('not_started', $this->proc($projectId, $part, 'N8')['status']);
        $this->assertSame('waiting_external', Db::value('SELECT status FROM project_parts WHERE id = ?', [$part]));
        // Mold Correction selesai → Mold Machining aktif kembali → T0 diulang
        $this->done($projectId, $part, 'N9');
        $this->assertBecomesActive($projectId, $part, 'N7');
        $this->assertNull($this->proc($projectId, $part, 'N9')['loop_after_process_id']);
        $this->done($projectId, $part, 'N7');
        $this->assertBecomesActive($projectId, $part, 'N8');
        $this->done($projectId, $part, 'N8', ['outcome' => 't0_ok']);
        $this->assertBecomesActive($projectId, $part, 'N10');
        $this->assertSame('completed', $this->proc($projectId, $part, 'N9')['status'], 'iterasi koreksi tercatat selesai');
    }

    private function driveToT0(int $projectId, int $part): void
    {
        $this->done($projectId, $part, 'N3');
        $this->done($projectId, $part, 'N4', ['outcome' => 'approved']);
        $this->uploadDoc((int) $this->proc($projectId, $part, 'N5')['id'], 'layout_decoration');
        $this->done($projectId, $part, 'N5');
        $this->uploadDoc((int) $this->proc($projectId, $part, 'N6')['id'], 'technical_drawing');
        $this->done($projectId, $part, 'N6', ['outcome' => 'approved']);
        $this->done($projectId, $part, 'N7');
        $this->assertBecomesActive($projectId, $part, 'N8');
    }

    public function testCommissioningNgRepeatsWithProblemStatus(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $this->driveToT0($projectId, $part);
        $this->done($projectId, $part, 'N8', ['outcome' => 't0_ok']);
        $this->done($projectId, $part, 'N10');
        $this->done($projectId, $part, 'N11', ['outcome' => 'ng']);
        $n11 = $this->proc($projectId, $part, 'N11');
        $this->assertSame('problem', $n11['status']);
        $this->assertSame(2, (int) $n11['iteration']);
        $this->assertSame(1, (int) $n11['loop_count']);
    }

    // ------------------------------------------------------------------ Tidak dijalankan

    public function testSkipRulesFollowWorkEvidenceAndMandatory(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $part = $this->partId($projectId);
        $n1 = (int) $this->proc($projectId, $part, 'N1')['id'];
        $n3 = (int) $this->proc($projectId, $part, 'N3')['id'];
        $this->reach($projectId, $part, 'N3'); // N1 & N3 aktif otomatis
        // N3 aktif dan sudah ada pekerjaan (dokumen) → grup 3D tidak dapat dilewati
        $this->uploadDoc($n3, 'prototype_3d_document');
        try {
            $this->engine->skip($this->npd, (int) $this->proc($projectId, $part, 'N4')['id'], 'Customer tidak perlu 3D');
            $this->fail('N3 sudah dikerjakan');
        } catch (BusinessRuleException) {
            $this->assertSame('current', $this->proc($projectId, $part, 'N3')['status']);
            $this->assertSame('not_started', $this->proc($projectId, $part, 'N4')['status']);
        }
        // N1 aktif otomatis tanpa pekerjaan → boleh dilewati berpasangan dengan N2 (OQ-26)
        $this->engine->skip($this->npd, $n1, 'Warna standar customer');
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N1')['status']);
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N2')['status']);
        $this->assertNull($this->proc($projectId, $part, 'N1')['actual_start']);
        $this->assertSame('skipped', Db::value('SELECT status FROM process_runs WHERE process_id = ?', [$n1]), 'tidak dihitung KPI');
        // proses wajib tidak dapat dilewati
        try {
            $this->engine->skip($this->npd, (int) $this->proc($projectId, $part, 'N5')['id'], 'x');
            $this->fail('N5 wajib');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('wajib', $e->getMessage());
        }
        // alasan wajib
        $this->expectException(ValidationException::class);
        $this->engine->skip($this->npd, $n3, '  ');
    }

    public function testSkipGroupPassesThroughAuditsAndUnskipRules(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $part = $this->partId($projectId);
        $n3 = (int) $this->proc($projectId, $part, 'N3')['id'];

        $this->engine->skip($this->npd, $n3, 'Customer sudah punya sampel fisik');
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N3')['status']);
        $this->assertSame('skipped', $this->proc($projectId, $part, 'N4')['status'], 'berpasangan');
        // N5 kini hanya menunggu N2 (N1 6–14 Okt, N2 15–20 Okt) → 21 Okt
        $this->assertSame('2026-10-21', $this->proc($projectId, $part, 'N5')['planned_start']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'process.skip' AND entity_id = ?", [$n3]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'skip'", [$projectId]));

        // jalankan kembali: proses sesudahnya belum mulai → NPD boleh; N3 dijadwalkan lagi
        $this->engine->unskip($this->npd, $n3, 'Customer minta 3D');
        $this->assertSame('not_started', $this->proc($projectId, $part, 'N3')['status']);
        $this->assertSame('2026-10-06', $this->proc($projectId, $part, 'N3')['planned_start']);
        $this->assertSame('not_started', $this->proc($projectId, $part, 'N4')['status']);

        // lewati lagi, lalu N5 mulai → hanya Admin yang dapat menjalankan kembali
        $this->engine->skip($this->npd, $n3, 'Batal 3D');
        $this->done($projectId, $part, 'N1');
        $this->done($projectId, $part, 'N2', ['outcome' => 'approved']);
        $this->reach($projectId, $part, 'N5');
        $this->assertSame('current', $this->proc($projectId, $part, 'N5')['status']);
        try {
            $this->engine->unskip($this->npd, $n3, 'x');
            $this->fail('NPD tidak boleh');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Administrator', $e->getMessage());
        }
        $this->engine->unskip($this->admin, $n3, 'Koreksi Admin');
        $this->assertNotSame('skipped', $this->proc($projectId, $part, 'N3')['status']);
    }

    public function testStartEarlyCountsAsWorkForSkip(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $part = $this->partId($projectId);
        $n1 = (int) $this->proc($projectId, $part, 'N1')['id'];
        $this->engine->startEarly($this->npd, $n1); // Senin 5 Okt, Planned Start Selasa 6 Okt
        $row = $this->proc($projectId, $part, 'N1');
        $this->assertSame(['current', '2026-10-05'], [$row['status'], $row['actual_start']]);
        $this->assertFalse($this->engine->canSkipNow($this->engine->load($n1)));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'process.start_early' AND entity_id = ?", [$n1]));
        $this->expectException(BusinessRuleException::class);
        $this->engine->skip($this->npd, $n1, 'x');
    }

    public function testStartEarlyRequiresDependencies(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $this->expectException(BusinessRuleException::class);
        $this->engine->startEarly($this->npd, (int) $this->proc($projectId, $this->partId($projectId), 'N2')['id']);
    }

    public function testSalesCannotSkip(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $this->expectException(AuthorizationException::class);
        $this->engine->skip($this->sales, (int) $this->proc($projectId, $this->partId($projectId), 'N4')['id'], 'x');
    }

    // ------------------------------------------------------------------ gate & finish

    public function testGateFailReopensChosenPartsAndPassContinues(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible'], false);
        $mold = $this->partId($projectId, 0);
        $sub = $this->partId($projectId, 1);
        $this->driveToT0($projectId, $mold);
        $this->done($projectId, $mold, 'N8', ['outcome' => 't0_ok']);
        foreach (['S1', 'S2'] as $c) {
            $this->done($projectId, $sub, $c);
        }
        $this->done($projectId, $sub, 'S3', ['outcome' => 'approved']);
        foreach (['S4', 'S5', 'S6'] as $c) {
            $this->done($projectId, $sub, $c);
        }
        $this->assertSame('not_started', $this->proc($projectId, null, 'G1')['status'], 'gate menunggu S7');
        $this->done($projectId, $sub, 'S7', ['outcome' => 'approved']);
        $this->assertBecomesActive($projectId, null, 'G1', 'gate aktif setelah milestone semua part');

        // Gate fail tanpa memilih part → ditolak
        try {
            $this->done($projectId, null, 'G1', ['outcome' => 'fail', 'comment' => 'Tutup tidak rapat']);
            $this->fail('pilih part');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('gate_parts', $e->errors());
        }
        $s5 = (int) $this->proc($projectId, $sub, 'S5')['id'];
        $this->done($projectId, null, 'G1', ['outcome' => 'fail', 'comment' => 'Tutup tidak rapat', 'gate_parts' => [$sub => $s5]]);
        $this->assertSame('revision', $this->proc($projectId, $sub, 'S5')['status']);
        $this->assertSame('not_started', $this->proc($projectId, $sub, 'S7')['status']);
        $this->assertSame('not_started', $this->proc($projectId, null, 'G1')['status']);
        $this->assertSame('completed', $this->proc($projectId, $mold, 'N8')['status'], 'part yang tidak dipilih tidak diulang');
        $this->assertSame('fail', Db::value('SELECT result FROM project_gates WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]));

        $this->done($projectId, $sub, 'S5');
        $this->done($projectId, $sub, 'S6');
        $this->done($projectId, $sub, 'S7', ['outcome' => 'approved']);
        $this->done($projectId, null, 'G1', ['outcome' => 'pass']);
        $this->assertSame('pass', Db::value('SELECT result FROM project_gates WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]));
        // material preparation menunggu G1 — sekarang S8 selesai → S9 aktif
        $this->done($projectId, $sub, 'S8');
        $this->assertBecomesActive($projectId, $sub, 'S9');
    }

    public function testFinishPartAndProjectLifecycle(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['cap', 'subcont']], ['feasible'], false);
        $sub = $this->partId($projectId);
        $this->engine->skip($this->npd, (int) $this->proc($projectId, null, 'G1')['id'], 'Satu part, tidak perlu fit test');
        foreach (['S1', 'S2'] as $c) {
            $this->done($projectId, $sub, $c);
        }
        $this->done($projectId, $sub, 'S3', ['outcome' => 'approved']);
        foreach (['S4', 'S5', 'S6'] as $c) {
            $this->done($projectId, $sub, $c);
        }
        $this->done($projectId, $sub, 'S7', ['outcome' => 'approved']);
        $this->done($projectId, $sub, 'S8');
        $this->done($projectId, $sub, 'S9');
        $this->done($projectId, $sub, 'S10', ['outcome' => 'pass_with_condition', 'comment' => 'Minor flow mark diterima customer']);
        $this->done($projectId, $sub, 'S11');
        $this->assertSame('completed', Db::value('SELECT status FROM project_parts WHERE id = ?', [$sub]));
        $this->assertSame('ready_to_finish', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        $this->assertBecomesActive($projectId, null, 'PF');
        try {
            $this->done($projectId, null, 'PF', [], $this->sales);
            $this->fail('sales tidak boleh finish');
        } catch (AuthorizationException) {
            $this->assertSame('ready_to_finish', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        }
        $this->done($projectId, null, 'PF');
        $this->assertSame('completed', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        $this->assertNotNull(Db::value('SELECT finished_at FROM projects WHERE id = ?', [$projectId]));
    }

    public function testCompleteRejectsFutureDateAndInactiveProcess(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        try {
            $this->done($projectId, $part, 'N3', ['actual_finish' => '2026-10-09']);
            $this->fail('tanggal masa depan');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('actual_finish', $e->errors());
        }
        $this->expectException(BusinessRuleException::class);
        $this->done($projectId, $part, 'N5'); // belum aktif
    }

    public function testStaleLockVersionIsRejected(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $n3 = $this->proc($projectId, $part, 'N3');
        $this->expectException(\App\Core\ConflictException::class);
        $this->done($projectId, $part, 'N3', ['lock_version' => (int) $n3['lock_version'] + 5]);
    }

    public function testPlanChangesDurationAndShiftsSuccessorsWithPreview(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible'], false);
        $part = $this->partId($projectId);
        $n5 = (int) $this->proc($projectId, $part, 'N5')['id'];
        $before = $this->proc($projectId, $part, 'N6');
        $preview = $this->engine->previewPlan($this->npd, $n5, ['duration' => '6']);
        $codes = array_column($preview['changes'], 'code');
        $this->assertContains('N5', $codes);
        $this->assertContains('N6', $codes);
        $this->assertSame($before['planned_start'], $this->proc($projectId, $part, 'N6')['planned_start'], 'pratinjau tidak menyimpan');

        $this->engine->plan($this->npd, $n5, ['duration' => '6']);
        $after = $this->proc($projectId, $part, 'N6');
        $cal = new WorkingCalendar([1 => true, 2 => true, 3 => true, 4 => true, 5 => true], []);
        $this->assertSame(2, $cal->deviation((string) $before['planned_start'], (string) $after['planned_start']));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'process.plan' AND entity_id = ?", [$n5]));
        // proses paralel (N3/N4) tidak bergeser
        $this->assertSame('2026-10-06', $this->proc($projectId, $part, 'N3')['planned_start']);

        try {
            $this->engine->plan($this->npd, $n5, ['duration' => '0']);
            $this->fail('durasi tidak valid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('duration', $e->errors());
        }
        $this->expectException(AuthorizationException::class);
        $this->engine->plan($this->sales, $n5, ['duration' => '3']);
    }

    public function testPartCancelledAfterStartDetachesFromGate(): void
    {
        [$nprId, $nprParts, $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible'], false);
        $sub = $this->partId($projectId, 1);
        (new NprService())->cancelPart($this->admin, $nprId, $nprParts[1], 'Customer membatalkan tutup');
        $this->assertSame('cancelled', Db::value('SELECT status FROM project_parts WHERE id = ?', [$sub]));
        $g1 = (int) $this->proc($projectId, null, 'G1')['id'];
        $preds = Db::column('SELECT p.code FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? ORDER BY p.code', [$g1]);
        $this->assertSame(['N8', 'P2'], $preds);
        $this->assertSame('on_progress', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
    }
}
