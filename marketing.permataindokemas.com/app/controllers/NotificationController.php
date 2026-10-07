<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Models\Notification;

/** Pusat notifikasi — setiap user hanya bisa melihat notifikasinya sendiri. */
final class NotificationController extends Controller
{
    public function index(): void
    {
        $filter = Request::queryString('filter') === 'unread' ? 'unread' : 'all';
        $userId = (int) Auth::id();
        $this->view('notifications/index', [
            'title'         => 'Notifikasi',
            'notifications' => Notification::paginateForUser($userId, $filter, $this->page()),
            'filter'        => $filter,
            'unread'        => Notification::unreadCount($userId),
        ]);
    }

    /** Tandai dibaca lalu buka tautan notifikasi. */
    public function open(int $id): void
    {
        $userId = (int) Auth::id();
        $notification = $this->found(Notification::findForUser($id, $userId));
        Notification::markRead($id, $userId);
        $link = (string) ($notification['link'] ?? '');
        redirect($link !== '' && str_starts_with($link, '/') && !str_starts_with($link, '//') ? $link : '/notifications');
    }

    public function markAll(): void
    {
        $count = Notification::markAllRead((int) Auth::id());
        $this->success($count > 0 ? "{$count} notifikasi ditandai sudah dibaca." : 'Tidak ada notifikasi baru.', '/notifications');
    }
}
