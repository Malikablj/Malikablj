<?php

declare(strict_types=1);

namespace App\Repositories;

final class ApprovalLogRepository extends Repository
{
    /**
     * Seluruh riwayat approval PR, termasuk dari putaran pengajuan sebelumnya.
     *
     * @return list<array<string, mixed>>
     */
    public function forPr(int $prId): array
    {
        return $this->many(
            'SELECT l.*, u.name AS approver_name, u.job_title AS approver_job_title
            FROM approval_logs l JOIN users u ON u.id = l.approver_id
            WHERE l.pr_id = ?
            ORDER BY l.submission_round, l.step_order, l.id',
            [$prId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRound(int $prId, int $round): array
    {
        return $this->many(
            'SELECT l.*, u.name AS approver_name, u.job_title AS approver_job_title
            FROM approval_logs l JOIN users u ON u.id = l.approver_id
            WHERE l.pr_id = ? AND l.submission_round = ?
            ORDER BY l.step_order',
            [$prId, $round],
        );
    }

    /**
     * @return list<int>
     */
    public function approverIdsInRound(int $prId, int $round): array
    {
        return array_map('intval', array_column($this->many(
            'SELECT approver_id FROM approval_logs WHERE pr_id = ? AND submission_round = ?',
            [$prId, $round],
        ), 'approver_id'));
    }

    public function userHasDecision(int $prId, int $userId): bool
    {
        return (bool) $this->scalar(
            'SELECT COUNT(*) FROM approval_logs WHERE pr_id = ? AND approver_id = ?',
            [$prId, $userId],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('approval_logs', $data);
    }
}
