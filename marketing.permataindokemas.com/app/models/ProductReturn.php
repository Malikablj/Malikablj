<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Complaint & Return (tabel `returns`).
 *
 * Setiap record adalah complaint customer:
 *   - record_type = 'Complaint' : keluhan tanpa barang kembali (tidak mengubah outstanding)
 *   - record_type = 'Return'    : complaint dengan barang diretur — qty retur MENAMBAH outstanding
 * Status penanganan: Open → Resolved (Selesai) / Unresolved (Tidak selesai + alasan).
 */
final class ProductReturn extends Model
{
    public const TABLE = 'returns';
    public const ENTITY = 'return';
    public const LABEL = 'code';

    public const TYPES = ['Complaint', 'Return'];
    public const TYPE_LABELS = ['Complaint' => 'Complaint (tanpa retur barang)', 'Return' => 'Complaint + retur barang'];
    public const TYPE_SHORT = ['Complaint' => 'Complaint', 'Return' => 'Retur barang'];

    public const STATUSES = ['Open', 'Resolved', 'Unresolved'];
    public const STATUS_LABELS = ['Open' => 'Dalam proses', 'Resolved' => 'Selesai', 'Unresolved' => 'Tidak selesai'];

    public const REASONS = ['Damage', 'Wrong Product', 'Quality Issue', 'Over Delivery', 'Customer Request', 'Other'];
    public const REASON_LABELS = [
        'Damage' => 'Rusak', 'Wrong Product' => 'Salah produk', 'Quality Issue' => 'Masalah kualitas',
        'Over Delivery' => 'Kelebihan kirim', 'Customer Request' => 'Permintaan customer', 'Other' => 'Lainnya',
    ];

    private const SELECT = 'SELECT r.*, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code,
            COALESCE(r.customer_id, p.customer_id) AS customer_id, c.name AS customer_name, c.marketing_pic_id,
            pr.name AS product_name, d.sj_number AS delivery_sj, ru.name AS resolved_by_name, cu.name AS created_by_name,
            (SELECT COUNT(*) FROM complaint_attachments ca WHERE ca.return_id = r.id) AS evidence_count
        FROM returns r
        LEFT JOIN purchase_orders p ON p.id = r.po_id
        LEFT JOIN customers c ON c.id = COALESCE(r.customer_id, p.customer_id)
        LEFT JOIN products pr ON pr.id = r.product_id
        LEFT JOIN deliveries d ON d.id = r.delivery_id
        LEFT JOIN users ru ON ru.id = r.resolved_by
        LEFT JOIN users cu ON cu.id = r.created_by';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(r.code LIKE :q1 OR p.po_number LIKE :q2 OR c.name LIKE :q3 OR pr.name LIKE :q4 OR r.product_legacy LIKE :q5 OR r.sj_number LIKE :q6 OR p.order_number LIKE :q7 OR r.complaint_detail LIKE :q8)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['reason']) && in_array($f['reason'], self::REASONS, true)) {
            $where[] = 'r.reason = :reason';
            $params['reason'] = $f['reason'];
        }
        if (!empty($f['type']) && in_array($f['type'], self::TYPES, true)) {
            $where[] = 'r.record_type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'r.complaint_status = :st';
            $params['st'] = $f['status'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'COALESCE(r.customer_id, p.customer_id) = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'r.return_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'r.return_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['link'] ?? '') === 'unlinked') {
            $where[] = "r.po_line_id IS NULL AND r.record_type = 'Return'";
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        // SELECT memuat r.* (termasuk r.customer_id) + alias customer_id efektif, sehingga tidak bisa
        // dibungkus sebagai subquery COUNT (MySQL: "Duplicate column name"). Hitung total terpisah.
        $total = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM returns r
             LEFT JOIN purchase_orders p ON p.id = r.po_id
             LEFT JOIN customers c ON c.id = COALESCE(r.customer_id, p.customer_id)
             LEFT JOIN products pr ON pr.id = r.product_id
             WHERE ' . $where,
            $params
        );
        $perPage = max(1, min(200, (int) config('app.pagination.per_page', 25)));
        $page = min(max(1, $page), max(1, (int) ceil($total / $perPage)));
        $items = Database::fetchAll(
            self::SELECT . ' WHERE ' . $where . " ORDER BY (r.complaint_status = 'Open') DESC, r.return_date IS NULL, r.return_date DESC, r.id DESC"
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );
        return new Paginator($items, $total, $page, $perPage);
    }

    /** @return array<string,int> jumlah per status penanganan */
    public static function statusCounts(): array
    {
        $out = ['Open' => 0, 'Resolved' => 0, 'Unresolved' => 0];
        foreach (Database::fetchAll('SELECT complaint_status, COUNT(*) AS n FROM returns GROUP BY complaint_status') as $row) {
            $out[(string) $row['complaint_status']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPo(int $poId): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE r.po_id = :id ORDER BY r.return_date DESC, r.id DESC', ['id' => $poId]);
    }

    /**
     * Simpan complaint / retur.
     * @param array<string,mixed> $data
     * @param list<array{tmp:string,name:string,mime:string,size:int,ext:string}> $files bukti (sudah divalidasi)
     */
    public static function saveReturn(?int $id, array $data, array $files = []): int
    {
        $stored = [];
        try {
            return Database::transaction(function () use ($id, $data, $files, &$stored): int {
                $before = $id !== null ? self::find($id) : null;
                if (($data['record_type'] ?? 'Return') === 'Complaint') {
                    $data['return_qty'] = null;
                }
                if (!empty($data['po_line_id'])) {
                    $line = PoLine::find((int) $data['po_line_id']);
                    if ($line === null) {
                        throw new DomainException('Baris order tidak ditemukan.');
                    }
                    $data['po_id'] = (int) $line['po_id'];
                    $data['product_id'] = (int) $line['product_id'];
                    $customer = Database::fetchValue('SELECT customer_id FROM purchase_orders WHERE id = :id', ['id' => $line['po_id']]);
                    if ($customer !== null) {
                        $data['customer_id'] = (int) $customer;
                    }
                    if (!empty($data['delivery_id'])) {
                        $deliveryLine = Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $data['delivery_id']]);
                        if ((int) $deliveryLine !== (int) $data['po_line_id']) {
                            throw new DomainException('Surat jalan yang dipilih bukan untuk baris order ini.');
                        }
                    }
                } elseif (array_key_exists('po_line_id', $data)) {
                    // complaint tanpa order: lepaskan relasi order bila sebelumnya ada
                    if ($before === null || $before['po_line_id'] !== null) {
                        $data['po_id'] = null;
                        $data['product_id'] = null;
                    }
                    $data['delivery_id'] = null;
                }
                if (empty($data['customer_id']) && $before === null) {
                    throw new DomainException('Pilih customer atau order yang dikomplain.');
                }
                if ($id === null) {
                    $data['complaint_status'] = 'Open';
                    $id = self::create($data);
                } else {
                    self::update($id, $data, $before);
                }
                foreach ($files as $file) {
                    $stored[] = ComplaintAttachment::store($id, $file);
                }
                if (!empty($data['po_line_id']) && ($before === null || $before['po_line_id'] === null)) {
                    MigrationIssue::resolveForRecord('RETURNS', $id, MigrationIssue::LINK_TYPES, 'Retur dihubungkan ke PO line oleh user.');
                }
                PurchaseOrder::syncStatus(isset($data['po_id']) ? (int) $data['po_id'] : null);
                if ($before !== null && $before['po_id'] !== null && (int) $before['po_id'] !== (int) ($data['po_id'] ?? 0)) {
                    PurchaseOrder::syncStatus((int) $before['po_id']);
                }
                return $id;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }
    }

    /**
     * Tandai complaint Selesai / Tidak selesai (wajib alasan) / buka kembali.
     */
    public static function resolve(int $id, string $outcome, ?string $note): void
    {
        $r = self::find($id);
        if ($r === null) {
            throw new DomainException('Complaint tidak ditemukan.');
        }
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $data = match ($outcome) {
            'resolved'   => ['complaint_status' => 'Resolved', 'resolution_note' => $note, 'resolved_by' => Auth::id(), 'resolved_at' => date('Y-m-d H:i:s')],
            'unresolved' => ['complaint_status' => 'Unresolved', 'resolution_note' => $note, 'resolved_by' => Auth::id(), 'resolved_at' => date('Y-m-d H:i:s')],
            'reopen'     => ['complaint_status' => 'Open', 'resolved_by' => null, 'resolved_at' => null],
            default      => throw new DomainException('Pilihan tidak dikenal.'),
        };
        if ($outcome === 'unresolved' && $note === null) {
            throw new DomainException('Tuliskan alasan mengapa complaint tidak selesai.');
        }
        self::update($id, $data, $r);
    }

    /** @param array{status:string,error:?string} $result */
    public static function recordEmail(int $id, array $result): void
    {
        Database::update('returns', [
            'email_status'  => $result['status'],
            'email_sent_at' => $result['status'] === 'sent' ? date('Y-m-d H:i:s') : null,
            'email_error'   => $result['error'] !== null ? mb_substr($result['error'], 0, 500) : null,
        ], 'id = :id', ['id' => $id]);
    }

    public static function remove(int $id): void
    {
        $r = self::find($id);
        if ($r === null) {
            throw new DomainException('Complaint tidak ditemukan.');
        }
        $files = ComplaintAttachment::forReturn($id);
        Database::transaction(function () use ($id, $r): void {
            self::delete($id, $r);
            PurchaseOrder::syncStatus($r['po_id'] !== null ? (int) $r['po_id'] : null);
        });
        foreach ($files as $f) {
            ComplaintAttachment::deleteFile($f);
        }
        ComplaintAttachment::removeDir($id);
    }

    /** @return array<int,string> surat jalan (delivery) per baris order, untuk referensi retur */
    public static function deliveryOptions(?int $poLineId): array
    {
        if (!$poLineId) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll("SELECT id, sj_number, code, delivery_date, delivered_qty, status FROM deliveries WHERE po_line_id = :l AND status <> 'Cancelled' ORDER BY delivery_date DESC", ['l' => $poLineId]) as $d) {
            $out[(int) $d['id']] = ($d['sj_number'] ?? $d['code']) . ' · ' . fmt_date($d['delivery_date']) . ' · ' . number_format((int) $d['delivered_qty'], 0, ',', '.') . ' pcs' . ($d['status'] !== 'Delivered' ? ' (' . $d['status'] . ')' : '');
        }
        return $out;
    }
}
