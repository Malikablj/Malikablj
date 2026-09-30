<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\PrRepository;
use App\Services\ApprovalService;

final class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $prs = new PrRepository();

        return $this->view('approvals/index', [
            'title' => 'Approval',
            'tab' => ($request->query['tab'] ?? '') === 'history' ? 'history' : 'pending',
            'pending' => $prs->pendingApprovals($user),
            'history' => $prs->decisionsBy((int) $user['id']),
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->approve($this->user(), $id, $request->string('comment'));
        $message = $pr['status'] === 'approved'
            ? "PR {$pr['pr_number']} disetujui sepenuhnya."
            : "PR {$pr['pr_number']} disetujui dan diteruskan ke tahap {$pr['current_step_label']}.";

        return $this->redirect($this->next($request, $id), $message);
    }

    public function reject(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->reject($this->user(), $id, $request->string('comment'));

        return $this->redirect($this->next($request, $id), "PR {$pr['pr_number']} ditolak.");
    }

    public function revision(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->requestRevision($this->user(), $id, $request->string('comment'));

        return $this->redirect($this->next($request, $id), "PR {$pr['pr_number']} dikembalikan ke pemohon untuk revisi.");
    }

    private function next(Request $request, int $id): string
    {
        return ($request->post['return'] ?? '') === 'queue' ? '/approvals' : '/pr/' . $id;
    }
}
