<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

/**
 * Estimasi tanggal delivery (lead time) per PO & produk.
 * "Terlambat" dihitung saat ditampilkan: status masih terbuka dan
 * estimasi tanggal delivery sudah lewat (tidak disimpan sebagai status).
 */
final class LeadTime extends Model
{
    public const TABLE = 'leadtime';
    public const ENTITY = 'leadtime';
    public const LABEL = 'code';

    public const STATUSES = ['Planned', 'On Process', 'Delivered', 'Delayed', 'Cancelled'];
    public const OPEN_STATUSES = ['Planned', 'On Process', 'Delayed'];
    public const STATUS_HELP = [
        'Planned'    => 'Dijadwalkan',
        'On Process' => 'Sedang diproduksi / disiapkan',
        'Delivered'  => 'Sudah dikirim',
        'Delayed'    => 'Mundur dari jadwal',
        'Cancelled'  => 'Dibatalkan',
    ];
    /** Issue migrasi yang selesai saat lead time dihubungkan ke PO. */
    public const LINK_ISSUES = ['PO NOT FOUND'];

    private const SELECT = 'SELECT lt.*, p.po_number, p.code AS po_code, p.status AS po_status, p.customer_id, c.name AS customer_name,
            pr.name AS product_name, pr.variant
        FROM leadtime lt
        LEFT JOIN purchase_orders p ON p.id = lt.po_id
        LEFT JOIN customers c ON c.id = p.customer_id
        LEFT JOIN products pr ON pr.id = lt.product_id';

    private const OPEN_SQL = "lt.status IN ('Planned','On Process','Delayed')";

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f, string $today): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.po_number LIKE :q1 OR lt.po_number_legacy LIKE :q2 OR pr.name LIKE :q3 OR lt.product_legacy LIKE :q4 OR c.name LIKE :q5 OR lt.code LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'open') {
            $where[] = self::OPEN_SQL;
        } elseif ($status === 'late') {
            $where[] = self::OPEN_SQL . ' AND lt.delivery_date < :today';
            $params['today'] = $today;
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'lt.status = :status';
            $params['status'] = $status;
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 'lt.delivery_date >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'lt.delivery_date <= :to';
            $params['to'] = $f['to'];
        }
        if (($f['link'] ?? '') === 'unlinked') {
            $where[] = 'lt.po_id IS NULL';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Estimasi yang masih terbuka tampil lebih dulu (terdekat di atas), lalu riwayat terbaru. */
    public static function paginate(array $f, string $today, int $page): Paginator
    {
        [$where, $params] = self::filters($f, $today);
        return Paginator::query(
            self::SELECT . ' WHERE ' . $where,
            $params,
            self::OPEN_SQL . ' DESC, lt.delivery_date IS NULL, CASE WHEN ' . self::OPEN_SQL . ' THEN lt.delivery_date END ASC, lt.delivery_date DESC, lt.id DESC',
            $page
        );
    }

    /** @return array<string,int> */
    public static function summary(array $f, string $today): array
    {
        [$where, $params] = self::filters($f, $today);
        $params['t1'] = $today;
        $params['t2'] = $today;
        $params['t3'] = date('Y-m-d', strtotime($today . ' +7 days'));
        $row = Database::fetch(
            'SELECT COUNT(*) AS n,
                    COALESCE(SUM(' . self::OPEN_SQL . '), 0) AS open_count,
                    COALESCE(SUM(' . self::OPEN_SQL . ' AND lt.delivery_date < :t1), 0) AS late,
                    COALESCE(SUM(' . self::OPEN_SQL . ' AND lt.delivery_date BETWEEN :t2 AND :t3), 0) AS due_week,
                    COALESCE(SUM(lt.po_id IS NULL), 0) AS unlinked
             FROM leadtime lt LEFT JOIN purchase_orders p ON p.id = lt.po_id LEFT JOIN customers c ON c.id = p.customer_id
             LEFT JOIN products pr ON pr.id = lt.product_id WHERE ' . $where,
            $params
        ) ?? [];
        return array_map('intval', $row);
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(self::SELECT . ' WHERE lt.id = :id', ['id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function forPo(int $poId): array
    {
        return Database::fetchAll(self::SELECT . ' WHERE lt.po_id = :id ORDER BY lt.delivery_date IS NULL, lt.delivery_date, lt.id', ['id' => $poId]);
    }

    /** @return list<array<string,mixed>> */
    public static function forProduct(int $productId, int $limit = 20): array
    {
        return Database::fetchAll(
            self::SELECT . ' WHERE lt.product_id = :id ORDER BY ' . self::OPEN_SQL . ' DESC, lt.delivery_date DESC, lt.id DESC LIMIT ' . max(1, $limit),
            ['id' => $productId]
        );
    }

    /** @return list<array<string,mixed>> estimasi terbuka mulai hari ini (terdekat dulu) */
    public static function upcoming(string $today, int $limit = 8): array
    {
        return Database::fetchAll(
            self::SELECT . ' WHERE ' . self::OPEN_SQL . ' AND lt.delivery_date >= :today ORDER BY lt.delivery_date, lt.id LIMIT ' . max(1, $limit),
            ['today' => $today]
        );
    }

    /** @param array<string,mixed> $row */
    public static function isLate(array $row, string $today): bool
    {
        return in_array($row['status'] ?? '', self::OPEN_STATUSES, true)
            && !empty($row['delivery_date']) && (string) $row['delivery_date'] < $today;
    }

    /** Cari PO line yang cocok dengan PO + produk lead time (untuk mengisi form edit). */
    public static function matchingLineId(?int $poId, ?int $productId): ?int
    {
        if ($poId === null || $productId === null) {
            return null;
        }
        $id = Database::fetchValue('SELECT id FROM po_lines WHERE po_id = :po AND product_id = :pr ORDER BY id LIMIT 1', ['po' => $poId, 'pr' => $productId]);
        return $id !== null ? (int) $id : null;
    }

    /**
     * Simpan lead time. PO & produk diturunkan dari PO line yang dipilih.
     * @param array<string,mixed> $data berisi po_line_id (tidak disimpan) + kolom leadtime
     */
    public static function saveLeadTime(?int $id, array $data): int
    {
        return Database::transaction(function () use ($id, $data): int {
            $before = $id !== null ? self::find($id) : null;
            if ($id !== null && $before === null) {
                throw new DomainException('Lead time tidak ditemukan.');
            }
            $lineId = $data['po_line_id'] ?? null;
            unset($data['po_line_id']);
            if ($lineId !== null) {
                $line = PoLine::find((int) $lineId);
                if ($line === null) {
                    throw new DomainException('Baris PO tidak ditemukan.');
                }
                $data['po_id'] = (int) $line['po_id'];
                $data['product_id'] = (int) $line['product_id'];
            }
            if ($id === null) {
                $id = self::create($data);
            } else {
                self::update($id, $data, $before);
            }
            if (!empty($data['po_id']) && ($before === null || $before['po_id'] === null)) {
                MigrationIssue::resolveForRecord('LEADTIME', $id, self::LINK_ISSUES, 'Lead time dihubungkan ke PO oleh user.');
            }
            return $id;
        });
    }

    public static function remove(int $id): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new DomainException('Lead time tidak ditemukan.');
        }
        Database::transaction(function () use ($id, $row): void {
            self::delete($id, $row);
            MigrationIssue::closeForDeletedRecord('LEADTIME', $id);
        });
    }
}
