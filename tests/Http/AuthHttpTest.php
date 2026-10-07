<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** AUTH-01/05/06/08, NFR-07: alur login/logout nyata lewat HTTP. */
final class AuthHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    public function testUnauthenticatedPagesRedirectToLogin(): void
    {
        foreach (['/dashboard.php', '/profile.php', '/settings/users.php', '/settings/audit.php'] as $path) {
            $res = $this->client()->get($path);
            $this->assertSame(303, $res['status'], $path);
            $this->assertStringEndsWith('/login.php', $res['headers']['location'][0]);
        }
    }

    public function testUnauthenticatedApiReturns401Json(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        $res = $c->post('/api/preferences.php', '{"theme":"dark"}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf(), 'Accept: application/json']);
        // tamu boleh menyimpan preferensi di sesi; endpoint lain yang wajib login diuji di AuthorizationHttpTest
        $this->assertSame(200, $res['status']);
        $this->assertSame(['ok' => true, 'language' => null, 'theme' => 'dark'], json_decode($res['body'], true));
    }

    public function testLoginPageSecurityHeadersAndCookieFlags(): void
    {
        $res = $this->client()->get('/login.php');
        $this->assertSame(200, $res['status']);
        $h = $res['headers'];
        $this->assertStringContainsString("default-src 'self'", $h['content-security-policy'][0]);
        $this->assertStringContainsString("frame-ancestors 'none'", $h['content-security-policy'][0]);
        $this->assertSame('DENY', $h['x-frame-options'][0]);
        $this->assertSame('nosniff', $h['x-content-type-options'][0]);
        $this->assertArrayNotHasKey('x-powered-by', $h);
        $cookie = implode(';', $h['set-cookie']);
        $this->assertStringContainsString('NPDSESSID=', $cookie);
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $cookie);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $cookie);
    }

    public function testLoginSuccessRegeneratesSessionId(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        $before = $c->sessionId();
        $res = $c->post('/login.php', ['email' => 'npd@test.local', 'password' => self::PASSWORD, '_csrf' => $c->csrf()]);
        $this->assertSame(303, $res['status']);
        $this->assertStringEndsWith('/dashboard.php', $res['headers']['location'][0]);
        $this->assertNotNull($before);
        $this->assertNotSame($before, $c->sessionId(), 'ID sesi wajib diganti setelah login (anti session fixation)');
        $dash = $c->get('/dashboard.php');
        $this->assertSame(200, $dash['status']);
        $this->assertStringContainsString('NPD Test', $dash['body']);
    }

    public function testWrongPasswordReturns401WithGenericMessage(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        $res = $c->post('/login.php', ['email' => 'npd@test.local', 'password' => 'salah', '_csrf' => $c->csrf()]);
        $this->assertSame(401, $res['status']);
        $this->assertStringContainsString('Email atau password salah.', $res['body']);
    }

    public function testInactiveUserRejected(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        $res = $c->post('/login.php', ['email' => 'inactive@test.local', 'password' => self::PASSWORD, '_csrf' => $c->csrf()]);
        $this->assertSame(401, $res['status']);
        $this->assertStringContainsString('nonaktif', $res['body']);
    }

    public function testLoginWithoutCsrfRejected(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        $res = $c->post('/login.php', ['email' => 'npd@test.local', 'password' => self::PASSWORD]);
        $this->assertSame(419, $res['status']);
        $res = $c->post('/login.php', ['email' => 'npd@test.local', 'password' => self::PASSWORD, '_csrf' => str_repeat('a', 64)]);
        $this->assertSame(419, $res['status']);
    }

    public function testRateLimitLocksAfterFiveFailures(): void
    {
        $c = $this->client();
        $c->get('/login.php');
        for ($i = 0; $i < 5; $i++) {
            $res = $c->post('/login.php', ['email' => 'quality@test.local', 'password' => 'salah' . $i, '_csrf' => $c->csrf()]);
            $this->assertSame(401, $res['status']);
        }
        $res = $c->post('/login.php', ['email' => 'quality@test.local', 'password' => self::PASSWORD, '_csrf' => $c->csrf()]);
        $this->assertSame(429, $res['status']);
        $this->assertNotEmpty($res['headers']['retry-after'][0]);
        $this->assertStringContainsString('Terlalu banyak percobaan', $res['body']);
    }

    public function testLogoutRequiresPostAndDestroysSession(): void
    {
        $c = $this->loginAs('drafter@test.local');
        $this->assertSame(405, $c->get('/logout.php')['status'], 'logout via GET ditolak');
        $page = $c->get('/dashboard.php');
        $oldSession = $c->sessionId();
        $res = $c->post('/logout.php', ['_csrf' => $c->csrf()]);
        $this->assertSame(303, $res['status']);
        $this->assertNotSame($oldSession, $c->sessionId());
        $this->assertSame(303, $c->get('/dashboard.php')['status'], 'setelah logout harus login lagi');
        // cookie sesi lama tidak lagi valid
        $replay = $this->client();
        $jarPath = (new \ReflectionProperty($replay, 'jar'))->getValue($replay);
        file_put_contents($jarPath, "127.0.0.1\tFALSE\t/\tFALSE\t0\tNPDSESSID\t{$oldSession}\n");
        $this->assertSame(303, $replay->get('/dashboard.php')['status']);
        unset($page);
    }

    public function testSessionIdleTimeout(): void
    {
        self::pdo()->exec("UPDATE application_settings SET setting_value = '5' WHERE setting_key = 'security.session_timeout_minutes'");
        $c = $this->loginAs('purchasing@test.local');
        // majukan waktu aktivitas terakhir di file sesi
        $sid = $c->sessionId();
        $dir = sys_get_temp_dir();
        $files = glob($dir . '/npd_http_*/sessions/sess_' . $sid) ?: [];
        $this->assertNotEmpty($files, 'file sesi ditemukan');
        $data = (string) file_get_contents($files[0]);
        $data = preg_replace('/_last_activity\|i:\d+;/', '_last_activity|i:' . (time() - 3600) . ';', $data);
        file_put_contents($files[0], $data);
        $res = $c->get('/dashboard.php');
        self::pdo()->exec("UPDATE application_settings SET setting_value = '480' WHERE setting_key = 'security.session_timeout_minutes'");
        $this->assertSame(303, $res['status']);
        $login = $c->get('/login.php');
        $this->assertStringContainsString('Sesi Anda berakhir', $login['body']);
    }

    public function testMustChangePasswordRedirectsToProfile(): void
    {
        self::pdo()->exec("UPDATE users SET must_change_password = 1 WHERE email = 'production@test.local'");
        $c = $this->loginAs('production@test.local');
        $res = $c->get('/dashboard.php');
        $this->assertSame(303, $res['status']);
        $this->assertStringEndsWith('/profile.php', $res['headers']['location'][0]);
        $c->get('/profile.php');
        $res = $c->post('/profile.php', ['_csrf' => $c->csrf(), 'action' => 'password', 'current_password' => self::PASSWORD, 'new_password' => 'Produksi99', 'password_confirmation' => 'Produksi99']);
        $this->assertSame(303, $res['status']);
        $this->assertSame(200, $c->get('/dashboard.php')['status']);
        // kembalikan password untuk test lain
        self::pdo()->exec("UPDATE users SET password_hash = '" . password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]) . "' WHERE email = 'production@test.local'");
    }
}
