<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Kernel HTTP: session → auth → routing → CSRF → middleware → controller,
 * lalu menambahkan security header ke setiap response.
 */
final class App
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        (require BASE_PATH . '/routes/web.php')($this->router);
    }

    public function handle(Request $request): Response
    {
        Request::setCurrent($request);

        try {
            Session::start((array) Config::get('app.session'), $request->isSecure());
            Session::ageFlash();
            Auth::boot();

            $route = $this->router->match($request->method, $request->path);

            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !Csrf::verify($request)) {
                throw new HttpException(419);
            }

            foreach ($route['middleware'] as $middleware) {
                $early = $this->runMiddleware($middleware, $request);
                if ($early !== null) {
                    return $this->finalize($request, $early);
                }
            }

            [$class, $method] = $route['handler'];
            $response = (new $class())->{$method}($request, ...array_values($route['params']));
        } catch (ValidationException $e) {
            $response = $this->validationFailed($request, $e);
        } catch (BusinessRuleException $e) {
            $response = $this->businessRuleFailed($request, $e);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
        } catch (Throwable $e) {
            self::report($e);
            $message = Config::get('app.debug') ? get_class($e) . ': ' . $e->getMessage() : HttpException::defaultMessage(500);
            $response = $this->error($request, 500, $message);
        }

        return $this->finalize($request, $response);
    }

    private function runMiddleware(string $middleware, Request $request): ?Response
    {
        [$name, $argument] = array_pad(explode(':', $middleware, 2), 2, '');

        switch ($name) {
            case 'auth':
                if (Auth::check()) {
                    return null;
                }
                if ($request->isApi()) {
                    throw new HttpException(401);
                }
                if ($request->method === 'GET') {
                    $query = $request->query ? '?' . http_build_query($request->query) : '';
                    Session::put('intended', $request->path . $query);
                }

                return Response::redirect('/login');

            case 'guest':
                return Auth::check() ? Response::redirect('/dashboard') : null;

            case 'role':
                $roles = explode(',', $argument);
                if (!in_array(Auth::user()['role'] ?? '', $roles, true)) {
                    throw new HttpException(403);
                }

                return null;
        }

        throw new HttpException(500, 'Middleware tidak dikenal: ' . $name);
    }

    private function validationFailed(Request $request, ValidationException $e): Response
    {
        if ($request->isApi()) {
            return Response::json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        $old = $request->all();
        unset($old['_token'], $old['_method'], $old['password'], $old['password_confirmation'], $old['current_password']);
        Session::flash('errors', $e->errors);
        Session::flash('old', $old);
        Session::flash('error', $e->getMessage());

        return Response::redirect($this->backUrl($request));
    }

    private function businessRuleFailed(Request $request, BusinessRuleException $e): Response
    {
        if ($request->isApi()) {
            return Response::json(['message' => $e->getMessage()], 422);
        }
        Session::flash('error', $e->getMessage());

        return Response::redirect($this->backUrl($request));
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->isApi()) {
            return Response::json(['message' => $message], $status);
        }

        // Token CSRF kedaluwarsa: kembalikan user ke form dengan pesan yang jelas.
        if ($status === 419) {
            Session::flash('error', $message);

            return Response::redirect($this->backUrl($request, Auth::check() ? '/dashboard' : '/login'));
        }

        $html = View::render('errors/error', [
            'title' => match ($status) {
                403 => 'Akses ditolak',
                404 => 'Tidak ditemukan',
                405 => 'Metode tidak diizinkan',
                default => 'Terjadi kesalahan',
            },
            'status' => $status,
            'message' => $message,
        ], Auth::check() ? 'layouts/app' : 'layouts/guest');

        return Response::html($html, $status);
    }

    /**
     * URL kembali yang aman: hanya Referer dari host yang sama.
     */
    private function backUrl(Request $request, string $fallback = '/dashboard'): string
    {
        $referer = $request->header('referer');
        if ($referer === null) {
            return $fallback;
        }
        $parts = parse_url($referer);
        $host = (string) ($request->server['HTTP_HOST'] ?? '');
        $refererHost = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($host === '' || $refererHost !== $host) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '/');
        $base = Request::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function finalize(Request $request, Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        if (!isset($response->headers['Cache-Control'])) {
            $headers['Cache-Control'] = 'no-store, private';
        }
        if (str_starts_with($response->headers['Content-Type'] ?? '', 'text/html')) {
            $headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self'; "
                . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; "
                . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
        }
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            $response->headers[$name] ??= $value;
        }

        return $response;
    }

    public static function report(Throwable $e): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}
