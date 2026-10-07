<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MigrationIssue;
use App\Models\PoFinancial;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use DomainException;

final class PoFinancialController extends Controller
{
    private const FIELDS = ['po_id', 'brand', 'po_date', 'product_legacy', 'product_code_legacy', 'order_qty', 'unit_price', 'payment_status', 'attachment', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'brand'       => Request::queryString('brand'),
            'link'        => Request::queryString('link'),
        ];
        $this->view('po_financials/index', [
            'title'     => 'PO Financials',
            'rows'      => PoFinancial::paginate($filters, $this->page()),
            'summary'   => PoFinancial::summary($filters),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
            'brands'    => PoFinancial::brands(),
        ]);
    }

    public function show(int $id): void
    {
        $row = $this->found(PoFinancial::findFull($id));
        $poId = $row['po_id'] !== null ? (int) $row['po_id'] : null;
        $this->view('po_financials/show', [
            'title'    => 'PO Financial ' . ($row['po_number'] ?? $row['po_number_legacy'] ?? $row['code']),
            'row'      => $row,
            'po'       => $poId !== null ? PurchaseOrder::findFull($poId) : null,
            'invoices' => $poId !== null ? Invoice::forPo($poId) : [],
            'ppnRate'  => Setting::float('ppn_rate', 11.0),
            'issues'   => Auth::can('migration.view') ? MigrationIssue::openForRecord('PO_FINANCIALS', $id) : [],
            'history'  => AuditLog::forEntity('po_financial', $id, 6),
        ]);
    }

    public function create(): void
    {
        $poId = Request::queryInt('po_id') ?: null;
        $preset = ['po_id' => $poId, 'payment_status' => 'Unpaid'];
        $this->view('po_financials/form', $this->formData(null, $poId) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('po_financials/form', $this->formData(null, null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = PoFinancial::savePoFinancial(null, $v->validated(), Setting::float('ppn_rate', 11.0));
        $this->success('Ringkasan finansial PO tersimpan.', '/po-financials/' . $id);
    }

    public function edit(int $id): void
    {
        $row = $this->found(PoFinancial::find($id));
        $this->view('po_financials/form', $this->formData($row, $row['po_id'] !== null ? (int) $row['po_id'] : null) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $row = $this->found(PoFinancial::find($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('po_financials/form', $this->formData($row, $row['po_id'] !== null ? (int) $row['po_id'] : null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        try {
            PoFinancial::savePoFinancial($id, $v->validated(), Setting::float('ppn_rate', 11.0));
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/po-financials/' . $id . '/edit');
        }
        $this->success('Ringkasan finansial PO diperbarui.', '/po-financials/' . $id);
    }

    public function destroy(int $id): void
    {
        $this->found(PoFinancial::find($id));
        try {
            PoFinancial::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/po-financials/' . $id);
        }
        $this->success('Ringkasan finansial PO dihapus.', '/po-financials');
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'po_id'               => 'nullable|integer|exists:purchase_orders,id',
            'brand'               => 'nullable|string|max:100',
            'po_date'             => 'nullable|date',
            'product_legacy'      => 'nullable|string|max:255',
            'product_code_legacy' => 'nullable|string|max:60',
            'order_qty'           => 'nullable|integer|min:0',
            'unit_price'          => 'nullable|numeric|min:0',
            'payment_status'      => ['required', ['in', PoFinancial::PAYMENT_STATUSES]],
            'attachment'          => 'nullable|url|max:500',
            'notes'               => 'nullable|string|max:5000',
        ], [
            'po_id' => 'PO', 'brand' => 'Brand', 'po_date' => 'Tanggal PO', 'product_legacy' => 'Produk / deskripsi',
            'product_code_legacy' => 'Kode produk', 'order_qty' => 'Qty', 'unit_price' => 'Harga satuan', 'payment_status' => 'Status pembayaran',
            'attachment' => 'Link lampiran', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            if ($d['unit_price'] !== null && (float) $d['unit_price'] >= 1.0e12) {
                $v->addError('unit_price', 'Harga satuan terlalu besar.');
            }
            if ($d['po_id'] === null && $d['po_date'] === null) {
                $v->addError('po_date', 'Isi tanggal PO atau pilih PO.');
            }
            if ($d['po_id'] === null && $d['product_legacy'] === null) {
                $v->addError('product_legacy', 'Isi produk / deskripsi atau pilih PO.');
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
    private function formData(?array $row, ?int $poId): array
    {
        return [
            'title'   => $row ? 'Edit PO Financial' : 'Tambah PO Financial',
            'row'     => $row,
            'pos'     => Invoice::poOptions(null, $poId),
            'brands'  => PoFinancial::brands(),
            'ppnRate' => Setting::float('ppn_rate', 11.0),
            'poLines' => $poId !== null ? Database::fetchAll(
                'SELECT pr.name, pl.order_qty FROM po_lines pl JOIN products pr ON pr.id = pl.product_id WHERE pl.po_id = :id ORDER BY pl.id',
                ['id' => $poId]
            ) : [],
        ];
    }
}
