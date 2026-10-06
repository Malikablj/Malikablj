<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Retur & komplain customer (tabel `returns`).
 *   Retur    = barang dikembalikan; return_qty MENAMBAH outstanding baris OEF.
 *   Komplain = tanpa barang kembali; qty bermasalah (affected_qty) hanya informasi.
 * Setiap kasus punya bukti (gambar/PDF), email QC, dan hasil penyelesaian.
 */
final class ProductReturn extends Model
{
    public const TABLE = 'returns';
    public const ENTITY = 'return';
    public const LABEL = 'code';

    public const CASE_TYPES = ['Retur', 'Komplain'];
    public const CASE_HELP = [
        'Retur'    => 'Retur — barang dikembalikan customer (menambah outstanding OEF)',
        'Komplain' => 'Komplain — tanpa pengembalian barang (outstanding tidak berubah)',
    ];
    public const RESOLUTIONS = ['Open', 'Selesai', 'Tidak selesai'];
    public const RESOLUTION_LABELS = ['Open' => 'Belum ada hasil', 'Selesai' => 'Selesai', 'Tidak selesai' => 'Tidak selesai'];
    public const EMAIL_LABELS = ['' => 'Belum dikirim', 'SENT' => 'Terkirim', 'LOGGED' => 'Dicatat (log)', 'FAILED' => 'Gagal'];

    public const REASONS = ['Damage', 'Wrong Product', 'Quality Issue', 'Over Delivery', 'Customer Request', 'Other'];
    public const REASON_LABELS = [
        'Damage' => 'Rusak', 'Wrong Product' => 'Salah produk', 'Quality Issue' => 'Masalah kualitas',
        'Over Delivery' => 'Kelebihan kirim', 'Customer Request' => 'Permintaan customer', 'Other' => 'Lainnya',
    ];

    private const SELECT = 'SELECT r.*, p.po_number, p.order_number, p.code AS po_code, p.customer_id, c.name AS customer_name,
            COALESCE(p.order_number, p.po_number, p.code, r.po_number_legacy) AS order_ref,
            pr.name AS product_name, d.sj_number AS delivery_sj, ru.name AS resolved_by_name, cu.name AS created_by_name,
            (SELECT COUNT(*) FROM return_attachments ra WHERE ra.return_id = r.id) AS evidence_count
        FROM returns r
        LEFT JOIN purchase_orders p ON p.id = r.po_id
        LEFT JOIN customers c ON c.id = p.customer_id
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
            $where[] = '(r.code LIKE :q1 OR p.po_number LIKE :q2 OR c.name LIKE :q3 OR pr.name LIKE :q4 OR r.product_legacy LIKE :q5 OR r.sj_number LIKE :q6 OR p.order_number LIKE :q7 OR r.note LIKE :q8)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['type']) && in_array($f['type'], self::CASE_TYPES, true)) {
            $where[] = 'r.case_type = :type';
            $params['type'] = $f['type'];
        }
        if (!empty($f['resolution']) && in_array($f['resolution'], self::RESOLUTIONS, true)) {
            $where[] = 'r.resolution_status = :res';
            $params['res'] = $f['resolution'];
        }
        if (!empty($f['reason']) && in_array($f['reason'], self::REASONS, true)) {
            $where[] = 'r.reason = :reason';
            $params['reason'] = $f['reason'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = :cid';
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
            $where[] = 'r.po_line_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 'r.return_date IS NULL, r.return_date DESC, r.id DESC', $page);
    }

    /** @return array<string,int> ringkasan untuk filter aktif */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        $row = Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(r.case_type = 'Retur'), 0) AS retur, COALESCE(SUM(r.case_type = 'Komplain'), 0) AS komplain,
                    COALESCE(SUM(r.resolution_status = 'Open'), 0) AS open_count, COALESCE(SUM(r.resolution_status = 'Selesai'), 0) AS done,
                    COALESCE(SUM(r.resolution_status = 'Tidak selesai'), 0) AS failed, COALESCE(SUM(r.return_qty), 0) AS return_qty
             FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = r.product_id WHERE " . $where,
            $params
        ) ?? [];
        return array_map('intval', $row);
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

    /** @param array<string,mixed> $data */
    public static function saveReturn(?int $id, array $data): int
    {
        return Database::transaction(function () use ($id, $data): int {
            $before = $id !== null ? self::find($id) : null;
            if (($data['case_type'] ?? 'Retur') === 'Komplain') {
                $data['return_qty'] = null; // komplain tanpa barang kembali tidak mengubah outstanding
            } elseif (array_key_exists('affected_qty', $data)) {
                $data['affected_qty'] = null;
            }
            if ($data['po_line_id'] !== null) {
                $line = PoLine::find((int) $data['po_line_id']);
                if ($line === null) {
                    throw new DomainException('Produk OEF tidak ditemukan.');
                }
                $data['po_id'] = (int) $line['po_id'];
                $data['product_id'] = (int) $line['product_id'];
                if (!empty($data['delivery_id'])) {
                    $deliveryLine = Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $data['delivery_id']]);
                    if ((int) $deliveryLine !== (int) $data['po_line_id']) {
                        throw new DomainException('Surat jalan yang dipilih bukan untuk produk OEF ini.');
                    }
                }
            }
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if ($data['po_line_id'] !== null && ($before === null || $before['po_line_id'] === null)) {
                MigrationIssue::resolveForRecord('RETURNS', $id, MigrationIssue::LINK_TYPES, 'Retur dihubungkan ke PO line oleh user.');
            }
            PurchaseOrder::syncStatus(isset($data['po_id']) ? (int) $data['po_id'] : null);
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $r = self::find($id);
        if ($r === null) {
            throw new DomainException('Retur tidak ditemukan.');
        }
        $files = array_values(array_filter(array_map([ReturnAttachment::class, 'path'], ReturnAttachment::forReturn($id))));
        Database::transaction(function () use ($id, $r): void {
            self::delete($id, $r); // baris bukti ikut terhapus (ON DELETE CASCADE)
            PurchaseOrder::syncStatus($r['po_id'] !== null ? (int) $r['po_id'] : null);
        });
        ReturnAttachment::discard($files);
    }

    /**
     * Catat hasil penyelesaian: "Selesai" (catatan opsional) atau "Tidak selesai" (alasan wajib).
     */
    public static function resolve(int $id, string $status, ?string $note): void
    {
        if (!in_array($status, ['Selesai', 'Tidak selesai'], true)) {
            throw new DomainException('Status penyelesaian tidak valid.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($status === 'Tidak selesai' && mb_strlen((string) $note) < 5) {
            throw new DomainException('Tuliskan alasan komplain tidak selesai (minimal 5 karakter).');
        }
        $r = self::find($id) ?? throw new DomainException('Data tidak ditemukan.');
        self::update($id, [
            'resolution_status' => $status,
            'resolution_note'   => $note !== '' ? $note : null,
            'resolved_by'       => \App\Helpers\Auth::id(),
            'resolved_at'       => date('Y-m-d H:i:s'),
        ], $r);
    }

    /** Buka kembali kasus yang sudah diberi hasil. */
    public static function reopen(int $id): void
    {
        $r = self::find($id) ?? throw new DomainException('Data tidak ditemukan.');
        self::update($id, ['resolution_status' => 'Open', 'resolution_note' => null, 'resolved_by' => null, 'resolved_at' => null], $r);
    }

    /** @return array<int,string> surat jalan (delivery) per PO line, untuk referensi retur */
    public static function deliveryOptions(?int $poLineId): array
    {
        if (!$poLineId) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll('SELECT id, sj_number, code, delivery_date, delivered_qty FROM deliveries WHERE po_line_id = :l ORDER BY delivery_date DESC', ['l' => $poLineId]) as $d) {
            $out[(int) $d['id']] = ($d['sj_number'] ?? $d['code']) . ' · ' . fmt_date($d['delivery_date']) . ' · ' . number_format((int) $d['delivered_qty'], 0, ',', '.') . ' pcs';
        }
        return $out;
    }
}
