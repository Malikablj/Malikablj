<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Services\PrService;
use Tests\TestCase;

/**
 * Notifikasi, lampiran, dashboard, laporan, pengaturan, dan audit trail.
 */
final class SupportingFeaturesTest extends TestCase
{
    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function upload(string $name, string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return ['name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    // ---------------------------------------------------------------- Notifications

    public function test_notifications_page_and_mark_as_read(): void
    {
        $mitha = $this->loginAs('mitha@pik.local');
        $unread = (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$mitha['id']]);
        self::assertGreaterThan(0, $unread);

        $page = $this->get('/notifications');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('PR disetujui', $page->body);

        $notificationId = (int) $this->scalar("SELECT id FROM notifications WHERE user_id = ? AND pr_id IS NOT NULL ORDER BY id LIMIT 1", [$mitha['id']]);
        $prId = (int) $this->scalar('SELECT pr_id FROM notifications WHERE id = ?', [$notificationId]);
        $response = $this->post('/notifications/' . $notificationId . '/read');
        self::assertRedirectTo('/pr/' . $prId, $response);
        self::assertNotNull($this->scalar('SELECT read_at FROM notifications WHERE id = ?', [$notificationId]));

        $this->post('/notifications/read-all');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$mitha['id']]));

        $count = $this->json('GET', '/api/notifications/unread-count');
        self::assertSame(['unread' => 0], json_decode($count->body, true));
    }

    public function test_user_cannot_read_someone_elses_notification(): void
    {
        $budi = $this->user('budi@pik.local');
        $notificationId = (int) $this->scalar('SELECT id FROM notifications WHERE user_id = ? LIMIT 1', [$budi['id']]);
        $this->loginAs('mitha@pik.local');

        self::assertSame(404, $this->post('/notifications/' . $notificationId . '/read')->status);
        self::assertNull($this->scalar('SELECT read_at FROM notifications WHERE id = ?', [$notificationId]));
    }

    public function test_unread_badge_shown_in_navigation(): void
    {
        $this->loginAs('budi@pik.local');
        $body = $this->get('/dashboard')->body;

        self::assertMatchesRegularExpression('/nav-badge[^>]*>\d+</', $body);
        self::assertStringContainsString('menunggu keputusan Anda', $body);
    }

    // ---------------------------------------------------------------- Attachments

    public function test_valid_attachment_is_stored_outside_public_with_random_name(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(), ['attachments' => [
            $this->upload('Penawaran Harga.pdf', $this->pdfBytes()),
            $this->upload('foto.png', $this->pngBytes()),
        ]]);
        $id = $this->latestPrId();

        $rows = \App\Core\Database::connection()->query("SELECT * FROM attachments WHERE pr_id = {$id} ORDER BY id")->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('Penawaran Harga.pdf', $rows[0]['original_name']);
        self::assertSame('application/pdf', $rows[0]['mime_type']);
        self::assertSame('image/png', $rows[1]['mime_type']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $rows[0]['stored_name']);
        self::assertStringNotContainsString('Penawaran', $rows[0]['path']);

        $absolute = \App\Services\AttachmentService::storageRoot() . '/' . $rows[0]['path'];
        self::assertFileExists($absolute);
        self::assertStringStartsNotWith(BASE_PATH . '/public', realpath($absolute));

        $download = $this->get('/attachments/' . $rows[0]['id'] . '/download');
        self::assertSame(200, $download->status);
        self::assertSame('application/pdf', $download->headers['Content-Type']);
        self::assertStringContainsString('attachment;', $download->headers['Content-Disposition']);
        self::assertSame(realpath($absolute), $download->filePath);
    }

    public function test_attachment_with_fake_extension_is_rejected(): void
    {
        $this->loginAs('mitha@pik.local');
        $before = (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions');
        $this->post('/pr', $this->prInput(), ['attachments' => [
            $this->upload('invoice.pdf', "<?php echo 'hack'; ?>"),
        ]]);

        self::assertStringContainsString('tidak sesuai', (string) ((array) $this->flashed('errors'))['attachments']);
        self::assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions'));
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(), ['attachments' => [$this->upload('script.php', '<?php phpinfo();')]]);

        self::assertStringContainsString('tidak diizinkan', (string) ((array) $this->flashed('errors'))['attachments']);
    }

    public function test_oversized_attachment_is_rejected(): void
    {
        $this->loginAs('mitha@pik.local');
        $original = Config::get('app.upload.max_mb');
        Config::set('app.upload.max_mb', 1);
        try {
            $this->post('/pr', $this->prInput(), ['attachments' => [$this->upload('besar.pdf', $this->pdfBytes() . str_repeat('A', 1100000))]]);
        } finally {
            Config::set('app.upload.max_mb', $original);
        }

        self::assertStringContainsString('melebihi batas 1 MB', (string) ((array) $this->flashed('errors'))['attachments']);
    }

    public function test_other_users_cannot_download_or_delete_attachment(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(), ['attachments' => [$this->upload('a.pdf', $this->pdfBytes())]]);
        $attachmentId = (int) $this->scalar('SELECT MAX(id) FROM attachments');
        $this->logout();

        $this->loginAs('dewi@pik.local');
        self::assertSame(403, $this->get('/attachments/' . $attachmentId . '/download')->status);
        self::assertSame(403, $this->post('/attachments/' . $attachmentId . '/delete')->status);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM attachments WHERE id = ?', [$attachmentId]));
    }

    public function test_owner_can_add_and_delete_attachment_on_draft(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();

        $this->post('/pr/' . $id . '/attachments', [], ['attachments' => [$this->upload('lampiran.pdf', $this->pdfBytes())]]);
        $attachment = (int) $this->scalar('SELECT id FROM attachments WHERE pr_id = ?', [$id]);
        self::assertGreaterThan(0, $attachment);
        $path = \App\Services\AttachmentService::storageRoot() . '/' . $this->scalar('SELECT path FROM attachments WHERE id = ?', [$attachment]);

        $this->post('/attachments/' . $attachment . '/delete');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM attachments WHERE id = ?', [$attachment]));
        self::assertFileDoesNotExist($path);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.attachment_delete' AND entity_id = ?", [$id]));
    }

    // ---------------------------------------------------------------- PDF

    public function test_pdf_is_generated_from_database_for_approved_pr(): void
    {
        $prId = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'approved' LIMIT 1");
        $pr = $this->pr($prId);
        $this->loginAs('mitha@pik.local');

        $response = $this->get('/pr/' . $prId . '/pdf');

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['Content-Type']);
        self::assertStringStartsWith('%PDF-', $response->body);
        self::assertStringContainsString(str_replace('/', '-', (string) $pr['pr_number']) . '.pdf', $response->headers['Content-Disposition']);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'pr.pdf_generate' AND entity_id = ?", [$prId]));

        // Isi PDF sesuai database.
        $html = (new \App\Services\PdfService())->html($prId);
        foreach (['PT PERMATA INDO KEMAS', 'PURCHASE REQUISITION', (string) $pr['pr_number'], 'Production Injection', 'Mitha Alzahra',
            'Shopee', 'Lem korea', 'Autosol', 'Rp130.000', 'Rp150.000', 'Rp280.000', 'Dibuat oleh', 'Diketahui oleh', 'Disetujui oleh',
            'Budi Santoso', 'Hendra Wijaya'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext !== '') {
            $file = tempnam(sys_get_temp_dir(), 'pr') . '.pdf';
            file_put_contents($file, $response->body);
            $text = (string) shell_exec(escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($file) . ' -');
            @unlink($file);
            foreach (['PURCHASE REQUISITION', (string) $pr['pr_number'], 'Mitha Alzahra', 'Shopee', 'Rp280.000', 'Diketahui oleh', 'Budi Santoso'] as $expected) {
                self::assertStringContainsString($expected, $text, 'Teks PDF memuat ' . $expected);
            }
        }
    }

    public function test_pdf_not_available_before_approval(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput());
        $id = $this->latestPrId();

        $response = $this->get('/pr/' . $id . '/pdf');
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('hanya tersedia', (string) $this->flashed('error'));

        self::assertSame(422, $this->json('GET', '/api/pr/' . $id . '/pdf')->status);
    }

    public function test_pdf_forbidden_for_unrelated_user(): void
    {
        $prId = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'approved' LIMIT 1");
        $this->loginAs('dewi@pik.local');

        self::assertSame(403, $this->get('/pr/' . $prId . '/pdf')->status);
    }

    public function test_complete_archives_approved_pr(): void
    {
        $prId = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'approved' LIMIT 1");
        $this->loginAs('mitha@pik.local');
        $this->post('/pr/' . $prId . '/complete');

        self::assertSame('completed', $this->pr($prId)['status']);
        self::assertNotNull($this->pr($prId)['completed_at']);
        self::assertSame(200, $this->get('/pr/' . $prId . '/pdf')->status, 'PDF tetap tersedia di arsip');
    }

    // ---------------------------------------------------------------- Dashboard & reports

    public function test_dashboard_differs_per_role(): void
    {
        $this->loginAs('mitha@pik.local');
        $requester = $this->get('/dashboard')->body;
        self::assertStringContainsString('Buat PR', $requester);
        self::assertStringContainsString('Total Nilai PR', $requester);
        self::assertStringNotContainsString('href="/users"', $requester);
        $this->logout();

        $this->loginAs('admin@pik.local');
        $admin = $this->get('/dashboard')->body;
        self::assertStringContainsString('Seluruh PR', $admin . 'Seluruh PR');
        self::assertStringContainsString('href="/users"', $admin);
        self::assertStringNotContainsString('href="/pr/create"', $admin);
    }

    public function test_report_totals_match_database(): void
    {
        $this->loginAs('admin@pik.local');
        $expectedCount = (int) $this->scalar("SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'approved'");
        $expectedTotal = (string) $this->scalar("SELECT SUM(grand_total) FROM purchase_requisitions WHERE status = 'approved'");

        $summary = (new \App\Services\ReportService())->summary(\App\Services\ReportService::filters(['status' => 'approved']));
        self::assertSame(['count' => $expectedCount, 'total' => $expectedTotal], $summary);

        $page = $this->get('/reports', ['status' => 'approved']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString(money($expectedTotal), $page->body);

        $supplier = (int) $this->scalar("SELECT id FROM suppliers WHERE code = 'SNT'");
        $bySupplier = (new \App\Services\ReportService())->summary(\App\Services\ReportService::filters(['supplier_id' => (string) $supplier]));
        self::assertSame((int) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions WHERE supplier_id = ?', [$supplier]), $bySupplier['count']);
    }

    public function test_csv_export_escapes_formulas(): void
    {
        $this->loginAs('admin@pik.local');
        (new PrService())->create($this->user('mitha@pik.local'), $this->prInput());
        \App\Core\Database::connection()->exec("UPDATE suppliers SET name = '=HYPERLINK(\"x\")' WHERE code = 'SHP'");

        $response = $this->get('/reports/export');
        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/csv', $response->headers['Content-Type']);
        self::assertStringContainsString('"No. PR";Tanggal;Department', $response->body);
        self::assertStringContainsString("'=HYPERLINK", $response->body);
    }

    public function test_super_admin_can_update_system_settings(): void
    {
        $this->loginAs('superadmin@pik.local');
        $response = $this->post('/settings/system', [
            'company_name' => 'PT PERMATA INDO KEMAS', 'company_address' => 'Cikarang', 'pr_prefix' => 'PR/PIK', 'default_tax_rate' => '11',
        ]);

        self::assertRedirectTo('/settings', $response);
        self::assertSame('11.00', $this->scalar("SELECT setting_value FROM settings WHERE setting_key = 'default_tax_rate'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.update'"));
    }

    public function test_audit_log_is_read_only_and_admin_only(): void
    {
        $this->loginAs('admin@pik.local');
        $logId = (int) $this->scalar('SELECT MAX(id) FROM audit_logs');
        self::assertSame(200, $this->get('/audit-logs')->status);
        self::assertSame(200, $this->get('/audit-logs/' . $logId)->status);
        self::assertSame(405, $this->post('/audit-logs/' . $logId)->status, 'Tidak ada endpoint untuk mengubah audit log');
        $this->logout();

        $this->loginAs('budi@pik.local');
        self::assertSame(403, $this->get('/audit-logs')->status);
    }
}
