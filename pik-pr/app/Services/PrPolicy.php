<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrStatus;
use App\Models\Role;
use App\Repositories\ApprovalLogRepository;

/**
 * Aturan otorisasi PR. Selalu dicek di server, bukan hanya dengan menyembunyikan tombol.
 */
final class PrPolicy
{
    private const OWNER_CANCELLABLE = ['draft', 'submitted', 'in_review', 'revision_required'];
    private const ADMIN_CANCELLABLE = ['draft', 'submitted', 'in_review', 'revision_required', 'approved'];

    private ApprovalService $approvals;
    private ApprovalLogRepository $logs;

    public function __construct(?ApprovalService $approvals = null)
    {
        $this->approvals = $approvals ?? new ApprovalService();
        $this->logs = new ApprovalLogRepository();
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function canCreate(array $user): bool
    {
        return $user['role'] === Role::Requester->value;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public static function isOwner(array $user, array $pr): bool
    {
        return (int) $user['id'] === (int) $pr['requester_id'];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public function canView(array $user, array $pr): bool
    {
        if (Role::isAdmin((string) $user['role']) || self::isOwner($user, $pr)) {
            return true;
        }

        return $this->approvals->canAct($user, $pr) || $this->logs->userHasDecision((int) $pr['id'], (int) $user['id']);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public static function canEdit(array $user, array $pr): bool
    {
        return self::isOwner($user, $pr) && in_array($pr['status'], PrStatus::editable(), true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public static function canCancel(array $user, array $pr): bool
    {
        if (Role::isAdmin((string) $user['role'])) {
            return in_array($pr['status'], self::ADMIN_CANCELLABLE, true);
        }

        return self::isOwner($user, $pr) && in_array($pr['status'], self::OWNER_CANCELLABLE, true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public static function canComplete(array $user, array $pr): bool
    {
        return $pr['status'] === PrStatus::Approved->value
            && (self::isOwner($user, $pr) || Role::isAdmin((string) $user['role']));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public function canDownloadPdf(array $user, array $pr): bool
    {
        return in_array($pr['status'], [PrStatus::Approved->value, PrStatus::Completed->value], true)
            && $this->canView($user, $pr);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public function canApprove(array $user, array $pr): bool
    {
        return $this->approvals->canAct($user, $pr);
    }
}
