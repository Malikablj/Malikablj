<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** Pusat notifikasi, pengaturan notifikasi & antrean email lewat HTTP nyata. */
final class NotificationHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    private function userId(string $email): int
    {
        $st = self::pdo()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        return (int) $st->fetchColumn();
    }

    private function notify(string $email, string $link): int
    {
        $st = self::pdo()->prepare("INSERT INTO notifications (user_id, type, title, body, link, created_at) VALUES (?, 'comment', 'Uji <b>judul</b>', 'isi', ?, NOW())");
        $st->execute([$this->userId($email), $link]);
        return (int) self::pdo()->lastInsertId();
    }

    public function testBellListOpenAndOwnership(): void
    {
        $id = $this->notify('drafter@test.local', 'projects.php');
        $c = $this->loginAs('drafter@test.local');
        $page = $c->get('/notifications.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Uji &lt;b&gt;judul&lt;/b&gt;', $page['body']);
        $this->assertMatchesRegularExpression('/data-notif-count>\d+</', $page['body'], 'lonceng menampilkan jumlah belum dibaca');
        $res = $c->get('/notifications.php?open=' . $id);
        $this->assertSame(303, $res['status']);
        $this->assertStringEndsWith('/projects.php', $res['headers']['location'][0]);
        $this->assertSame('1', (string) self::pdo()->query('SELECT is_read FROM notifications WHERE id = ' . $id)->fetchColumn());
        // tautan eksternal tidak diikuti
        $bad = $this->notify('drafter@test.local', 'https://evil.example/');
        $res = $c->get('/notifications.php?open=' . $bad);
        $this->assertStringEndsWith('/notifications.php', $res['headers']['location'][0]);
        // notifikasi milik orang lain → 404
        $other = $this->notify('sales@test.local', 'projects.php');
        $this->assertSame(404, $c->get('/notifications.php?open=' . $other)['status']);
        $c->get('/notifications.php');
        $this->assertSame(303, $c->post('/notifications.php', ['_csrf' => $c->csrf(), 'action' => 'read_all'])['status']);
        $this->assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND user_id = ' . $this->userId('drafter@test.local'))->fetchColumn());
    }

    public function testSettingsPagesAdminOnly(): void
    {
        foreach (['npd@test.local', 'mgmt@test.local'] as $email) {
            $c = $this->loginAs($email);
            $this->assertSame(403, $c->get('/settings/notifications.php')['status']);
            $this->assertSame(403, $c->get('/settings/email-queue.php')['status']);
        }
        $a = $this->loginAs('admin@test.local');
        $page = $a->get('/settings/notifications.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('value="Rahasia', $page['body']);
        $res = $a->post('/settings/notifications.php', ['_csrf' => $a->csrf(), 'action' => 'save', 'due_soon_days' => '31', 'no_update_days' => '7', 'smtp_port' => '587', 'smtp_encryption' => 'tls']);
        $this->assertSame(422, $res['status']);
        $res = $a->post('/settings/notifications.php', ['_csrf' => $a->csrf(), 'action' => 'save', 'due_soon_days' => '4', 'no_update_days' => '7', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_password' => 'Rahasia123']);
        $this->assertSame(303, $res['status']);
        $page = $a->get('/settings/notifications.php');
        $this->assertStringNotContainsString('Rahasia123', $page['body'], 'password tidak pernah ditampilkan');
        $this->assertSame(200, $a->get('/settings/email-queue.php?status=failed')['status']);
    }
}
