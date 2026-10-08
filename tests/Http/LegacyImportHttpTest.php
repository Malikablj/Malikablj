<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;
use Tests\Support\LegacyImportFixtures;

/**
 * Pengaturan › Impor Data Lama lewat HTTP: hanya Admin, CSRF, unduh template, unggah → periksa (tanpa simpan)
 * → impor dengan token pemeriksaan, file bukan .xlsx ditolak, token lama tidak dapat dipakai ulang.
 */
final class LegacyImportHttpTest extends HttpTestCase
{
    use LegacyImportFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
        self::pdo()->exec("INSERT IGNORE INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('HTTPIMP', 'PT Impor HTTP', 'Jl. A', 'Jl. B', '021')");
    }

    protected function tearDown(): void
    {
        $this->cleanLegacyFiles();
        parent::tearDown();
    }

    private function scalar(string $sql, array $p = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function upload(\Tests\Support\HttpClient $c, string $path, string $name = 'data-lama.xlsx'): array
    {
        $c->get('/settings/import.php');
        return $c->post('/settings/import.php', ['_csrf' => $c->csrf(), 'action' => 'analyze',
            'file' => new \CURLFile($path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $name)]);
    }

    public function testOnlyAdminCanOpenDownloadOrUpload(): void
    {
        foreach (['npd@test.local', 'sales@test.local', 'mgmt@test.local'] as $email) {
            $c = $this->loginAs($email);
            $this->assertSame(403, $c->get('/settings/import.php')['status'], $email);
            $this->assertSame(403, $c->get('/settings/import.php?action=template')['status'], $email);
            $c->get('/dashboard.php');
            $this->assertSame(403, $c->post('/settings/import.php', ['_csrf' => $c->csrf(), 'action' => 'cancel'])['status'], $email);
            $this->assertStringNotContainsString('settings/import.php', $c->get('/dashboard.php')['body'], 'menu tidak tampil untuk ' . $email);
        }
        $admin = $this->loginAs('admin@test.local');
        $this->assertStringContainsString('settings/import.php', $admin->get('/dashboard.php')['body']);
        $this->assertSame(419, $admin->post('/settings/import.php', ['action' => 'cancel'])['status'], 'tanpa token CSRF');
    }

    public function testAdminChecksThenImportsLegacyFile(): void
    {
        $c = $this->loginAs('admin@test.local');
        $page = $c->get('/settings/import.php');
        $this->assertSame(200, $page['status']);
        $tpl = $c->get('/settings/import.php?action=template');
        $this->assertSame(200, $tpl['status']);
        $this->assertStringContainsString('spreadsheetml', $tpl['headers']['content-type'][0]);
        $this->assertStringStartsWith('PK', $tpl['body']);
        $this->assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'import.template'"));

        $before = (int) $this->scalar('SELECT COUNT(*) FROM projects');
        $project = ['ref' => 'HTTP-001', 'name' => 'Pot Krim 50g', 'customer' => 'HTTPIMP', 'sales' => 'sales@test.local', 'npd' => 'npd@test.local',
            'npr_date' => '2026-02-02', 'status' => 'Berjalan'];
        // file dengan kesalahan: ditampilkan per baris, tidak ada yang tersimpan, tombol Impor tidak ada
        $bad = $this->legacyFile([$project + ['target' => '2025-01-01']], [['ref' => 'HTTP-001', 'part' => 'Pot', 'type' => 'Kaleng']], [], $tpl['body']);
        $this->assertSame(303, $this->upload($c, $bad)['status']);
        $res = $c->get('/settings/import.php');
        $this->assertStringContainsString('data-import-errors', $res['body']);
        $this->assertStringContainsString('Tidak boleh sebelum Tanggal NPR', $res['body']);
        $this->assertStringNotContainsString('name="action" value="commit"', $res['body']);
        $this->assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM projects'));

        // file benar: ringkasan + tombol Impor
        $good = $this->legacyFile([$project], [['ref' => 'HTTP-001', 'part' => 'Pot', 'type' => 'Subcont', 'drafter' => 'drafter@test.local']],
            [['ref' => 'HTTP-001', 'part' => 'Pot', 'process' => 'S1 — Artwork Development', 'status' => 'Berjalan', 'start' => '2026-02-09']], $tpl['body']);
        $this->assertSame(303, $this->upload($c, $good)['status']);
        $res = $c->get('/settings/import.php');
        $this->assertStringContainsString('Impor 1 project', $res['body']);
        $this->assertStringContainsString('HTTP-001', $res['body']);
        $this->assertMatchesRegularExpression('/name="token" value="([a-f0-9]{32})"/', $res['body']);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $res['body'], $m);
        $token = $m[1];
        $this->assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM projects'), 'periksa tidak menyimpan');
        // token palsu ditolak
        $c->post('/settings/import.php', ['_csrf' => $c->csrf(), 'action' => 'commit', 'token' => str_repeat('0', 32)]);
        $this->assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM projects'));

        $this->assertSame(303, $this->upload($c, $good)['status']);
        $res = $c->get('/settings/import.php');
        preg_match('/name="token" value="([a-f0-9]{32})"/', $res['body'], $m);
        $token = $m[1];
        $commit = $c->post('/settings/import.php', ['_csrf' => $c->csrf(), 'action' => 'commit', 'token' => $token]);
        $this->assertSame(303, $commit['status'], substr($commit['body'], 0, 500));
        $done = $c->get('/settings/import.php');
        $this->assertStringContainsString('1 project berhasil diimpor', $done['body']);
        $code = (string) $this->scalar("SELECT code FROM projects WHERE legacy_ref = 'HTTP-001'");
        $this->assertMatchesRegularExpression('/^NPD-2026-\d{3}$/', $code);
        $this->assertStringContainsString($code, $done['body']);
        $this->assertSame('current', $this->scalar("SELECT pr.status FROM processes pr JOIN projects p ON p.id = pr.project_id WHERE p.legacy_ref = 'HTTP-001' AND pr.code = 'S1'"));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'import.legacy'"));
        // halaman project hasil impor dapat dibuka (timeline, proses)
        $pid = (int) $this->scalar("SELECT id FROM projects WHERE legacy_ref = 'HTTP-001'");
        foreach (['/project.php?id=' . $pid, '/project.php?id=' . $pid . '&tab=timeline', '/timeline.php?project=' . $pid, '/gantt.php', '/tracker.php',
                  '/export.php?type=timeline_xlsx&project=' . $pid . '&scope=all', '/export.php?type=timeline_pdf&project=' . $pid . '&scope=all'] as $url) {
            $this->assertSame(200, $c->get($url)['status'], $url);
        }
        $s1 = (int) $this->scalar("SELECT pr.id FROM processes pr WHERE pr.project_id = ? AND pr.code = 'S1'", [$pid]);
        $this->assertSame(200, $c->get('/process.php?id=' . $s1)['status']);
        // token yang sudah dipakai tidak dapat mengimpor ulang
        $c->post('/settings/import.php', ['_csrf' => $c->csrf(), 'action' => 'commit', 'token' => $token]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM projects WHERE legacy_ref = 'HTTP-001'"));
    }

    public function testNonXlsxUploadIsRejected(): void
    {
        $c = $this->loginAs('admin@test.local');
        $csv = $this->scratch('csv');
        file_put_contents($csv, "Ref Project,Nama\nX,Y\n");
        $res = $this->upload($c, $csv, 'data.csv');
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('Hanya file Excel .xlsx', $res['body']);
        $fake = $this->scratch('xlsx');
        file_put_contents($fake, '<?php echo 1;');
        $res = $this->upload($c, $fake, 'palsu.xlsx');
        $this->assertSame(422, $res['status']);
    }
}
