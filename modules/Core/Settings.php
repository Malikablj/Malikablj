<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pengaturan aplikasi (tabel application_settings), di-cache per request.
 * Nilai bertipe 'secret' dienkripsi dengan Crypto (APP_KEY).
 */
final class Settings
{
    /** @var array<string,array{value:?string,type:string}>|null */
    private static ?array $cache = null;

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        foreach (Db::fetchAll('SELECT setting_key, setting_value, value_type FROM application_settings') as $row) {
            self::$cache[$row['setting_key']] = ['value' => $row['setting_value'], 'type' => $row['value_type']];
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        if (!isset(self::$cache[$key])) {
            return $default;
        }
        $item = self::$cache[$key];
        if ($item['type'] === 'secret') {
            return $item['value'] ? Crypto::decrypt($item['value']) : $default;
        }
        return $item['value'] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int) $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null || $v === '' ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<mixed> */
    public static function json(string $key): array
    {
        $v = self::get($key);
        $d = $v ? json_decode($v, true) : null;
        return is_array($d) ? $d : [];
    }

    public static function type(string $key): ?string
    {
        self::load();
        return self::$cache[$key]['type'] ?? null;
    }

    /**
     * Simpan pengaturan + audit (nilai rahasia tidak pernah dicatat).
     */
    public static function set(string $key, ?string $value, ?int $userId = null, string $type = ''): void
    {
        self::load();
        $type = $type !== '' ? $type : (self::$cache[$key]['type'] ?? 'string');
        $old = self::$cache[$key]['value'] ?? null;
        $stored = ($type === 'secret' && $value !== null && $value !== '') ? Crypto::encrypt($value) : $value;
        Db::execute(
            'INSERT INTO application_settings (setting_key, setting_value, value_type, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [$key, $stored, $type, $userId]
        );
        self::$cache[$key] = ['value' => $stored, 'type' => $type];
        if ($old !== $stored) {
            AuditLogger::log(
                'settings.update',
                'setting',
                $key,
                $type === 'secret' ? ['value' => '***'] : ['value' => $old],
                $type === 'secret' ? ['value' => '***'] : ['value' => $value]
            );
        }
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
