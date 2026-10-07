<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Db;
use App\Core\User;

/**
 * Test integrasi dengan MySQL nyata (database npd_test). Setiap test berjalan di dalam
 * transaksi yang di-rollback di tearDown, sehingga test saling terisolasi.
 */
abstract class DbTestCase extends TestCase
{
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Db::beginOuter();
    }

    protected function tearDown(): void
    {
        Db::rollbackOuter();
        parent::tearDown();
    }

    /** Buat user dengan role tertentu. Password bawaan: Passw0rd! */
    protected function makeUser(string $role, array $attrs = []): User
    {
        self::$seq++;
        $roleId = (int) Db::value('SELECT id FROM roles WHERE code = ?', [$role]);
        $id = Db::insert('users', array_merge([
            'role_id' => $roleId,
            'name' => ucfirst(str_replace('_', ' ', $role)) . ' ' . self::$seq,
            'email' => $role . self::$seq . '_' . bin2hex(random_bytes(3)) . '@test.local',
            'password_hash' => password_hash('Passw0rd!', PASSWORD_BCRYPT, ['cost' => 4]),
            'job_title' => ucfirst($role),
            'is_active' => 1,
            'language' => 'id',
        ], $attrs));
        return User::find($id);
    }
}
