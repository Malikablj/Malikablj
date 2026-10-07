<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** UI-07, I18N-01, FR-UI-02: navigasi sesuai role, ganti bahasa & tema tersimpan per pengguna. */
final class LayoutHttpTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
        self::pdo()->exec("UPDATE users SET language = 'id', theme = 'system'");
    }

    public function testSettingsMenuOnlyForAdmin(): void
    {
        $admin = $this->loginAs('admin@test.local')->get('/dashboard.php')['body'];
        $this->assertStringContainsString('settings/users.php', $admin);
        $this->assertStringContainsString('settings/audit.php', $admin);
        $sales = $this->loginAs('sales@test.local')->get('/dashboard.php')['body'];
        $this->assertStringNotContainsString('settings/users.php', $sales);
        $this->assertStringNotContainsString('Pengaturan', $sales);
    }

    public function testLanguageSwitchPersistsInProfile(): void
    {
        $c = $this->loginAs('npd@test.local');
        $page = $c->get('/dashboard.php');
        $this->assertStringContainsString('<html lang="id"', $page['body']);
        $this->assertStringContainsString('Keluar', $page['body']);
        $res = $c->post('/api/preferences.php', ['_csrf' => $c->csrf(), 'language' => 'en', 'return' => '/dashboard.php']);
        $this->assertSame(303, $res['status']);
        $this->assertSame('/dashboard.php', $res['headers']['location'][0]);
        $page = $c->get('/dashboard.php');
        $this->assertStringContainsString('<html lang="en"', $page['body']);
        $this->assertStringContainsString('Sign out', $page['body']);
        $this->assertSame('en', self::pdo()->query("SELECT language FROM users WHERE email = 'npd@test.local'")->fetchColumn());
        // login ulang di perangkat lain → tetap Inggris
        $other = $this->loginAs('npd@test.local');
        $this->assertStringContainsString('<html lang="en"', $other->get('/dashboard.php')['body']);
    }

    public function testThemePreferenceRenderedServerSide(): void
    {
        $c = $this->loginAs('quality@test.local');
        $c->get('/dashboard.php');
        $res = $c->post('/api/preferences.php', '{"theme":"dark"}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf()]);
        $this->assertSame(200, $res['status']);
        $page = $c->get('/dashboard.php')['body'];
        $this->assertStringContainsString('data-theme="dark"', $page);
        $this->assertStringContainsString('data-theme-pref="dark"', $page);
        $res = $c->post('/api/preferences.php', '{"theme":"neon"}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $c->csrf()]);
        $this->assertSame(422, $res['status']);
    }

    public function testOpenRedirectBlockedOnPreferences(): void
    {
        $c = $this->loginAs('quality@test.local');
        $c->get('/dashboard.php');
        $res = $c->post('/api/preferences.php', ['_csrf' => $c->csrf(), 'language' => 'id', 'return' => 'https://evil.example/']);
        $this->assertSame(303, $res['status']);
        $this->assertStringNotContainsString('evil.example', $res['headers']['location'][0]);
    }

    public function testInlineScriptsCarryCspNonce(): void
    {
        $c = $this->loginAs('admin@test.local');
        $res = $c->get('/dashboard.php');
        preg_match("/'nonce-([^']+)'/", $res['headers']['content-security-policy'][0], $m);
        $this->assertNotEmpty($m[1]);
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/', $res['body'], $inline);
        foreach ($inline[1] as $attrs) {
            $this->assertStringContainsString('nonce="' . $m[1] . '"', $attrs);
        }
    }

    public function testStorageNotWebAccessible(): void
    {
        $c = $this->client();
        foreach (['/../storage/logs/app.log', '/../.env', '/../config/config.php'] as $p) {
            $res = $c->get($p);
            $this->assertNotSame(200, $res['status'], $p);
        }
    }
}
