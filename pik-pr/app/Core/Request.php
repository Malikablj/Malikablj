<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;
    private static string $basePath = '';

    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $uriPath = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

        // Mendukung instalasi di sub-folder, mis. http://server/pik-pr/public
        $scriptDir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php')));
        $base = rtrim($scriptDir, '/');
        if ($base !== '' && str_starts_with($uriPath, $base . '/')) {
            $uriPath = substr($uriPath, strlen($base));
        } elseif ($base !== '' && $uriPath === $base) {
            $uriPath = '/';
        } else {
            $base = '';
        }
        self::$basePath = $base;

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $post = $_POST;
        if ($method === 'POST' && isset($post['_method'])) {
            $override = strtoupper((string) $post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self(
            $method,
            self::normalizePath($uriPath),
            $_GET,
            $post,
            self::normalizeFiles($_FILES),
            $server,
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    public static function setCurrent(?Request $request): void
    {
        self::$current = $request;
    }

    public static function current(): ?Request
    {
        return self::$current;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Semua input body (form atau JSON).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->json(), $this->post);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $this->jsonCache = [];
        if (str_contains($this->header('content-type') ?? '', 'application/json') && $this->body !== '') {
            $decoded = json_decode($this->body, true);
            if (is_array($decoded)) {
                $this->jsonCache = $decoded;
            }
        }

        return $this->jsonCache;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? 'cli');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return $https !== '' && $https !== 'off';
    }

    /**
     * Daftar file untuk sebuah field (mendukung input multiple).
     *
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function files(string $key): array
    {
        return $this->files[$key] ?? [];
    }

    /**
     * Mengubah struktur $_FILES menjadi daftar file per field.
     *
     * @param array<string, mixed> $files
     * @return array<string, list<array{name: string, type: string, tmp_name: string, error: int, size: int}>>
     */
    private static function normalizeFiles(array $files): array
    {
        $result = [];
        foreach ($files as $field => $spec) {
            if (!is_array($spec) || !isset($spec['name'])) {
                continue;
            }
            if (is_array($spec['name'])) {
                foreach (array_keys($spec['name']) as $i) {
                    if (is_array($spec['name'][$i])) {
                        continue;
                    }
                    $result[$field][] = [
                        'name' => (string) $spec['name'][$i],
                        'type' => (string) ($spec['type'][$i] ?? ''),
                        'tmp_name' => (string) ($spec['tmp_name'][$i] ?? ''),
                        'error' => (int) ($spec['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'size' => (int) ($spec['size'][$i] ?? 0),
                    ];
                }
            } else {
                $result[$field][] = [
                    'name' => (string) $spec['name'],
                    'type' => (string) ($spec['type'] ?? ''),
                    'tmp_name' => (string) ($spec['tmp_name'] ?? ''),
                    'error' => (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($spec['size'] ?? 0),
                ];
            }
        }

        return $result;
    }
}
