<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\PrStatus;
use App\Repositories\ApprovalLogRepository;
use App\Repositories\PrRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkflowRepository;
use App\Support\Decimal;

/**
 * Mesin approval bertingkat berbasis konfigurasi workflow.
 */
final class ApprovalService
{
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_REVISION = 'revision_required';

    private PrRepository $prs;
    private WorkflowRepository $workflows;
    private ApprovalLogRepository $logs;
    private UserRepository $users;
    private NotificationService $notifications;
    private AuditService $audit;

    public function __construct()
    {
        $this->prs = new PrRepository();
        $this->workflows = new WorkflowRepository();
        $this->logs = new ApprovalLogRepository();
        $this->users = new UserRepository();
        $this->notifications = new NotificationService();
        $this->audit = new AuditService();
    }

    /**
     * Workflow aktif untuk department beserta tahap yang berlaku untuk nilai PR tertentu.
     *
     * @return array{workflow: array<string, mixed>, steps: list<array<string, mixed>>}|null
     */
    public function resolveWorkflow(int $departmentId, string $grandTotal): ?array
    {
        $workflow = $this->workflows->activeForDepartment($departmentId);
        if ($workflow === null) {
            return null;
        }

        return [
            'workflow' => $workflow,
            'steps' => self::applicableSteps($this->workflows->steps((int) $workflow['id']), $grandTotal),
        ];
    }

    /**
     * Tahap wajib selalu berlaku; tahap kondisional hanya bila grand total >= min_amount.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    public static function applicableSteps(array $steps, string $grandTotal): array
    {
        return array_values(array_filter($steps, static function (array $step) use ($grandTotal): bool {
            if ((bool) $step['is_required']) {
                return true;
            }

            return $step['min_amount'] !== null && Decimal::compare($grandTotal, (string) $step['min_amount']) >= 0;
        }));
    }

    /**
     * User yang berhak memutuskan tahap ini untuk PR tersebut pada putaran pengajuan berjalan.
     * Pemohon dan user yang sudah memberi keputusan di putaran yang sama dikecualikan.
     *
     * @param array<string, mixed> $pr
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    public function eligibleApprovers(array $pr, array $step): array
    {
        $exclude = array_merge(
            [(int) $pr['requester_id']],
            $this->logs->approverIdsInRound((int) $pr['id'], (int) $pr['submission_round']),
        );

        if ($step['approver_type'] === 'user') {
            $user = $this->users->find((int) $step['approver_user_id']);
            if ($user === null || !(bool) $user['is_active'] || in_array((int) $user['id'], $exclude, true)) {
                return [];
            }

            return [$user];
        }

        return $this->users->activeByRole(
            (string) $step['approver_role'],
            (bool) $step['same_department'] ? (int) $pr['department_id'] : null,
            $exclude,
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     */
    public function canAct(array $user, array $pr): bool
    {
        if (!in_array($pr['status'], PrStatus::pending(), true) || empty($pr['current_step_id'])) {
            return false;
        }
        $step = $this->workflows->step((int) $pr['current_step_id']);

        return $step !== null && $this->isEligible($user, $pr, $step);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function approve(array $actor, int $prId, string $comment = ''): array
    {
        return $this->decide($actor, $prId, self::ACTION_APPROVED, $comment);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function reject(array $actor, int $prId, string $comment): array
    {
        return $this->decide($actor, $prId, self::ACTION_REJECTED, $comment);
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function requestRevision(array $actor, int $prId, string $comment): array
    {
        return $this->decide($actor, $prId, self::ACTION_REVISION, $comment);
    }

    /**
     * Status tiap tahap untuk ditampilkan di halaman detail PR.
     *
     * @param array<string, mixed> $pr
     * @return list<array<string, mixed>>
     */
    public function progress(array $pr): array
    {
        if (empty($pr['workflow_id'])) {
            $resolved = $this->resolveWorkflow((int) $pr['department_id'], (string) $pr['grand_total']);
            $preview = [...$pr, 'submission_round' => (int) $pr['submission_round'] + 1];

            return array_map(fn (array $step): array => [
                'order' => (int) $step['step_order'],
                'label' => (string) $step['label'],
                'state' => 'upcoming',
                'candidates' => array_column($this->eligibleApprovers($preview, $step), 'name'),
            ], $resolved['steps'] ?? []);
        }

        $steps = self::applicableSteps($this->workflows->steps((int) $pr['workflow_id']), (string) $pr['grand_total']);
        $logs = [];
        foreach ($this->logs->forRound((int) $pr['id'], (int) $pr['submission_round']) as $log) {
            $logs[(int) $log['step_order']] = $log;
        }

        $result = [];
        foreach ($steps as $step) {
            $order = (int) $step['step_order'];
            $entry = ['order' => $order, 'label' => (string) $step['label'], 'state' => 'upcoming', 'candidates' => []];
            if (isset($logs[$order])) {
                $log = $logs[$order];
                $entry = array_merge($entry, [
                    'label' => (string) $log['step_label'],
                    'state' => (string) $log['action'],
                    'approver' => (string) $log['approver_name'],
                    'acted_at' => (string) $log['acted_at'],
                    'comment' => $log['comment'],
                ]);
            } elseif ((int) $pr['current_step_id'] === (int) $step['id'] && in_array($pr['status'], PrStatus::pending(), true)) {
                $entry['state'] = 'current';
                $entry['candidates'] = array_column($this->eligibleApprovers($pr, $step), 'name');
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private function decide(array $actor, int $prId, string $action, string $comment): array
    {
        $comment = trim($comment);

        return Database::transaction(function () use ($actor, $prId, $action, $comment): array {
            $pr = $this->prs->lockForUpdate($prId);
            if ($pr === null) {
                throw new HttpException(404);
            }
            if (!in_array($pr['status'], PrStatus::pending(), true) || empty($pr['current_step_id'])) {
                throw new BusinessRuleException('PR ini tidak sedang menunggu approval.');
            }
            $step = $this->workflows->step((int) $pr['current_step_id']);
            if ($step === null || !$this->isEligible($actor, $pr, $step)) {
                throw new HttpException(403, 'Anda bukan approver untuk tahap approval PR ini.');
            }

            if ($action !== self::ACTION_APPROVED && $comment === '') {
                $what = $action === self::ACTION_REJECTED ? 'penolakan' : 'permintaan revisi';
                throw new ValidationException(['comment' => "Alasan {$what} wajib diisi."], "Alasan {$what} wajib diisi.");
            }
            if (mb_strlen($comment) > 2000) {
                throw new ValidationException(['comment' => 'Komentar maksimal 2000 karakter.']);
            }

            $next = null;
            if ($action === self::ACTION_APPROVED) {
                $steps = self::applicableSteps($this->workflows->steps((int) $pr['workflow_id']), (string) $pr['grand_total']);
                foreach ($steps as $candidate) {
                    if ((int) $candidate['step_order'] > (int) $step['step_order']) {
                        $next = $candidate;
                        break;
                    }
                }
                $newStatus = $next !== null ? PrStatus::InReview->value : PrStatus::Approved->value;
            } else {
                $newStatus = $action === self::ACTION_REJECTED ? PrStatus::Rejected->value : PrStatus::RevisionRequired->value;
            }

            $this->logs->create([
                'pr_id' => $prId,
                'step_id' => (int) $step['id'],
                'submission_round' => (int) $pr['submission_round'],
                'step_order' => (int) $step['step_order'],
                'step_label' => (string) $step['label'],
                'approver_id' => (int) $actor['id'],
                'action' => $action,
                'comment' => $comment !== '' ? $comment : null,
                'status_after' => $newStatus,
            ]);

            $update = ['status' => $newStatus, 'current_step_id' => $next !== null ? (int) $next['id'] : null];
            if ($newStatus === PrStatus::Approved->value) {
                $update['approved_at'] = date('Y-m-d H:i:s');
            }
            $this->prs->update($prId, $update);

            $auditAction = match ($action) {
                self::ACTION_APPROVED => 'pr.approve',
                self::ACTION_REJECTED => 'pr.reject',
                default => 'pr.request_revision',
            };
            $this->audit->log((int) $actor['id'], $auditAction, 'purchase_requisition', $prId, [
                'status' => $pr['status'],
                'step' => $step['label'],
            ], [
                'status' => $newStatus,
                'step' => $step['label'],
                'next_step' => $next['label'] ?? null,
                'comment' => $comment !== '' ? $comment : null,
                'round' => (int) $pr['submission_round'],
            ]);

            $updated = $this->prs->find($prId) ?? $pr;
            $this->notifyDecision($actor, $updated, $step, $next, $action, $comment);

            return $updated;
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $pr
     * @param array<string, mixed> $step
     */
    private function isEligible(array $user, array $pr, array $step): bool
    {
        foreach ($this->eligibleApprovers($pr, $step) as $candidate) {
            if ((int) $candidate['id'] === (int) $user['id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $pr
     * @param array<string, mixed> $step
     * @param array<string, mixed>|null $next
     */
    private function notifyDecision(array $actor, array $pr, array $step, ?array $next, string $action, string $comment): void
    {
        $number = (string) $pr['pr_number'];
        $requesterId = (int) $pr['requester_id'];
        $prId = (int) $pr['id'];
        $by = (string) $actor['name'];

        if ($action === self::ACTION_APPROVED && $next !== null) {
            $this->notifications->notify(
                $requesterId,
                'pr_step_approved',
                "PR {$step['label']}",
                "{$number} telah melewati tahap {$step['label']} oleh {$by}. Menunggu tahap {$next['label']}.",
                $prId,
            );
            $this->notifyApprovers($pr, $next);

            return;
        }

        [$type, $title, $message] = match ($action) {
            self::ACTION_APPROVED => ['pr_approved', 'PR disetujui', "{$number} telah disetujui sepenuhnya. Dokumen PDF sudah dapat diunduh."],
            self::ACTION_REJECTED => ['pr_rejected', 'PR ditolak', "{$number} ditolak oleh {$by} pada tahap {$step['label']}. Alasan: {$comment}"],
            default => ['pr_revision', 'PR perlu revisi', "{$number} dikembalikan oleh {$by} untuk diperbaiki. Catatan: {$comment}"],
        };
        $this->notifications->notify($requesterId, $type, $title, $message, $prId);
    }

    /**
     * Memberi tahu approver yang berhak pada tahap tertentu.
     *
     * @param array<string, mixed> $pr
     * @param array<string, mixed> $step
     */
    public function notifyApprovers(array $pr, array $step): void
    {
        $approvers = $this->eligibleApprovers($pr, $step);
        $this->notifications->notifyMany(
            array_map(static fn (array $u): int => (int) $u['id'], $approvers),
            'approval_required',
            'PR menunggu persetujuan Anda',
            sprintf(
                '%s dari %s (%s) senilai %s menunggu tahap %s.',
                $pr['pr_number'],
                $pr['requester_name'] ?? 'pemohon',
                $pr['department_name'] ?? '-',
                money((string) $pr['grand_total']),
                $step['label'],
            ),
            (int) $pr['id'],
        );
    }
}
