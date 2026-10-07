<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;

/** Komentar pada proses/part/project (semua role kecuali Management). PIC proses & NPD PIC diberi tahu. */
final class CommentService
{
    public function add(User $actor, int $projectId, ?int $processId, string $body): int
    {
        Gate::authorize($actor, 'comment.create');
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new ValidationException(['body' => I18n::t('validation.required_max', ['max' => 4000])]);
        }
        $project = Db::fetch('SELECT id, code, npd_pic_id FROM projects WHERE id = ?', [$projectId]);
        if (!$project) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $process = null;
        if ($processId !== null) {
            $process = Db::fetch('SELECT id, part_id, name, pic_user_id FROM processes WHERE id = ? AND project_id = ?', [$processId, $projectId]);
            if (!$process) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
        }
        $id = Db::insert('comments', [
            'project_id' => $projectId, 'part_id' => $process['part_id'] ?? null, 'process_id' => $processId,
            'user_id' => $actor->id, 'body' => $body, 'created_at' => Clock::nowString(),
        ]);
        Db::update('projects', ['last_activity_at' => Clock::nowString()], ['id' => $projectId]);
        AuditLogger::log('comment.create', 'comment', $id, null, ['process_id' => $processId, 'length' => mb_strlen($body)], null, $projectId, $actor);
        $to = array_filter([$process['pic_user_id'] ?? null, $project['npd_pic_id']]);
        if ($to) {
            Notifier::send(array_map('intval', $to), 'comment', 'notif.comment.title', 'notif.comment.body',
                ['project' => (string) $project['code'], 'process' => (string) ($process['name'] ?? $project['code']), 'user' => $actor->name, 'excerpt' => mb_substr($body, 0, 120)],
                $processId ? 'process.php?id=' . $processId : 'project.php?id=' . $projectId, $projectId, $processId, 'comment:' . $id, $actor->id);
        }
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function forProcess(int $processId): array
    {
        return Db::fetchAll('SELECT c.*, u.name AS user_name FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.process_id = ? ORDER BY c.id', [$processId]);
    }
}
