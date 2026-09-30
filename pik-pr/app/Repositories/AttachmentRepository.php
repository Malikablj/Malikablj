<?php

declare(strict_types=1);

namespace App\Repositories;

final class AttachmentRepository extends Repository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forPr(int $prId): array
    {
        return $this->many(
            'SELECT a.*, u.name AS uploader_name
            FROM attachments a JOIN users u ON u.id = a.uploaded_by
            WHERE a.pr_id = ? ORDER BY a.id',
            [$prId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM attachments WHERE id = ?', [$id]);
    }

    public function countForPr(int $prId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM attachments WHERE pr_id = ?', [$prId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        return $this->insertRow('attachments', $data);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM attachments WHERE id = ?', [$id]);
    }
}
