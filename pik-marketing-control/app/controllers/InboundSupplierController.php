<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\InboundSupplier;
use App\Models\Product;
use DomainException;

/** Inbound Supplier: penerimaan barang dari supplier, diinput manual oleh Purchasing. */
final class InboundSupplierController extends Controller
{
    private const FIELDS = ['supplier', 'receive_date', 'purchase_number', 'sj_number', 'item_name', 'specification', 'quantity', 'unit', 'reject_qty', 'receiver', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'        => Request::queryString('q'),
            'supplier' => Request::queryString('supplier'),
            'from'     => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'       => Validator::parseDate(Request::queryString('to')) ?? '',
            'reject'   => Request::queryString('reject') === '1' ? '1' : '',
        ];
        $this->view('inbound_supplier/index', [
            'title'     => 'Inbound Supplier',
            'rows'      => InboundSupplier::paginate($filters, $this->page()),
            'summary'   => InboundSupplier::summary($filters),
            'filters'   => $filters,
            'suppliers' => InboundSupplier::distinct('supplier'),
        ]);
    }

    public function show(int $id): void
    {
        $row = $this->found(InboundSupplier::findFull($id));
        $this->view('inbound_supplier/show', [
            'title'   => 'Inbound ' . $row['code'],
            'row'     => $row,
            'history' => Auth::can('audit.view') ? AuditLog::forEntity(InboundSupplier::ENTITY, $id, 5) : [],
        ]);
    }

    public function create(): void
    {
        $this->view('inbound_supplier/form', $this->formData(null) + ['errors' => [], 'preset' => ['receive_date' => today(), 'unit' => 'pcs', 'reject_qty' => '0']]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('inbound_supplier/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = InboundSupplier::create($this->data($v->validated()));
        $this->success('Penerimaan dari supplier tersimpan.', '/inbound-supplier/' . $id);
    }

    public function edit(int $id): void
    {
        $row = $this->found(InboundSupplier::find($id));
        $this->view('inbound_supplier/form', $this->formData($row) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $row = $this->found(InboundSupplier::find($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('inbound_supplier/form', $this->formData($row) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        InboundSupplier::update($id, $this->data($v->validated()), $row);
        $this->success('Penerimaan dari supplier diperbarui.', '/inbound-supplier/' . $id);
    }

    public function destroy(int $id): void
    {
        $row = $this->found(InboundSupplier::find($id));
        try {
            InboundSupplier::delete($id, $row);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/inbound-supplier/' . $id);
        }
        $this->success('Data penerimaan ' . $row['code'] . ' dihapus.', '/inbound-supplier');
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'supplier'        => 'required|string|max:150',
            'receive_date'    => 'required|date',
            'purchase_number' => 'nullable|string|max:80',
            'sj_number'       => 'nullable|string|max:80',
            'item_name'       => 'required|string|max:255',
            'specification'   => 'nullable|string|max:2000',
            'quantity'        => 'required|integer|min:1',
            'unit'            => 'required|string|max:20',
            'reject_qty'      => 'nullable|integer|min:0',
            'receiver'        => 'nullable|string|max:120',
            'notes'           => 'nullable|string|max:2000',
        ], [
            'supplier' => 'Supplier', 'receive_date' => 'Tanggal diterima', 'purchase_number' => 'No PO pembelian', 'sj_number' => 'No surat jalan supplier',
            'item_name' => 'Nama barang', 'specification' => 'Spesifikasi', 'quantity' => 'Qty datang', 'unit' => 'Satuan', 'reject_qty' => 'Qty reject',
            'receiver' => 'Penerima', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            if ((int) ($d['reject_qty'] ?? 0) > (int) $d['quantity']) {
                $v->addError('reject_qty', 'Qty reject tidak boleh melebihi qty datang.');
            }
        }
        return $v;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function data(array $d): array
    {
        $d['reject_qty'] = (int) ($d['reject_qty'] ?? 0);
        foreach (['supplier', 'item_name', 'receiver', 'unit'] as $f) {
            if ($d[$f] !== null) {
                $d[$f] = trim((string) preg_replace('/\s+/u', ' ', (string) $d[$f]));
            }
        }
        return $d;
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = is_scalar($_POST[$f] ?? null) ? (string) $_POST[$f] : '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $row): array
    {
        return [
            'title'     => $row ? 'Edit Inbound Supplier' : 'Catat Inbound Supplier',
            'row'       => $row,
            'suppliers' => InboundSupplier::distinct('supplier'),
            'receivers' => InboundSupplier::distinct('receiver'),
            'items'     => InboundSupplier::distinct('item_name'),
            'units'     => array_values(array_unique(array_merge(Product::UNITS, InboundSupplier::distinct('unit')))),
        ];
    }
}
