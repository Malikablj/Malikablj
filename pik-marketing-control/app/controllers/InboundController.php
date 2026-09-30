<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\InboundMaklon;
use App\Models\MigrationIssue;
use App\Models\Product;
use DomainException;

final class InboundController extends Controller
{
    private const FIELDS = [
        'vendor', 'receiver', 'actual_inbound_date', 'sj_date', 'sj_number', 'po_id', 'product_id', 'internal_component_code',
        'type', 'component_name', 'factory_component_code', 'quantity', 'reject_qty', 'attachment', 'odoo_checklist', 'notes',
    ];

    public function index(): void
    {
        $filters = [
            'q'        => Request::queryString('q'),
            'vendor'   => Request::queryString('vendor'),
            'receiver' => Request::queryString('receiver'),
            'from'     => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'       => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'     => Request::queryString('link'),
        ];
        $this->view('inbound/index', [
            'title'     => 'Inbound Maklon',
            'rows'      => InboundMaklon::paginate($filters, $this->page()),
            'summary'   => InboundMaklon::summary($filters),
            'filters'   => $filters,
            'vendors'   => InboundMaklon::distinct('vendor'),
            'receivers' => InboundMaklon::distinct('receiver'),
        ]);
    }

    public function show(int $id): void
    {
        $row = $this->found(InboundMaklon::findFull($id));
        $this->view('inbound/show', [
            'title'   => 'Inbound ' . ($row['sj_number'] ?? $row['code']),
            'row'     => $row,
            'issues'  => Auth::can('migration.view') ? MigrationIssue::openForRecord('INBOUND_MAKLON', $id) : [],
            'history' => Auth::can('audit.view') ? AuditLog::forEntity('inbound_maklon', $id, 5) : [],
        ]);
    }

    public function create(): void
    {
        $preset = [
            'actual_inbound_date' => today(),
            'po_id'               => Request::queryInt('po_id') ?: null,
            'product_id'          => Request::queryInt('product_id') ?: null,
        ];
        $this->view('inbound/form', $this->formData(null, $preset['po_id'], $preset['product_id']) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('inbound/form', $this->formData(null, null, null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = InboundMaklon::saveInbound(null, $v->validated());
        $this->success('Inbound maklon tersimpan.', '/inbound/' . $id);
    }

    public function edit(int $id): void
    {
        $row = $this->found(InboundMaklon::find($id));
        $this->view('inbound/form', $this->formData($row, $row['po_id'] !== null ? (int) $row['po_id'] : null, $row['product_id'] !== null ? (int) $row['product_id'] : null)
            + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $row = $this->found(InboundMaklon::find($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('inbound/form', $this->formData($row, $row['po_id'] !== null ? (int) $row['po_id'] : null, $row['product_id'] !== null ? (int) $row['product_id'] : null)
                + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        try {
            InboundMaklon::saveInbound($id, $v->validated());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/inbound/' . $id . '/edit');
        }
        $this->success('Inbound maklon diperbarui.', '/inbound/' . $id);
    }

    public function destroy(int $id): void
    {
        $this->found(InboundMaklon::find($id));
        try {
            InboundMaklon::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/inbound/' . $id);
        }
        $this->success('Data inbound dihapus.', '/inbound');
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'vendor'                  => 'required|string|max:120',
            'receiver'                => 'nullable|string|max:120',
            'actual_inbound_date'     => 'required|date',
            'sj_date'                 => 'nullable|date',
            'sj_number'               => 'nullable|string|max:60',
            'po_id'                   => 'nullable|integer|exists:purchase_orders,id',
            'product_id'              => 'nullable|integer|exists:products,id',
            'internal_component_code' => 'nullable|string|max:60',
            'type'                    => 'nullable|string|max:60',
            'component_name'          => 'nullable|string|max:255',
            'factory_component_code'  => 'nullable|string|max:60',
            'quantity'                => 'required|integer|min:1',
            'reject_qty'              => 'nullable|integer|min:0',
            'attachment'              => 'nullable|url|max:500',
            'odoo_checklist'          => 'nullable|string|max:60',
            'notes'                   => 'nullable|string|max:2000',
        ], [
            'vendor' => 'Vendor', 'receiver' => 'Penerima', 'actual_inbound_date' => 'Tanggal barang masuk', 'sj_date' => 'Tanggal surat jalan',
            'sj_number' => 'Nomor surat jalan', 'po_id' => 'PO', 'product_id' => 'Produk', 'internal_component_code' => 'Kode komponen internal',
            'type' => 'Tipe', 'component_name' => 'Nama komponen', 'factory_component_code' => 'Kode komponen pabrik', 'quantity' => 'Qty diterima',
            'reject_qty' => 'Qty reject', 'attachment' => 'Link lampiran', 'odoo_checklist' => 'Checklist Odoo', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            if ($d['component_name'] === null && $d['product_id'] === null) {
                $v->addError('component_name', 'Isi nama komponen atau pilih produk.');
            }
            if ($d['reject_qty'] !== null && $d['reject_qty'] > $d['quantity']) {
                $v->addError('reject_qty', 'Qty reject tidak boleh melebihi qty diterima.');
            }
        }
        return $v;
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
    private function formData(?array $row, ?int $poId, ?int $productId): array
    {
        return [
            'title'     => $row ? 'Edit Inbound Maklon' : 'Catat Inbound Maklon',
            'row'       => $row,
            'pos'       => InboundMaklon::poOptions($poId),
            'products'  => Product::selectOptions(true, $productId),
            'vendors'   => InboundMaklon::distinct('vendor'),
            'receivers' => InboundMaklon::distinct('receiver'),
            'types'     => InboundMaklon::distinct('type'),
        ];
    }
}
