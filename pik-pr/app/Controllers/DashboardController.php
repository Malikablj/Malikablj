<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Role;
use App\Repositories\PrRepository;
use App\Services\PrPolicy;
use App\Support\Decimal;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $prs = new PrRepository();
        $byStatus = $prs->statsByStatus($user);

        $count = static fn (string ...$statuses): int => array_sum(array_map(
            static fn (string $s): int => $byStatus[$s]['count'] ?? 0,
            $statuses,
        ));
        $sum = static function (string ...$statuses) use ($byStatus): string {
            $units = 0;
            foreach ($statuses as $status) {
                $units += Decimal::toUnits($byStatus[$status]['total'] ?? '0');
            }

            return Decimal::fromUnits($units);
        };

        $stats = [
            'total' => array_sum(array_column($byStatus, 'count')),
            'draft' => $count('draft'),
            'pending' => $count('submitted', 'in_review'),
            'revision' => $count('revision_required'),
            'approved' => $count('approved', 'completed'),
            'rejected' => $count('rejected'),
            'value_approved' => $sum('approved', 'completed'),
            'value_pending' => $sum('submitted', 'in_review'),
        ];

        $isAdmin = Role::isAdmin((string) $user['role']);
        $myApprovals = $prs->pendingApprovals($user, 5);

        // Kartu kanan menyesuaikan role: antrian pribadi, antrian seluruh sistem, atau PR milik sendiri yang sedang diproses.
        if ($myApprovals !== [] || $user['role'] === Role::Approver->value) {
            $side = ['title' => 'Menunggu Keputusan Anda', 'subtitle' => 'Antrian approval pribadi', 'rows' => $myApprovals,
                'link' => '/approvals', 'empty' => 'Semua PR sudah diproses.'];
        } elseif ($isAdmin) {
            $side = ['title' => 'PR Menunggu Approval', 'subtitle' => 'Seluruh PR yang sedang diproses approver',
                'rows' => $prs->paginate($user, ['status' => 'pending'], 1, 5)['rows'], 'link' => '/pr?status=pending',
                'empty' => 'Tidak ada PR yang menunggu approval.'];
        } else {
            $side = ['title' => 'PR Anda dalam Proses', 'subtitle' => 'Menunggu keputusan approver',
                'rows' => $prs->paginate($user, ['status' => 'pending'], 1, 5)['rows'], 'link' => '/pr?status=pending',
                'empty' => 'Tidak ada PR yang sedang menunggu approval.'];
        }

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => $stats,
            'myApprovalCount' => $prs->countPendingApprovals($user),
            'side' => $side,
            'recent' => $prs->recent($user, 6),
            'canCreate' => PrPolicy::canCreate($user),
            'isAdmin' => $isAdmin,
        ]);
    }
}
