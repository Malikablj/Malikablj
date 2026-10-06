<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Mailer;
use App\Models\EmailLog;
use App\Models\ProductReturn;
use App\Models\ReturnAttachment;

/**
 * Kirim hasil retur & komplain ke email QC yang tertulis di kolom "Email QC".
 * Dipanggil saat kasus dicatat (bila dicentang), saat diselesaikan (Selesai /
 * Tidak selesai), dan saat user menekan "Kirim ulang". Setiap percobaan dicatat
 * di email_logs dengan status sebenarnya (SENT / LOGGED / FAILED).
 */
final class ComplaintMailer
{
    /** @return array{status:string,driver:string,error:?string,attached:int}|null null bila kolom Email QC kosong */
    public static function send(int $returnId): ?array
    {
        $r = ProductReturn::findFull($returnId);
        if ($r === null) {
            return null;
        }
        $to = Mailer::parseList($r['qc_email'] ?? null) ?? [];
        if ($to === []) {
            return null;
        }
        $files = ReturnAttachment::forReturn($returnId);
        $attachments = [];
        foreach ($files as $f) {
            $path = ReturnAttachment::path($f);
            if ($path !== null) {
                $attachments[] = ['name' => (string) $f['original_name'], 'path' => $path, 'mime' => (string) $f['mime_type']];
            }
        }
        $subject = self::subject($r);
        $result = Mailer::send($to, $subject, self::body($r, $files), $attachments);
        EmailLog::record('return', $returnId, $to, $subject, $result);
        return $result;
    }

    /** Pesan singkat untuk ditampilkan ke user setelah mengirim. */
    public static function describe(?array $result): string
    {
        if ($result === null) {
            return 'Email QC belum diisi, email tidak dikirim.';
        }
        return match ($result['status']) {
            Mailer::SENT   => 'Email terkirim ke QC' . ($result['attached'] > 0 ? ' beserta ' . $result['attached'] . ' file bukti' : '') . '.',
            Mailer::LOGGED => 'Email TIDAK dikirim karena server memakai MAIL_DRIVER=log (hanya dicatat di storage/logs). Atur MAIL_* di .env agar email benar-benar terkirim.',
            default        => 'Email ke QC GAGAL dikirim: ' . $result['error'] . ' Data tetap tersimpan; coba "Kirim ulang ke QC" setelah masalah diperbaiki.',
        };
    }

    /** @param array<string,mixed> $r */
    public static function subject(array $r): string
    {
        $status = ProductReturn::RESOLUTION_LABELS[$r['resolution_status']] ?? $r['resolution_status'];
        return '[' . $r['case_type'] . '] ' . $r['code'] . ' · ' . ($r['customer_name'] ?? 'Customer ?') . ' · ' . ($r['product_name'] ?? $r['product_legacy'] ?? 'Produk ?') . ' — ' . $status;
    }

    /**
     * @param array<string,mixed> $r
     * @param list<array<string,mixed>> $files
     */
    public static function body(array $r, array $files): string
    {
        $qty = $r['return_qty'] ?? $r['affected_qty'];
        $ref = array_filter([$r['order_number'] ?? null, !empty($r['po_number']) ? 'PO ' . $r['po_number'] : null]);
        $rows = [
            'Kode'          => $r['code'],
            'Jenis'         => $r['case_type'] === 'Retur' ? 'Retur (barang dikembalikan)' : 'Komplain',
            'Hasil'         => ProductReturn::RESOLUTION_LABELS[$r['resolution_status']] ?? $r['resolution_status'],
            'Tanggal'       => fmt_date($r['return_date'], '—'),
            'Customer'      => $r['customer_name'] ?? '—',
            'OEF / PO'      => $ref !== [] ? implode(' / ', $ref) : ($r['po_number_legacy'] ?? '—'),
            'Produk'        => $r['product_name'] ?? $r['product_legacy'] ?? '—',
            'Qty'           => $qty !== null ? number_format((int) $qty, 0, ',', '.') . ' pcs' : '—',
            'Alasan'        => $r['reason'] !== null ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—',
            'Surat jalan'   => $r['delivery_sj'] ?? '—',
        ];
        $lines = ["Hasil retur & komplain — PIK Marketing Control", str_repeat('=', 46), ''];
        foreach ($rows as $label => $value) {
            $lines[] = str_pad($label, 13) . ': ' . $value;
        }
        if ($r['note']) {
            $lines[] = '';
            $lines[] = 'Detail masalah:';
            $lines[] = (string) $r['note'];
        }
        if ($r['resolution_status'] !== 'Open') {
            $lines[] = '';
            $lines[] = ($r['resolution_status'] === 'Selesai' ? 'Catatan penyelesaian' : 'Alasan tidak selesai') . ' (' . ($r['resolved_by_name'] ?? '—') . ', ' . fmt_datetime($r['resolved_at']) . '):';
            $lines[] = (string) ($r['resolution_note'] ?: '—');
        }
        $lines[] = '';
        $lines[] = 'Bukti: ' . ($files === [] ? 'tidak ada file' : count($files) . ' file (' . implode(', ', array_map(static fn ($f) => (string) $f['original_name'], $files)) . ')');
        $base = (string) config('app.url', '');
        $lines[] = $base !== '' ? 'Buka di aplikasi: ' . $base . url('/returns/' . (int) $r['id']) : 'Buka di aplikasi: menu Retur & Komplain, kode ' . $r['code'];
        $lines[] = '';
        $lines[] = 'Email ini dikirim otomatis oleh PIK Marketing Control — PT Permata Indo Kemas.';
        return implode("\n", $lines);
    }
}
