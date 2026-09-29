<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/** Retur barang dari customer (tabel `returns`). Retur MENAMBAH outstanding. */
final class ProductReturn extends Model
{
    public const TABLE = 'returns';
    public const ENTITY = 'return';
    public const LABEL = 'code';

    public const REASONS = ['Damage', 'Wrong Product', 'Quality Issue', 'Over Delivery', 'Customer Request', 'Other'];
    public const REASON_LABELS = [
        'Damage' => 'Rusak', 'Wrong Product' => 'Salah produk', 'Quality Issue' => 'Masalah kualitas',
        'Over Delivery' => 'Kelebihan kirim', 'Customer Request' => 'Permintaan customer', 'Other' => 'Lainnya',
    ];

    private const SELECT = 'SELECT r.*, p.po_number, p.code AS po_code, p.customer_id, c.name AS customer_name,
            pr.name AS product_name, d.sj_number AS delivery_sj
        FROM returns r
        LEFT JOIN purchase_orders p ON p.id = r.po_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN products pr ON pr.id = r.product_id
        LEFT JOIN deliveries d ON d.id = r.delivery_id';

    public static function paginate(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(r.code LIKE :q1 OR p.po_number LIKE :q2 OR c.name LIKE :q3 OR pr.name LIKE :q4 OR r.product_legacy LIKE :q5 OR r.sj_number LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
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
        return Paginator::query(self::SELECT . ' WHERE ' . implode(' AND ', $where), $params, 'r.return_date IS NULL, r.return_date DESC, r.id DESC', $page);
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
            if ($data['po_line_id'] !== null) {
                $line = PoLine::find((int) $data['po_line_id']);
                if ($line === null) {
                    throw new DomainException('PO line tidak ditemukan.');
                }
                $data['po_id'] = (int) $line['po_id'];
                $data['product_id'] = (int) $line['product_id'];
                if (!empty($data['delivery_id'])) {
                    $deliveryLine = Database::fetchValue('SELECT po_line_id FROM deliveries WHERE id = :id', ['id' => $data['delivery_id']]);
                    if ((int) $deliveryLine !== (int) $data['po_line_id']) {
                        throw new DomainException('Surat jalan yang dipilih bukan untuk PO line ini.');
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
        Database::transaction(function () use ($id, $r): void {
            self::delete($id, $r);
            PurchaseOrder::syncStatus($r['po_id'] !== null ? (int) $r['po_id'] : null);
        });
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
