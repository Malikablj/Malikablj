<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\NotificationRepository;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $unreadOnly = ($request->query['filter'] ?? '') === 'unread';
        $page = $this->page($request);
        $result = (new NotificationRepository())->forUser((int) $user['id'], $unreadOnly, $page);

        return $this->view('notifications/index', [
            'title' => 'Notifikasi',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 20,
            'unreadOnly' => $unreadOnly,
        ]);
    }

    /**
     * Tandai dibaca lalu buka PR terkait.
     */
    public function read(Request $request, int $id): Response
    {
        $user = $this->user();
        $repo = new NotificationRepository();
        $notification = $this->findOr404($repo->findForUser($id, (int) $user['id']));
        $repo->markRead($id, (int) $user['id']);

        return Response::redirect($notification['pr_id'] !== null ? '/pr/' . $notification['pr_id'] : '/notifications');
    }

    public function readAll(Request $request): Response
    {
        $count = (new NotificationRepository())->markAllRead((int) $this->user()['id']);

        return $this->redirect('/notifications', $count > 0 ? "{$count} notifikasi ditandai sudah dibaca." : 'Tidak ada notifikasi baru.');
    }

    public function unreadCount(Request $request): Response
    {
        return Response::json(['unread' => (new NotificationRepository())->unreadCount((int) $this->user()['id'])]);
    }
}
