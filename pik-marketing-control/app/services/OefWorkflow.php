<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use DomainException;

/**
 * Alur review OEF oleh PPIC:
 *   OEF baru / diubah → Pending (notifikasi ke semua user PPIC)
 *   PPIC "Bisa diproses"       → Approved, status Open → On Process, jadwal delivery dibuat otomatis
 *   PPIC "Tidak bisa diproses" → Rejected + alasan (wajib); pembuat OEF diberi notifikasi
 * Keputusan hanya bisa diambil saat OEF berstatus Pending. OEF yang diubah setelah
 * direview kembali Pending sehingga PPIC mereview versi terbaru.
 */
final class OefWorkflow
{
    /** Permission untuk mengonfirmasi OEF (lihat config/permissions.php). */
    public const REVIEW_PERMISSION = 'oef_review.approve';

    /** OEF baru dibuat: beri tahu PPIC. */
    public static function submitted(int $poId): void
    {
        $po = PurchaseOrder::findFull($poId);
        if ($po === null) {
            return;
        }
        Notification::sendMany(Notification::usersWith(self::REVIEW_PERMISSION), 'oef_review', 'OEF ' . PurchaseOrder::label($po) . ' menunggu review',
            self::summary($po), '/purchase-orders/' . $poId, 'purchase_order', $poId, 'oef_review:' . $poId . ':' . date('YmdHis'));
    }

    /**
     * Isi OEF berubah. Bila sudah direview, status review kembali Pending dan PPIC diberi tahu.
     * @return bool true bila review di-reset
     */
    public static function changed(int $poId, string $what): bool
    {
        $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id', ['id' => $poId]);
        if ($po === null || $po['review_status'] === 'Pending') {
            return false;
        }
        Database::update('purchase_orders', ['review_status' => 'Pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null], 'id = :id', ['id' => $poId]);
        Audit::log('review_reset', PurchaseOrder::ENTITY, $poId, PurchaseOrder::label($po), [
            'review_status' => ['old' => $po['review_status'], 'new' => 'Pending'],
            'alasan'        => ['old' => null, 'new' => $what],
        ]);
        $full = PurchaseOrder::findFull($poId);
        Notification::sendMany(Notification::usersWith(self::REVIEW_PERMISSION), 'oef_review', 'OEF ' . PurchaseOrder::label($po) . ' diperbarui, perlu review ulang',
            $what . ($full ? ' · ' . self::summary($full) : ''), '/purchase-orders/' . $poId, 'purchase_order', $poId, 'oef_review:' . $poId . ':' . date('YmdHis'));
        return true;
    }

    /**
     * PPIC: "Bisa diproses".
     * @return array{created:int,updated:int,cancelled:int,skipped:string|null}
     */
    public static function approve(int $poId, ?string $note): array
    {
        $result = Database::transaction(function () use ($poId, $note): array {
            $po = self::lockPending($poId);
            if ($po['status'] === 'Cancelled') {
                throw new DomainException('OEF berstatus Cancelled tidak dapat diproses.');
            }
            $data = ['review_status' => 'Approved', 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $note];
            if ($po['status'] === 'Open') {
                $data['status'] = 'On Process';
            }
            Database::update('purchase_orders', $data, 'id = :id', ['id' => $poId]);
            Audit::log('approve', PurchaseOrder::ENTITY, $poId, PurchaseOrder::label($po), Audit::diff($po, $data));
            return Delivery::scheduleFromOef($poId);
        });
        self::notifyOwner($poId, 'oef_approved', 'bisa diproses', $note);
        return $result;
    }

    /** PPIC: "Tidak bisa diproses" (alasan wajib). */
    public static function reject(int $poId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new DomainException('Tuliskan alasan OEF tidak bisa diproses (minimal 5 karakter).');
        }
        Database::transaction(function () use ($poId, $reason): void {
            $po = self::lockPending($poId);
            $data = ['review_status' => 'Rejected', 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => mb_substr($reason, 0, 2000)];
            Database::update('purchase_orders', $data, 'id = :id', ['id' => $poId]);
            Audit::log('reject', PurchaseOrder::ENTITY, $poId, PurchaseOrder::label($po), Audit::diff($po, $data));
        });
        self::notifyOwner($poId, 'oef_rejected', 'tidak bisa diproses', $reason);
    }

    /** @return array<string,mixed> */
    private static function lockPending(int $poId): array
    {
        $po = Database::fetch('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE', ['id' => $poId]);
        if ($po === null) {
            throw new DomainException('OEF tidak ditemukan.');
        }
        if ($po['review_status'] !== 'Pending') {
            throw new DomainException('OEF ini sudah direview (' . (PurchaseOrder::REVIEW_LABELS[$po['review_status']] ?? $po['review_status']) . '). OEF akan kembali menunggu review bila isinya diubah.');
        }
        return $po;
    }

    private static function notifyOwner(int $poId, string $type, string $verdict, ?string $note): void
    {
        $po = PurchaseOrder::findFull($poId);
        if ($po === null) {
            return;
        }
        $owners = array_filter(
            array_unique([(int) $po['created_by'], (int) $po['updated_by']]),
            static fn (int $uid): bool => $uid > 0 && $uid !== (int) Auth::id()
        );
        $allowed = array_values(array_intersect($owners, Notification::usersWith('purchase_orders.view')));
        Notification::sendMany($allowed, $type, 'OEF ' . PurchaseOrder::label($po) . ' ' . $verdict,
            ($note !== null && $note !== '' ? $note . ' · ' : '') . self::summary($po), '/purchase-orders/' . $poId, 'purchase_order', $poId,
            $type . ':' . $poId . ':' . date('YmdHis'));
    }

    /** @param array<string,mixed> $po */
    private static function summary(array $po): string
    {
        $parts = array_filter([
            $po['customer_name'] ?? null,
            isset($po['total_qty']) ? number_format((int) $po['total_qty'], 0, ',', '.') . ' pcs' : null,
            $po['requested_delivery_date'] ? 'kirim ' . fmt_date($po['requested_delivery_date']) : null,
        ]);
        return implode(' · ', $parts);
    }
}
