<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Menerjemahkan entri audit log PR menjadi timeline yang mudah dibaca.
 */
final class Timeline
{
    /**
     * @param list<array<string, mixed>> $auditEntries
     * @return list<array{title: string, detail: string, tone: string, user: string, at: string}>
     */
    public static function fromAudit(array $auditEntries): array
    {
        $events = [];
        foreach ($auditEntries as $entry) {
            $new = json_decode((string) ($entry['new_values'] ?? ''), true) ?: [];
            $action = (string) $entry['action'];

            [$title, $detail, $tone] = match ($action) {
                'pr.create' => ['Draft dibuat', '', 'neutral'],
                'pr.update' => ['Draft diperbarui', self::changedFields($new), 'neutral'],
                'pr.submit' => ['PR diajukan', 'Nomor ' . ($new['pr_number'] ?? '-') . ' diterbitkan', 'info'],
                'pr.resubmit' => ['PR diajukan ulang', 'Pengajuan ke-' . ($new['round'] ?? '?'), 'info'],
                'pr.approve' => ['Approval tahap ' . ($new['step'] ?? '-'), (string) ($new['comment'] ?? ''), 'success'],
                'pr.reject' => ['Ditolak pada tahap ' . ($new['step'] ?? '-'), (string) ($new['comment'] ?? ''), 'danger'],
                'pr.request_revision' => ['Diminta revisi pada tahap ' . ($new['step'] ?? '-'), (string) ($new['comment'] ?? ''), 'warning'],
                'pr.cancel' => ['PR dibatalkan', (string) ($new['reason'] ?? ''), 'danger'],
                'pr.complete' => ['PR selesai & diarsipkan', '', 'success'],
                'pr.attachment_upload' => ['Lampiran ditambahkan', (string) ($new['original_name'] ?? ''), 'neutral'],
                'pr.attachment_delete' => ['Lampiran dihapus', (string) ((json_decode((string) ($entry['old_values'] ?? ''), true) ?: [])['original_name'] ?? ''), 'neutral'],
                default => [null, '', 'neutral'],
            };
            if ($title === null) {
                continue;
            }

            $events[] = [
                'title' => $title,
                'detail' => $detail,
                'tone' => $tone,
                'user' => (string) ($entry['user_name'] ?? 'Sistem'),
                'at' => (string) $entry['created_at'],
            ];
        }

        return array_reverse($events);
    }

    /**
     * @param array<string, mixed> $new
     */
    private static function changedFields(array $new): string
    {
        $labels = [
            'department_id' => 'department',
            'supplier_id' => 'supplier',
            'pr_date' => 'tanggal',
            'tax_rate' => 'pajak',
            'notes' => 'catatan',
            'items' => 'item',
            'grand_total' => 'total',
        ];
        $changed = array_values(array_filter(array_map(
            static fn (string $key): ?string => $labels[$key] ?? null,
            array_keys($new),
        )));

        return $changed === [] ? '' : 'Mengubah ' . implode(', ', array_unique($changed));
    }
}
