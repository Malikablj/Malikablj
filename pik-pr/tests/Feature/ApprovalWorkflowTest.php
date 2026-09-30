<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Services\ApprovalService;
use App\Services\PrService;
use Tests\TestCase;

final class ApprovalWorkflowTest extends TestCase
{
    /**
     * Membuat & submit PR sebagai Mitha lewat service, lalu mengembalikan ID-nya.
     */
    private function submittedPr(array $overrides = []): int
    {
        $mitha = $this->user('mitha@pik.local');
        $service = new PrService();
        $id = $service->create($mitha, $this->prInput($overrides));
        $service->submit($mitha, $id);

        return $id;
    }

    public function test_two_level_approval_diketahui_then_disetujui(): void
    {
        $id = $this->submittedPr();

        $this->loginAs('budi@pik.local');
        $queue = $this->get('/approvals');
        self::assertStringContainsString((string) $this->pr($id)['pr_number'], $queue->body);

        $response = $this->post('/pr/' . $id . '/approve', ['comment' => 'Sudah dicek']);
        self::assertRedirectTo('/pr/' . $id, $response);
        $pr = $this->pr($id);
        self::assertSame('in_review', $pr['status']);
        self::assertSame('Disetujui', $pr['current_step_label']);
        $this->logout();

        $this->loginAs('hendra@pik.local');
        $this->post('/pr/' . $id . '/approve');
        $pr = $this->pr($id);
        self::assertSame('approved', $pr['status']);
        self::assertNull($pr['current_step_id']);
        self::assertNotNull($pr['approved_at']);

        $logs = Database::connection()->query("SELECT step_label, action, comment, status_after FROM approval_logs WHERE pr_id = {$id} ORDER BY step_order")->fetchAll();
        self::assertSame([
            ['step_label' => 'Diketahui', 'action' => 'approved', 'comment' => 'Sudah dicek', 'status_after' => 'in_review'],
            ['step_label' => 'Disetujui', 'action' => 'approved', 'comment' => null, 'status_after' => 'approved'],
        ], $logs);

        $mitha = $this->user('mitha@pik.local');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ? AND type = 'pr_approved'", [$mitha['id'], $id]));
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.approve' AND entity_id = ?", [$id]));
    }

    public function test_second_approver_is_notified_only_after_first_step(): void
    {
        $id = $this->submittedPr();
        $hendra = $this->user('hendra@pik.local');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ?", [$hendra['id'], $id]));

        (new ApprovalService())->approve($this->user('budi@pik.local'), $id);

        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ? AND type = 'approval_required'", [$hendra['id'], $id]));
    }

    public function test_approver_cannot_act_on_step_that_is_not_theirs(): void
    {
        $id = $this->submittedPr();
        $this->loginAs('hendra@pik.local');

        self::assertSame(403, $this->post('/pr/' . $id . '/approve')->status);
        self::assertSame(403, $this->post('/pr/' . $id . '/reject', ['comment' => 'x'])->status);
        self::assertSame('submitted', $this->pr($id)['status']);
        self::assertStringNotContainsString((string) $this->pr($id)['pr_number'], $this->get('/approvals')->body);
    }

    public function test_requester_cannot_approve(): void
    {
        $id = $this->submittedPr();
        $this->loginAs('mitha@pik.local');

        self::assertSame(403, $this->post('/pr/' . $id . '/approve')->status);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM approval_logs WHERE pr_id = ?', [$id]));
    }

    public function test_admin_cannot_approve_unless_assigned(): void
    {
        $id = $this->submittedPr();
        $this->loginAs('admin@pik.local');

        self::assertSame(200, $this->get('/pr/' . $id)->status, 'Admin dapat melihat seluruh PR');
        self::assertSame(403, $this->post('/pr/' . $id . '/approve')->status);
    }

    public function test_decision_cannot_be_made_twice(): void
    {
        $id = $this->submittedPr();
        $this->loginAs('budi@pik.local');
        $this->post('/pr/' . $id . '/approve');

        self::assertSame(403, $this->post('/pr/' . $id . '/approve')->status);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM approval_logs WHERE pr_id = ?', [$id]));
    }

    public function test_reject_requires_reason_and_is_final(): void
    {
        $id = $this->submittedPr();
        $this->loginAs('budi@pik.local');

        $this->post('/pr/' . $id . '/reject', ['comment' => '  ']);
        self::assertSame('submitted', $this->pr($id)['status']);
        self::assertArrayHasKey('comment', (array) $this->flashed('errors'));

        $this->post('/pr/' . $id . '/reject', ['comment' => 'Anggaran tidak tersedia']);
        $pr = $this->pr($id);
        self::assertSame('rejected', $pr['status']);
        self::assertNull($pr['current_step_id']);
        self::assertSame('Anggaran tidak tersedia', $this->scalar("SELECT comment FROM approval_logs WHERE pr_id = ? AND action = 'rejected'", [$id]));
        $this->logout();

        $this->loginAs('mitha@pik.local');
        $this->post('/pr/' . $id . '/submit');
        self::assertSame('rejected', $this->pr($id)['status'], 'PR ditolak tidak dapat disubmit ulang');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE pr_id = ? AND type = 'pr_rejected'", [$id]));
    }

    public function test_revision_cycle_keeps_history_and_number(): void
    {
        $id = $this->submittedPr();
        $number = $this->pr($id)['pr_number'];

        $this->loginAs('budi@pik.local');
        $this->post('/pr/' . $id . '/revision', ['comment' => '']);
        self::assertSame('submitted', $this->pr($id)['status'], 'Komentar revisi wajib');
        $this->post('/pr/' . $id . '/revision', ['comment' => 'Tambahkan pembanding harga']);
        self::assertSame('revision_required', $this->pr($id)['status']);
        $this->logout();

        $this->loginAs('mitha@pik.local');
        $form = $this->get('/pr/' . $id . '/edit');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('Tambahkan pembanding harga', $form->body);

        $input = $this->prInput(['notes' => 'Sudah ditambah pembanding']);
        $input['items'][1]['unit_price'] = '45000';
        $this->post('/pr/' . $id, $input);
        $this->post('/pr/' . $id . '/submit');

        $pr = $this->pr($id);
        self::assertSame('submitted', $pr['status']);
        self::assertSame($number, $pr['pr_number'], 'Nomor PR tidak berubah setelah revisi');
        self::assertSame(2, (int) $pr['submission_round']);
        self::assertSame('Diketahui', $pr['current_step_label'], 'Approval dimulai lagi dari tahap pertama');
        self::assertSame('265000.00', $pr['grand_total']);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.resubmit' AND entity_id = ?", [$id]));
        $this->logout();

        $this->loginAs('budi@pik.local');
        $this->post('/pr/' . $id . '/approve', ['comment' => 'OK sekarang']);
        $logs = Database::connection()->query("SELECT submission_round, action FROM approval_logs WHERE pr_id = {$id} ORDER BY id")->fetchAll();
        self::assertSame([
            ['submission_round' => 1, 'action' => 'revision_required'],
            ['submission_round' => 2, 'action' => 'approved'],
        ], $logs, 'Riwayat approval putaran pertama tidak hilang');

        $detail = $this->get('/pr/' . $id);
        self::assertStringContainsString('Tambahkan pembanding harga', $detail->body);
        self::assertStringContainsString('OK sekarang', $detail->body);
    }

    public function test_approval_logs_cannot_duplicate_a_step_in_same_round(): void
    {
        $id = $this->submittedPr();
        (new ApprovalService())->approve($this->user('budi@pik.local'), $id);
        $step = (int) $this->scalar('SELECT step_id FROM approval_logs WHERE pr_id = ?', [$id]);

        $this->expectException(\PDOException::class);
        Database::connection()->prepare(
            "INSERT INTO approval_logs (pr_id, step_id, submission_round, step_order, step_label, approver_id, action, status_after)
            VALUES (?, ?, 1, 1, 'Diketahui', ?, 'approved', 'in_review')",
        )->execute([$id, $step, $this->user('hendra@pik.local')['id']]);
    }

    public function test_department_specific_role_based_workflow(): void
    {
        $admin = $this->loginAs('admin@pik.local');
        $qc = (int) $this->scalar("SELECT id FROM departments WHERE code = 'QC'");
        $response = $this->post('/approval-workflows', [
            'name' => 'Workflow QC',
            'department_id' => (string) $qc,
            'is_active' => '1',
            'steps' => [
                ['label' => 'Diketahui', 'approver_type' => 'role', 'approver_role' => 'approver', 'same_department' => '1', 'is_required' => '1'],
                ['label' => 'Disetujui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('hendra@pik.local')['id'], 'is_required' => '1'],
            ],
        ]);
        self::assertRedirectTo('/approval-workflows', $response);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'workflow.create' AND user_id = ?", [$admin['id']]));

        // QC belum punya approver -> submit ditolak dengan pesan jelas.
        $dewi = $this->user('dewi@pik.local');
        $service = new PrService();
        $id = $service->create($dewi, $this->prInput(['department_id' => (string) $qc]));
        try {
            $service->submit($dewi, $id);
            self::fail('Submit seharusnya ditolak karena tidak ada approver QC');
        } catch (\App\Core\BusinessRuleException $e) {
            self::assertStringContainsString('belum memiliki approver', $e->getMessage());
        }

        // Tambah approver QC -> workflow QC dipakai, bukan default.
        $userId = (new \App\Services\UserService())->create($admin, [
            'name' => 'Sari QC', 'email' => 'sari@pik.local', 'role' => 'approver', 'department_id' => (string) $qc, 'password' => 'Rahasia123',
        ]);
        $pr = $service->submit($dewi, $id);
        self::assertSame('Workflow QC', $pr['workflow_name']);
        self::assertStringContainsString('-QCPR', (string) $pr['pr_number']);

        $budi = $this->user('budi@pik.local');
        self::assertFalse((new ApprovalService())->canAct($budi, $pr), 'Approver PD tidak berhak pada tahap QC');
        self::assertTrue((new ApprovalService())->canAct($this->user('sari@pik.local'), $pr));
        self::assertSame($userId, (int) $this->user('sari@pik.local')['id']);
    }

    public function test_conditional_step_applies_only_above_minimum_amount(): void
    {
        $admin = $this->user('admin@pik.local');
        $workflowId = (int) $this->scalar('SELECT id FROM approval_workflows WHERE department_id IS NULL AND is_active = 1');
        (new \App\Services\WorkflowService())->update($admin, $workflowId, [
            'name' => 'Workflow Default PIK',
            'is_active' => '1',
            'steps' => [
                ['label' => 'Diketahui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('budi@pik.local')['id'], 'is_required' => '1'],
                ['label' => 'Disetujui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('hendra@pik.local')['id'], 'is_required' => '1'],
            ],
        ]);

        // Workflow sudah dipakai -> jumlah tahap tidak boleh berubah.
        try {
            (new \App\Services\WorkflowService())->update($admin, $workflowId, [
                'name' => 'Workflow Default PIK', 'is_active' => '1',
                'steps' => [['label' => 'Diketahui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('budi@pik.local')['id'], 'is_required' => '1']],
            ]);
            self::fail('Struktur workflow yang sudah dipakai seharusnya terkunci');
        } catch (\App\Core\BusinessRuleException $e) {
            self::assertStringContainsString('jumlah tahap tidak dapat diubah', $e->getMessage());
        }

        // Workflow baru dengan tahap "Final" kondisional >= Rp1.000.000 oleh Super Admin.
        Database::connection()->exec('UPDATE approval_workflows SET is_active = 0 WHERE id = ' . $workflowId);
        (new \App\Services\WorkflowService())->create($admin, [
            'name' => 'Workflow Bertingkat',
            'is_active' => '1',
            'steps' => [
                ['label' => 'Diketahui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('budi@pik.local')['id'], 'is_required' => '1'],
                ['label' => 'Disetujui', 'approver_type' => 'user', 'approver_user_id' => (string) $this->user('hendra@pik.local')['id'], 'is_required' => '1'],
                ['label' => 'Final', 'approver_type' => 'role', 'approver_role' => 'super_admin', 'is_required' => '0', 'min_amount' => '1000000'],
            ],
        ]);

        $small = $this->submittedPr();
        $big = $this->submittedPr(['items' => [['name' => 'Mesin kecil', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '2500000']]]);
        $approvals = new ApprovalService();
        foreach ([$small, $big] as $id) {
            $approvals->approve($this->user('budi@pik.local'), $id);
            $approvals->approve($this->user('hendra@pik.local'), $id);
        }

        self::assertSame('approved', $this->pr($small)['status'], 'PR kecil tidak perlu tahap Final');
        self::assertSame('in_review', $this->pr($big)['status']);
        self::assertSame('Final', $this->pr($big)['current_step_label']);

        $approvals->approve($this->user('superadmin@pik.local'), $big, 'Disetujui direksi');
        self::assertSame('approved', $this->pr($big)['status']);
    }

    public function test_only_one_active_workflow_per_department(): void
    {
        $this->loginAs('admin@pik.local');
        $this->post('/approval-workflows', [
            'name' => 'Default kedua',
            'is_active' => '1',
            'steps' => [['label' => 'Diketahui', 'approver_type' => 'role', 'approver_role' => 'approver', 'is_required' => '1']],
        ]);

        self::assertArrayHasKey('is_active', (array) $this->flashed('errors'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM approval_workflows WHERE department_id IS NULL AND is_active = 1'));
    }

    public function test_workflow_rejects_same_user_on_two_steps(): void
    {
        $this->loginAs('admin@pik.local');
        $budi = (string) $this->user('budi@pik.local')['id'];
        $this->post('/approval-workflows', [
            'name' => 'Salah',
            'is_active' => '0',
            'steps' => [
                ['label' => 'A', 'approver_type' => 'user', 'approver_user_id' => $budi, 'is_required' => '1'],
                ['label' => 'B', 'approver_type' => 'user', 'approver_user_id' => $budi, 'is_required' => '1'],
            ],
        ]);

        self::assertArrayHasKey('steps.1.approver_user_id', (array) $this->flashed('errors'));
    }

    public function test_approver_sees_only_relevant_prs(): void
    {
        $draftId = (new PrService())->create($this->user('mitha@pik.local'), $this->prInput());
        $this->loginAs('hendra@pik.local');

        self::assertSame(403, $this->get('/pr/' . $draftId)->status, 'Approver tidak melihat draft orang lain');
        $list = $this->get('/pr');
        self::assertStringNotContainsString('Draft #' . $draftId, $list->body);
        self::assertStringContainsString('PDPR003', $list->body, 'PR yang menunggu Hendra terlihat');
    }
}
