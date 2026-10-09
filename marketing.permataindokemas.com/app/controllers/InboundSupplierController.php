<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\InboundSupplier;
use DomainException;

/** Inbound Supplier: penerimaan barang dari supplier, diinput manual oleh Produksi. */
final class InboundSupplierController extends Controller
{
    private const FIELDS = [
        'supplier', 'inbound_date', 'sj_number', 'sj_date', 'po_reference', 'item_name', 'item_code', 'category',
        'unit', 'quantity', 'reject_qty', 'receiver', 'location', 'attachment', 'notes',
    ];

    public function index(): void
    {
        $filters = [
            'q'        => Request::queryString('q'),
            'supplier' => Request::queryString('supplier'),
            'category' => Request::queryString('category'),
            'from'     => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'       => Validator::parseDate(Request::queryString('to')) ?? '',
            'reject'   => Request::queryString('reject'),
        ];
        $mode = Request::queryString('view') === 'items' ? 'items' : 'list';
        $this->view('inbound_supplier/index', [
            'title'      => 'Inbound Supplier',
            'mode'       => $mode,
            'rows'       => $mode === 'items' ? InboundSupplier::paginateByItem($filters, $this->page()) : InboundSupplier::paginate($filters, $this->page()),
            'summary'    => InboundSupplier::summary($filters),
            'filters'    => $filters,
            'suppliers'  => InboundSupplier::distinct('supplier'),
            'categories' => InboundSupplier::distinct('category'),
        ]);
    }

    public function show(int $id): void
    {
        $row = $this->found(InboundSupplier::findFull($id));
        $this->view('inbound_supplier/show', [
            'title'   => 'Inbound ' . $row['item_name'],
            'row'     => $row,
            'history' => Auth::can('audit.view') ? AuditLog::forEntity('inbound_supplier', $id, 5) : [],
        ]);
    }

    public function create(): void
    {
        $preset = ['inbound_date' => today(), 'unit' => 'pcs', 'receiver' => Auth::user()['name'] ?? ''];
        $this->view('inbound_supplier/form', $this->formData(null) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('inbound_supplier/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = InboundSupplier::saveInbound(null, $v->validated());
        $this->success('Inbound supplier tersimpan.', '/inbound-supplier/' . $id);
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
        try {
            InboundSupplier::saveInbound($id, $v->validated());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/inbound-supplier/' . $id . '/edit');
        }
        $this->success('Inbound supplier diperbarui.', '/inbound-supplier/' . $id);
    }

    public function destroy(int $id): void
    {
        $this->found(InboundSupplier::find($id));
        try {
            InboundSupplier::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/inbound-supplier/' . $id);
        }
        $this->success('Data inbound supplier dihapus.', '/inbound-supplier');
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'supplier'     => 'required|string|max:150',
            'inbound_date' => 'required|date',
            'sj_number'    => 'nullable|string|max:60',
            'sj_date'      => 'nullable|date',
            'po_reference' => 'nullable|string|max:80',
            'item_name'    => 'required|string|max:255',
            'item_code'    => 'nullable|string|max:60',
            'category'     => 'nullable|string|max:80',
            'unit'         => 'required|string|max:20',
            'quantity'     => 'required|numeric|min:0.01|max:9999999999999',
            'reject_qty'   => 'nullable|numeric|min:0|max:9999999999999',
            'receiver'     => 'nullable|string|max:120',
            'location'     => 'nullable|string|max:120',
            'attachment'   => 'nullable|url|max:500',
            'notes'        => 'nullable|string|max:2000',
        ], [
            'supplier' => 'Supplier', 'inbound_date' => 'Tanggal barang masuk', 'sj_number' => 'No. surat jalan', 'sj_date' => 'Tanggal surat jalan',
            'po_reference' => 'No. PO pembelian', 'item_name' => 'Nama barang', 'item_code' => 'Kode barang', 'category' => 'Jenis barang',
            'unit' => 'Satuan', 'quantity' => 'Qty diterima', 'reject_qty' => 'Qty reject', 'receiver' => 'Penerima',
            'location' => 'Lokasi simpan', 'attachment' => 'Link lampiran', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            if ($d['reject_qty'] !== null && (float) $d['reject_qty'] > (float) $d['quantity']) {
                $v->addError('reject_qty', 'Qty reject tidak boleh melebihi qty diterima.');
            }
            if ($d['sj_date'] !== null && $d['sj_date'] > $d['inbound_date']) {
                $v->addError('sj_date', 'Tanggal surat jalan tidak boleh setelah tanggal barang masuk.');
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
    private function formData(?array $row): array
    {
        return [
            'title'      => $row ? 'Edit Inbound Supplier' : 'Catat Inbound Supplier',
            'row'        => $row,
            'suppliers'  => InboundSupplier::distinct('supplier'),
            'items'      => InboundSupplier::distinct('item_name'),
            'categories' => array_values(array_unique(array_merge(InboundSupplier::CATEGORIES, InboundSupplier::distinct('category')))),
            'units'      => array_values(array_unique(array_merge(InboundSupplier::UNITS, InboundSupplier::distinct('unit')))),
            'receivers'  => InboundSupplier::distinct('receiver'),
            'locations'  => InboundSupplier::distinct('location'),
        ];
    }
}
