<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationRepository;

final class NotificationService
{
    private NotificationRepository $notifications;

    public function __construct(?NotificationRepository $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationRepository();
    }

    public function notify(int $userId, string $type, string $title, string $message, ?int $prId = null): void
    {
        $this->notifications->create([
            'user_id' => $userId,
            'pr_id' => $prId,
            'type' => $type,
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @param iterable<int> $userIds
     */
    public function notifyMany(iterable $userIds, string $type, string $title, string $message, ?int $prId = null): void
    {
        $sent = [];
        foreach ($userIds as $userId) {
            if (isset($sent[$userId])) {
                continue;
            }
            $sent[$userId] = true;
            $this->notify($userId, $type, $title, $message, $prId);
        }
    }
}
