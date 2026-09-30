<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BusinessRuleException;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\PrStatus;
use App\Models\Role;
use App\Repositories\AttachmentRepository;
use App\Repositories\MasterDataRepository;
use App\Repositories\PrRepository;
use App\Support\Decimal;
use Throwable;

/**
 * Siklus hidup PR: draft -> submit -> (approval) -> approved -> completed,
 * serta revisi dan pembatalan. Setiap operasi berjalan dalam satu transaksi.
 */
final class PrService
{
    private PrRepository $prs;
    private PrCalculator $calculator;
    private ApprovalService $approvals;
    private AttachmentService $attachments;
    private NotificationService $notifications;
    private AuditService $audit;

    public function __construct()
    {
        $this->prs = new PrRepository();
        $this->calculator = new PrCalculator();
        $this->approvals = new ApprovalService();
        $this->attachments = new AttachmentService();
        $this->notifications = new NotificationService();
        $this->audit = new AuditService();
    }

    /**
     * Membuat PR baru berstatus Draft.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    public function create(array $actor, array $input, array $files = []): int
    {
        if (!PrPolicy::canCreate($actor)) {
            throw new HttpException(403, 'Hanya Requester yang dapat membuat PR.');
        }
        $data = $this->validate($input, null);
        $uploads = $this->attachments->validate($files, 0);

        try {
            return Database::transaction(function () use ($actor, $data, $uploads): int {
                $id = $this->prs->create($data['header'] + [
                    'requester_id' => (int) $actor['id'],
                    'status' => PrStatus::Draft->value,
                ]);
                $this->prs->replaceItems($id, $data['items']);
                $this->attachments->store($uploads, $id, (int) $actor['id']);
                $this->audit->log((int) $actor['id'], 'pr.create', 'purchase_requisition', $id, null, $this->snapshot($id));

                return $id;
            });
        } catch (Throwable $e) {
            $this->attachments->discardMoved();
            throw $e;
        }
    }

    /**
     * Mengubah PR yang masih Draft / Revision Required.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    public function update(array $actor, int $prId, array $input, array $files = []): void
    {
        try {
            Database::transaction(function () use ($actor, $prId, $input, $files): void {
                $pr = $this->lockEditable($actor, $prId);
                $data = $this->validate($input, $pr);
                $uploads = $this->attachments->validate($files, (new AttachmentRepository())->countForPr($prId));

                $before = $this->snapshot($prId);
                $this->prs->update($prId, $data['header']);
                $this->prs->replaceItems($prId, $data['items']);
                $this->attachments->store($uploads, $prId, (int) $actor['id']);

                [$old, $new] = AuditService::diff($before, $this->snapshot($prId));
                $this->audit->log((int) $actor['id'], 'pr.update', 'purchase_requisition', $prId, $old, $new);
            });
        } catch (Throwable $e) {
            $this->attachments->discardMoved();
            throw $e;
        }
    }

    /**
     * Mengajukan PR ke workflow approval. Nomor PR diterbitkan pada submit pertama.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function submit(array $actor, int $prId): array
    {
        return Database::transactionWithRetry(function () use ($actor, $prId): array {
            $pr = $this->prs->lockForUpdate($prId);
            if ($pr === null) {
                throw new HttpException(404);
            }
            if (!PrPolicy::isOwner($actor, $pr)) {
                throw new HttpException(403, 'Hanya pemohon yang dapat mengajukan PR ini.');
            }
            if (!in_array($pr['status'], PrStatus::editable(), true)) {
                throw new BusinessRuleException('Hanya PR berstatus Draft atau Revision Required yang dapat disubmit.');
            }

            $items = $this->prs->items($prId);
            $department = MasterDataRepository::departments()->find((int) $pr['department_id']);
            $supplier = $pr['supplier_id'] !== null ? MasterDataRepository::suppliers()->find((int) $pr['supplier_id']) : null;

            $problems = [];
            if ($department === null || !(bool) $department['is_active']) {
                $problems[] = 'Department tidak aktif.';
            }
            if ($supplier === null) {
                $problems[] = 'Supplier belum dipilih.';
            } elseif (!(bool) $supplier['is_active']) {
                $problems[] = 'Supplier sudah nonaktif, pilih supplier lain.';
            }
            if ($items === []) {
                $problems[] = 'PR belum memiliki item.';
            }

            // Hitung ulang dari data tersimpan: server adalah sumber kebenaran.
            $totals = $this->calculator->calculate($items, (string) $pr['tax_rate']);
            if ($totals === null) {
                $problems[] = 'Total PR melebihi batas maksimum.';
            } elseif (Decimal::compare($totals['grand_total'], '0') <= 0) {
                $problems[] = 'Total PR harus lebih dari 0.';
            }
            if ($problems !== []) {
                throw new BusinessRuleException('PR belum dapat disubmit: ' . implode(' ', $problems));
            }

            $resolved = $this->approvals->resolveWorkflow((int) $pr['department_id'], $totals['grand_total']);
            if ($resolved === null || $resolved['steps'] === []) {
                throw new BusinessRuleException('Belum ada approval workflow aktif untuk department ini. Hubungi admin.');
            }

            $round = (int) $pr['submission_round'] + 1;
            $candidate = [...$pr, 'submission_round' => $round];
            foreach ($resolved['steps'] as $step) {
                if ($this->approvals->eligibleApprovers($candidate, $step) === []) {
                    throw new BusinessRuleException("Tahap approval \"{$step['label']}\" belum memiliki approver yang valid. Hubungi admin.");
                }
            }

            $number = $pr['pr_number'] ?? (new PrNumberGenerator())->next((string) $department['code'], (string) $pr['pr_date']);
            $first = $resolved['steps'][0];

            $this->prs->update($prId, [
                'pr_number' => $number,
                'status' => PrStatus::Submitted->value,
                'workflow_id' => (int) $resolved['workflow']['id'],
                'current_step_id' => (int) $first['id'],
                'submission_round' => $round,
                'submitted_at' => date('Y-m-d H:i:s'),
                'approved_at' => null,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['tax_amount'],
                'grand_total' => $totals['grand_total'],
            ]);
            // Pastikan nilai tiap baris juga sesuai hasil hitung ulang.
            $changed = false;
            foreach ($items as $index => $item) {
                if ((string) $item['line_total'] !== $totals['lines'][$index]) {
                    $items[$index]['line_total'] = $totals['lines'][$index];
                    $changed = true;
                }
            }
            if ($changed) {
                $this->prs->replaceItems($prId, $items);
            }

            $this->audit->log((int) $actor['id'], $round > 1 ? 'pr.resubmit' : 'pr.submit', 'purchase_requisition', $prId, [
                'status' => $pr['status'],
            ], [
                'status' => PrStatus::Submitted->value,
                'pr_number' => $number,
                'round' => $round,
                'workflow' => $resolved['workflow']['name'],
                'grand_total' => $totals['grand_total'],
            ]);

            $submitted = $this->prs->find($prId);
            $this->notifications->notify(
                (int) $pr['requester_id'],
                'pr_submitted',
                $round > 1 ? 'PR diajukan ulang' : 'PR berhasil diajukan',
                "{$number} telah diajukan dan menunggu tahap {$first['label']}.",
                $prId,
            );
            $this->approvals->notifyApprovers($submitted, $first);

            return $submitted;
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function cancel(array $actor, int $prId, string $reason): void
    {
        Database::transaction(function () use ($actor, $prId, $reason): void {
            $pr = $this->prs->lockForUpdate($prId);
            if ($pr === null) {
                throw new HttpException(404);
            }
            if (!PrPolicy::isOwner($actor, $pr) && !Role::isAdmin((string) $actor['role'])) {
                throw new HttpException(403);
            }
            if (!PrPolicy::canCancel($actor, $pr)) {
                throw new BusinessRuleException('PR dengan status ' . status_label((string) $pr['status']) . ' tidak dapat dibatalkan.');
            }
            $reason = trim($reason);
            if ($pr['status'] !== PrStatus::Draft->value && $reason === '') {
                throw new ValidationException(['reason' => 'Alasan pembatalan wajib diisi.'], 'Alasan pembatalan wajib diisi.');
            }
            if (mb_strlen($reason) > 1000) {
                throw new ValidationException(['reason' => 'Alasan maksimal 1000 karakter.']);
            }

            $wasPending = in_array($pr['status'], PrStatus::pending(), true);
            $currentStep = $wasPending ? (new \App\Repositories\WorkflowRepository())->step((int) $pr['current_step_id']) : null;
            $pendingApprovers = $currentStep !== null ? $this->approvals->eligibleApprovers($pr, $currentStep) : [];

            $this->prs->update($prId, [
                'status' => PrStatus::Cancelled->value,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason !== '' ? $reason : null,
                'current_step_id' => null,
            ]);
            $this->audit->log((int) $actor['id'], 'pr.cancel', 'purchase_requisition', $prId, [
                'status' => $pr['status'],
            ], [
                'status' => PrStatus::Cancelled->value,
                'reason' => $reason !== '' ? $reason : null,
            ]);

            $label = pr_label($pr);
            if (!PrPolicy::isOwner($actor, $pr)) {
                $this->notifications->notify((int) $pr['requester_id'], 'pr_cancelled', 'PR dibatalkan', "{$label} dibatalkan oleh {$actor['name']}. Alasan: {$reason}", $prId);
            }
            $this->notifications->notifyMany(
                array_map(static fn (array $u): int => (int) $u['id'], $pendingApprovers),
                'pr_cancelled',
                'PR dibatalkan',
                "{$label} dibatalkan sehingga tidak lagi memerlukan approval Anda.",
                $prId,
            );
        });
    }

    /**
     * Menandai PR approved sebagai selesai (diarsipkan).
     *
     * @param array<string, mixed> $actor
     */
    public function complete(array $actor, int $prId): void
    {
        Database::transaction(function () use ($actor, $prId): void {
            $pr = $this->prs->lockForUpdate($prId);
            if ($pr === null) {
                throw new HttpException(404);
            }
            if (!PrPolicy::isOwner($actor, $pr) && !Role::isAdmin((string) $actor['role'])) {
                throw new HttpException(403);
            }
            if (!PrPolicy::canComplete($actor, $pr)) {
                throw new BusinessRuleException('Hanya PR berstatus Approved yang dapat diselesaikan.');
            }
            $this->prs->update($prId, ['status' => PrStatus::Completed->value, 'completed_at' => date('Y-m-d H:i:s')]);
            $this->audit->log((int) $actor['id'], 'pr.complete', 'purchase_requisition', $prId, ['status' => $pr['status']], ['status' => PrStatus::Completed->value]);
            if (!PrPolicy::isOwner($actor, $pr)) {
                $this->notifications->notify((int) $pr['requester_id'], 'pr_completed', 'PR selesai', "{$pr['pr_number']} telah ditandai selesai dan diarsipkan oleh {$actor['name']}.", $prId);
            }
        });
    }

    /**
     * Upload lampiran tambahan dari halaman detail.
     *
     * @param array<string, mixed> $actor
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    public function addAttachments(array $actor, int $prId, array $files): int
    {
        try {
            return Database::transaction(function () use ($actor, $prId, $files): int {
                $this->lockEditable($actor, $prId);
                $uploads = $this->attachments->validate($files, (new AttachmentRepository())->countForPr($prId));
                if ($uploads === []) {
                    throw new ValidationException(['attachments' => 'Pilih minimal satu file.'], 'Pilih minimal satu file.');
                }

                return count($this->attachments->store($uploads, $prId, (int) $actor['id']));
            });
        } catch (Throwable $e) {
            $this->attachments->discardMoved();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function deleteAttachment(array $actor, int $attachmentId): int
    {
        return Database::transaction(function () use ($actor, $attachmentId): int {
            $attachment = (new AttachmentRepository())->find($attachmentId);
            if ($attachment === null) {
                throw new HttpException(404);
            }
            $this->lockEditable($actor, (int) $attachment['pr_id']);
            $this->attachments->delete((int) $actor['id'], $attachment);

            return (int) $attachment['pr_id'];
        });
    }

    /**
     * Validasi & normalisasi input form PR, termasuk perhitungan ulang total di server.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{header: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function validate(array $input, ?array $existing): array
    {
        $locked = $existing !== null && $existing['pr_number'] !== null;
        $v = new Validator($input);

        if (!$locked) {
            $v->required('department_id', 'Department')
                ->required('pr_date', 'Tanggal')
                ->date('pr_date', 'Tanggal');
            $departmentId = (int) $v->value('department_id');
            if ($departmentId > 0) {
                $department = MasterDataRepository::departments()->find($departmentId);
                if ($department === null || !(bool) $department['is_active']) {
                    $v->add('department_id', 'Department tidak valid atau sudah nonaktif.');
                }
            }
            $prDate = $v->value('pr_date');
            if ($prDate !== '' && !isset($v->errors()['pr_date']) && ($prDate < '2000-01-01' || $prDate > '2099-12-31')) {
                $v->add('pr_date', 'Tanggal di luar rentang yang diizinkan.');
            }
        }

        $supplierId = (int) $v->value('supplier_id');
        if ($supplierId > 0) {
            $supplier = MasterDataRepository::suppliers()->find($supplierId);
            $keepsOld = $existing !== null && (int) $existing['supplier_id'] === $supplierId;
            if ($supplier === null || (!(bool) $supplier['is_active'] && !$keepsOld)) {
                $v->add('supplier_id', 'Supplier tidak valid atau sudah nonaktif.');
            }
        }

        $v->decimal('tax_rate', 'Pajak (%)', '0', '100')->maxLength('notes', 2000, 'Catatan');
        $taxRate = Decimal::parse($v->value('tax_rate') === '' ? '0' : $v->value('tax_rate')) ?? '0.00';

        $items = [];
        $rows = is_array($input['items'] ?? null) ? $input['items'] : [];
        $itemRepo = MasterDataRepository::items();
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $field = static fn (string $name): string => is_scalar($row[$name] ?? null) ? trim((string) $row[$name]) : '';
            $name = $field('name');
            $description = $field('description');
            $quantityRaw = $field('quantity');
            $priceRaw = $field('unit_price');
            if ($name === '' && $description === '' && $quantityRaw === '' && $priceRaw === '') {
                continue; // baris kosong diabaikan
            }

            $key = 'items.' . $index;
            $rowValid = true;
            if ($name === '') {
                $v->add("{$key}.name", 'Nama item wajib diisi.');
                $rowValid = false;
            } elseif (mb_strlen($name) > 150) {
                $v->add("{$key}.name", 'Nama item maksimal 150 karakter.');
                $rowValid = false;
            }
            if (mb_strlen($description) > 500) {
                $v->add("{$key}.description", 'Keterangan maksimal 500 karakter.');
                $rowValid = false;
            }
            $quantity = Decimal::parse($quantityRaw);
            if ($quantity === null || Decimal::compare($quantity, '0') <= 0 || Decimal::compare($quantity, PrCalculator::MAX_QUANTITY) > 0) {
                $v->add("{$key}.quantity", 'Qty harus angka lebih dari 0 (maks. 2 desimal).');
                $rowValid = false;
            }
            $price = Decimal::parse($priceRaw);
            if ($price === null || Decimal::compare($price, PrCalculator::MAX_AMOUNT) > 0) {
                $v->add("{$key}.unit_price", 'Harga satuan harus angka 0 atau lebih (maks. 2 desimal).');
                $rowValid = false;
            }
            $unit = $field('unit') !== '' ? $field('unit') : 'pcs';
            if (mb_strlen($unit) > 20) {
                $v->add("{$key}.unit", 'Satuan maksimal 20 karakter.');
                $rowValid = false;
            }

            $itemId = (int) $field('item_id');
            if ($itemId > 0 && $itemRepo->find($itemId) === null) {
                $itemId = 0;
            }

            if (!$rowValid) {
                continue;
            }
            $lineTotal = $this->calculator->lineTotal($quantity, $price);
            if ($lineTotal === null) {
                $v->add("{$key}.unit_price", 'Jumlah baris melebihi batas maksimum.');
                continue;
            }

            $items[] = [
                'item_id' => $itemId > 0 ? $itemId : null,
                'item_name_snapshot' => $name,
                'description' => $description !== '' ? $description : null,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $price,
                'line_total' => $lineTotal,
            ];
        }

        $maxItems = (int) Config::get('app.pr.max_items', 100);
        if (count($items) > $maxItems) {
            $v->add('items', "Maksimal {$maxItems} item per PR.");
        }

        $totals = $this->calculator->totals(array_column($items, 'line_total'), $taxRate);
        if ($totals === null) {
            $v->add('items', 'Total PR melebihi batas maksimum.');
        }
        $v->throwIfFailed();

        $notes = $v->value('notes');
        $header = [
            'supplier_id' => $supplierId > 0 ? $supplierId : null,
            'tax_rate' => $taxRate,
            'notes' => $notes !== '' ? $notes : null,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax_amount'],
            'grand_total' => $totals['grand_total'],
        ];
        if (!$locked) {
            $header['department_id'] = (int) $v->value('department_id');
            $header['pr_date'] = $v->value('pr_date');
        }

        return ['header' => $header, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private function lockEditable(array $actor, int $prId): array
    {
        $pr = $this->prs->lockForUpdate($prId);
        if ($pr === null) {
            throw new HttpException(404);
        }
        if (!PrPolicy::isOwner($actor, $pr)) {
            throw new HttpException(403, 'Hanya pemohon yang dapat mengubah PR ini.');
        }
        if (!in_array($pr['status'], PrStatus::editable(), true)) {
            throw new BusinessRuleException('PR dengan status ' . status_label((string) $pr['status']) . ' tidak dapat diubah.');
        }

        return $pr;
    }

    /**
     * Ringkasan isi PR untuk audit log.
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $prId): array
    {
        $pr = $this->prs->find($prId) ?? [];

        return [
            'department_id' => $pr['department_id'] ?? null,
            'supplier_id' => $pr['supplier_id'] ?? null,
            'pr_date' => $pr['pr_date'] ?? null,
            'tax_rate' => $pr['tax_rate'] ?? null,
            'subtotal' => $pr['subtotal'] ?? null,
            'tax_amount' => $pr['tax_amount'] ?? null,
            'grand_total' => $pr['grand_total'] ?? null,
            'notes' => $pr['notes'] ?? null,
            'items' => array_map(static fn (array $item): array => [
                'name' => $item['item_name_snapshot'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ], $this->prs->items($prId)),
        ];
    }
}
