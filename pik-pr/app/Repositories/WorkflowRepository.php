<?php

declare(strict_types=1);

namespace App\Repositories;

final class WorkflowRepository extends Repository
{
    private const STEP_SELECT = 'SELECT s.*, u.name AS approver_user_name, u.is_active AS approver_user_active
        FROM approval_steps s
        LEFT JOIN users u ON u.id = s.approver_user_id';

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->many(
            'SELECT w.*, d.name AS department_name,
                (SELECT COUNT(*) FROM approval_steps s WHERE s.workflow_id = w.id) AS step_count,
                (SELECT COUNT(*) FROM purchase_requisitions p WHERE p.workflow_id = w.id) AS usage_count
            FROM approval_workflows w
            LEFT JOIN departments d ON d.id = w.department_id
            ORDER BY w.is_active DESC, w.department_id IS NOT NULL, d.name, w.name',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT w.*, d.name AS department_name
            FROM approval_workflows w LEFT JOIN departments d ON d.id = w.department_id
            WHERE w.id = ?',
            [$id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(int $workflowId): array
    {
        return $this->many(self::STEP_SELECT . ' WHERE s.workflow_id = ? ORDER BY s.step_order', [$workflowId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function step(int $stepId): ?array
    {
        return $this->one(self::STEP_SELECT . ' WHERE s.id = ?', [$stepId]);
    }

    /**
     * Workflow aktif untuk department: workflow khusus department diprioritaskan,
     * jika tidak ada dipakai workflow default (department_id NULL).
     *
     * @return array<string, mixed>|null
     */
    public function activeForDepartment(int $departmentId): ?array
    {
        return $this->one(
            'SELECT w.*, d.name AS department_name
            FROM approval_workflows w LEFT JOIN departments d ON d.id = w.department_id
            WHERE w.is_active = 1 AND (w.department_id = ? OR w.department_id IS NULL)
            ORDER BY w.department_id IS NULL
            LIMIT 1',
            [$departmentId],
        );
    }

    public function activeExistsForScope(?int $departmentId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM approval_workflows WHERE is_active = 1 AND id <> ? AND '
            . ($departmentId === null ? 'department_id IS NULL' : 'department_id = ?');
        $params = [$exceptId ?? 0];
        if ($departmentId !== null) {
            $params[] = $departmentId;
        }

        return (bool) $this->scalar($sql, $params);
    }

    public function isUsed(int $workflowId): bool
    {
        return (bool) $this->scalar('SELECT COUNT(*) FROM purchase_requisitions WHERE workflow_id = ?', [$workflowId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('approval_workflows', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->updateRow('approval_workflows', $id, $data);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM approval_workflows WHERE id = ?', [$id]);
    }

    public function deleteSteps(int $workflowId): void
    {
        $this->run('DELETE FROM approval_steps WHERE workflow_id = ?', [$workflowId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createStep(array $data): int
    {
        return $this->insertRow('approval_steps', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStep(int $stepId, array $data): void
    {
        $this->updateRow('approval_steps', $stepId, $data);
    }
}
