<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;

/** Catatan data migrasi yang perlu ditinjau (lihat Settings › Migration Issues). */
final class MigrationIssue
{
    public const STATUSES = ['Needs Review', 'Auto-Corrected', 'Resolved', 'Ignored'];

    /** Jenis issue yang otomatis selesai saat record berhasil dihubungkan ke PO line. */
    public const LINK_TYPES = ['PRODUCT/LINE NOT MATCHED', 'PO NOT FOUND', 'LINE NOT LINKED', 'PO LINE MISMATCH', 'RETURN NOT LINKED TO PO LINE'];

    /**
     * Tandai issue terbuka milik sebuah record sebagai Resolved.
     * @param list<string> $types
     */
    public static function resolveForRecord(string $table, int $recordId, array $types, string $note): int
    {
        if ($types === []) {
            return 0;
        }
        $placeholders = [];
        $params = ['t' => $table, 'r' => $recordId, 'note' => $note, 'by' => Auth::id(), 'at' => date('Y-m-d H:i:s')];
        foreach (array_values($types) as $i => $type) {
            $placeholders[] = ':ty' . $i;
            $params['ty' . $i] = $type;
        }
        $count = Database::query(
            "UPDATE migration_issues SET resolution_status = 'Resolved', resolution_note = :note, resolved_by = :by, resolved_at = :at
             WHERE table_name = :t AND record_id = :r AND resolution_status = 'Needs Review' AND issue_type IN (" . implode(',', $placeholders) . ')',
            $params
        )->rowCount();
        if ($count > 0) {
            Audit::log('resolve_issue', 'migration_issue', null, "{$table} #{$recordId}", ['resolved' => ['old' => null, 'new' => $count . ' issue: ' . $note]]);
        }
        return $count;
    }

    /**
     * Record legacy dihapus user (mis. baris judul/ringkasan spreadsheet):
     * issue yang masih "Needs Review" untuk record tsb ikut ditutup.
     */
    public static function closeForDeletedRecord(string $table, int $recordId): int
    {
        $count = Database::query(
            "UPDATE migration_issues SET resolution_status = 'Resolved', resolution_note = :note, resolved_by = :by, resolved_at = :at
             WHERE table_name = :t AND record_id = :r AND resolution_status = 'Needs Review'",
            ['t' => $table, 'r' => $recordId, 'note' => 'Record dihapus oleh user.', 'by' => Auth::id(), 'at' => date('Y-m-d H:i:s')]
        )->rowCount();
        if ($count > 0) {
            Audit::log('resolve_issue', 'migration_issue', null, "{$table} #{$recordId}", ['resolved' => ['old' => null, 'new' => $count . ' issue: record dihapus']]);
        }
        return $count;
    }

    /** @return list<array<string,mixed>> issue terbuka untuk sebuah record */
    public static function openForRecord(string $table, int $recordId): array
    {
        return Database::fetchAll(
            "SELECT * FROM migration_issues WHERE table_name = :t AND record_id = :r AND resolution_status IN ('Needs Review','Auto-Corrected') ORDER BY id",
            ['t' => $table, 'r' => $recordId]
        );
    }

    public static function openCount(): int
    {
        return (int) Database::fetchValue("SELECT COUNT(*) FROM migration_issues WHERE resolution_status = 'Needs Review'");
    }
}
