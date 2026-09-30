<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Database;

/**
 * Pengaturan aplikasi (tabel settings, key → value).
 * Nilai default dipakai bila key belum ada di database, sehingga aplikasi
 * tetap berjalan walau seed.sql belum dijalankan ulang setelah update.
 */
final class Setting
{
    public const DEFAULTS = [
        'company_name'                => 'PT Permata Indo Kemas',
        'app_name'                    => 'PIK Marketing Control',
        'invoice_default_due_days'    => '30',
        'ppn_rate'                    => '11',
        'delivery_reminder_days'      => '2',
        'automation_interval_minutes' => '60',
    ];

    /** @var array<string,string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::fetchAll('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$cache[(string) $row['setting_key']] = $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
            }
        }
        $value = self::$cache[$key] ?? null;
        if ($value === null || $value === '') {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (float) $value : $default;
    }

    public static function set(string $key, ?string $value, bool $audit = true): void
    {
        $old = self::get($key);
        Database::query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['k' => $key, 'v' => $value]
        );
        if ($audit && $old !== $value) {
            Audit::log('update', 'setting', null, $key, [$key => ['old' => $old, 'new' => $value]]);
        }
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
