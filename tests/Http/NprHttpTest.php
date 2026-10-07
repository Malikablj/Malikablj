<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/** NPR lewat HTTP nyata: hak akses manual request (UAT-02), autosave, lampiran, unduhan, PDF. */
final class NprHttpTest extends HttpTestCase
{
    private static int $customerId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
        if (self::$customerId === 0) {
            self::pdo()->exec("INSERT INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('SNT', 'PT Sanitya Utama', 'Jl A', 'Jl B', '021')");
            self::$customerId = (int) self::pdo()->lastInsertId();
        }
    }

    /** Buat NPR draft lewat HTTP, kembalikan [client, nprId, partId]. @return array{0:HttpClient,1:int,2:int} */
    private function newDraft(string $email = 'sales@test.local'): array
    {
        $c = $this->loginAs($email);
        $c->get('/npr.php');
        $res = $c->post('/npr.php', ['_csrf' => $c->csrf(), 'action' => 'create']);
        $this->assertSame(303, $res['status'], substr($res['body'], 0, 500));
        preg_match('/id=(\d+)/', $res['headers']['location'][0], $m);
        $id = (int) $m[1];
        $page = $c->get('/npr-edit.php?id=' . $id);
        $this->assertSame(200, $page['status']);
        preg_match('/name="parts\[(\d+)\]\[part_name_code\]"/', $page['body'], $pm);
        return [$c, $id, (int) $pm[1]];
    }

    private function lock(HttpClient $c, int $id): string
    {
        $page = $c->get('/npr-edit.php?id=' . $id);
        preg_match('/name="lock_version" value="(\d+)"/', $page['body'], $m);
        return $m[1];
    }

    /** @return array<string,mixed> */
    private function fullForm(int $partId): array
    {
        return [
            'npr' => [
                'product_name' => 'Jar Krim 50g', 'request_types' => ['', 'produk_baru'], 'customer_id' => (string) self::$customerId,
                'invoice_address' => 'Jl A', 'shipping_address' => 'Jl B', 'phone' => '021', 'product_applications' => ['', 'kosmetik'],
                'product_contents' => ['', 'krim'], 'net_weight_gr' => '50', 'qty_per_month' => '1000', 'qty_per_year' => '12000',
                'packaging' => ['', 'box'], 'regulation_compliance' => 'no', 'attach_sample' => 'ada', 'attach_technical_drawing' => 'ada', 'attach_mockup' => 'tidak_ada',
                'deco_printing' => '0', 'deco_labelling' => '0', 'deco_shrink' => '0',
            ],
            'parts' => [$partId => ['part_name_code' => 'body', 'part_type' => 'new_mold', 'development_type' => 'new_mould', 'resin_code' => 'pp', 'color_code' => 'opaque', 'surface_code' => 'glossy', 'is_external_component' => '0']],
        ];
    }

    public function testSalesFullDraftSubmitAndBlueLockedForNpd(): void
    {
        [$c, $id, $pid] = $this->newDraft();
        $res = $c->post('/npr-edit.php?id=' . $id, ['_csrf' => $c->csrf(), 'action' => 'submit', 'lock_version' => $this->lock($c, $id)] + $this->fullForm($pid));
        $this->assertSame(303, $res['status'], substr($res['body'], 0, 800));
        $status = self::pdo()->query("SELECT status, npr_number FROM npr WHERE id = {$id}")->fetch();
        $this->assertSame('submitted', $status['status']);
        $this->assertMatchesRegularExpression('#^\d{3}/PIK/NPR/[IVX]+/\d{4}$#', $status['npr_number']);

        // UAT-02: NPD mengirim kolom biru secara manual → 403, data tidak berubah
        $npd = $this->loginAs('npd@test.local');
        $npd->get('/npr-edit.php?id=' . $id);
        $res = $npd->post('/npr-edit.php?id=' . $id, ['_csrf' => $npd->csrf(), 'action' => 'save', 'npr' => ['product_name' => 'DIRETAS']]);
        $this->assertSame(403, $res['status']);
        $this->assertSame('Jar Krim 50g', self::pdo()->query("SELECT product_name FROM npr WHERE id = {$id}")->fetchColumn());
        // NPD boleh isi pink
        $res = $npd->post('/npr-edit.php?id=' . $id, ['_csrf' => $npd->csrf(), 'action' => 'save', 'feedback' => [$pid => ['weight_gr' => '12.5']]]);
        $this->assertSame(303, $res['status']);

        // Sales tidak boleh isi pink (request manual)
        $c->get('/npr-edit.php?id=' . $id);
        $res = $c->post('/npr-edit.php?id=' . $id, ['_csrf' => $c->csrf(), 'action' => 'save', 'feedback' => [$pid => ['weight_gr' => '99']]]);
        $this->assertSame(403, $res['status']);
        $this->assertSame('12.50', self::pdo()->query("SELECT weight_gr FROM npr_feedback WHERE npr_part_id = {$pid}")->fetchColumn());
        // Sales tidak melihat draft feedback
        $page = $c->get('/npr-edit.php?id=' . $id);
        $this->assertStringNotContainsString('name="feedback[', $page['body']);
        $this->assertStringNotContainsString('12.5', $page['body']);
    }

    public function testManagementReadOnly(): void
    {
        [$c, $id, $pid] = $this->newDraft();
        $c->post('/npr-edit.php?id=' . $id, ['_csrf' => $c->csrf(), 'action' => 'submit', 'lock_version' => $this->lock($c, $id)] + $this->fullForm($pid));
        $m = $this->loginAs('mgmt@test.local');
        $page = $m->get('/npr-edit.php?id=' . $id);
        $this->assertSame(200, $page['status']);
        $this->assertDoesNotMatchRegularExpression('/<input class="input"[^>]*name="npr\[product_name\]"(?![^>]*disabled)/', $page['body'], 'input harus disabled');
        $this->assertStringNotContainsString('value="submit"', $page['body']);
        $res = $m->post('/npr-edit.php?id=' . $id, ['_csrf' => $m->csrf(), 'action' => 'return', 'reason' => 'x']);
        $this->assertSame(403, $res['status']);
        $res = $m->post('/npr.php', ['_csrf' => $m->csrf(), 'action' => 'create']);
        $this->assertSame(403, $res['status']);
    }

    public function testDraftOfOtherSalesIsForbidden(): void
    {
        [, $id] = $this->newDraft('sales@test.local');
        $other = $this->loginAs('sales2@test.local');
        $this->assertSame(403, $other->get('/npr-edit.php?id=' . $id)['status']);
        $list = $other->get('/npr.php');
        $this->assertStringNotContainsString('npr-edit.php?id=' . $id . '"', $list['body']);
    }

    public function testAutosaveJsonAndConflict(): void
    {
        [$c, $id] = $this->newDraft();
        $lock = $this->lock($c, $id);
        $token = $c->csrf();
        $res = $c->post('/api/npr-autosave.php', ['_csrf' => $token, 'npr_id' => $id, 'lock_version' => $lock, 'npr' => ['product_name' => 'Autosave 1']], ['Accept: application/json']);
        $this->assertSame(200, $res['status'], $res['body']);
        $json = json_decode($res['body'], true);
        $this->assertTrue($json['ok']);
        $this->assertSame((int) $lock + 1, $json['lock_version']);
        $this->assertSame('Autosave 1', self::pdo()->query("SELECT product_name FROM npr WHERE id = {$id}")->fetchColumn());
        // versi usang → 409
        $res = $c->post('/api/npr-autosave.php', ['_csrf' => $token, 'npr_id' => $id, 'lock_version' => $lock, 'npr' => ['product_name' => 'Autosave lama']], ['Accept: application/json']);
        $this->assertSame(409, $res['status']);
        // tanpa CSRF → 419
        $res = $c->post('/api/npr-autosave.php', ['npr_id' => $id, 'npr' => ['product_name' => 'x']], ['Accept: application/json']);
        $this->assertSame(419, $res['status']);
    }

    public function testValidationErrorKeepsInputAndShowsFieldErrors(): void
    {
        [$c, $id, $pid] = $this->newDraft();
        $form = $this->fullForm($pid);
        $form['npr']['qty_per_month'] = 'banyak';
        $res = $c->post('/npr-edit.php?id=' . $id, ['_csrf' => $c->csrf(), 'action' => 'save', 'lock_version' => $this->lock($c, $id)] + $form);
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('value="banyak"', $res['body']);
        $this->assertStringContainsString('field-error', $res['body']);
    }

    public function testAttachmentUploadDownloadAndAccessControl(): void
    {
        [$c, $id] = $this->newDraft();
        $c->get('/npr-edit.php?id=' . $id);
        $png = tempnam(sys_get_temp_dir(), 'png');
        $im = imagecreatetruecolor(8, 8);
        imagepng($im, $png);
        $res = $c->post('/npr-edit.php?id=' . $id, [
            '_csrf' => $c->csrf(), 'action' => 'upload', 'lock_version' => $this->lock($c, $id),
            'attachment_product_shape' => new \CURLFile($png, 'image/png', 'contoh.png'),
        ]);
        $this->assertSame(303, $res['status'], substr($res['body'], 0, 500));
        $vid = (int) self::pdo()->query("SELECT v.id FROM document_versions v JOIN documents d ON d.id = v.document_id WHERE d.npr_id = {$id}")->fetchColumn();
        $this->assertGreaterThan(0, $vid);
        $path = (string) self::pdo()->query("SELECT stored_path FROM document_versions WHERE id = {$vid}")->fetchColumn();
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $path, 'nama file acak, bukan nama asli');
        $dl = $c->get('/download.php?v=' . $vid);
        $this->assertSame(200, $dl['status']);
        $this->assertStringContainsString('attachment', $dl['headers']['content-disposition'][0]);
        $this->assertSame('nosniff', $dl['headers']['x-content-type-options'][0]);
        $this->assertSame(file_get_contents($png), $dl['body']);
        // pengguna lain tidak boleh mengunduh lampiran draft orang lain
        $other = $this->loginAs('sales2@test.local');
        $this->assertSame(403, $other->get('/download.php?v=' . $vid)['status']);
        // file berbahaya ditolak
        $php = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($php, '<?php echo "pwned"; ?>');
        $c->get('/npr-edit.php?id=' . $id);
        $res = $c->post('/npr-edit.php?id=' . $id, [
            '_csrf' => $c->csrf(), 'action' => 'upload', 'lock_version' => $this->lock($c, $id),
            'attachment_spec_reference' => new \CURLFile($php, 'image/png', 'gambar.png'),
        ]);
        $this->assertSame(422, $res['status']);
        $this->assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM documents WHERE npr_id = {$id}")->fetchColumn());
        @unlink($png);
        @unlink($php);
    }

    public function testPdfExport(): void
    {
        [$c, $id, $pid] = $this->newDraft();
        $c->post('/npr-edit.php?id=' . $id, ['_csrf' => $c->csrf(), 'action' => 'save', 'lock_version' => $this->lock($c, $id)] + $this->fullForm($pid));
        $res = $c->get('/export.php?type=npr_pdf&id=' . $id);
        $this->assertSame(200, $res['status'], substr($res['body'], 0, 300));
        $this->assertSame('application/pdf', $res['headers']['content-type'][0]);
        $this->assertStringStartsWith('%PDF-', $res['body']);
        $this->assertStringContainsString('inline', $res['headers']['content-disposition'][0], 'pratinjau sebelum Selesai Feedback');
        $this->assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'export.npr_pdf_preview' AND entity_id = '{$id}'")->fetchColumn());
        $other = $this->loginAs('sales2@test.local');
        $this->assertSame(403, $other->get('/export.php?type=npr_pdf&id=' . $id)['status']);
    }

    public function testMastersAndCustomersAdminOnly(): void
    {
        $npd = $this->loginAs('npd@test.local');
        $this->assertSame(403, $npd->get('/settings/masters.php')['status']);
        $this->assertSame(403, $npd->get('/settings/customers.php')['status']);
        $admin = $this->loginAs('admin@test.local');
        $admin->get('/settings/masters.php?category=resin');
        $res = $admin->post('/settings/masters.php', ['_csrf' => $admin->csrf(), 'action' => 'create', 'category' => 'resin', 'label_id' => 'PCR-PET', 'label_en' => 'PCR-PET']);
        $this->assertSame(303, $res['status']);
        $this->assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM master_options WHERE category = 'resin' AND code = 'pcr_pet'")->fetchColumn());
        $admin->get('/settings/customers.php');
        $res = $admin->post('/settings/customers.php', ['_csrf' => $admin->csrf(), 'action' => 'create', 'code' => 'kos', 'name' => 'PT Kosmetika']);
        $this->assertSame(303, $res['status']);
        $this->assertSame(1, (int) self::pdo()->query("SELECT COUNT(*) FROM customers WHERE code = 'KOS'")->fetchColumn());
    }
}
