<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Role;

final class PrRepository extends Repository
{
    private const SELECT = 'SELECT pr.*,
            req.name AS requester_name, req.email AS requester_email, req.job_title AS requester_job_title,
            d.name AS department_name, d.code AS department_code,
            s.name AS supplier_name, s.code AS supplier_code, s.contact AS supplier_contact, s.address AS supplier_address,
            w.name AS workflow_name,
            cs.label AS current_step_label, cs.step_order AS current_step_order
        FROM purchase_requisitions pr
        JOIN users req ON req.id = pr.requester_id
        JOIN departments d ON d.id = pr.department_id
        LEFT JOIN suppliers s ON s.id = pr.supplier_id
        LEFT JOIN approval_workflows w ON w.id = pr.workflow_id
        LEFT JOIN approval_steps cs ON cs.id = pr.current_step_id';

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE pr.id = ?', [$id]);
    }

    /**
     * Mengunci baris PR (SELECT ... FOR UPDATE) selama transaksi berjalan
     * sehingga dua aksi bersamaan pada PR yang sama diproses berurutan.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->one('SELECT * FROM purchase_requisitions WHERE id = ? FOR UPDATE', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $prId): array
    {
        return $this->many(
            'SELECT i.*, m.code AS item_code
            FROM purchase_requisition_items i
            LEFT JOIN items m ON m.id = i.item_id
            WHERE i.pr_id = ? ORDER BY i.line_no',
            [$prId],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('purchase_requisitions', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow('purchase_requisitions', $id, $data);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function replaceItems(int $prId, array $items): void
    {
        $this->run('DELETE FROM purchase_requisition_items WHERE pr_id = ?', [$prId]);
        foreach (array_values($items) as $index => $item) {
            $this->insertRow('purchase_requisition_items', [
                'pr_id' => $prId,
                'line_no' => $index + 1,
                'item_id' => $item['item_id'],
                'item_name_snapshot' => $item['item_name_snapshot'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ]);
        }
    }

    /**
     * Kondisi SQL: PR sedang menunggu keputusan user ini pada tahap berjalan.
     * Harus konsisten dengan ApprovalService::eligibleApprovers().
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: list<mixed>}
     */
    public static function pendingForUserSql(array $user): array
    {
        $sql = "(pr.status IN ('submitted', 'in_review')
            AND pr.requester_id <> ?
            AND EXISTS (
                SELECT 1 FROM approval_steps ps
                WHERE ps.id = pr.current_step_id AND (
                    (ps.approver_type = 'user' AND ps.approver_user_id = ?)
                    OR (ps.approver_type = 'role' AND ps.approver_role = ?
                        AND (ps.same_department = 0 OR pr.department_id = ?))
                )
            )
            AND NOT EXISTS (
                SELECT 1 FROM approval_logs pl
                WHERE pl.pr_id = pr.id AND pl.submission_round = pr.submission_round AND pl.approver_id = ?
            ))";
        $id = (int) $user['id'];

        return [$sql, [$id, $id, (string) $user['role'], (int) ($user['department_id'] ?? 0), $id]];
    }

    /**
     * Kondisi SQL: PR yang boleh dilihat user.
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: list<mixed>}
     */
    public static function visibilitySql(array $user): array
    {
        if (Role::isAdmin((string) $user['role'])) {
            return ['1 = 1', []];
        }
        $id = (int) $user['id'];
        if ($user['role'] === Role::Requester->value) {
            return ['pr.requester_id = ?', [$id]];
        }

        [$pendingSql, $pendingParams] = self::pendingForUserSql($user);

        return [
            "(pr.requester_id = ? OR {$pendingSql}
              OR EXISTS (SELECT 1 FROM approval_logs vl WHERE vl.pr_id = pr.id AND vl.approver_id = ?))",
            [$id, ...$pendingParams, $id],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $user, array $filters, int $page, int $perPage = 15): array
    {
        [$visibility, $params] = self::visibilitySql($user);
        $where = [$visibility];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(pr.pr_number LIKE ? OR pr.notes LIKE ? OR s.name LIKE ? OR req.name LIKE ?)';
            $like = self::like($filters['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if (($filters['status'] ?? '') === 'pending') {
            $where[] = "pr.status IN ('submitted', 'in_review')";
        } elseif (($filters['status'] ?? '') !== '') {
            $where[] = 'pr.status = ?';
            $params[] = $filters['status'];
        }
        foreach (['department_id', 'supplier_id', 'requester_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $where[] = "pr.{$column} = ?";
                $params[] = (int) $filters[$column];
            }
        }
        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'pr.pr_date >= ?';
            $params[] = $filters['date_from'];
        }
        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'pr.pr_date <= ?';
            $params[] = $filters['date_to'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $from = ' FROM purchase_requisitions pr
            JOIN users req ON req.id = pr.requester_id
            LEFT JOIN suppliers s ON s.id = pr.supplier_id';

        return [
            'rows' => $this->many(
                self::SELECT . $whereSql . ' ORDER BY pr.updated_at DESC, pr.id DESC' . self::limit($page, $perPage),
                $params,
            ),
            'total' => (int) $this->scalar('SELECT COUNT(*)' . $from . $whereSql, $params),
        ];
    }

    /**
     * PR yang menunggu keputusan user ini (antrian approval).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function pendingApprovals(array $user, int $limit = 100): array
    {
        [$sql, $params] = self::pendingForUserSql($user);

        return $this->many(
            self::SELECT . " WHERE {$sql} ORDER BY pr.submitted_at ASC, pr.id ASC LIMIT " . max(1, $limit),
            $params,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function countPendingApprovals(array $user): int
    {
        [$sql, $params] = self::pendingForUserSql($user);

        return (int) $this->scalar("SELECT COUNT(*) FROM purchase_requisitions pr WHERE {$sql}", $params);
    }

    /**
     * Keputusan approval yang pernah dibuat user ini.
     *
     * @return list<array<string, mixed>>
     */
    public function decisionsBy(int $userId, int $limit = 50): array
    {
        return $this->many(
            'SELECT l.action, l.comment, l.acted_at, l.step_label, l.submission_round,
                pr.id, pr.pr_number, pr.status, pr.grand_total, pr.pr_date,
                req.name AS requester_name, d.name AS department_name
            FROM approval_logs l
            JOIN purchase_requisitions pr ON pr.id = l.pr_id
            JOIN users req ON req.id = pr.requester_id
            JOIN departments d ON d.id = pr.department_id
            WHERE l.approver_id = ?
            ORDER BY l.acted_at DESC, l.id DESC LIMIT ' . max(1, $limit),
            [$userId],
        );
    }

    /**
     * Jumlah & nilai PR per status sesuai hak lihat user.
     *
     * @param array<string, mixed> $user
     * @return array<string, array{count: int, total: string}>
     */
    public function statsByStatus(array $user): array
    {
        [$visibility, $params] = self::visibilitySql($user);
        $rows = $this->many(
            "SELECT pr.status, COUNT(*) AS cnt, COALESCE(SUM(pr.grand_total), 0) AS total
            FROM purchase_requisitions pr WHERE {$visibility} GROUP BY pr.status",
            $params,
        );
        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = ['count' => (int) $row['cnt'], 'total' => (string) $row['total']];
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function recent(array $user, int $limit = 6): array
    {
        [$visibility, $params] = self::visibilitySql($user);

        return $this->many(
            self::SELECT . " WHERE {$visibility} ORDER BY pr.updated_at DESC, pr.id DESC LIMIT " . max(1, $limit),
            $params,
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function isVisibleTo(array $user, int $prId): bool
    {
        [$visibility, $params] = self::visibilitySql($user);

        return (bool) $this->scalar(
            "SELECT COUNT(*) FROM purchase_requisitions pr WHERE pr.id = ? AND {$visibility}",
            [$prId, ...$params],
        );
    }
}
