<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\LeadTime;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use DomainException;

final class LeadTimeController extends Controller
{
    private const FIELDS = ['po_line_id', 'quantity', 'delivery_date', 'status', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'        => Request::queryString('link'),
        ];
        $this->view('lead_times/index', [
            'title'     => 'Lead Time',
            'rows'      => LeadTime::paginate($filters, today(), $this->page()),
            'summary'   => LeadTime::summary($filters, today()),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $lineId = Request::queryInt('po_line_id') ?: null;
        $poId = Request::queryInt('po_id') ?: null;
        $qty = null;
        if ($lineId !== null && ($totals = PoLine::totals($lineId)) !== null) {
            $qty = max(0, $totals['outstanding_qty']) ?: null;
            $poId = (int) (PoLine::find($lineId)['po_id'] ?? 0) ?: null;
        }
        $preset = ['po_line_id' => $lineId, 'quantity' => $qty, 'status' => 'Planned'];
        $this->view('lead_times/form', $this->formData(null, $lineId, $poId) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate(false);
        if ($v->fails()) {
            $lineId = (int) ($_POST['po_line_id'] ?? 0) ?: null;
            $this->invalid('lead_times/form', $this->formData(null, $lineId, null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = LeadTime::saveLeadTime(null, $v->validated());
        $po = LeadTime::find($id)['po_id'] ?? null;
        $this->success('Estimasi lead time tersimpan.', $this->returnTo($po && Auth::can('purchase_orders.view') ? '/purchase-orders/' . $po : '/lead-times'));
    }

    public function edit(int $id): void
    {
        $row = $this->found(LeadTime::findFull($id));
        $lineId = LeadTime::matchingLineId($row['po_id'] !== null ? (int) $row['po_id'] : null, $row['product_id'] !== null ? (int) $row['product_id'] : null);
        $this->view('lead_times/form', $this->formData($row, $lineId, $row['po_id'] !== null ? (int) $row['po_id'] : null)
            + ['errors' => [], 'preset' => ['po_line_id' => $lineId]]);
    }

    public function update(int $id): void
    {
        $row = $this->found(LeadTime::findFull($id));
        $lineId = LeadTime::matchingLineId($row['po_id'] !== null ? (int) $row['po_id'] : null, $row['product_id'] !== null ? (int) $row['product_id'] : null);
        // Data legacy yang belum punya PO line boleh disimpan tanpa memilih baris PO
        $v = $this->validate($lineId === null);
        if ($v->fails()) {
            $this->invalid('lead_times/form', $this->formData($row, $lineId, $row['po_id'] !== null ? (int) $row['po_id'] : null) + ['preset' => ['po_line_id' => $lineId]], $v->errors(), $this->old());
            return;
        }
        $data = $v->validated();
        try {
            LeadTime::saveLeadTime($id, $data);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/lead-times/' . $id . '/edit');
        }
        $this->success('Lead time diperbarui.', $this->returnTo('/lead-times'));
    }

    public function destroy(int $id): void
    {
        $this->found(LeadTime::find($id));
        try {
            LeadTime::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/lead-times/' . $id . '/edit');
        }
        $this->success('Lead time dihapus.', $this->returnTo('/lead-times'));
    }

    private function validate(bool $lineOptional): Validator
    {
        return Validator::make($_POST, [
            'po_line_id'    => ($lineOptional ? 'nullable' : 'required') . '|integer|exists:po_lines,id',
            'quantity'      => 'nullable|integer|min:1',
            'delivery_date' => 'required|date',
            'status'        => ['required', ['in', LeadTime::STATUSES]],
            'notes'         => 'nullable|string|max:2000',
        ], [
            'po_line_id' => 'Produk OEF', 'quantity' => 'Qty', 'delivery_date' => 'Estimasi tanggal delivery', 'status' => 'Status', 'notes' => 'Catatan',
        ]);
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = $_POST[$f] ?? '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $row, ?int $lineId, ?int $poId): array
    {
        $statuses = [];
        foreach (LeadTime::STATUSES as $s) {
            $statuses[$s] = $s . ' — ' . LeadTime::STATUS_HELP[$s];
        }
        return [
            'title'       => $row ? 'Edit Lead Time' : 'Tambah Lead Time',
            'row'         => $row,
            'lineOptions' => PurchaseOrder::lineOptions(true, $lineId, $poId),
            'statuses'    => $statuses,
            'issues'      => $row && Auth::can('migration.view') ? MigrationIssue::openForRecord('LEADTIME', (int) $row['id']) : [],
            'return'      => $this->returnTo(''),
        ];
    }
}
