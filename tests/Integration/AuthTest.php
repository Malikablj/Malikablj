<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\AuthenticationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\LoginThrottle;
use App\Core\TooManyAttemptsException;
use Tests\Support\DbTestCase;

/** AUTH-01..07: login email+password, hash, nonaktif, rate limit, audit. */
final class AuthTest extends DbTestCase
{
    public function testSuccessfulLoginReturnsUserAndAudits(): void
    {
        $u = $this->makeUser('admin_sales');
        $user = Auth::attempt(strtoupper($u->email), 'Passw0rd!', '10.0.0.1');
        $this->assertSame($u->id, $user->id);
        $this->assertSame('admin_sales', $user->roleCode);
        $this->assertNotNull(Db::value('SELECT last_login_at FROM users WHERE id = ?', [$u->id]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login' AND entity_id = ?", [$u->id]));
    }

    public function testPasswordIsHashedNeverPlaintext(): void
    {
        $u = $this->makeUser('admin');
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$u->id]);
        $this->assertNotSame('Passw0rd!', $hash);
        $this->assertTrue(password_verify('Passw0rd!', $hash));
        $this->assertMatchesRegularExpression('/^\$(2y|argon2id?)\$/', $hash);
    }

    public function testWeakHashIsUpgradedOnLogin(): void
    {
        $u = $this->makeUser('admin'); // dibuat dengan cost 4
        Auth::attempt($u->email, 'Passw0rd!', '10.0.0.1');
        $hash = (string) Db::value('SELECT password_hash FROM users WHERE id = ?', [$u->id]);
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
    }

    public function testWrongPasswordRejectedAndRecorded(): void
    {
        $u = $this->makeUser('npd_staff');
        try {
            Auth::attempt($u->email, 'salah', '10.0.0.2');
            $this->fail('harus gagal');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('Email atau password salah.', $e->getMessage());
        }
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0', [$u->email]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_failed'"));
    }

    public function testUnknownEmailGivesSameMessage(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Email atau password salah.');
        Auth::attempt('tidakada@test.local', 'apapun', '10.0.0.3');
    }

    public function testInactiveUserCannotLogin(): void
    {
        $u = $this->makeUser('drafter', ['is_active' => 0]);
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Akun Anda nonaktif');
        Auth::attempt($u->email, 'Passw0rd!', '10.0.0.4');
    }

    public function testLockoutAfterMaxFailedAttempts(): void
    {
        $u = $this->makeUser('admin');
        Clock::freeze('2026-10-07 09:00:00');
        for ($i = 0; $i < 5; $i++) {
            try {
                Auth::attempt($u->email, 'salah' . $i, '10.0.0.5');
            } catch (AuthenticationException) {
            }
        }
        try {
            Auth::attempt($u->email, 'Passw0rd!', '10.0.0.5'); // password benar tetap ditolak saat terkunci
            $this->fail('harus terkunci');
        } catch (TooManyAttemptsException $e) {
            $this->assertSame(429, $e->httpStatus());
            $this->assertGreaterThan(0, $e->retryAfter());
        }
        // IP lain tidak terkunci untuk email yang sama
        $this->assertSame(0, LoginThrottle::lockedSeconds($u->email, '10.9.9.9'));
        // setelah 15 menit kunci terbuka
        Clock::freeze('2026-10-07 09:16:00');
        $this->assertSame($u->id, Auth::attempt($u->email, 'Passw0rd!', '10.0.0.5')->id);
    }

    public function testSuccessResetsFailureCount(): void
    {
        $u = $this->makeUser('admin');
        Clock::freeze('2026-10-07 09:00:00');
        for ($i = 0; $i < 4; $i++) {
            try { Auth::attempt($u->email, 'x', '10.0.0.6'); } catch (AuthenticationException) {}
        }
        Clock::freeze('2026-10-07 09:01:00');
        Auth::attempt($u->email, 'Passw0rd!', '10.0.0.6');
        Clock::freeze('2026-10-07 09:02:00');
        try { Auth::attempt($u->email, 'x', '10.0.0.6'); } catch (AuthenticationException) {}
        $this->assertSame(0, LoginThrottle::lockedSeconds($u->email, '10.0.0.6'));
    }

    public function testDeactivatedUserLosesSessionAccess(): void
    {
        $u = $this->makeUser('admin_sales');
        $_SESSION = ['user_id' => $u->id];
        Auth::reset();
        $this->assertSame($u->id, Auth::user()?->id);
        Db::update('users', ['is_active' => 0], ['id' => $u->id]);
        Auth::reset();
        $this->assertNull(Auth::user());
        $_SESSION = [];
    }
}
