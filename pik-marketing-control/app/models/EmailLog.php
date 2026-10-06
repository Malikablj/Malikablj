<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Auth;
use App\Helpers\Database;

/** Riwayat email yang dikirim aplikasi (status kirim nyata: SENT / LOGGED / FAILED). */
final class EmailLog
{
    public const LABELS = [
        'SENT'   => 'Terkirim',
        'LOGGED' => 'Dicatat (log)',
        'FAILED' => 'Gagal',
    ];

    /**
     * @param list<string> $to
     * @param array{status:string,driver:string,error:?string} $result
     */
    public static function record(string $entityType, int $entityId, array $to, string $subject, array $result): int
    {
        return Database::insert('email_logs', [
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'recipients'    => mb_substr(implode(', ', $to), 0, 500),
            'subject'       => mb_substr($subject, 0, 255),
            'driver'        => $result['driver'],
            'status'        => $result['status'],
            'error_message' => $result['error'] !== null ? mb_substr($result['error'], 0, 500) : null,
            'sent_by'       => Auth::id(),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forEntity(string $entityType, int $entityId, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT l.*, u.name AS sent_by_name FROM email_logs l LEFT JOIN users u ON u.id = l.sent_by
             WHERE l.entity_type = :t AND l.entity_id = :id ORDER BY l.id DESC LIMIT ' . max(1, $limit),
            ['t' => $entityType, 'id' => $entityId]
        );
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'SENT' => 'success', 'LOGGED' => 'info', default => 'danger',
        };
    }
}
