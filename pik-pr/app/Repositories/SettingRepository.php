<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingRepository extends Repository
{
    public const DEFAULTS = [
        'company_name' => 'PT PERMATA INDO KEMAS',
        'company_address' => '',
        'pr_prefix' => 'PR/PIK',
        'default_tax_rate' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = self::DEFAULTS;
        foreach ($this->many('SELECT setting_key, setting_value FROM settings') as $row) {
            $values[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $values;
    }

    public function get(string $key): string
    {
        $value = $this->scalar('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);

        return $value === null ? (self::DEFAULTS[$key] ?? '') : (string) $value;
    }

    public function set(string $key, string $value, ?int $userId): void
    {
        $this->run(
            'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?',
            [$key, $value, $userId, $value, $userId],
        );
    }
}
