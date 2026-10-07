<?php

declare(strict_types=1);

namespace App\Helpers;

use Throwable;

/** Pencatatan audit log: siapa melakukan apa, kapan, dan perubahan datanya. */
final class Audit
{
    /** @param array<string,mixed>|null $changes */
    public static function log(string $action, ?string $entityType, ?int $entityId, ?string $label = null, ?array $changes = null): void
    {
        try {
            $user = Auth::user();
            Database::insert('audit_logs', [
                'user_id'      => $user ? (int) $user['id'] : null,
                'user_name'    => $user ? mb_substr((string) $user['name'], 0, 120) : (PHP_SAPI === 'cli' ? 'system (CLI)' : null),
                'action'       => mb_substr($action, 0, 40),
                'entity_type'  => $entityType !== null ? mb_substr($entityType, 0, 40) : null,
                'entity_id'    => $entityId,
                'entity_label' => $label !== null ? mb_substr($label, 0, 190) : null,
                'changes'      => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip_address'   => PHP_SAPI === 'cli' ? null : Request::ip(),
                'user_agent'   => PHP_SAPI === 'cli' ? 'cli' : Request::userAgent(),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Audit tidak boleh menggagalkan aksi utama, tetapi kegagalan dicatat.
            Logger::error('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Hitung perbedaan field antara data lama dan baru.
     *
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,array{old:mixed,new:mixed}>
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $field => $value) {
            if (in_array($field, ['updated_at', 'updated_by', 'created_at', 'created_by', 'password_hash'], true)) {
                continue;
            }
            $before = $old[$field] ?? null;
            if (self::normalize($before) !== self::normalize($value)) {
                $changes[$field] = ['old' => $before, 'new' => $value];
            }
        }
        return $changes;
    }

    /** @param array<string,mixed> $data @return array<string,array{old:mixed,new:mixed}> */
    public static function snapshot(array $data): array
    {
        $changes = [];
        foreach ($data as $field => $value) {
            if (in_array($field, ['updated_at', 'updated_by', 'created_at', 'created_by', 'password_hash'], true) || $value === null || $value === '') {
                continue;
            }
            $changes[$field] = ['old' => null, 'new' => $value];
        }
        return $changes;
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            // 1500.00 dan "1500" dianggap sama
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }
        return (string) $value;
    }
}
