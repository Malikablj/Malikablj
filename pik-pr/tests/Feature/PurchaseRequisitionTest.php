<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use Tests\TestCase;

final class PurchaseRequisitionTest extends TestCase
{
    public function test_requester_can_open_create_form(): void
    {
        $this->loginAs('mitha@pik.local');
        $response = $this->get('/pr/create');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Buat Purchase Requisition', $response->body);
        self::assertStringContainsString('Lem korea', $response->body, 'Master item tersedia di datalist');
        self::assertStringContainsString('PR/PIK/', $response->body, 'Pratinjau format nomor PR');
    }

    public function test_create_draft_with_multiple_items_and_server_side_totals(): void
    {
        $user = $this->loginAs('mitha@pik.local');
        $before = (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions');

        // Nilai total yang dikirim browser diabaikan; server menghitung ulang.
        $response = $this->post('/pr', $this->prInput(['subtotal' => '1', 'grand_total' => '1', 'action' => 'draft']));

        $id = $this->latestPrId();
        self::assertRedirectTo('/pr/' . $id . '/edit', $response);
        self::assertSame($before + 1, (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions'));

        $pr = $this->pr($id);
        self::assertSame('draft', $pr['status']);
        self::assertNull($pr['pr_number'], 'Nomor PR baru terbit saat submit');
        self::assertSame((int) $user['id'], (int) $pr['requester_id']);
        self::assertSame('280000.00', $pr['subtotal']);
        self::assertSame('0.00', $pr['tax_amount']);
        self::assertSame('280000.00', $pr['grand_total']);

        $lines = \App\Core\Database::connection()->query("SELECT item_name_snapshot, quantity, unit_price, line_total FROM purchase_requisition_items WHERE pr_id = {$id} ORDER BY line_no")->fetchAll();
        self::assertSame([
            ['item_name_snapshot' => 'Lem korea', 'quantity' => '2.00', 'unit_price' => '65000.00', 'line_total' => '130000.00'],
            ['item_name_snapshot' => 'Autosol', 'quantity' => '3.00', 'unit_price' => '50000.00', 'line_total' => '150000.00'],
        ], $lines);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.create' AND entity_id = ?", [$id]));
    }

    public function test_calculation_with_tax_and_decimal_quantity_uses_half_up_rounding(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput([
            'tax_rate' => '11',
            'items' => [
                ['name' => 'Kabel', 'quantity' => '1.5', 'unit' => 'm', 'unit_price' => '33333.33'],   // 49.999,995 -> 50.000,00
                ['name' => 'Baut', 'quantity' => '3', 'unit' => 'pcs', 'unit_price' => '1250,50'],     // koma desimal diterima
            ],
        ]));
        $pr = $this->pr($this->latestPrId());

        self::assertSame('53751.50', $pr['subtotal']);   // 50.000,00 + 3.751,50
        self::assertSame('5912.67', $pr['tax_amount']);  // 5.912,665 -> 5.912,67
        self::assertSame('59664.17', $pr['grand_total']);
        self::assertSame('11.00', $pr['tax_rate']);
    }

    public function test_empty_rows_are_ignored(): void
    {
        $this->loginAs('mitha@pik.local');
        $input = $this->prInput();
        $input['items'][] = ['name' => '', 'quantity' => '', 'unit' => 'pcs', 'unit_price' => ''];
        $this->post('/pr', $input);

        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisition_items WHERE pr_id = ?', [$this->latestPrId()]));
    }

    public function test_invalid_items_are_rejected_without_saving_anything(): void
    {
        $this->loginAs('mitha@pik.local');
        $before = (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions');

        $response = $this->post('/pr', $this->prInput(['items' => [
            ['name' => '', 'quantity' => '2', 'unit' => 'pcs', 'unit_price' => '1000'],
            ['name' => 'Lem', 'quantity' => '0', 'unit' => 'pcs', 'unit_price' => '1000'],
            ['name' => 'Lem', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '-5'],
            ['name' => 'Lem', 'quantity' => '1.234', 'unit' => 'pcs', 'unit_price' => '10'],
        ]]));

        self::assertTrue($response->isRedirect());
        $errors = (array) $this->flashed('errors');
        self::assertArrayHasKey('items.0.name', $errors);
        self::assertArrayHasKey('items.1.quantity', $errors);
        self::assertArrayHasKey('items.2.unit_price', $errors);
        self::assertArrayHasKey('items.3.quantity', $errors);
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions'));
        self::assertSame('Lem', $this->flashed('old')['items'][1]['name'], 'Input lama dikembalikan ke form');
    }

    public function test_tax_rate_must_be_between_zero_and_hundred(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(['tax_rate' => '150']));

        self::assertArrayHasKey('tax_rate', (array) $this->flashed('errors'));
    }

    public function test_line_total_overflow_is_rejected(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(['items' => [
            ['name' => 'Mesin', 'quantity' => '999999', 'unit' => 'unit', 'unit_price' => '999999999'],
        ]]));

        self::assertArrayHasKey('items.0.unit_price', (array) $this->flashed('errors'));
    }

    public function test_requester_can_edit_own_draft(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();

        self::assertSame(200, $this->get('/pr/' . $id . '/edit')->status);

        $input = $this->prInput(['tax_rate' => '11', 'notes' => 'Diperbarui']);
        $input['items'][0]['quantity'] = '4';
        $response = $this->post('/pr/' . $id, $input + ['action' => 'review']);

        self::assertRedirectTo('/pr/' . $id . '?review=1', $response);
        $pr = $this->pr($id);
        self::assertSame('410000.00', $pr['subtotal']);
        self::assertSame('45100.00', $pr['tax_amount']);
        self::assertSame('455100.00', $pr['grand_total']);
        self::assertSame('Diperbarui', $pr['notes']);

        $audit = (string) $this->scalar("SELECT new_values FROM audit_logs WHERE action = 'pr.update' AND entity_id = ?", [$id]);
        self::assertStringContainsString('455100.00', $audit);

        $review = $this->get('/pr/' . $id, ['review' => '1']);
        self::assertStringContainsString('Review sebelum submit', $review->body);
    }

    public function test_submit_assigns_unique_number_and_starts_workflow(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();

        $response = $this->post('/pr/' . $id . '/submit');

        self::assertRedirectTo('/pr/' . $id, $response);
        $pr = $this->pr($id);
        self::assertSame('submitted', $pr['status']);
        $month = ['JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGS', 'SEPT', 'OKT', 'NOV', 'DES'][(int) date('n') - 1];
        self::assertMatchesRegularExpression('#^PR/PIK/' . $month . '/' . date('Y') . '-PDPR\d{3}$#', (string) $pr['pr_number']);
        self::assertSame('Diketahui', $pr['current_step_label']);
        self::assertSame(1, (int) $pr['submission_round']);
        self::assertNotNull($pr['submitted_at']);

        $budi = $this->user('budi@pik.local');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ? AND type = 'approval_required'", [$budi['id'], $id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ? AND type = 'pr_submitted'", [$pr['requester_id'], $id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.submit' AND entity_id = ?", [$id]));
    }

    public function test_sequential_submissions_get_consecutive_numbers(): void
    {
        $this->loginAs('mitha@pik.local');
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $this->post('/pr', $this->prInput());
            $id = $this->latestPrId();
            $this->post('/pr/' . $id . '/submit');
            $numbers[] = (int) substr((string) $this->pr($id)['pr_number'], -3);
        }

        self::assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers);
    }

    public function test_submit_requires_supplier_and_items(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(['supplier_id' => '', 'items' => []]));
        $id = $this->latestPrId();

        $this->post('/pr/' . $id . '/submit');

        self::assertSame('draft', $this->pr($id)['status']);
        self::assertNull($this->pr($id)['pr_number']);
        $error = (string) $this->flashed('error');
        self::assertStringContainsString('Supplier belum dipilih', $error);
        self::assertStringContainsString('belum memiliki item', $error);
    }

    public function test_submitted_pr_can_no_longer_be_edited(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();
        $this->post('/pr/' . $id . '/submit');

        self::assertRedirectTo('/pr/' . $id, $this->get('/pr/' . $id . '/edit'));
        $this->post('/pr/' . $id, $this->prInput(['notes' => 'Diubah diam-diam']));

        self::assertStringContainsString('tidak dapat diubah', (string) $this->flashed('error'));
        self::assertSame('Test PR', $this->pr($id)['notes']);
    }

    public function test_other_requester_cannot_view_or_edit(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();
        $this->logout();

        $this->loginAs('dewi@pik.local');
        self::assertSame(403, $this->get('/pr/' . $id)->status);
        self::assertSame(403, $this->get('/pr/' . $id . '/edit')->status);
        self::assertSame(403, $this->post('/pr/' . $id, $this->prInput())->status);
        self::assertSame(403, $this->post('/pr/' . $id . '/submit')->status);
        self::assertSame('draft', $this->pr($id)['status']);
    }

    public function test_requester_can_cancel_own_submitted_pr_with_reason(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();
        $this->post('/pr/' . $id . '/submit');

        $this->post('/pr/' . $id . '/cancel', ['reason' => '']);
        self::assertSame('submitted', $this->pr($id)['status'], 'Alasan wajib untuk PR yang sudah diajukan');

        $this->post('/pr/' . $id . '/cancel', ['reason' => 'Tidak jadi dibeli']);
        $pr = $this->pr($id);
        self::assertSame('cancelled', $pr['status']);
        self::assertSame('Tidak jadi dibeli', $pr['cancel_reason']);
        self::assertNull($pr['current_step_id']);

        $budi = $this->user('budi@pik.local');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND pr_id = ? AND type = 'pr_cancelled'", [$budi['id'], $id]));
    }

    public function test_failed_save_rolls_back_whole_transaction(): void
    {
        $this->loginAs('mitha@pik.local');
        $before = [
            (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions'),
            (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisition_items'),
            (int) $this->scalar('SELECT COUNT(*) FROM audit_logs'),
        ];

        // Folder upload tidak bisa dibuat -> gagal SETELAH header & item tersimpan.
        $original = Config::get('app.upload.path');
        Config::set('app.upload.path', BASE_PATH . '/composer.json/uploads');
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        try {
            $response = $this->post('/pr', $this->prInput(), ['attachments' => [
                ['name' => 'penawaran.pdf', 'type' => 'application/pdf', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
            ]]);
        } finally {
            Config::set('app.upload.path', $original);
            @unlink($file);
        }

        self::assertSame(500, $response->status);
        self::assertSame($before, [
            (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions'),
            (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisition_items'),
            (int) $this->scalar('SELECT COUNT(*) FROM audit_logs'),
        ], 'Header, item, dan audit log ikut di-rollback: tidak ada PR setengah tersimpan');
    }

    public function test_pr_list_filters_by_status_and_search(): void
    {
        $this->loginAs('mitha@pik.local');

        $approved = $this->get('/pr', ['status' => 'approved']);
        self::assertSame(200, $approved->status);
        self::assertStringContainsString('PDPR002', $approved->body);
        self::assertStringNotContainsString('PDPR004', $approved->body);

        $search = $this->get('/pr', ['q' => 'PDPR003']);
        self::assertStringContainsString('PDPR003', $search->body);
        self::assertStringNotContainsString('PDPR002', $search->body);
    }
}
