<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\PrStatus;
use App\Repositories\ApprovalLogRepository;
use App\Repositories\PrRepository;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\PdfService;
use App\Services\PrPolicy;
use App\Services\PrService;

/**
 * JSON API untuk PR. Memakai session login yang sama; request yang mengubah data
 * wajib mengirim header X-CSRF-Token. Aturan otorisasi identik dengan halaman web.
 */
final class PrApiController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $status = $request->queryString('status');
        $filters = [
            'q' => mb_substr($request->queryString('q'), 0, 100),
            'status' => ($status === 'pending' || PrStatus::tryFrom($status) !== null) ? $status : '',
        ];
        $page = $this->page($request);
        $result = (new PrRepository())->paginate($user, $filters, $page, 20);

        return Response::json([
            'data' => array_map([self::class, 'summary'], $result['rows']),
            'meta' => ['page' => $page, 'per_page' => 20, 'total' => $result['total']],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->user();
        $pr = $this->visible($user, $id);

        return Response::json(['data' => $this->detail($pr)]);
    }

    public function store(Request $request): Response
    {
        $user = $this->user();
        $input = $request->all();
        $service = new PrService();

        $id = Database::transaction(static function () use ($service, $user, $input): int {
            $id = $service->create($user, $input);
            if (($input['submit'] ?? false) === true) {
                $service->submit($user, $id);
            }

            return $id;
        });

        return Response::json(['data' => $this->detail((new PrRepository())->find($id) ?? [])], 201);
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->user();
        (new PrService())->update($user, $id, $request->all());

        return Response::json(['data' => $this->detail($this->visible($user, $id))]);
    }

    public function submit(Request $request, int $id): Response
    {
        $pr = (new PrService())->submit($this->user(), $id);

        return Response::json(['data' => $this->detail($pr)]);
    }

    public function approve(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->approve($this->user(), $id, $request->string('comment'));

        return Response::json(['data' => $this->detail($pr)]);
    }

    public function reject(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->reject($this->user(), $id, $request->string('comment'));

        return Response::json(['data' => $this->detail($pr)]);
    }

    public function revision(Request $request, int $id): Response
    {
        $pr = (new ApprovalService())->requestRevision($this->user(), $id, $request->string('comment'));

        return Response::json(['data' => $this->detail($pr)]);
    }

    public function pdf(Request $request, int $id): Response
    {
        $user = $this->user();
        $pr = $this->visible($user, $id);
        if (!(new PrPolicy())->canDownloadPdf($user, $pr)) {
            throw new HttpException(422, 'PDF hanya tersedia untuk PR yang sudah disetujui.');
        }
        $pdf = (new PdfService())->render($id);
        (new AuditService())->log((int) $user['id'], 'pr.pdf_generate', 'purchase_requisition', $id, null, ['pr_number' => $pr['pr_number'], 'via' => 'api']);

        return Response::download($pdf, PdfService::filename($pr), 'application/pdf');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function visible(array $user, int $id): array
    {
        $pr = (new PrRepository())->find($id);
        if ($pr === null) {
            throw new HttpException(404);
        }
        if (!(new PrPolicy())->canView($user, $pr)) {
            throw new HttpException(403);
        }

        return $pr;
    }

    /**
     * @param array<string, mixed> $pr
     * @return array<string, mixed>
     */
    public static function summary(array $pr): array
    {
        return [
            'id' => (int) $pr['id'],
            'pr_number' => $pr['pr_number'],
            'status' => $pr['status'],
            'status_label' => status_label((string) $pr['status']),
            'pr_date' => $pr['pr_date'],
            'department' => $pr['department_name'],
            'supplier' => $pr['supplier_name'],
            'requester' => $pr['requester_name'],
            'current_step' => $pr['current_step_label'],
            'subtotal' => $pr['subtotal'],
            'tax_rate' => $pr['tax_rate'],
            'tax_amount' => $pr['tax_amount'],
            'grand_total' => $pr['grand_total'],
            'submitted_at' => $pr['submitted_at'],
            'updated_at' => $pr['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $pr
     * @return array<string, mixed>
     */
    private function detail(array $pr): array
    {
        $id = (int) $pr['id'];

        return self::summary($pr) + [
            'department_id' => (int) $pr['department_id'],
            'supplier_id' => $pr['supplier_id'] !== null ? (int) $pr['supplier_id'] : null,
            'notes' => $pr['notes'],
            'submission_round' => (int) $pr['submission_round'],
            'items' => array_map(static fn (array $item): array => [
                'line_no' => (int) $item['line_no'],
                'item_id' => $item['item_id'] !== null ? (int) $item['item_id'] : null,
                'name' => $item['item_name_snapshot'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ], (new PrRepository())->items($id)),
            'approval_history' => array_map(static fn (array $log): array => [
                'round' => (int) $log['submission_round'],
                'step' => $log['step_label'],
                'approver' => $log['approver_name'],
                'action' => $log['action'],
                'comment' => $log['comment'],
                'acted_at' => $log['acted_at'],
            ], (new ApprovalLogRepository())->forPr($id)),
        ];
    }
}
