<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, middleware: list<string>}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function add(string $method, string $path, array $handler, array $middleware = []): void
    {
        // Parameter route selalu berupa ID numerik, mis. /pr/{id}
        $regex = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[0-9]+)', Request::normalizePath($path));
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array{0: class-string, 1: string} $handler @param list<string> $middleware */
    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, params: array<string, int>, middleware: list<string>}
     */
    public function match(string $method, string $path): array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (int) $value;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params, 'middleware' => $route['middleware']];
        }

        throw new HttpException($allowed ? 405 : 404);
    }
}
