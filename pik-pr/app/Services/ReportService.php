<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\PrStatus;
use PDO;

/**
 * Laporan PR dengan filter tanggal, department, supplier, requester, dan status.
 */
final class ReportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    public static function filters(array $input): array
    {
        $filters = [];
        foreach (['date_from', 'date_to'] as $key) {
            $value = is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
            $filters[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        }
        foreach (['department_id', 'supplier_id', 'requester_id'] as $key) {
            $value = is_scalar($input[$key] ?? null) ? (int) $input[$key] : 0;
            $filters[$key] = $value > 0 ? (string) $value : '';
        }
        $status = is_scalar($input['status'] ?? null) ? (string) $input['status'] : '';
        $filters['status'] = PrStatus::tryFrom($status) !== null ? $status : '';

        return $filters;
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filters['date_from'] !== '') {
            $where[] = 'pr.pr_date >= ?';
            $params[] = $filters['date_from'];
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'pr.pr_date <= ?';
            $params[] = $filters['date_to'];
        }
        foreach (['department_id', 'supplier_id', 'requester_id'] as $column) {
            if ($filters[$column] !== '') {
                $where[] = "pr.{$column} = ?";
                $params[] = (int) $filters[$column];
            }
        }
        if ($filters['status'] !== '') {
            $where[] = 'pr.status = ?';
            $params[] = $filters['status'];
        }

        return [' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, string> $filters
     * @return array{count: int, total: string}
     */
    public function summary(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(pr.grand_total), 0) AS total FROM purchase_requisitions pr{$where}");
        $stmt->execute($params);
        $row = (array) $stmt->fetch();

        return ['count' => (int) $row['cnt'], 'total' => (string) $row['total']];
    }

    /**
     * Rekap per dimensi: status | department | supplier | requester | month.
     *
     * @param array<string, string> $filters
     * @return list<array{label: string, count: int, total: string}>
     */
    public function breakdown(array $filters, string $dimension): array
    {
        [$select, $join, $group] = match ($dimension) {
            'status' => ['pr.status', '', 'pr.status'],
            'department' => ['d.name', 'JOIN departments d ON d.id = pr.department_id', 'd.id, d.name'],
            'supplier' => ["COALESCE(s.name, '(Belum dipilih)')", 'LEFT JOIN suppliers s ON s.id = pr.supplier_id', 's.id, s.name'],
            'requester' => ['u.name', 'JOIN users u ON u.id = pr.requester_id', 'u.id, u.name'],
            'month' => ["DATE_FORMAT(pr.pr_date, '%Y-%m')", '', "DATE_FORMAT(pr.pr_date, '%Y-%m')"],
            default => throw new \InvalidArgumentException('Dimensi laporan tidak dikenal.'),
        };
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare(
            "SELECT {$select} AS label, COUNT(*) AS cnt, COALESCE(SUM(pr.grand_total), 0) AS total
            FROM purchase_requisitions pr {$join}{$where}
            GROUP BY {$group}
            ORDER BY total DESC, label",
        );
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'label' => $dimension === 'status' ? status_label((string) $row['label']) : (string) $row['label'],
            'count' => (int) $row['cnt'],
            'total' => (string) $row['total'],
        ], $stmt->fetchAll());
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters, int $limit = 500): array
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->db->prepare(
            'SELECT pr.id, pr.pr_number, pr.pr_date, pr.status, pr.subtotal, pr.tax_amount, pr.grand_total,
                d.name AS department_name, COALESCE(s.name, \'\') AS supplier_name, u.name AS requester_name,
                (SELECT COUNT(*) FROM purchase_requisition_items i WHERE i.pr_id = pr.id) AS item_count
            FROM purchase_requisitions pr
            JOIN departments d ON d.id = pr.department_id
            JOIN users u ON u.id = pr.requester_id
            LEFT JOIN suppliers s ON s.id = pr.supplier_id'
            . $where . ' ORDER BY pr.pr_date DESC, pr.id DESC LIMIT ' . max(1, $limit),
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * CSV (dibuka langsung oleh Excel). Nilai uang tetap dalam format angka MySQL.
     *
     * @param array<string, string> $filters
     */
    public function csv(array $filters): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
        fputcsv($handle, ['No. PR', 'Tanggal', 'Department', 'Supplier', 'Pemohon', 'Status', 'Jumlah Item', 'Subtotal', 'Pajak', 'Total'], ';', '"', '');
        foreach ($this->rows($filters, 100000) as $row) {
            fputcsv($handle, array_map([self::class, 'safeCell'], [
                $row['pr_number'] ?? ('Draft #' . $row['id']),
                $row['pr_date'],
                $row['department_name'],
                $row['supplier_name'],
                $row['requester_name'],
                status_label((string) $row['status']),
                (string) $row['item_count'],
                $row['subtotal'],
                $row['tax_amount'],
                $row['grand_total'],
            ]), ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Mencegah CSV/formula injection saat file dibuka di Excel.
     */
    private static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
