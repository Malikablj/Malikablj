<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use PDOException;

/**
 * Order Entry Form (OEF) — pengganti input PO.
 *
 * Satu OEF = satu produk (diketik manual) dengan spesifikasi & qty. Secara teknis
 * OEF disimpan di tabel purchase_orders + satu baris po_lines, sehingga delivery,
 * retur, outstanding, dan laporan yang sudah ada tetap berjalan.
 *
 * Alur:
 *   1. Sales/Marketing mengisi OEF: No. order, nama customer, dan nama produk diketik
 *      manual. Customer & produk yang belum ada dicatat otomatis di menu Customers &
 *      Products (qty & spesifikasi OEF terakhir disimpan di produk sebagai arsip).
 *      Jadwal delivery dibuat otomatis dari "Permintaan selesai/kirim",
 *      status PPIC = Pending dan user PPIC menerima notifikasi.
 *   2. PPIC menekan "Bisa diproses" (status order → On Process) atau
 *      "Tidak bisa diproses" + alasan (order → Cancelled, jadwal delivery dibatalkan).
 *   3. OEF yang ditolak bisa direvisi; menyimpan revisi mengirim ulang ke PPIC.
 */
final class OrderEntry
{
    /** Field OEF yang bila diubah membutuhkan konfirmasi ulang PPIC. */
    private const PPIC_FIELDS = ['product_spec', 'is_subcont', 'supplier', 'requested_date'];

    /**
     * @param array<string,mixed> $header kolom purchase_orders (tervalidasi, termasuk order_number)
     * @return array{id:int,product_created:bool,customer_created:bool}
     */
    public static function create(array $header, string $customerName, string $productName, ?string $spec, int $qty): array
    {
        return Database::transaction(function () use ($header, $customerName, $productName, $spec, $qty): array {
            $label = (string) ($header['order_number'] ?? '');
            $header['sales_user_id'] = self::matchUser($header['sales_name'] ?? null);
            $customer = Customer::findOrCreateForOrder($customerName, $label, $header['sales_user_id']);
            $product = Product::findOrCreateForOrder($productName, $spec, $label, $qty);
            $header['customer_id'] = $customer['id'];
            $header['product_spec'] = $spec;
            $header['ppic_status'] = 'Pending';
            $header['status'] = $header['status'] ?? 'Open';
            $id = self::insertOrder($header);
            PoLine::create(['po_id' => $id, 'product_id' => $product['id'], 'order_qty' => $qty, 'remark' => null]);
            self::syncSchedule($id, true);
            self::notifyPpic(PurchaseOrder::find($id) ?? [], false);
            return ['id' => $id, 'product_created' => $product['created'], 'customer_created' => $customer['created']];
        });
    }

    /**
     * Simpan perubahan OEF.
     * @param array<string,mixed> $header
     * @param string|null $customerName null = customer tidak diubah dari form ini
     * @param array{name:string,spec:?string,qty:int}|null $product null = baris produk tidak diubah dari form ini
     * @return array{resubmitted:bool,product_created:bool,customer_created:bool}
     */
    public static function update(int $id, array $header, ?string $customerName, ?array $product): array
    {
        return Database::transaction(function () use ($id, $header, $customerName, $product): array {
            $before = PurchaseOrder::find($id);
            if ($before === null) {
                throw new DomainException('Order tidak ditemukan.');
            }
            $label = (string) (($header['order_number'] ?? null) ?: PurchaseOrder::displayNumber($before));
            $productCreated = false;
            $customerCreated = false;
            $lineChanged = false;
            if (array_key_exists('sales_name', $header)) {
                $header['sales_user_id'] = self::matchUser($header['sales_name']);
            }
            if ($customerName !== null) {
                $customer = Customer::findOrCreateForOrder($customerName, $label, $header['sales_user_id'] ?? null);
                $header['customer_id'] = $customer['id'];
                $customerCreated = $customer['created'];
            }
            if ($product !== null) {
                $header['product_spec'] = $product['spec'];
                $line = self::singleLine($id);
                if ($line === null) {
                    throw new DomainException('Produk OEF hanya dapat diubah dari form ini bila order berisi satu baris produk.');
                }
                $found = Product::findOrCreateForOrder($product['name'], $product['spec'], $label, $product['qty']);
                $productCreated = $found['created'];
                if ($found['id'] !== (int) $line['product_id'] && self::lineLocked($before, (int) $line['id'])) {
                    throw new DomainException('Produk tidak dapat diganti karena order sudah memiliki pengiriman atau retur. Ubah qty/spesifikasi saja, atau buat OEF baru.');
                }
                if ($found['id'] !== (int) $line['product_id'] || (int) $line['order_qty'] !== $product['qty']) {
                    PoLine::update((int) $line['id'], ['product_id' => $found['id'], 'order_qty' => $product['qty']], $line);
                    $lineChanged = true;
                }
            }

            // Perubahan isi order → konfirmasi ulang PPIC (hanya untuk OEF, bukan PO lama)
            $resubmit = false;
            if ($before['ppic_status'] !== null) {
                foreach (self::PPIC_FIELDS as $f) {
                    if (array_key_exists($f, $header) && (string) ($header[$f] ?? '') !== (string) ($before[$f] ?? '')) {
                        $resubmit = true;
                    }
                }
                $resubmit = $resubmit || $lineChanged;
                if ($before['ppic_status'] === 'Rejected') {
                    $resubmit = true; // menyimpan revisi = mengajukan ulang
                }
                if ($resubmit) {
                    $header['ppic_status'] = 'Pending';
                    $header['ppic_note'] = null;
                    $header['ppic_by'] = null;
                    $header['ppic_at'] = null;
                    if ($before['status'] === 'Cancelled' && ($header['status'] ?? 'Cancelled') === 'Cancelled') {
                        $header['status'] = 'Open';
                    }
                }
            }
            try {
                PurchaseOrder::update($id, $header, $before);
            } catch (PDOException $e) {
                throw self::duplicateNumber($e) ?? $e;
            }
            $dateChanged = array_key_exists('requested_date', $header) && (string) $header['requested_date'] !== (string) $before['requested_date'];
            self::syncSchedule($id, $dateChanged);
            PurchaseOrder::syncStatus($id);
            if ($resubmit) {
                self::notifyPpic(PurchaseOrder::find($id) ?? $before, true);
            }
            return ['resubmitted' => $resubmit, 'product_created' => $productCreated, 'customer_created' => $customerCreated];
        });
    }

    /** Keputusan PPIC: approve = bisa diproses, reject = tidak bisa diproses (wajib alasan). */
    public static function decide(int $id, string $decision, ?string $note): void
    {
        Database::transaction(function () use ($id, $decision, $note): void {
            $po = PurchaseOrder::find($id);
            if ($po === null) {
                throw new DomainException('Order tidak ditemukan.');
            }
            if ($po['ppic_status'] === null) {
                throw new DomainException('Data PO lama tidak memerlukan konfirmasi PPIC.');
            }
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            if ($decision === 'reject' && $note === null) {
                throw new DomainException('Isi alasan mengapa order tidak bisa diproses.');
            }
            $data = [
                'ppic_status' => $decision === 'approve' ? 'Approved' : 'Rejected',
                'ppic_note'   => $note,
                'ppic_by'     => Auth::id(),
                'ppic_at'     => date('Y-m-d H:i:s'),
            ];
            if ($decision === 'approve' && in_array($po['status'], ['Open', 'Cancelled'], true)) {
                $data['status'] = 'On Process';
            } elseif ($decision === 'reject' && in_array($po['status'], ['Open', 'On Process'], true)) {
                $data['status'] = 'Cancelled';
            }
            PurchaseOrder::update($id, $data, $po);
            Audit::log($decision === 'approve' ? 'ppic_approve' : 'ppic_reject', PurchaseOrder::ENTITY, $id, PurchaseOrder::displayNumber($po), [
                'ppic_status' => ['old' => $po['ppic_status'], 'new' => $data['ppic_status']],
            ]);
            self::syncSchedule($id, false);
            PurchaseOrder::syncStatus($id);
            self::notifyDecision(PurchaseOrder::find($id) ?? $po);
        });
    }

    /** Ubah jadwal kirim (delivery terjadwal otomatis) dari halaman OEF. */
    public static function reschedule(int $id, string $date, ?string $reason): void
    {
        Database::transaction(function () use ($id, $date, $reason): void {
            $po = PurchaseOrder::find($id);
            if ($po === null || empty($po['schedule_delivery_id'])) {
                throw new DomainException('Order ini belum memiliki jadwal delivery.');
            }
            $d = Delivery::find((int) $po['schedule_delivery_id']);
            if ($d === null || !in_array($d['status'], ['Scheduled', 'On Delivery'], true)) {
                throw new DomainException('Jadwal hanya dapat diubah selama delivery berstatus Scheduled / On Delivery.');
            }
            $note = trim((string) $d['note']);
            $line = 'Jadwal diubah ' . fmt_date($d['delivery_date']) . ' → ' . fmt_date($date) . ($reason ? ': ' . $reason : '') . ' (' . (Auth::user()['name'] ?? 'sistem') . ', ' . date('d/m/Y H:i') . ')';
            Delivery::update((int) $d['id'], ['delivery_date' => $date, 'note' => mb_substr(($note !== '' ? $note . "\n" : '') . $line, 0, 2000)], $d);
        });
    }

    // ------------------------------------------------------------------ internal

    /** Insert header OEF. No. order diisi manual; bentrok dengan order lain → pesan yang jelas. */
    private static function insertOrder(array $header): int
    {
        try {
            return PurchaseOrder::create($header);
        } catch (PDOException $e) {
            throw self::duplicateNumber($e) ?? $e;
        }
    }

    /** Ubah error unique index No. order menjadi pesan untuk user (null = error lain). */
    private static function duplicateNumber(PDOException $e): ?DomainException
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'order_number')) {
            return new DomainException('No. order sudah dipakai order lain. Gunakan nomor lain.');
        }
        return null;
    }

    /** @return array<string,mixed>|null baris produk bila order hanya punya satu baris */
    public static function singleLine(int $poId): ?array
    {
        $lines = PoLine::linesOfPo($poId);
        return count($lines) === 1 ? $lines[0] : null;
    }

    /** Produk baris tidak boleh diganti bila sudah ada pengiriman (selain jadwal otomatis) atau retur. */
    public static function lineLocked(array $po, int $lineId): bool
    {
        return (int) Database::fetchValue(
            'SELECT (SELECT COUNT(*) FROM deliveries WHERE po_line_id = :a AND NOT (id <=> :s))
                  + (SELECT COUNT(*) FROM returns WHERE po_line_id = :b)',
            ['a' => $lineId, 'b' => $lineId, 's' => $po['schedule_delivery_id'] !== null ? (int) $po['schedule_delivery_id'] : null]
        ) > 0;
    }

    private static function matchUser(mixed $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $id = Database::fetchValue('SELECT id FROM users WHERE is_active = 1 AND LOWER(TRIM(name)) = LOWER(:n) ORDER BY id LIMIT 1', ['n' => $name]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * Sinkron jadwal delivery otomatis dengan isi OEF:
     *  - dibuat dari "Permintaan selesai/kirim" (status Scheduled)
     *  - ditolak PPIC / order dibatalkan → jadwal dibatalkan
     *  - revisi diajukan ulang → jadwal diaktifkan kembali
     *  - selama masih Scheduled, qty/tujuan/produk mengikuti OEF; tanggal mengikuti
     *    OEF hanya bila tanggal permintaan diubah (perubahan jadwal manual tetap dipakai)
     */
    private static function syncSchedule(int $poId, bool $dateChanged): void
    {
        $po = PurchaseOrder::findFull($poId);
        if ($po === null || $po['ppic_status'] === null) {
            return;
        }
        $line = Database::fetch('SELECT * FROM po_lines WHERE po_id = :p ORDER BY id LIMIT 1', ['p' => $poId]);
        $schedule = !empty($po['schedule_delivery_id']) ? Delivery::find((int) $po['schedule_delivery_id']) : null;
        $label = PurchaseOrder::displayNumber($po);
        $stop = $po['ppic_status'] === 'Rejected' || $po['status'] === 'Cancelled';

        if ($stop) {
            if ($schedule !== null && $schedule['status'] === 'Scheduled') {
                Delivery::update((int) $schedule['id'], ['status' => 'Cancelled'], $schedule);
            }
            return;
        }
        if ($line === null || empty($po['requested_date'])) {
            return;
        }
        // Tujuan kirim: isian OEF → alamat customer → nama customer
        $destination = $po['ship_to'] ?: (trim((string) ($po['customer_address'] ?? '')) !== '' ? $po['customer_address'] : ($po['customer_name'] ?? null));
        if ($schedule === null) {
            $deliveryId = Delivery::create([
                'po_id'         => $poId,
                'po_line_id'    => (int) $line['id'],
                'product_id'    => (int) $line['product_id'],
                'delivery_date' => $po['requested_date'],
                'destination'   => $destination !== null ? mb_substr((string) $destination, 0, 255) : null,
                'delivered_qty' => (int) $line['order_qty'],
                'status'        => 'Scheduled',
                'note'          => 'Dijadwalkan otomatis dari Order Entry Form ' . $label . '.',
            ]);
            Database::update('purchase_orders', ['schedule_delivery_id' => $deliveryId], 'id = :id', ['id' => $poId]);
            return;
        }
        $data = [];
        if ($schedule['status'] === 'Cancelled') {
            $data['status'] = 'Scheduled';
            $data['delivery_date'] = $po['requested_date'];
        }
        if (in_array($schedule['status'], ['Scheduled', 'Cancelled'], true)) {
            if ($dateChanged) {
                $data['delivery_date'] = $po['requested_date'];
            }
            $data['po_line_id'] = (int) $line['id'];
            $data['product_id'] = (int) $line['product_id'];
            $data['delivered_qty'] = (int) $line['order_qty'];
            $data['destination'] = $destination !== null ? mb_substr((string) $destination, 0, 255) : null;
            Delivery::update((int) $schedule['id'], $data, $schedule);
        }
    }

    /** Notifikasi ke PPIC: OEF baru / revisi menunggu konfirmasi. */
    private static function notifyPpic(array $po, bool $revised): void
    {
        $recipients = User::activeByRoles(['PPIC']);
        if ($recipients === []) {
            $recipients = User::activeByRoles(['Admin']);
        }
        $label = PurchaseOrder::displayNumber($po);
        $customer = $po['customer_id'] ? (string) Database::fetchValue('SELECT name FROM customers WHERE id = :id', ['id' => $po['customer_id']]) : '';
        foreach ($recipients as $u) {
            if ((int) $u['id'] === Auth::id()) {
                continue;
            }
            Notification::send(
                (int) $u['id'],
                'oef_ppic',
                ($revised ? 'Revisi OEF ' : 'OEF baru ') . $label . ' menunggu konfirmasi PPIC',
                trim($customer . ($po['requested_date'] ? ' · kirim ' . fmt_date($po['requested_date']) : '') . ($po['sales_name'] ? ' · sales ' . $po['sales_name'] : ''), ' ·'),
                '/purchase-orders/' . $po['id'],
                'purchase_order',
                (int) $po['id']
            );
        }
    }

    /** Notifikasi keputusan PPIC ke pembuat OEF & sales. */
    private static function notifyDecision(array $po): void
    {
        $ids = array_unique(array_filter([(int) ($po['created_by'] ?? 0), (int) ($po['sales_user_id'] ?? 0)]));
        $label = PurchaseOrder::displayNumber($po);
        $ok = $po['ppic_status'] === 'Approved';
        foreach ($ids as $uid) {
            if ($uid === Auth::id()) {
                continue;
            }
            Notification::send(
                $uid,
                'oef_ppic_result',
                'OEF ' . $label . ($ok ? ' bisa diproses' : ' tidak bisa diproses'),
                $po['ppic_note'] ? mb_substr((string) $po['ppic_note'], 0, 400) : null,
                '/purchase-orders/' . $po['id'],
                'purchase_order',
                (int) $po['id']
            );
        }
    }
}
