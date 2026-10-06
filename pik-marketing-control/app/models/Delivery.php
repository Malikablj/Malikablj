<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class Delivery extends Model
{
    public const TABLE = 'deliveries';
    public const ENTITY = 'delivery';
    public const LABEL = 'sj_number';

    public const STATUSES = ['Scheduled', 'On Delivery', 'Delivered', 'Partial', 'Cancelled'];
    /** Status yang mengurangi outstanding (barang sudah sampai di customer). */
    public const COUNTED_STATUSES = PoLine::DELIVERED_STATUSES;
    public const STATUS_HELP = [
        'Scheduled'   => 'Dijadwalkan, belum berangkat',
        'On Delivery' => 'Dalam perjalanan',
        'Delivered'   => 'Diterima customer (mengurangi outstanding)',
        'Partial'     => 'Diterima sebagian (qty yang diterima mengurangi outstanding)',
        'Cancelled'   => 'Dibatalkan',
    ];

    /** schedule_source untuk jadwal yang dibuat otomatis saat OEF disetujui PPIC. */
    public const SOURCE_OEF = 'OEF';
    /** Jadwal otomatis dari OEF yang sudah diubah manual (tidak lagi disesuaikan otomatis). */
    public const SOURCE_OEF_EDITED = 'OEF_EDITED';

    private const SELECT = 'SELECT d.*, p.po_number, p.order_number, p.code AS po_code, p.customer_id, c.name AS customer_name,
            COALESCE(p.order_number, p.po_number, p.code) AS order_ref, pr.name AS product_name, pl.code AS line_code
        FROM deliveries d
        LEFT JOIN purchase_orders p ON p.id = d.po_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN products pr ON pr.id = d.product_id
        LEFT JOIN po_lines pl ON pl.id = d.po_line_id';

    /** @return array{0:string,1:array<string,mixed>} */
    public static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(d.sj_number LIKE :q1 OR d.code LIKE :q2 OR p.po_number LIKE :q3 OR c.name LIKE :q4 OR d.destination LIKE :q5 OR pr.name LIKE :q6 OR p.order_number LIKE :q7)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'upcoming') {
                $where[] = "d.status IN ('Scheduled','On Delivery')";
            } elseif (in_array($f['status'], self::STATUSES, true)) {
                $where[] = 'd.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['po_id'])) {
            $where[] = 'd.po_id = :po';
            $params['po'] = (int) $f['po_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'd.delivery_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'd.delivery_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['link'] ?? '') === 'unlinked') {
            $where[] = 'd.po_line_id IS NULL';
        }
        if (($f['sj'] ?? '') === 'missing') {
            $where[] = "(d.sj_number IS NULL OR d.sj_number = '') AND d.status <> 'Cancelled'";
        }
        return [implode(' AND ', $where), $params];
    }

    public static function paginate(array $f, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, 'd.delivery_date IS NULL, d.delivery_date DESC, d.id DESC', $page);
    }

    /** @return array<string,mixed> */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        return Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN d.status IN ('Delivered','Partial') THEN d.delivered_qty ELSE 0 END), 0) AS delivered,
                    COALESCE(SUM(d.po_line_id IS NULL), 0) AS unlinked,
                    COALESCE(SUM(d.status IN ('Scheduled','On Delivery')), 0) AS upcoming,
                    COALESCE(SUM((d.sj_number IS NULL OR d.sj_number = '') AND d.status <> 'Cancelled'), 0) AS without_sj
             FROM deliveries d LEFT JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = d.product_id WHERE " . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE d.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPo(int $poId): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE d.po_id = :id ORDER BY d.delivery_date DESC, d.id DESC', ['id' => $poId]);
    }

    /**
     * Simpan delivery. PO & produk selalu diturunkan dari PO line (konsisten).
     * @param array<string,mixed> $data
     */
    public static function saveDelivery(?int $id, array $data): int
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
                $data['migration_flag'] = null;
            }
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if ($data['po_line_id'] !== null && ($before === null || $before['po_line_id'] === null)) {
                MigrationIssue::resolveForRecord('DELIVERIES', $id, MigrationIssue::LINK_TYPES, 'Delivery dihubungkan ke PO line oleh user.');
            }
            PurchaseOrder::syncStatus(isset($data['po_id']) ? (int) $data['po_id'] : null);
            if ($before !== null && $before['po_id'] !== null && (int) $before['po_id'] !== (int) ($data['po_id'] ?? 0)) {
                PurchaseOrder::syncStatus((int) $before['po_id']);
            }
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $d = self::find($id);
        if ($d === null) {
            throw new DomainException('Delivery tidak ditemukan.');
        }
        $returns = (int) Database::fetchValue('SELECT COUNT(*) FROM returns WHERE delivery_id = :id', ['id' => $id]);
        if ($returns > 0) {
            throw new DomainException("Delivery tidak dapat dihapus karena direferensikan oleh {$returns} retur.");
        }
        Database::transaction(function () use ($id, $d): void {
            self::delete($id, $d);
            PurchaseOrder::syncStatus($d['po_id'] !== null ? (int) $d['po_id'] : null);
        });
    }

    /**
     * Jadwalkan pengiriman dari OEF yang disetujui PPIC (tanggal = permintaan
     * selesai/kirim, tujuan = tujuan kirim OEF). Per baris OEF:
     *   kebutuhan = outstanding − qty jadwal lain yang belum terkirim (Scheduled / On Delivery)
     *   - ada jadwal otomatis (Scheduled, belum ada SJ, belum diubah manual) → tanggal & qty disesuaikan,
     *     atau dibatalkan bila kebutuhan ≤ 0;
     *   - belum ada → jadwal baru dibuat bila kebutuhan > 0.
     * Jadwal yang sudah diubah manual (PPIC) atau sudah dikirim tidak pernah diubah.
     * @return array{created:int,updated:int,cancelled:int,skipped:string|null}
     */
    public static function scheduleFromOef(int $poId): array
    {
        $po = Database::fetch('SELECT id, requested_delivery_date, delivery_address FROM purchase_orders WHERE id = :id', ['id' => $poId]);
        $result = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'skipped' => null];
        if ($po === null) {
            return $result;
        }
        if ($po['requested_delivery_date'] === null) {
            $result['skipped'] = 'Tanggal permintaan selesai/kirim kosong, jadwal delivery belum dibuat.';
            return $result;
        }
        $destination = $po['delivery_address'] !== null ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $po['delivery_address'])), 0, 255) : null;
        foreach (Database::fetchAll('SELECT t.* FROM (' . PoLine::totalsSql() . ') t WHERE t.po_id = :p ORDER BY t.line_id', ['p' => $poId]) as $line) {
            $lineId = (int) $line['line_id'];
            $auto = Database::fetch(
                "SELECT * FROM deliveries WHERE po_line_id = :l AND schedule_source = :src AND status = 'Scheduled' AND (sj_number IS NULL OR sj_number = '')
                 ORDER BY id LIMIT 1",
                ['l' => $lineId, 'src' => self::SOURCE_OEF]
            );
            $pendingOther = (int) Database::fetchValue(
                "SELECT COALESCE(SUM(delivered_qty), 0) FROM deliveries WHERE po_line_id = :l AND status IN ('Scheduled','On Delivery') AND id <> :ex",
                ['l' => $lineId, 'ex' => $auto['id'] ?? 0]
            );
            $need = (int) $line['outstanding_qty'] - $pendingOther;
            if ($auto !== null) {
                if ($need > 0) {
                    $changes = self::update((int) $auto['id'], ['delivery_date' => $po['requested_delivery_date'], 'delivered_qty' => $need, 'destination' => $destination], $auto);
                    $result['updated'] += $changes !== [] ? 1 : 0;
                } else {
                    self::update((int) $auto['id'], ['status' => 'Cancelled', 'note' => trim(($auto['note'] ?? '') . "\nDibatalkan otomatis: qty OEF sudah terpenuhi jadwal lain.")], $auto);
                    $result['cancelled']++;
                }
            } elseif ($need > 0) {
                self::create([
                    'po_id' => $poId, 'po_line_id' => $lineId, 'product_id' => (int) $line['product_id'],
                    'delivery_date' => $po['requested_delivery_date'], 'delivered_qty' => $need, 'destination' => $destination,
                    'status' => 'Scheduled', 'schedule_source' => self::SOURCE_OEF,
                    'note' => 'Dijadwalkan otomatis dari OEF (permintaan selesai/kirim).',
                ]);
                $result['created']++;
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> delivery terjadwal / dalam perjalanan */
    public static function upcoming(int $limit = 8): array
    {
        return Database::fetchAll(
            self::SELECT . " WHERE d.status IN ('Scheduled','On Delivery') ORDER BY d.delivery_date IS NULL, d.delivery_date ASC, d.id ASC LIMIT " . max(1, $limit)
        );
    }
}
