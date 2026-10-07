<?php
declare(strict_types=1);

namespace App\Core;

/** Akses konfigurasi bertitik: Config::get('db.host'). */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    /** @param array<string,mixed> $items */
    public static function init(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function set(string $key, mixed $value): void
    {
        $node = &self::$items;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    public static function isDevelopment(): bool
    {
        return self::get('app.env') === 'development';
    }
}
