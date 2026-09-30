<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use Database\Seeders\DemoSeeder;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    public function test_login_page_is_available_for_guests(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="_token"', $response->body);
        self::assertStringContainsString("default-src 'self'", $response->headers['Content-Security-Policy']);
    }

    public function test_login_with_correct_credentials(): void
    {
        $response = $this->post('/login', ['email' => 'mitha@pik.local', 'password' => DemoSeeder::password()]);

        self::assertRedirectTo('/dashboard', $response);
        self::assertSame('mitha@pik.local', Auth::user()['email']);
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login' AND user_id = ?", [Auth::id()]));

        $dashboard = $this->get('/dashboard');
        self::assertSame(200, $dashboard->status);
        self::assertStringContainsString('Mitha', $dashboard->body);
    }

    public function test_login_is_case_insensitive_on_email(): void
    {
        $response = $this->post('/login', ['email' => 'MITHA@PIK.LOCAL', 'password' => DemoSeeder::password()]);

        self::assertRedirectTo('/dashboard', $response);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $response = $this->post('/login', ['email' => 'mitha@pik.local', 'password' => 'salah-password1']);

        self::assertRedirectTo('/login', $response);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertFalse(Auth::check());
        self::assertSame('Email atau password salah.', $this->flashed('error'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_failed'"));
    }

    public function test_unknown_email_gets_same_generic_message(): void
    {
        $this->post('/login', ['email' => 'tidakada@pik.local', 'password' => 'apa-saja123']);

        self::assertSame('Email atau password salah.', $this->flashed('error'));
    }

    public function test_login_is_locked_after_too_many_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'budi@pik.local', 'password' => 'salah' . $i]);
        }
        $response = $this->post('/login', ['email' => 'budi@pik.local', 'password' => DemoSeeder::password()]);

        self::assertRedirectTo('/login', $response);
        self::assertFalse(Auth::check());
        self::assertStringContainsString('Terlalu banyak percobaan', (string) $this->flashed('error'));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->scalar("UPDATE users SET is_active = 0 WHERE email = 'dewi@pik.local'");
        $this->post('/login', ['email' => 'dewi@pik.local', 'password' => DemoSeeder::password()]);

        self::assertFalse(Auth::check());
        self::assertStringContainsString('dinonaktifkan', (string) $this->flashed('error'));
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $this->loginAs('dewi@pik.local');
        $this->scalar("UPDATE users SET is_active = 0 WHERE email = 'dewi@pik.local'");

        self::assertRedirectTo('/login', $this->get('/dashboard'));
    }

    public function test_logout_ends_session(): void
    {
        $user = $this->loginAs('mitha@pik.local');
        $response = $this->post('/logout');

        self::assertRedirectTo('/login', $response);
        self::assertFalse(Auth::check());
        self::assertRedirectTo('/login', $this->get('/dashboard'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.logout' AND user_id = ?", [$user['id']]));
    }

    public function test_guest_is_redirected_from_protected_pages(): void
    {
        foreach (['/dashboard', '/pr', '/pr/create', '/approvals', '/users', '/audit-logs', '/settings'] as $path) {
            self::assertRedirectTo('/login', $this->get($path));
        }
    }

    public function test_api_returns_401_for_guest(): void
    {
        $response = $this->json('GET', '/api/pr');

        self::assertSame(401, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function test_post_without_csrf_token_is_rejected(): void
    {
        $response = $this->post('/login', ['email' => 'mitha@pik.local', 'password' => DemoSeeder::password()], [], false);

        self::assertTrue($response->isRedirect());
        self::assertFalse(Auth::check());
        self::assertStringContainsString('kedaluwarsa', (string) $this->flashed('error'));
    }

    public function test_session_expires_after_idle_timeout(): void
    {
        $this->loginAs('mitha@pik.local');
        $_SESSION['last_activity'] = time() - 3 * 3600;

        self::assertRedirectTo('/login', $this->get('/dashboard'));
    }

    public function test_passwords_are_stored_hashed(): void
    {
        $hash = (string) $this->scalar("SELECT password_hash FROM users WHERE email = 'mitha@pik.local'");

        self::assertNotSame(DemoSeeder::password(), $hash);
        self::assertTrue(password_verify(DemoSeeder::password(), $hash));
    }

    public function test_user_can_change_own_password(): void
    {
        $this->loginAs('mitha@pik.local');
        $response = $this->post('/settings/password', [
            'current_password' => DemoSeeder::password(),
            'password' => 'PasswordBaru123',
            'password_confirmation' => 'PasswordBaru123',
        ]);

        self::assertRedirectTo('/settings', $response);
        $hash = (string) $this->scalar("SELECT password_hash FROM users WHERE email = 'mitha@pik.local'");
        self::assertTrue(password_verify('PasswordBaru123', $hash));
    }
}
