<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BusinessRuleException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\PrStatus;
use App\Repositories\ApprovalLogRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\PrRepository;
use App\Repositories\SettingRepository;
use App\Repositories\WorkflowRepository;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Services\PdfService;
use App\Services\PrNumberGenerator;
use App\Services\PrPolicy;
use App\Services\PrService;
use App\Support\Timeline;

final class PrController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $filters = [
            'q' => mb_substr($request->queryString('q'), 0, 100),
            'status' => $request->queryString('status'),
            'department_id' => (string) (int) $request->queryString('department_id'),
            'date_from' => $request->queryString('date_from'),
            'date_to' => $request->queryString('date_to'),
        ];
        if ($filters['status'] !== 'pending' && PrStatus::tryFrom($filters['status']) === null) {
            $filters['status'] = '';
        }
        if ($filters['department_id'] === '0') {
            $filters['department_id'] = '';
        }
        foreach (['date_from', 'date_to'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                $filters[$key] = '';
            }
        }

        $page = $this->page($request);
        $result = (new PrRepository())->paginate($user, $filters, $page, 15);

        return $this->view('pr/index', [
            'title' => 'Purchase Requisition',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 15,
            'filters' => $filters,
            'departments' => MasterDataRepository::departments()->all(),
            'canCreate' => PrPolicy::canCreate($user),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();
        $this->authorize(PrPolicy::canCreate($user), 'Hanya Requester yang dapat membuat PR.');

        $pr = [
            'id' => null,
            'pr_number' => null,
            'status' => PrStatus::Draft->value,
            'department_id' => $user['department_id'],
            'pr_date' => date('Y-m-d'),
            'supplier_id' => null,
            'tax_rate' => (new SettingRepository())->get('default_tax_rate'),
            'notes' => '',
            'requester_name' => $user['name'],
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'grand_total' => '0.00',
        ];

        return $this->form($pr, [], []);
    }

    public function store(Request $request): Response
    {
        $id = (new PrService())->create($this->user(), $request->all(), $request->files('attachments'));

        return $this->afterSave($request, $id);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user();
        $pr = $this->findOr404((new PrRepository())->find($id));
        $policy = new PrPolicy();
        $this->authorize($policy->canView($user, $pr));

        $approvals = new ApprovalService();
        $logs = (new ApprovalLogRepository())->forPr($id);
        $rounds = [];
        foreach ($logs as $log) {
            $rounds[(int) $log['submission_round']][] = $log;
        }

        $noApprover = false;
        if (in_array($pr['status'], PrStatus::pending(), true) && $pr['current_step_id'] !== null) {
            $step = (new WorkflowRepository())->step((int) $pr['current_step_id']);
            $noApprover = $step !== null && $approvals->eligibleApprovers($pr, $step) === [];
        }

        return $this->view('pr/show', [
            'title' => pr_label($pr),
            'pr' => $pr,
            'items' => (new PrRepository())->items($id),
            'attachments' => (new AttachmentRepository())->forPr($id),
            'rounds' => $rounds,
            'progress' => $approvals->progress($pr),
            'timeline' => Timeline::fromAudit((new AuditLogRepository())->forEntity('purchase_requisition', $id)),
            'reviewMode' => ($request->query['review'] ?? '') === '1' && PrPolicy::canEdit($user, $pr),
            'noApprover' => $noApprover,
            'can' => [
                'edit' => PrPolicy::canEdit($user, $pr),
                'submit' => PrPolicy::canEdit($user, $pr),
                'cancel' => PrPolicy::canCancel($user, $pr),
                'complete' => PrPolicy::canComplete($user, $pr),
                'approve' => $policy->canApprove($user, $pr),
                'pdf' => $policy->canDownloadPdf($user, $pr),
                'attach' => PrPolicy::canEdit($user, $pr),
            ],
            'upload' => [
                'extensions' => AttachmentService::allowedExtensions(),
                'maxMb' => (int) config('app.upload.max_mb', 5),
            ],
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $user = $this->user();
        $pr = $this->findOr404((new PrRepository())->find($id));
        if (!PrPolicy::canEdit($user, $pr)) {
            $this->authorize(PrPolicy::isOwner($user, $pr), 'Hanya pemohon yang dapat mengubah PR ini.');
            Session::flash('error', 'PR dengan status ' . status_label((string) $pr['status']) . ' tidak dapat diubah.');

            return Response::redirect('/pr/' . $id);
        }

        return $this->form($pr, (new PrRepository())->items($id), (new AttachmentRepository())->forPr($id));
    }

    public function update(Request $request, int $id): Response
    {
        (new PrService())->update($this->user(), $id, $request->all(), $request->files('attachments'));

        return $this->afterSave($request, $id);
    }

    public function submit(Request $request, int $id): Response
    {
        $pr = (new PrService())->submit($this->user(), $id);

        return $this->redirect('/pr/' . $id, 'PR ' . $pr['pr_number'] . ' berhasil diajukan dan menunggu tahap ' . $pr['current_step_label'] . '.');
    }

    public function cancel(Request $request, int $id): Response
    {
        (new PrService())->cancel($this->user(), $id, $request->string('reason'));

        return $this->redirect('/pr/' . $id, 'PR telah dibatalkan.');
    }

    public function complete(Request $request, int $id): Response
    {
        (new PrService())->complete($this->user(), $id);

        return $this->redirect('/pr/' . $id, 'PR ditandai selesai dan diarsipkan.');
    }

    public function pdf(Request $request, int $id): Response
    {
        $user = $this->user();
        $pr = $this->findOr404((new PrRepository())->find($id));
        $policy = new PrPolicy();
        $this->authorize($policy->canView($user, $pr));
        if (!$policy->canDownloadPdf($user, $pr)) {
            throw new BusinessRuleException('PDF hanya tersedia untuk PR yang sudah disetujui.');
        }

        $pdf = (new PdfService())->render($id);
        (new AuditService())->log((int) $user['id'], 'pr.pdf_generate', 'purchase_requisition', $id, null, ['pr_number' => $pr['pr_number']]);

        return Response::download($pdf, PdfService::filename($pr), 'application/pdf', ($request->query['download'] ?? '') !== '1');
    }

    public function uploadAttachments(Request $request, int $id): Response
    {
        $count = (new PrService())->addAttachments($this->user(), $id, $request->files('attachments'));

        return $this->redirect('/pr/' . $id, $count . ' lampiran berhasil diunggah.');
    }

    /**
     * @param array<string, mixed> $pr
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $attachments
     */
    private function form(array $pr, array $items, array $attachments): Response
    {
        $departments = MasterDataRepository::departments()->allActive();
        $suppliers = MasterDataRepository::suppliers()->allActive();
        if ($pr['supplier_id'] !== null && !in_array((int) $pr['supplier_id'], array_map('intval', array_column($suppliers, 'id')), true)) {
            $current = MasterDataRepository::suppliers()->find((int) $pr['supplier_id']);
            if ($current !== null) {
                $suppliers[] = $current;
            }
        }

        $departmentCode = '';
        foreach (MasterDataRepository::departments()->all() as $department) {
            if ((int) $department['id'] === (int) $pr['department_id']) {
                $departmentCode = (string) $department['code'];
            }
        }

        $revisionNote = null;
        if ($pr['status'] === PrStatus::RevisionRequired->value) {
            foreach ((new ApprovalLogRepository())->forRound((int) $pr['id'], (int) $pr['submission_round']) as $log) {
                if ($log['action'] === ApprovalService::ACTION_REVISION) {
                    $revisionNote = $log;
                }
            }
        }

        return $this->view('pr/form', [
            'title' => $pr['id'] === null ? 'Buat PR' : 'Ubah ' . pr_label($pr),
            'revisionNote' => $revisionNote,
            'departmentCodes' => array_column(MasterDataRepository::departments()->all(), 'code', 'id'),
            'settings' => (new SettingRepository())->all(),
            'pr' => $pr,
            'items' => $items,
            'attachments' => $attachments,
            'departments' => $departments,
            'suppliers' => $suppliers,
            'masterItems' => MasterDataRepository::items()->allActive(),
            'numberPreview' => (new PrNumberGenerator())->preview($departmentCode !== '' ? $departmentCode : 'XX', (string) $pr['pr_date']),
            'locked' => $pr['pr_number'] !== null,
            'upload' => [
                'extensions' => AttachmentService::allowedExtensions(),
                'maxMb' => (int) config('app.upload.max_mb', 5),
            ],
        ]);
    }

    private function afterSave(Request $request, int $id): Response
    {
        if ($request->string('action') === 'review') {
            return $this->redirect('/pr/' . $id . '?review=1', 'Draft tersimpan. Periksa kembali data PR sebelum submit.');
        }

        return $this->redirect('/pr/' . $id . '/edit', 'Draft PR berhasil disimpan.');
    }
}
