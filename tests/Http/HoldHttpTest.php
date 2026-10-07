<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/**
 * Hold/Resume/Cancel/Arsip lewat HTTP nyata (PRD §8): otorisasi server (403), CSRF (419), pratinjau Resume
 * dihitung server dan wajib ditinjau sebelum simpan, escaping alasan, filter Arsip.
 */
final class HoldHttpTest extends HttpTestCase
{
    private static int $projectId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$projectId = (int) self::seedWith(dirname(__DIR__) . '/Support/seed-http-project.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    private function projectValue(string $col): mixed
    {
        $st = self::pdo()->prepare('SELECT ' . preg_replace('/[^a-z_]/', '', $col) . ' FROM projects WHERE id = ?');
        $st->execute([self::$projectId]);
        return $st->fetchColumn();
    }

    private function scalar(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    public function testNonAuthorizedRolesAreRejectedByServer(): void
    {
        $url = '/project.php?id=' . self::$projectId;
        foreach (['drafter@test.local', 'mgmt@test.local', 'sales@test.local'] as $email) {
            $c = $this->loginAs($email);
            $page = $c->get($url);
            $this->assertStringNotContainsString('dlg-hold-0', $page['body'], $email . ': tombol Hold disembunyikan');
            foreach ([['action' => 'hold', 'reason' => 'x'], ['action' => 'cancel_project', 'reason' => 'x'], ['action' => 'archive', 'reason' => 'x']] as $form) {
                $this->assertSame(403, $c->post($url, ['_csrf' => $c->csrf()] + $form)['status'], $email . ' ' . $form['action']);
            }
            $this->assertSame(403, $c->get('/resume.php?project=' . self::$projectId)['status'], $email . ' resume.php');
        }
        // NPD boleh Hold/Cancel, tetapi Arsip & buka kembali hanya Admin
        $c = $this->loginAs('npd@test.local');
        $page = $c->get($url);
        $this->assertStringContainsString('dlg-hold-0', $page['body']);
        $this->assertStringNotContainsString('dlg-archive', $page['body']);
        $this->assertSame(403, $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'archive', 'reason' => 'x'])['status']);
        $this->assertSame(403, $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'reopen_project', 'reason' => 'x'])['status']);
        $this->assertSame(0, (int) $this->projectValue('is_on_hold'));
        $this->assertSame(0, (int) $this->projectValue('is_archived'));
    }

    public function testHoldThenResumeRequiresServerPreview(): void
    {
        $c = $this->loginAs('npd@test.local');
        $url = '/project.php?id=' . self::$projectId;
        $c->get($url);
        // tanpa CSRF ditolak
        $this->assertSame(419, $c->post($url, ['action' => 'hold', 'reason' => 'x'])['status']);
        // alasan wajib
        $c->get($url);
        $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'hold', 'reason' => '']);
        $this->assertSame(0, (int) $this->projectValue('is_on_hold'));

        $c->get($url);
        $res = $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'hold', 'reason' => 'Tunggu <script>alert(1)</script> customer', 'expected_resume_date' => '2026-11-30']);
        $this->assertSame(303, $res['status']);
        $this->assertSame(1, (int) $this->projectValue('is_on_hold'));
        $this->assertSame('hold', $this->projectValue('status'));
        $page = $c->get($url);
        $this->assertStringContainsString('hold-banner', $page['body']);
        $this->assertStringContainsString('Tunggu &lt;script&gt;alert(1)&lt;/script&gt; customer', $page['body'], 'alasan di-escape');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page['body']);
        $this->assertStringContainsString('resume.php?project=' . self::$projectId, $page['body']);
        // proses Hold tidak dapat diselesaikan walau dipaksa lewat POST
        $n1 = $this->scalar("SELECT p.id FROM processes p JOIN project_parts pp ON pp.id = p.part_id WHERE p.project_id = ? AND p.code = 'N1' ORDER BY pp.sort_order LIMIT 1", [self::$projectId]);
        $c->get('/process.php?id=' . $n1);
        $c->post('/process.php?id=' . $n1, ['_csrf' => $c->csrf(), 'action' => 'complete']);
        $st = self::pdo()->prepare('SELECT status FROM processes WHERE id = ?');
        $st->execute([$n1]);
        $this->assertSame('current', (string) $st->fetchColumn());

        // halaman Resume
        $resumeUrl = '/resume.php?project=' . self::$projectId;
        $form = $c->get($resumeUrl);
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('name="target_finish"', $form['body']);
        $this->assertStringContainsString('name="remaining[', $form['body']);
        $this->assertStringNotContainsString('value="save"', $form['body'], 'Simpan baru tersedia setelah pratinjau');
        $baselinesBefore = $this->scalar('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ?', [self::$projectId]);

        // target wajib → 422
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'preview', 'target_finish' => '']);
        $this->assertSame(422, $res['status']);
        // simpan tanpa pratinjau → diminta meninjau pratinjau, tidak tersimpan
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'save', 'target_finish' => '2027-03-31']);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('id="sec-preview"', $res['body']);
        $this->assertSame(1, (int) $this->projectValue('is_on_hold'));

        // pratinjau → token terikat pada isian
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'preview', 'target_finish' => '2027-03-31', 'note' => 'Lanjut']);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('id="sec-preview"', $res['body']);
        $this->assertSame(1, preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $res['body'], $m));
        $token = $m[1];
        // isian diubah setelah pratinjau → tidak tersimpan
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'save', 'target_finish' => '2027-04-30', 'note' => 'Lanjut', 'preview_token' => $token]);
        $this->assertSame(200, $res['status']);
        $this->assertSame(1, (int) $this->projectValue('is_on_hold'));
        // isian sama dengan pratinjau → tersimpan
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'preview', 'target_finish' => '2027-03-31', 'note' => 'Lanjut']);
        preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $res['body'], $m);
        $res = $c->post($resumeUrl, ['_csrf' => $c->csrf(), 'op' => 'save', 'target_finish' => '2027-03-31', 'note' => 'Lanjut', 'preview_token' => $m[1]]);
        $this->assertSame(303, $res['status'], substr($res['body'], 0, 500));
        $this->assertSame(0, (int) $this->projectValue('is_on_hold'));
        $this->assertSame('2027-03-31', $this->projectValue('target_finish'));
        $this->assertSame($baselinesBefore + 1, $this->scalar('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ?', [self::$projectId]));
        $history = $c->get($url . '&tab=history');
        $this->assertStringContainsString('id="sec-hold"', $history['body']);
        $this->assertStringContainsString('Lanjut', $history['body']);
        // tidak sedang Hold → halaman Resume kembali ke project
        $this->assertSame(303, $c->get($resumeUrl)['status']);
    }

    public function testArchiveAndRestoreByAdmin(): void
    {
        $c = $this->loginAs('admin@test.local');
        $url = '/project.php?id=' . self::$projectId;
        $code = (string) $this->projectValue('code');
        $page = $c->get($url);
        $this->assertStringContainsString('dlg-archive', $page['body']);
        $this->assertSame(303, $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'archive', 'reason' => 'Hold berkepanjangan'])['status']);
        $this->assertSame(1, (int) $this->projectValue('is_archived'));
        $this->assertStringNotContainsString($code, $c->get('/projects.php')['body'], 'hilang dari daftar bawaan');
        $this->assertStringContainsString($code, $c->get('/projects.php?archived=1')['body'], 'muncul di filter Arsip');
        $page = $c->get($url);
        $this->assertStringContainsString('Hold berkepanjangan', $page['body']);
        $this->assertStringContainsString('dlg-restore', $page['body']);
        $this->assertSame(303, $c->post($url, ['_csrf' => $c->csrf(), 'action' => 'restore', 'reason' => 'Aktif kembali'])['status']);
        $this->assertSame(0, (int) $this->projectValue('is_archived'));
        $this->assertStringContainsString($code, $c->get('/projects.php')['body']);
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.archive'", [self::$projectId]));
        $this->assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.restore'", [self::$projectId]));
    }
}
