<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Audit log APPEND-ONLY (PRD §9.4). Kelas ini hanya menyediakan INSERT dan SELECT;
 * tidak ada fungsi ubah/hapus. Mencatat: user, waktu, IP, aksi, entitas, nilai lama/baru, alasan.
 */
final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'password_hash', 'smtp_password', 'mail.smtp_password', '_csrf', 'token'];

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public static function log(
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $projectId = null,
        ?User $actor = null,
    ): int {
        $user = $actor ?? RequestContext::user();
        return Db::insert('audit_logs', [
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'system',
            'ip_address' => mb_substr(RequestContext::ip(), 0, 45),
            'user_agent' => mb_substr(RequestContext::userAgent(), 0, 255),
            'action' => mb_substr($action, 0, 60),
            'entity_type' => mb_substr($entityType, 0, 40),
            'entity_id' => $entityId === null ? null : mb_substr((string) $entityId, 0, 40),
            'project_id' => $projectId,
            'old_value' => $old === null ? null : self::encode(self::redact($old)),
            'new_value' => $new === null ? null : self::encode(self::redact($new)),
            'reason' => $reason !== null && $reason !== '' ? $reason : null,
            'created_at' => Clock::nowString(),
        ]);
    }

    /**
     * Hanya kolom yang berubah (untuk old/new yang ringkas).
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function diff(array $old, array $new): array
    {
        $o = [];
        $n = [];
        foreach ($new as $k => $v) {
            $ov = $old[$k] ?? null;
            if ((string) json_encode($ov) !== (string) json_encode($v)) {
                $o[$k] = $ov;
                $n[$k] = $v;
            }
        }
        return [$o, $n];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array((string) $k, self::SENSITIVE_KEYS, true)) {
                $data[$k] = '***';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
