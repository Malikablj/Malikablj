<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Router sederhana. Setiap route mendeklarasikan permission yang dibutuhkan,
 * sehingga otorisasi dicek terpusat di backend sebelum controller dijalankan.
 *
 *   $router->get('/customers', [CustomerController::class, 'index'], 'customers.view');
 *   $router->post('/customers/{id}/delete', [CustomerController::class, 'destroy'], 'customers.delete');
 *
 * Opsi permission:
 *   string  : permission yang wajib dimiliki (mis. "customers.view")
 *   'auth'  : cukup login (mis. notifikasi & profil sendiri)
 *   'guest' : tanpa login (mis. halaman login)
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:array{0:class-string,1:string},permission:string}> */
    private array $routes = [];

    /** @param array{0:class-string,1:string} $handler */
    public function get(string $pattern, array $handler, string $permission): void
    {
        $this->add('GET', $pattern, $handler, $permission);
    }

    /** @param array{0:class-string,1:string} $handler */
    public function post(string $pattern, array $handler, string $permission): void
    {
        $this->add('POST', $pattern, $handler, $permission);
    }

    /** @param array{0:class-string,1:string} $handler */
    private function add(string $method, string $pattern, array $handler, string $permission): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m): string {
            return $m[1] === 'id' || str_ends_with($m[1], '_id') ? '(?P<' . $m[1] . '>\d+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $pattern,
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'permission' => $permission,
        ];
    }

    /** @return list<array{method:string,pattern:string,permission:string}> */
    public function all(): array
    {
        return array_map(static fn ($r) => ['method' => $r['method'], 'pattern' => $r['pattern'], 'permission' => $r['permission']], $this->routes);
    }

    public function dispatch(string $method, string $path): void
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowedMethods = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = ctype_digit($value) ? (int) $value : $value;
                }
            }
            $this->run($route, $params);
            return;
        }
        if ($allowedMethods !== []) {
            throw new HttpException(405);
        }
        throw new HttpException(404);
    }

    /**
     * @param array{method:string,pattern:string,regex:string,handler:array{0:class-string,1:string},permission:string} $route
     * @param array<string,int|string> $params
     */
    private function run(array $route, array $params): void
    {
        $permission = $route['permission'];

        // CSRF untuk semua request POST (termasuk login)
        if ($route['method'] === 'POST') {
            Csrf::verifyRequest();
        }

        if ($permission !== 'guest') {
            if (!Auth::check()) {
                if (Request::isAjax()) {
                    throw new HttpException(401, 'Sesi login berakhir. Silakan login kembali.');
                }
                if (Session::wasExpired()) {
                    Session::flash('warning', 'Sesi Anda berakhir karena tidak aktif. Silakan login kembali.');
                }
                if (Request::method() === 'GET') {
                    Session::set('intended_url', Request::fullPath());
                }
                redirect('/login');
            }
            $user = Auth::user();
            // User yang wajib ganti password hanya boleh ke halaman ganti password/logout
            if ((int) ($user['must_change_password'] ?? 0) === 1
                && !in_array($route['pattern'], ['/profile/password', '/logout'], true)) {
                Session::flash('warning', 'Silakan ganti password sementara Anda terlebih dahulu.');
                redirect('/profile/password');
            }
            if ($permission !== 'auth' && !Auth::can($permission)) {
                throw new HttpException(403);
            }
        }

        [$class, $action] = $route['handler'];
        $controller = new $class();
        $controller->$action(...$params);
    }
}
