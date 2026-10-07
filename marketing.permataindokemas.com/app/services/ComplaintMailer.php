<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Logger;
use App\Helpers\Mailer;
use App\Models\ComplaintAttachment;
use App\Models\ProductReturn;
use App\Models\Setting;
use Throwable;

/**
 * Email notifikasi complaint ke QC (alamat di kolom "Email QC" complaint).
 *   - event "new"    : complaint baru dicatat
 *   - event "result" : complaint ditandai Selesai / Tidak selesai
 *   - event "manual" : kirim ulang dari halaman complaint
 * Bukti (gambar/PDF) dilampirkan selama total ukurannya ≤ Mailer::MAX_ATTACH_BYTES.
 */
final class ComplaintMailer
{
    /**
     * @return array{status:string,error:?string,to:list<string>} status: sent | failed | skipped
     */
    public static function send(int $returnId, string $event): array
    {
        $r = ProductReturn::findFull($returnId);
        if ($r === null) {
            return ['status' => 'skipped', 'error' => 'Complaint tidak ditemukan.', 'to' => []];
        }
        $to = Mailer::parseList($r['qc_email'] ?? null)['valid'];
        if ($to === []) {
            return ['status' => 'skipped', 'error' => null, 'to' => []];
        }
        try {
            [$subject, $html, $attachments] = self::compose($r, $event);
            Mailer::send($to, $subject, $html, null, $attachments);
            $result = ['status' => 'sent', 'error' => null, 'to' => $to];
            Audit::log('email', ProductReturn::ENTITY, $returnId, (string) $r['code'], ['email_qc' => ['old' => null, 'new' => implode(', ', $to) . ' (' . $event . ')']]);
        } catch (Throwable $e) {
            Logger::error('Email complaint ' . $r['code'] . ' gagal: ' . $e->getMessage());
            $result = ['status' => 'failed', 'error' => $e->getMessage(), 'to' => $to];
        }
        ProductReturn::recordEmail($returnId, $result);
        return $result;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{0:string,1:string,2:list<array{name:string,path:string,mime:string}>}
     */
    public static function compose(array $r, string $event): array
    {
        $status = ProductReturn::STATUS_LABELS[$r['complaint_status']] ?? $r['complaint_status'];
        $reason = $r['reason'] ? (ProductReturn::REASON_LABELS[$r['reason']] ?? $r['reason']) : '—';
        $company = (string) (Setting::get('company_name') ?? 'PT Permata Indo Kemas');
        $headline = match ($event) {
            'new'    => 'Complaint baru dari customer',
            'result' => 'Hasil complaint: ' . $status,
            default  => 'Informasi complaint (' . $status . ')',
        };
        $subject = '[Complaint ' . $r['code'] . '] ' . ($r['customer_name'] ?? 'Customer') . ' — ' . ($event === 'new' ? 'Baru' : $status);
        $link = absolute_url('/returns/' . $r['id']);

        // Lampiran: bukti complaint selama total ukuran masih dalam batas
        $attachments = [];
        $skipped = [];
        $total = 0;
        foreach (ComplaintAttachment::forReturn((int) $r['id']) as $att) {
            $path = ComplaintAttachment::path($att);
            if (!is_file($path)) {
                continue;
            }
            if ($total + (int) $att['file_size'] > Mailer::MAX_ATTACH_BYTES) {
                $skipped[] = (string) $att['original_name'];
                continue;
            }
            $total += (int) $att['file_size'];
            $attachments[] = ['name' => (string) $att['original_name'], 'path' => $path, 'mime' => (string) $att['mime_type']];
        }

        $color = match ($r['complaint_status']) {
            'Resolved'   => '#1f8a3b',
            'Unresolved' => '#c4271b',
            default      => '#b26a00',
        };
        $rows = [
            'No. complaint'   => $r['code'],
            'Tanggal'         => fmt_date($r['return_date']),
            'Customer'        => $r['customer_name'] ?? '—',
            'Order / PO'      => $r['po_number'] ?? ($r['po_number_legacy'] ?? '—'),
            'Produk'          => $r['product_name'] ?? ($r['product_legacy'] ?? '—'),
            'Jenis'           => ProductReturn::TYPE_LABELS[$r['record_type']] ?? $r['record_type'],
            'Alasan'          => $reason,
        ];
        if ($r['record_type'] === 'Return') {
            $rows['Qty retur'] = fmt_qty($r['return_qty'], '—') . ' pcs';
        }
        if ($r['delivery_sj'] || $r['sj_number']) {
            $rows['Surat jalan / dokumen'] = trim(($r['delivery_sj'] ?? '') . ' ' . ($r['sj_number'] ? '· ' . $r['sj_number'] : ''), ' ·');
        }
        $rows['Dicatat oleh'] = $r['created_by_name'] ?? '—';

        $tr = '';
        foreach ($rows as $label => $value) {
            $tr .= '<tr><td style="padding:6px 12px 6px 0;color:#6e6e73;white-space:nowrap;vertical-align:top">' . e($label) . '</td>'
                . '<td style="padding:6px 0;color:#1d1d1f;vertical-align:top">' . e((string) $value) . '</td></tr>';
        }
        $result = '';
        if ($r['complaint_status'] !== 'Open') {
            $result = '<div style="margin:18px 0;padding:14px 16px;border-radius:10px;border:1px solid ' . $color . '33;background:' . $color . '0f">'
                . '<div style="font-weight:600;color:' . $color . '">' . e($r['complaint_status'] === 'Resolved' ? 'SELESAI' : 'TIDAK SELESAI') . '</div>'
                . '<div style="font-size:12px;color:#6e6e73;margin-top:2px">' . e(($r['resolved_by_name'] ?? '—') . ' · ' . fmt_datetime($r['resolved_at'])) . '</div>'
                . ($r['resolution_note'] ? '<div style="margin-top:8px"><strong>' . ($r['complaint_status'] === 'Unresolved' ? 'Alasan' : 'Hasil penyelesaian') . ':</strong><br>' . nl2br(e($r['resolution_note'])) . '</div>' : '')
                . '</div>';
        }
        $evidence = '';
        if ($attachments !== [] || $skipped !== []) {
            $evidence = '<p style="font-size:13px;color:#6e6e73;margin:14px 0 0">Bukti complaint: ' . count($attachments) . ' file terlampir'
                . ($skipped ? '; ' . count($skipped) . ' file terlalu besar untuk email (' . e(implode(', ', $skipped)) . ') — lihat di aplikasi' : '') . '.</p>';
        }
        $html = '<!doctype html><html><body style="margin:0;background:#f5f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#1d1d1f">'
            . '<div style="max-width:620px;margin:0 auto;padding:24px 16px">'
            . '<div style="background:#fff;border-radius:14px;padding:24px;border:1px solid #e5e5e7">'
            . '<div style="font-size:12px;color:#6e6e73;text-transform:uppercase;letter-spacing:.04em">' . e($company) . ' · Complaint &amp; Return</div>'
            . '<h1 style="font-size:20px;margin:6px 0 4px">' . e($headline) . '</h1>'
            . '<div style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;color:' . $color . ';background:' . $color . '18">' . e($status) . '</div>'
            . '<table style="border-collapse:collapse;margin-top:16px;width:100%">' . $tr . '</table>'
            . '<div style="margin-top:16px"><div style="color:#6e6e73;font-size:12px;margin-bottom:4px">Detail complaint</div>'
            . '<div style="padding:12px 14px;background:#fbfbfd;border:1px solid #e5e5e7;border-radius:10px">' . nl2br(e((string) ($r['complaint_detail'] ?? $r['note'] ?? '—'))) . '</div></div>'
            . $result . $evidence
            . '<p style="margin:20px 0 0"><a href="' . e($link) . '" style="display:inline-block;background:#0071e3;color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:600">Buka complaint di aplikasi</a></p>'
            . '</div><p style="font-size:11px;color:#86868b;text-align:center;margin-top:14px">Email otomatis dari ' . e((string) (Setting::get('app_name') ?? 'PIK Marketing Control'))
            . ($event === 'manual' && Auth::user() ? ' · dikirim oleh ' . e((string) Auth::user()['name']) : '') . '.</p>'
            . '</div></body></html>';
        return [$subject, $html, $attachments];
    }
}
