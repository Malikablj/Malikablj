<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Approval\ApprovalService;
use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Document\DocumentService;
use App\Project\CommentService;
use App\Project\NextActionService;
use App\Record\RecordService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Dokumen, approval, catatan proses, next action, komentar (PRD §9). */
final class DocumentApprovalRecordTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $admin;
    private int $projectId;
    private int $mold;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->admin = $this->makeUser('admin');
        [, , $this->projectId] = $this->completedNpr($this->sales, $this->npd, $this->makeCustomer(), [['body', 'new_mold']], ['feasible'], false);
        $this->mold = (int) Db::value('SELECT id FROM project_parts WHERE project_id = ?', [$this->projectId]);
        $this->engine = new WorkflowEngine();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    private function pid(string $code): int
    {
        return (int) Db::value('SELECT id FROM processes WHERE part_id = ? AND code = ?', [$this->mold, $code]);
    }

    private function reach(string $code): void
    {
        for ($i = 0; $i < 3; $i++) {
            $p = Db::fetch('SELECT status, planned_start FROM processes WHERE id = ?', [$this->pid($code)]);
            if ($p['status'] !== 'not_started') {
                return;
            }
            if ($p['planned_start'] > Clock::todayString()) {
                Clock::freeze($p['planned_start'] . ' 08:00:00');
            }
            $this->engine->activateReady($this->projectId);
        }
    }

    /** File uji sementara (PNG kecil valid). @return array<string,mixed> */
    private function file(string $name = 'bukti.png'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        copy(APP_ROOT . '/tests/browser/fixtures/bottle.png', $tmp);
        return ['name' => $name, 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    public function testApprovalPendingOnActivationThenDecidedWithEvidence(): void
    {
        $this->reach('N3');
        $this->engine->complete($this->npd, $this->pid('N3'));
        $this->reach('N4');
        $pending = Db::fetch('SELECT * FROM approvals WHERE process_id = ?', [$this->pid('N4')]);
        $this->assertSame(['pending', 'customer', '3d', 1], [$pending['status'], $pending['giver'], $pending['approval_type'], (int) $pending['iteration']]);
        $this->assertNull($pending['requested_by'], 'diminta sistem (aktivasi otomatis)');
        $queue = (new ApprovalService())->search($this->sales, ['status' => 'pending']);
        $this->assertSame(1, $queue['total']);
        $this->assertTrue($queue['rows'][0]['can_decide'], 'Sales PIC project boleh mencatat approval customer');
        $other = $this->makeUser('admin_sales');
        $this->assertFalse((new ApprovalService())->search($other, ['status' => 'pending'])['rows'][0]['can_decide']);

        // dokumen yang dinilai + bukti approval
        $docs = new DocumentService();
        $reviewed = $docs->addProcessDocument($this->npd, $this->pid('N4'), 'prototype_3d_document', $this->file('render.png'), null, false);
        $evidence = $docs->addProcessDocument($this->sales, $this->pid('N4'), 'approval', $this->file('email.png'), 'Bukti', false);
        $this->engine->complete($this->sales, $this->pid('N4'), ['outcome' => 'approved', 'decision_maker' => 'Ibu Rina', 'evidence_document_id' => $evidence]);
        $a = Db::fetch('SELECT * FROM approvals WHERE id = ?', [(int) $pending['id']]);
        $this->assertSame('approved', $a['status']);
        $this->assertSame($evidence, (int) $a['evidence_document_id']);
        $reviewedVersion = (int) Db::value('SELECT current_version_id FROM documents WHERE id = ?', [$reviewed]);
        $this->assertSame($reviewedVersion, (int) $a['document_version_id']);
        $this->assertSame('approved', Db::value('SELECT status FROM document_versions WHERE id = ?', [$reviewedVersion]));
        $this->assertSame(['request', 'decide'], Db::column('SELECT action FROM approval_history WHERE approval_id = ? ORDER BY id', [(int) $pending['id']]));
    }

    public function testPendingWithdrawnWhenProcessReset(): void
    {
        $this->reach('N3');
        $this->engine->complete($this->npd, $this->pid('N3'));
        $this->reach('N4');
        $this->engine->manualMove($this->admin, $this->pid('N4'), 'not_started', 'Salah aktivasi', '2026-12-01');
        $a = Db::fetch('SELECT * FROM approvals WHERE process_id = ?', [$this->pid('N4')]);
        $this->assertSame('revision_required', $a['status']);
        $this->assertSame('withdraw', Db::value('SELECT action FROM approval_history WHERE approval_id = ? ORDER BY id DESC LIMIT 1', [(int) $a['id']]));
    }

    public function testDocumentVersioningSearchAndRemoval(): void
    {
        $docs = new DocumentService();
        $n5 = $this->pid('N5');
        $d1 = $docs->addProcessDocument($this->npd, $n5, 'layout_decoration', $this->file('layout-a.png'), null, false);
        $d2 = $docs->addProcessDocument($this->npd, $n5, 'layout_decoration', $this->file('layout-b.png'), 'revisi warna', false);
        $this->assertSame($d1, $d2, 'jenis sama = versi baru');
        $versions = $docs->versions($d1);
        $this->assertSame([2, 1], array_map('intval', array_column($versions, 'version_no')));
        $this->assertSame(['current', 'superseded'], array_column($versions, 'status'));
        $found = $docs->search($this->sales, ['project_id' => $this->projectId, 'doc_type' => 'layout_decoration']);
        $this->assertSame(1, $found['total']);
        $this->assertSame('layout-b.png', $found['rows'][0]['original_name']);
        $this->assertSame(0, $docs->search($this->sales, ['status' => 'approved'])['total']);
        try {
            $docs->removeDocument($this->npd, $d1, 'salah');
            $this->fail('hanya Admin');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $docs->removeDocument($this->admin, $d1, 'Salah unggah');
        $this->assertSame(0, $docs->search($this->admin, ['project_id' => $this->projectId, 'doc_type' => 'layout_decoration'])['total']);
        $this->assertSame(1, $docs->search($this->admin, ['project_id' => $this->projectId, 'removed' => true])['total']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'document.remove' AND entity_id = ?", [(string) $d1]));
        // drafter bukan PIC tidak boleh mengunggah ke proses NPD
        $this->expectException(AuthorizationException::class);
        $docs->addProcessDocument($this->makeUser('drafter'), $this->pid('N6'), 'technical_drawing', $this->file(), null, false);
    }

    public function testTrialAndValidationRecordsPerIterationAndSync(): void
    {
        $rec = new RecordService();
        $prod = $this->makeUser('production');
        $n8 = $this->pid('N8');
        $id = $rec->saveIteration($prod, $n8, ['trial_date' => '2026-12-07', 'machine' => 'ASB-70', 'cavity' => '4', 'problems' => 'Flash di parting line']);
        $id2 = $rec->saveIteration($prod, $n8, ['evaluation' => 'Perlu koreksi mold']);
        $this->assertSame($id, $id2, 'satu catatan per iterasi');
        $row = Db::fetch('SELECT * FROM trial_records WHERE id = ?', [$id]);
        $this->assertSame(['t0', 'ASB-70', 'Perlu koreksi mold', 1], [$row['trial_type'], $row['machine'], $row['evaluation'], (int) $row['iteration']]);
        try {
            $rec->saveIteration($prod, $n8, ['trial_date' => '2026-02-30']);
            $this->fail('tanggal tidak valid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('trial_date', $e->errors());
        }
        try {
            $rec->saveIteration($this->sales, $n8, ['machine' => 'x']);
            $this->fail('Sales tidak berhak');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        // validasi: hasil mengikuti keputusan proses saat diselesaikan
        $n13 = $this->pid('N13');
        $rec->saveIteration($prod, $n13, ['validation_date' => '2027-01-04', 'production_qty' => '5000 pcs']);
        Db::update('processes', ['status' => 'current', 'actual_start' => Clock::todayString()], ['id' => $n13]);
        Db::execute('UPDATE process_dependencies SET dep_type = ? WHERE process_id = ?', ['PARALLEL', $n13]);
        $this->engine->complete($this->npd, $n13, ['outcome' => 'pass_with_condition', 'comment' => 'Minor flow mark']);
        $this->assertSame('pass_with_condition', Db::value('SELECT result FROM validation_records WHERE process_id = ?', [$n13]));
        $this->assertSame(3, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('record.create', 'record.update') AND project_id = ?", [$this->projectId]));
    }

    public function testMaterialRecordsPurchasingCanUpdate(): void
    {
        $rec = new RecordService();
        $purch = $this->makeUser('purchasing');
        $n12 = $this->pid('N12');
        $id = $rec->saveMaterial($purch, $n12, ['material' => 'HDPE 5502', 'batch_no' => 'B-778', 'quantity_kg' => '250,5', 'received_date' => '2026-12-28', 'supplier' => 'PT Resin', 'pic_user_id' => (string) $purch->id]);
        $this->assertSame('250.500', Db::value('SELECT quantity_kg FROM material_requests WHERE id = ?', [$id]));
        $this->assertSame('preparation', Db::value('SELECT request_type FROM material_requests WHERE id = ?', [$id]));
        $rec->saveMaterial($purch, $n12, ['material_received' => 'HDPE 5502 (lot 2)'], $id);
        $this->assertSame('HDPE 5502 (lot 2)', Db::value('SELECT material_received FROM material_requests WHERE id = ?', [$id]));
        $rec->saveMaterial($purch, $n12, ['material' => 'Masterbatch putih', 'quantity_kg' => '12']);
        $this->assertCount(2, $rec->forProcess(Db::fetch('SELECT * FROM processes WHERE id = ?', [$n12])));
        $all = $rec->forProject($this->projectId);
        $this->assertCount(2, $all['material']);
        try {
            $rec->saveMaterial($purch, $n12, ['quantity_kg' => '-3']);
            $this->fail('angka tidak valid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity_kg', $e->errors());
        }
        $this->expectException(BusinessRuleException::class);
        $rec->saveMaterial($this->npd, $this->pid('N8'), ['material' => 'x']); // N8 bukan proses material (otorisasi dicek lebih dulu)
    }

    public function testNextActionReplaceCompleteAndPermissions(): void
    {
        $svc = new NextActionService();
        $owner = $this->makeUser('purchasing');
        $id1 = $svc->set($this->sales, $this->projectId, $this->mold, ['description' => 'Kirim sampel warna ke customer', 'due_date' => '2026-10-09', 'owner_user_id' => (string) $owner->id, 'waiting_for' => 'customer']);
        $id2 = $svc->set($this->npd, $this->projectId, $this->mold, ['description' => 'Follow-up persetujuan warna', 'waiting_for' => 'customer', 'waiting_for_note' => 'Ibu Rina']);
        $this->assertSame('cancelled', Db::value('SELECT status FROM next_actions WHERE id = ?', [$id1]));
        $open = $svc->open($this->projectId);
        $this->assertCount(1, $open);
        $this->assertSame($id2, (int) $open[0]['id']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'next_action'", [$owner->id]));
        $svc->complete($this->npd, $id2);
        $this->assertSame([], $svc->open($this->projectId));
        try {
            $svc->set($this->makeUser('admin_sales'), $this->projectId, null, ['description' => 'x']);
            $this->fail('Sales lain tidak berhak');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        $svc->set($this->npd, $this->projectId, null, ['description' => '', 'waiting_for' => 'mars']);
    }

    public function testCommentsNotifyPicAndRejectManagement(): void
    {
        $svc = new CommentService();
        $drafter = $this->makeUser('drafter');
        Db::update('processes', ['pic_user_id' => $drafter->id], ['id' => $this->pid('N3')]);
        $svc->add($this->sales, $this->projectId, $this->pid('N3'), 'Customer minta leher lebih ramping');
        $this->assertCount(1, $svc->forProcess($this->pid('N3')));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'comment'", [$drafter->id]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'comment'", [$this->npd->id]));
        $this->expectException(AuthorizationException::class);
        $svc->add($this->makeUser('management'), $this->projectId, null, 'x');
    }

    public function testNprAttachmentBecomesProjectDocument(): void
    {
        // lampiran NPR yang diunggah saat draft memperoleh project_id saat NPR dikirim
        $nprId = (int) Db::value('SELECT npr_id FROM projects WHERE id = ?', [$this->projectId]);
        $count = (int) Db::value('SELECT COUNT(*) FROM documents WHERE npr_id = ? AND project_id IS NULL', [$nprId]);
        $this->assertSame(0, $count);
    }
}
