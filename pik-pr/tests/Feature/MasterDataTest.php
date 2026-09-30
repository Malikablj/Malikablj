<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class MasterDataTest extends TestCase
{
    public function test_admin_can_open_every_master_data_page(): void
    {
        $this->loginAs('admin@pik.local');

        foreach (['/users', '/users/create', '/departments', '/departments/create', '/suppliers', '/suppliers/create',
            '/items', '/items/create', '/approval-workflows', '/approval-workflows/create', '/audit-logs', '/reports'] as $path) {
            self::assertSame(200, $this->get($path)->status, $path);
        }
    }

    public function test_admin_can_create_update_and_toggle_supplier(): void
    {
        $admin = $this->loginAs('admin@pik.local');

        $response = $this->post('/suppliers', ['code' => 'abc-01', 'name' => 'CV Abadi', 'contact' => 'Pak Joko', 'address' => 'Bekasi']);
        self::assertRedirectTo('/suppliers', $response);
        $id = (int) $this->scalar("SELECT id FROM suppliers WHERE code = 'ABC-01'");
        self::assertGreaterThan(0, $id, 'Kode dinormalisasi menjadi huruf kapital');

        $this->post('/suppliers/' . $id, ['code' => 'ABC-01', 'name' => 'CV Abadi Jaya', 'contact' => 'Pak Joko', 'address' => 'Bekasi']);
        self::assertSame('CV Abadi Jaya', $this->scalar('SELECT name FROM suppliers WHERE id = ?', [$id]));

        $this->post('/suppliers/' . $id . '/toggle');
        self::assertSame(0, (int) $this->scalar('SELECT is_active FROM suppliers WHERE id = ?', [$id]));

        $actions = array_column(\App\Core\Database::connection()->query(
            "SELECT action FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = {$id} ORDER BY id",
        )->fetchAll(), 'action');
        self::assertSame(['supplier.create', 'supplier.update', 'supplier.deactivate'], $actions);
        self::assertSame($admin['id'], (int) $this->scalar("SELECT user_id FROM audit_logs WHERE entity_type = 'supplier' AND entity_id = ? LIMIT 1", [$id]));
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->loginAs('admin@pik.local');
        $this->post('/departments', ['code' => 'PD', 'name' => 'Departemen Lain']);

        self::assertStringContainsString('sudah digunakan', errors_flashed()['code'] ?? '');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM departments WHERE code = 'PD'"));
    }

    public function test_item_price_is_validated_and_stored_as_decimal(): void
    {
        $this->loginAs('admin@pik.local');
        $this->post('/items', ['code' => 'X1', 'name' => 'Test', 'unit' => 'pcs', 'default_price' => 'abc']);
        self::assertArrayHasKey('default_price', errors_flashed());

        $this->post('/items', ['code' => 'X1', 'name' => 'Test', 'unit' => 'pcs', 'default_price' => '12500.50']);
        self::assertSame('12500.50', $this->scalar("SELECT default_price FROM items WHERE code = 'X1'"));
    }

    public function test_department_in_use_cannot_be_deleted(): void
    {
        $this->loginAs('admin@pik.local');
        $id = (int) $this->scalar("SELECT id FROM departments WHERE code = 'PD'");
        $this->post('/departments/' . $id . '/delete');

        self::assertStringContainsString('sudah dipakai', (string) $this->flashed('error'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM departments WHERE id = ?', [$id]));
    }

    public function test_unused_department_can_be_deleted(): void
    {
        $this->loginAs('admin@pik.local');
        $this->post('/departments', ['code' => 'TMP', 'name' => 'Temporary']);
        $id = (int) $this->scalar("SELECT id FROM departments WHERE code = 'TMP'");
        $this->post('/departments/' . $id . '/delete');

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM departments WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'department.delete' AND entity_id = ?", [$id]));
    }

    public function test_admin_can_create_and_deactivate_user(): void
    {
        $this->loginAs('admin@pik.local');
        $department = (int) $this->scalar("SELECT id FROM departments WHERE code = 'QC'");
        $response = $this->post('/users', [
            'name' => 'Andi Pratama', 'email' => 'andi@pik.local', 'role' => 'requester',
            'department_id' => (string) $department, 'job_title' => 'Staff QC', 'password' => 'Rahasia123',
        ]);
        self::assertRedirectTo('/users', $response);

        $user = $this->user('andi@pik.local');
        self::assertTrue(password_verify('Rahasia123', (string) $user['password_hash']));
        $newValues = (string) $this->scalar("SELECT new_values FROM audit_logs WHERE action = 'user.create' AND entity_id = ?", [$user['id']]);
        self::assertStringNotContainsString('$2y$', $newValues, 'Hash password tidak boleh masuk audit log');

        $this->post('/users/' . $user['id'] . '/toggle');
        self::assertSame(0, (int) $this->scalar('SELECT is_active FROM users WHERE id = ?', [$user['id']]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.deactivate' AND entity_id = ?", [$user['id']]));
    }

    public function test_admin_cannot_create_or_edit_super_admin(): void
    {
        $this->loginAs('admin@pik.local');
        $this->post('/users', ['name' => 'X', 'email' => 'x@pik.local', 'role' => 'super_admin', 'password' => 'Rahasia123']);
        self::assertArrayHasKey('role', errors_flashed());
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM users WHERE email = 'x@pik.local'"));

        $superAdmin = $this->user('superadmin@pik.local');
        self::assertSame(403, $this->get('/users/' . $superAdmin['id'] . '/edit')->status);
        self::assertSame(403, $this->post('/users/' . $superAdmin['id'] . '/toggle')->status);
    }

    public function test_user_cannot_deactivate_self(): void
    {
        $admin = $this->loginAs('admin@pik.local');
        $this->post('/users/' . $admin['id'] . '/toggle');

        self::assertStringContainsString('akun sendiri', (string) $this->flashed('error'));
        self::assertSame(1, (int) $this->scalar('SELECT is_active FROM users WHERE id = ?', [$admin['id']]));
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->loginAs('superadmin@pik.local');
        $this->post('/users', ['name' => 'Y', 'email' => 'y@pik.local', 'role' => 'admin', 'password' => 'abc']);

        self::assertArrayHasKey('password', errors_flashed());
    }
}

/**
 * @return array<string, string>
 */
function errors_flashed(): array
{
    return (array) ($_SESSION['_flash_new']['errors'] ?? []);
}
