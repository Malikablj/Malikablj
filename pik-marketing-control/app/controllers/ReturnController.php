<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\PoLine;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use DomainException;

final class ReturnController extends Controller
{
    private const FIELDS = ['po_line_id', 'delivery_id', 'return_date', 'sj_number', 'destination', 'return_qty', 'reason', 'attachment', 'note'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'reason'      => Request::queryString('reason'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'        => Request::queryString('link'),
        ];
        $this->view('returns/index', [
            'title'     => 'Returns',
            'returns'   => ProductReturn::paginate($filters, $this->page()),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $lineId = Request::queryInt('po_line_id') ?: null;
        $poId = Request::queryInt('po_id') ?: null;
        if ($lineId) {
            $poId = (int) (PoLine::find($lineId)['po_id'] ?? 0) ?: null;
        }
        $preset = ['po_line_id' => $lineId, 'delivery_id' => Request::queryInt('delivery_id') ?: null, 'return_date' => today()];
        $this->view('returns/form', $this->formData(null, $poId, $lineId) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate(false);
        if ($v->fails()) {
            $lineId = (int) ($_POST['po_line_id'] ?? 0) ?: null;
            $this->invalid('returns/form', $this->formData(null, null, $lineId) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        try {
            $id = ProductReturn::saveReturn(null, $v->validated());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/returns/create');
        }
        $po = ProductReturn::find($id)['po_id'] ?? null;
        $this->success('Retur tersimpan. Outstanding PO bertambah sesuai qty retur.', $this->returnTo($po ? '/purchase-orders/' . $po : '/returns'));
    }

    public function edit(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $this->view('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $v = $this->validate($r['po_line_id'] === null);
        if ($v->fails()) {
            $this->invalid('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        try {
            ProductReturn::saveReturn($id, $v->validated());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/returns/' . $id . '/edit');
        }
        $this->success('Retur diperbarui.', $this->returnTo($r['po_id'] ? '/purchase-orders/' . $r['po_id'] : '/returns'));
    }

    public function destroy(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        ProductReturn::remove($id);
        $this->success('Retur dihapus.', $r['po_id'] ? '/purchase-orders/' . $r['po_id'] : '/returns');
    }

    private function validate(bool $legacyUnlinked): Validator
    {
        return Validator::make($_POST, [
            'po_line_id'  => ($legacyUnlinked ? 'nullable' : 'required') . '|integer|exists:po_lines,id',
            'delivery_id' => 'nullable|integer|exists:deliveries,id',
            'return_date' => 'required|date',
            'sj_number'   => 'nullable|string|max:60',
            'destination' => 'nullable|string|max:255',
            'return_qty'  => 'required|integer|min:1',
            'reason'      => ['required', ['in', ProductReturn::REASONS]],
            'attachment'  => 'nullable|url|max:500',
            'note'        => 'nullable|string|max:2000',
        ], [
            'po_line_id' => 'Baris PO', 'delivery_id' => 'Surat jalan asal', 'return_date' => 'Tanggal retur', 'sj_number' => 'Nomor dokumen retur',
            'destination' => 'Asal/tujuan', 'return_qty' => 'Qty retur', 'reason' => 'Alasan', 'attachment' => 'Link lampiran', 'note' => 'Catatan',
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
    private function formData(?array $return, ?int $poId, ?int $lineId): array
    {
        $reasons = [];
        foreach (ProductReturn::REASONS as $r) {
            $reasons[$r] = $r . ' — ' . ProductReturn::REASON_LABELS[$r];
        }
        return [
            'title'       => $return ? 'Edit Retur' : 'Catat Retur',
            'ret'         => $return,
            'lineOptions' => PurchaseOrder::lineOptions(false, $lineId, $poId),
            'deliveries'  => ProductReturn::deliveryOptions($lineId),
            'reasons'     => $reasons,
            'return'      => $this->returnTo(''),
        ];
    }
}
