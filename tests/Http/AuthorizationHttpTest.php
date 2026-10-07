<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** ROLE-05: otorisasi ditegakkan di server walau request dibuat manual. */
final class AuthorizationHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    public function testNonAdminCannotOpenAdminPages(): void
    {
        foreach (['mgmt@test.local', 'npd@test.local', 'sales@test.local', 'drafter@test.local'] as $email) {
            $c = $this->loginAs($email);
            foreach (['/settings/users.php', '/settings/audit.php'] as $path) {
                $res = $c->get($path);
                $this->assertSame(403, $res['status'], "{$email} {$path}");
                $this->assertStringContainsString('tidak memiliki hak akses', $res['body']);
            }
        }
    }

    public function testManagementManualPostIsRejected(): void
    {
        $c = $this->loginAs('mgmt@test.local');
        $c->get('/dashboard.php');
        $res = $c->post('/settings/users.php', ['_csrf' => $c->csrf(), 'action' => 'create', 'name' => 'Hacker', 'email' => 'h@x.local', 'role' => 'admin', 'password' => 'Hacker123']);
        $this->assertSame(403, $res['status']);
        $count = (int) self::pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'h@x.local'")->fetchColumn();
        $this->assertSame(0, $count);
        // JSON client mendapat JSON 403
        $res = $c->post('/settings/users.php', ['_csrf' => $c->csrf(), 'action' => 'create'], ['Accept: application/json']);
        $this->assertSame(403, $res['status']);
        $this->assertArrayHasKey('error', json_decode($res['body'], true));
        // penolakan tercatat di audit log
        $denied = (int) self::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'authz.denied'")->fetchColumn();
        $this->assertGreaterThan(0, $denied);
    }

    public function testAdminCanManageUsersAndOutputIsEscaped(): void
    {
        $c = $this->loginAs('admin@test.local');
        $c->get('/settings/users.php');
        $xss = '<script>alert(1)</script>';
        $res = $c->post('/settings/users.php', ['_csrf' => $c->csrf(), 'action' => 'create', 'name' => $xss, 'email' => 'xss@test.local', 'role' => 'drafter', 'password' => 'Drafter123', 'language' => 'id']);
        $this->assertSame(303, $res['status']);
        $list = $c->get('/settings/users.php?q=xss');
        $this->assertStringNotContainsString($xss, $list['body']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list['body']);
        $hash = (string) self::pdo()->query("SELECT password_hash FROM users WHERE email = 'xss@test.local'")->fetchColumn();
        $this->assertTrue(password_verify('Drafter123', $hash));
    }

    public function testCsrfRequiredForAdminPost(): void
    {
        $c = $this->loginAs('admin@test.local');
        $res = $c->post('/settings/users.php', ['action' => 'create', 'name' => 'NoCsrf', 'email' => 'nocsrf@test.local', 'role' => 'drafter', 'password' => 'Drafter123']);
        $this->assertSame(419, $res['status']);
        $this->assertSame(0, (int) self::pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'nocsrf@test.local'")->fetchColumn());
    }

    public function testSqlInjectionAttemptIsHarmless(): void
    {
        $c = $this->loginAs('admin@test.local');
        $res = $c->get('/settings/users.php?q=' . rawurlencode("' OR 1=1 -- ") . '&role=' . rawurlencode("admin' OR '1'='1"));
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('0 user', $res['body']);
        $res = $c->get('/settings/audit.php?action=' . rawurlencode("x'; DROP TABLE users; --") . '&entity=' . rawurlencode('user` --'));
        $this->assertSame(200, $res['status']);
        $this->assertGreaterThan(0, (int) self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testAuditLogHasNoMutationEndpoint(): void
    {
        $c = $this->loginAs('admin@test.local');
        $page = $c->get('/settings/audit.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('name="action" value="delete"', $page['body']);
        $before = (int) self::pdo()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $res = $c->post('/settings/audit.php', ['_csrf' => $c->csrf(), 'action' => 'delete', 'id' => 1]);
        $after = (int) self::pdo()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $this->assertGreaterThanOrEqual($before, $after, 'POST ke halaman audit tidak boleh menghapus apa pun');
        $this->assertNotSame(500, $res['status']);
    }

    public function testNoAiAssistantEndpoint(): void
    {
        $c = $this->loginAs('admin@test.local');
        foreach (['/assistant.php', '/api/assistant.php', '/ai.php'] as $p) {
            $this->assertSame(404, $c->get($p)['status'], $p);
        }
        $this->assertStringNotContainsStringIgnoringCase('assistant', $c->get('/dashboard.php')['body']);
    }
}
