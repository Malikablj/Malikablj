<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function adminOnlyPages(): iterable
    {
        foreach (['mitha@pik.local' => 'requester', 'budi@pik.local' => 'approver'] as $email => $role) {
            foreach (['/users', '/users/create', '/departments', '/suppliers', '/items/create', '/approval-workflows', '/audit-logs', '/reports', '/reports/export'] as $path) {
                yield "{$role} {$path}" => [$email, 'GET', $path];
            }
            foreach (['/departments', '/suppliers', '/items', '/users', '/approval-workflows'] as $path) {
                yield "{$role} POST {$path}" => [$email, 'POST', $path];
            }
        }
    }

    #[DataProvider('adminOnlyPages')]
    public function test_non_admin_cannot_access_admin_functions(string $email, string $method, string $path): void
    {
        $this->loginAs($email);
        $response = $method === 'GET' ? $this->get($path) : $this->post($path, ['code' => 'HACK', 'name' => 'Hack']);

        self::assertSame(403, $response->status);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM departments WHERE code = 'HACK'"));
    }

    public function test_only_super_admin_can_change_system_settings(): void
    {
        $this->loginAs('admin@pik.local');
        $response = $this->post('/settings/system', ['company_name' => 'X', 'pr_prefix' => 'X', 'default_tax_rate' => '0']);

        self::assertSame(403, $response->status);
        self::assertSame('PT PERMATA INDO KEMAS', $this->scalar("SELECT setting_value FROM settings WHERE setting_key = 'company_name'"));
    }

    public function test_approver_and_admin_cannot_create_pr(): void
    {
        foreach (['budi@pik.local', 'admin@pik.local'] as $email) {
            $this->loginAs($email);
            self::assertSame(403, $this->get('/pr/create')->status);
            self::assertSame(403, $this->post('/pr', $this->prInput())->status);
            $this->logout();
        }
    }

    public function test_requester_only_sees_own_prs_in_list_and_api(): void
    {
        $this->loginAs('dewi@pik.local');

        $list = $this->get('/pr');
        self::assertStringContainsString('QCPR001', $list->body);
        self::assertStringNotContainsString('PDPR00', $list->body);

        $api = json_decode($this->json('GET', '/api/pr')->body, true);
        self::assertSame(1, $api['meta']['total']);
        self::assertSame('Dewi Lestari', $api['data'][0]['requester']);
    }

    public function test_requester_cannot_approve_via_api(): void
    {
        $prId = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'submitted' AND pr_number LIKE '%PDPR%' LIMIT 1");
        $this->loginAs('mitha@pik.local');

        $response = $this->json('POST', '/api/pr/' . $prId . '/approve', ['comment' => 'ok']);
        self::assertSame(403, $response->status);
        self::assertSame('submitted', $this->pr($prId)['status']);
    }

    public function test_api_mutation_requires_csrf_header(): void
    {
        $this->loginAs('mitha@pik.local');
        $response = $this->json('POST', '/api/pr', $this->prInput(), false);

        self::assertSame(419, $response->status);
    }

    public function test_unknown_pr_returns_404(): void
    {
        $this->loginAs('admin@pik.local');

        self::assertSame(404, $this->get('/pr/999999')->status);
        self::assertSame(404, $this->json('GET', '/api/pr/999999')->status);
    }

    public function test_output_is_escaped_against_xss(): void
    {
        $this->loginAs('mitha@pik.local');
        $this->post('/pr', $this->prInput(['notes' => '<script>alert(1)</script>', 'items' => [
            ['name' => '<img src=x onerror=alert(1)>', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '1000'],
        ]]));
        $body = $this->get('/pr/' . $this->latestPrId())->body;

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function test_login_redirect_does_not_allow_open_redirect(): void
    {
        $_SESSION['intended'] = '//evil.example.com/phish';
        $response = $this->post('/login', ['email' => 'mitha@pik.local', 'password' => \Database\Seeders\DemoSeeder::password()]);

        self::assertRedirectTo('/dashboard', $response);
    }
}
