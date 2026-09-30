<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Pembaca file .env sederhana. Environment variable asli (dari server/OS)
 * selalu diprioritaskan di atas isi file .env.
 */
final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim((string) preg_replace('/^export\s+/', '', trim($key)));
            $value = trim($value);

            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }

            $quoted = strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && str_ends_with($value, $value[0]);
            if ($quoted) {
                $value = substr($value, 1, -1);
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }

            if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
                continue;
            }

            self::set($key, $value);
        }
    }

    public static function set(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}
