<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\PrRepository;
use App\Repositories\UserRepository;
use Database\Seeders\DemoSeeder;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Setiap test dibungkus transaksi MySQL yang di-rollback, sehingga data
 * demo hasil seeder selalu kembali bersih. Request HTTP disimulasikan
 * langsung ke kernel aplikasi (tanpa web server).
 */
abstract class TestCase extends BaseTestCase
{
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();
        Session::reset();
        Auth::reset();
        Request::setCurrent(null);
        Database::begin();
        $this->app = new App();
    }

    protected function tearDown(): void
    {
        while (Database::depth() > 0) {
            Database::rollBack();
        }
        Session::reset();
        Auth::reset();
        Request::setCurrent(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function get(string $path, array $query = []): Response
    {
        return $this->app->handle($this->request('GET', $path, $query));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<array<string, mixed>>> $files
     */
    protected function post(string $path, array $data = [], array $files = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $data['_token'] ??= Csrf::token();
        }

        return $this->app->handle($this->request('POST', $path, [], $data, $files));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function json(string $method, string $path, array $data = [], bool $withToken = true): Response
    {
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];
        if ($withToken) {
            $headers['x-csrf-token'] = Csrf::token();
        }
        $body = $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR);

        return $this->app->handle(new Request($method, $path, $method === 'GET' ? $data : [], [], [], $this->server(), $headers, $body));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, list<array<string, mixed>>> $files
     */
    private function request(string $method, string $path, array $query = [], array $post = [], array $files = []): Request
    {
        return new Request($method, $path, $query, $post, $files, $this->server(), ['referer' => 'http://localhost' . $path]);
    }

    /**
     * @return array<string, string>
     */
    private function server(): array
    {
        return ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit', 'HTTP_HOST' => 'localhost'];
    }

    /**
     * Login melalui form login yang sebenarnya.
     *
     * @return array<string, mixed>
     */
    protected function loginAs(string $email): array
    {
        $response = $this->post('/login', ['email' => $email, 'password' => DemoSeeder::password()]);
        self::assertSame(302, $response->status, 'Login gagal untuk ' . $email);
        self::assertStringEndsWith('/dashboard', $response->headers['Location']);

        return $this->user($email);
    }

    protected function logout(): void
    {
        $this->post('/logout');
    }

    /**
     * @return array<string, mixed>
     */
    protected function user(string $email): array
    {
        $user = (new UserRepository())->findByEmail($email);
        self::assertNotNull($user, 'User tidak ditemukan: ' . $email);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function pr(int $id): array
    {
        $pr = (new PrRepository())->find($id);
        self::assertNotNull($pr, 'PR tidak ditemukan: ' . $id);

        return $pr;
    }

    protected function flashed(string $key): mixed
    {
        return $_SESSION['_flash_new'][$key] ?? null;
    }

    protected static function assertRedirectTo(string $path, Response $response): void
    {
        self::assertTrue($response->isRedirect(), 'Response bukan redirect (status ' . $response->status . ')');
        self::assertSame($path, parse_url($response->headers['Location'], PHP_URL_PATH) . (($q = parse_url($response->headers['Location'], PHP_URL_QUERY)) ? '?' . $q : ''));
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * Input PR standar: 2 item (Lem korea 2 x 65.000, Autosol 3 x 50.000).
     *
     * @return array<string, mixed>
     */
    protected function prInput(array $overrides = []): array
    {
        $department = (int) $this->scalar("SELECT id FROM departments WHERE code = 'PD'");
        $supplier = (int) $this->scalar("SELECT id FROM suppliers WHERE code = 'SHP'");

        return array_replace([
            'department_id' => (string) $department,
            'pr_date' => date('Y-m-d'),
            'supplier_id' => (string) $supplier,
            'tax_rate' => '0',
            'notes' => 'Test PR',
            'items' => [
                ['item_id' => '', 'name' => 'Lem korea', 'description' => '', 'quantity' => '2', 'unit' => 'pcs', 'unit_price' => '65000'],
                ['item_id' => '', 'name' => 'Autosol', 'description' => '', 'quantity' => '3', 'unit' => 'pcs', 'unit_price' => '50000'],
            ],
        ], $overrides);
    }

    protected function latestPrId(): int
    {
        return (int) $this->scalar('SELECT MAX(id) FROM purchase_requisitions');
    }
}
