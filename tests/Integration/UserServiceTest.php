<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Db;
use App\Core\ValidationException;
use App\User\UserService;
use Tests\Support\DbTestCase;

/** AUTH-03/04: manajemen user oleh Admin, ganti password sendiri, preferensi. */
final class UserServiceTest extends DbTestCase
{
    private UserService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new UserService();
    }

    public function testAdminCreatesUserWithHashedPasswordAndMustChange(): void
    {
        $admin = $this->makeUser('admin');
        $id = $this->svc->create($admin, ['name' => 'Rina Sales', 'email' => 'Rina@PIK.local', 'role' => 'admin_sales', 'job_title' => 'Sales', 'password' => 'Awal12345']);
        $row = Db::fetch('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame('rina@pik.local', $row['email']);
        $this->assertTrue(password_verify('Awal12345', $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
        $audit = Db::fetch("SELECT * FROM audit_logs WHERE action = 'user.create' AND entity_id = ?", [$id]);
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('Awal12345', (string) $audit['new_value']);
    }

    public function testNonAdminCannotManageUsers(): void
    {
        foreach (['npd_staff', 'admin_sales', 'management'] as $role) {
            $actor = $this->makeUser($role);
            try {
                $this->svc->create($actor, ['name' => 'X', 'email' => 'x' . $role . '@t.local', 'role' => 'admin', 'password' => 'Abcdefg12']);
                $this->fail($role . ' tidak boleh membuat user');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidation(): void
    {
        $admin = $this->makeUser('admin');
        try {
            $this->svc->create($admin, ['name' => '', 'email' => 'bukan-email', 'role' => 'superuser', 'password' => '123']);
            $this->fail('harus gagal validasi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertArrayHasKey('role', $e->errors());
            $this->assertArrayHasKey('password', $e->errors());
        }
    }

    public function testDuplicateEmailRejected(): void
    {
        $admin = $this->makeUser('admin');
        $existing = $this->makeUser('drafter');
        $this->expectException(ValidationException::class);
        $this->svc->create($admin, ['name' => 'Dup', 'email' => strtoupper($existing->email), 'role' => 'drafter', 'password' => 'Abcdefg12']);
    }

    public function testPasswordComplexity(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->assertPasswordStrength('abcdefghij', 'password');
    }

    public function testDeactivateKeepsUserAndAudits(): void
    {
        $admin = $this->makeUser('admin');
        $u = $this->makeUser('drafter');
        $this->svc->setActive($admin, $u->id, false, 'Resign');
        $this->assertSame(0, (int) Db::value('SELECT is_active FROM users WHERE id = ?', [$u->id]));
        $this->assertSame('Resign', Db::value("SELECT reason FROM audit_logs WHERE action = 'user.deactivate' AND entity_id = ?", [$u->id]));
        $this->svc->setActive($admin, $u->id, true);
        $this->assertSame(1, (int) Db::value('SELECT is_active FROM users WHERE id = ?', [$u->id]));
    }

    public function testCannotDeactivateSelfOrLastAdmin(): void
    {
        Db::execute('UPDATE users SET is_active = 0');
        $admin = $this->makeUser('admin');
        try {
            $this->svc->setActive($admin, $admin->id, false);
            $this->fail('tidak boleh menonaktifkan diri sendiri');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
        $admin2 = $this->makeUser('admin');
        $this->svc->setActive($admin, $admin2->id, false); // masih ada $admin
        $this->expectException(BusinessRuleException::class);
        $this->svc->update($admin2, $admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'npd_staff']);
    }

    public function testResetPasswordForcesChange(): void
    {
        $admin = $this->makeUser('admin');
        $u = $this->makeUser('quality');
        $this->svc->resetPassword($admin, $u->id, 'Baru12345');
        $row = Db::fetch('SELECT password_hash, must_change_password FROM users WHERE id = ?', [$u->id]);
        $this->assertTrue(password_verify('Baru12345', $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
    }

    public function testChangeOwnPassword(): void
    {
        $u = $this->makeUser('production', ['must_change_password' => 1]);
        try {
            $this->svc->changeOwnPassword($u, 'salah', 'Baru12345', 'Baru12345');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }
        try {
            $this->svc->changeOwnPassword($u, 'Passw0rd!', 'Baru12345', 'Beda12345');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password_confirmation', $e->errors());
        }
        $this->svc->changeOwnPassword($u, 'Passw0rd!', 'Baru12345', 'Baru12345');
        $row = Db::fetch('SELECT password_hash, must_change_password FROM users WHERE id = ?', [$u->id]);
        $this->assertTrue(password_verify('Baru12345', $row['password_hash']));
        $this->assertSame(0, (int) $row['must_change_password']);
    }

    public function testPreferences(): void
    {
        $u = $this->makeUser('management');
        $this->svc->updatePreferences($u, 'en', 'dark');
        $this->assertSame(['language' => 'en', 'theme' => 'dark'], Db::fetch('SELECT language, theme FROM users WHERE id = ?', [$u->id]));
        $this->expectException(ValidationException::class);
        $this->svc->updatePreferences($u, 'fr', null);
    }

    public function testCannotChangeOwnRole(): void
    {
        $admin = $this->makeUser('admin');
        $this->expectException(BusinessRuleException::class);
        $this->svc->update($admin, $admin->id, ['name' => 'A', 'email' => $admin->email, 'role' => 'drafter']);
    }
}
