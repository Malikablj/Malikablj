<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\Clock;
use App\Core\Db;
use App\Core\RequestContext;

/** Revision History (PRD §4.1, §5.2, §9.4): perubahan NPR, loop, revisi dokumen, Hold, baseline. */
final class RevisionHistory
{
    /** @param array<string,mixed>|null $details */
    public static function record(
        string $type,
        string $summary,
        ?array $details = null,
        ?int $nprId = null,
        ?int $projectId = null,
        ?int $partId = null,
        ?int $processId = null,
        ?int $userId = null,
    ): int {
        return Db::insert('revision_history', [
            'npr_id' => $nprId,
            'project_id' => $projectId,
            'part_id' => $partId,
            'process_id' => $processId,
            'revision_type' => $type,
            'summary' => mb_substr($summary, 0, 500),
            'details_json' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'user_id' => $userId ?? RequestContext::user()?->id,
            'created_at' => Clock::nowString(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function forNpr(int $nprId): array
    {
        return Db::fetchAll(
            'SELECT r.*, u.name AS user_name FROM revision_history r LEFT JOIN users u ON u.id = r.user_id
             WHERE r.npr_id = ? ORDER BY r.id DESC',
            [$nprId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forProject(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT r.*, u.name AS user_name, pp.name AS part_name, pr.name AS process_name
             FROM revision_history r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN project_parts pp ON pp.id = r.part_id
             LEFT JOIN processes pr ON pr.id = r.process_id
             WHERE r.project_id = ? OR r.npr_id = (SELECT npr_id FROM projects WHERE id = ?)
             ORDER BY r.id DESC',
            [$projectId, $projectId]
        );
    }
}
