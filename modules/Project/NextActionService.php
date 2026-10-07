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

/**
 * Next Action & Waiting For per part (dan ringkasannya di project) — PRD §9.5.
 * Satu next action terbuka per part (yang baru menggantikan yang lama → status "cancelled").
 */
final class NextActionService
{
    public const WAITING = ['internal', 'external', 'customer'];

    /** @return list<array<string,mixed>> next action terbuka per part (dan level project) */
    public function open(int $projectId): array
    {
        return Db::fetchAll(
            "SELECT n.*, u.name AS owner_name, pp.name AS part_name FROM next_actions n LEFT JOIN users u ON u.id = n.owner_user_id
             LEFT JOIN project_parts pp ON pp.id = n.part_id WHERE n.project_id = ? AND n.status = 'open' ORDER BY n.part_id IS NOT NULL, pp.sort_order, n.id",
            [$projectId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT n.*, u.name AS owner_name, pp.name AS part_name, c.name AS completed_by_name FROM next_actions n LEFT JOIN users u ON u.id = n.owner_user_id
             LEFT JOIN project_parts pp ON pp.id = n.part_id LEFT JOIN users c ON c.id = n.completed_by WHERE n.project_id = ? ORDER BY n.id DESC LIMIT 100',
            [$projectId]
        );
    }

    public function canEdit(User $user, array $project): bool
    {
        return Gate::can($user, 'project.edit', ['owner_ids' => [$project['sales_pic_id'], $project['npd_pic_id']]]);
    }

    /** @param array<string,mixed> $input description, due_date, owner_user_id, waiting_for, waiting_for_note */
    public function set(User $actor, int $projectId, ?int $partId, array $input): int
    {
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        if (!$project) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        Gate::authorize($actor, 'project.edit', ['owner_ids' => [$project['sales_pic_id'], $project['npd_pic_id']]]);
        if ((int) $project['is_archived'] === 1 || $project['finished_at'] !== null || $project['cancelled_at'] !== null) {
            throw new \App\Core\BusinessRuleException(I18n::t('hold.project_closed'));
        }
        if ($partId !== null && !Db::value('SELECT id FROM project_parts WHERE id = ? AND project_id = ?', [$partId, $projectId])) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $errors = [];
        $desc = trim((string) ($input['description'] ?? ''));
        if ($desc === '' || mb_strlen($desc) > 500) {
            $errors['description'] = I18n::t('validation.required_max', ['max' => 500]);
        }
        $due = trim((string) ($input['due_date'] ?? ''));
        if ($due !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if (!$d || $d->format('Y-m-d') !== $due) {
                $errors['due_date'] = I18n::t('validation.date');
            }
        }
        $waiting = (string) ($input['waiting_for'] ?? 'internal');
        if (!in_array($waiting, self::WAITING, true)) {
            $errors['waiting_for'] = I18n::t('validation.invalid');
        }
        $owner = trim((string) ($input['owner_user_id'] ?? ''));
        if ($owner !== '' && !Db::value('SELECT id FROM users WHERE id = ? AND is_active = 1', [(int) $owner])) {
            $errors['owner_user_id'] = I18n::t('validation.invalid');
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return Db::transaction(function () use ($actor, $projectId, $partId, $desc, $due, $waiting, $owner, $input, $project): int {
            $now = Clock::nowString();
            Db::execute("UPDATE next_actions SET status = 'cancelled', completed_at = ?, completed_by = ? WHERE project_id = ? AND part_id <=> ? AND status = 'open'",
                [$now, $actor->id, $projectId, $partId]);
            $data = [
                'project_id' => $projectId, 'part_id' => $partId, 'description' => $desc, 'due_date' => $due ?: null,
                'owner_user_id' => $owner !== '' ? (int) $owner : null, 'waiting_for' => $waiting,
                'waiting_for_note' => mb_substr(trim((string) ($input['waiting_for_note'] ?? '')), 0, 255) ?: null,
                'status' => 'open', 'created_by' => $actor->id, 'created_at' => $now,
            ];
            $id = Db::insert('next_actions', $data);
            Db::update('projects', ['last_activity_at' => $now], ['id' => $projectId]);
            AuditLogger::log('next_action.set', 'next_action', $id, null, array_diff_key($data, ['created_at' => 1, 'created_by' => 1]), null, $projectId, $actor);
            if ($data['owner_user_id'] !== null) {
                Notifier::send([(int) $data['owner_user_id']], 'next_action', 'notif.next_action.title', 'notif.next_action.body',
                    ['project' => (string) $project['code'], 'action' => $desc, 'due' => $due !== '' ? I18n::date($due) : '–'],
                    'project.php?id=' . $projectId, $projectId, null, 'next_action:' . $id, $actor->id);
            }
            return $id;
        });
    }

    public function complete(User $actor, int $id): void
    {
        $n = Db::fetch('SELECT n.*, p.sales_pic_id, p.npd_pic_id FROM next_actions n JOIN projects p ON p.id = n.project_id WHERE n.id = ?', [$id]);
        if (!$n || $n['status'] !== 'open') {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        Gate::authorize($actor, 'project.edit', ['owner_ids' => [$n['sales_pic_id'], $n['npd_pic_id'], $n['owner_user_id']]]);
        Db::update('next_actions', ['status' => 'done', 'completed_at' => Clock::nowString(), 'completed_by' => $actor->id], ['id' => $id]);
        AuditLogger::log('next_action.done', 'next_action', $id, ['status' => 'open'], ['status' => 'done'], null, (int) $n['project_id'], $actor);
    }
}
