<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\LeadTime;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use DomainException;

final class PurchaseOrderController extends Controller
{
    private const HEADER_FIELDS = [
        'po_number', 'customer_id', 'po_date', 'payment_term', 'status', 'remark',
        'currency', 'price_includes_tax', 'subtotal', 'discount_amount', 'tax_amount', 'shipping_cost',
        'requested_delivery_date', 'contact_person', 'delivery_address',
    ];

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'issue'       => Request::queryString('issue'),
            'month'       => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', Request::queryString('month')) ? Request::queryString('month') : '',
            'review'      => Request::queryString('review') === 'needs_review' ? 'needs_review' : '',
        ];
    }

    public function index(): void
    {
        $filters = $this->filters();
        $sort = Request::queryString('sort', 'date');
        $dir = Request::queryString('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->view('purchase_orders/index', [
            'title'     => 'Purchase Orders',
            'orders'    => PurchaseOrder::paginate($filters, $sort, $dir, $this->page()),
            'summary'   => PurchaseOrder::summary($filters),
            'filters'   => $filters,
            'sort'      => $sort,
            'dir'       => $dir,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $preset = [
            'customer_id' => Request::queryInt('customer_id') ?: null,
            'po_date'     => today(),
            'status'      => 'Open',
        ];
        $this->view('purchase_orders/form', $this->formData(null) + ['errors' => [], 'preset' => $preset, 'lines' => [[], [], []]]);
    }

    public function store(): void
    {
        $v = $this->validateHeader(null);
        [$lines, $lineErrors] = $this->validateLines();
        $errors = array_merge($v->errors(), $lineErrors);
        if ($lines === [] && !isset($lineErrors['lines'])) {
            $errors['lines'] = 'Tambahkan minimal satu produk beserta qty.';
        }
        if ($errors !== []) {
            $this->invalid('purchase_orders/form', $this->formData(null) + ['preset' => [], 'lines' => $this->postedLines()], $errors, $this->oldHeader());
            return;
        }
        $header = $this->headerData($v->validated(), null);
        $id = PurchaseOrder::createWithLines($header, $lines);
        $this->success('PO ' . $header['po_number'] . ' dibuat dengan ' . count($lines) . ' baris produk.', '/purchase-orders/' . $id);
    }

    public function show(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $finance = Auth::can('finance.view');
        $this->view('purchase_orders/show', [
            'title'      => 'PO ' . ($po['po_number'] ?? $po['code']),
            'po'         => $po,
            'lines'      => PurchaseOrder::lines($id),
            'deliveries' => Delivery::forPo($id),
            'returns'    => ProductReturn::forPo($id),
            'invoices'   => $finance ? Invoice::forPo($id) : [],
            'financials' => $finance ? Database::fetchAll('SELECT * FROM po_financials WHERE po_id = :id ORDER BY id', ['id' => $id]) : [],
            'leadtimes'  => Auth::can('leadtime.view') ? LeadTime::forPo($id) : [],
            'issues'     => Auth::can('migration.view') ? MigrationIssue::openForRecord('PURCHASE_ORDERS', $id) : [],
            'products'   => Auth::can('purchase_orders.edit') ? Product::selectOptions() : [],
            'errors'     => [],
        ]);
    }

    public function edit(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $this->view('purchase_orders/form', $this->formData($po) + ['errors' => [], 'preset' => [], 'lines' => []]);
    }

    public function update(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $v = $this->validateHeader($id);
        if ($v->fails()) {
            $this->invalid('purchase_orders/form', $this->formData($po) + ['preset' => [], 'lines' => []], $v->errors(), $this->oldHeader());
            return;
        }
        $data = $this->headerData($v->validated(), $po);
        Database::transaction(function () use ($id, $data, $po): void {
            PurchaseOrder::update($id, $data);
            if ((int) ($data['price_includes_tax'] ?? 0) !== (int) ($po['price_includes_tax'] ?? 0)) {
                PoLine::recalcSubtotals($id); // harga termasuk/tidak termasuk PPN berubah
            }
            PurchaseOrder::recalcTotals($id);
        });
        $this->success('PO diperbarui.', '/purchase-orders/' . $id);
    }

    public function destroy(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        try {
            PurchaseOrder::deleteSafely($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $this->success('PO ' . ($po['po_number'] ?? $po['code']) . ' dihapus.', '/purchase-orders');
    }

    public function addLine(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $v = $this->validateLine($_POST);
        if ($v->fails()) {
            $this->failure('Baris gagal ditambahkan: ' . implode(' ', $v->errors()), '/purchase-orders/' . $id);
        }
        $data = $v->validated();
        Database::transaction(function () use ($id, $data, $po): void {
            PoLine::create(['po_id' => $id, 'product_id' => $data['product_id'], 'order_qty' => $data['order_qty'], 'remark' => $data['remark'],
                'unit' => $data['unit'], 'unit_price' => $data['unit_price'],
                'line_subtotal' => PoLine::subtotalFor((int) $data['order_qty'], $data['unit_price'], (bool) $po['price_includes_tax'], null)]);
            PurchaseOrder::syncStatus($id);
            PurchaseOrder::recalcTotals($id);
        });
        $this->success('Baris produk ditambahkan ke PO ' . ($po['po_number'] ?? $po['code']) . '.', '/purchase-orders/' . $id);
    }

    public function editLine(int $id): void
    {
        $line = $this->found(PoLine::findFull($id));
        $this->view('purchase_orders/line_form', [
            'title'    => 'Edit Baris PO',
            'line'     => $line,
            'products' => Product::selectOptions(true, (int) $line['product_id']),
            'errors'   => [],
            'locked'   => PoLine::dependents($id) > 0,
        ]);
    }

    public function updateLine(int $id): void
    {
        $line = $this->found(PoLine::findFull($id));
        $locked = PoLine::dependents($id) > 0;
        $v = $this->validateLine($_POST);
        $data = $v->validated();
        if (!$v->fails() && $locked && (int) $data['product_id'] !== (int) $line['product_id']) {
            $v->addError('product_id', 'Produk tidak dapat diganti karena baris ini sudah memiliki delivery/retur.');
        }
        if ($v->fails()) {
            $this->invalid('purchase_orders/line_form', [
                'title' => 'Edit Baris PO', 'line' => $line, 'products' => Product::selectOptions(true, (int) $line['product_id']), 'locked' => $locked,
            ], $v->errors(), ['product_id' => $_POST['product_id'] ?? '', 'order_qty' => $_POST['order_qty'] ?? '', 'remark' => $_POST['remark'] ?? '',
                'unit' => $_POST['unit'] ?? '', 'unit_price' => $_POST['unit_price'] ?? '']);
            return;
        }
        Database::transaction(function () use ($id, $data, $line): void {
            $includesTax = (bool) Database::fetchValue('SELECT price_includes_tax FROM purchase_orders WHERE id = :id', ['id' => $line['po_id']]);
            PoLine::update($id, ['product_id' => $data['product_id'], 'order_qty' => $data['order_qty'], 'remark' => $data['remark'],
                'unit' => $data['unit'], 'unit_price' => $data['unit_price'],
                'line_subtotal' => PoLine::subtotalFor((int) $data['order_qty'], $data['unit_price'], $includesTax, $line['tax_rate'] !== null ? (string) $line['tax_rate'] : null)]);
            PurchaseOrder::syncStatus((int) $line['po_id']);
            PurchaseOrder::recalcTotals((int) $line['po_id']);
        });
        $this->success('Baris PO diperbarui.', '/purchase-orders/' . $line['po_id']);
    }

    public function destroyLine(int $id): void
    {
        $line = $this->found(PoLine::find($id));
        try {
            PoLine::removeLine($id);
            PurchaseOrder::recalcTotals((int) $line['po_id']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $line['po_id']);
        }
        $this->success('Baris PO dihapus.', '/purchase-orders/' . $line['po_id']);
    }

    private function validateHeader(?int $id): Validator
    {
        $v = Validator::make($_POST, [
            'po_number'    => 'required|string|max:80',
            'customer_id'  => 'required|integer|exists:customers,id',
            'po_date'      => 'required|date',
            'payment_term' => 'nullable|string|max:255',
            'status'       => ['required', ['in', PurchaseOrder::STATUSES]],
            'remark'       => 'nullable|string|max:5000',
            'currency'                => 'nullable|string|max:3',
            'price_includes_tax'      => 'boolean',
            'subtotal'                => 'nullable|numeric|min:0',
            'discount_amount'         => 'nullable|numeric|min:0',
            'tax_amount'              => 'nullable|numeric|min:0',
            'shipping_cost'           => 'nullable|numeric|min:0',
            'requested_delivery_date' => 'nullable|date',
            'contact_person'          => 'nullable|string|max:150',
            'delivery_address'        => 'nullable|string|max:2000',
        ], ['po_number' => 'Nomor PO', 'customer_id' => 'Customer', 'po_date' => 'Tanggal PO', 'payment_term' => 'Termin pembayaran', 'status' => 'Status', 'remark' => 'Catatan',
            'currency' => 'Mata uang', 'price_includes_tax' => 'Harga termasuk PPN', 'subtotal' => 'Subtotal', 'discount_amount' => 'Diskon', 'tax_amount' => 'PPN',
            'shipping_cost' => 'Ongkos kirim', 'requested_delivery_date' => 'Tanggal kirim diminta', 'contact_person' => 'Contact person', 'delivery_address' => 'Alamat kirim']);
        if (!$v->fails()) {
            $d = $v->validated();
            if ($d['currency'] !== null && !preg_match('/^[A-Za-z]{3}$/', (string) $d['currency'])) {
                $v->addError('currency', 'Mata uang harus kode 3 huruf, mis. IDR.');
            }
            if ($d['discount_amount'] !== null && $d['subtotal'] !== null && Number::toCents((string) $d['discount_amount']) > Number::toCents((string) $d['subtotal'])) {
                $v->addError('discount_amount', 'Diskon tidak boleh melebihi subtotal.');
            }
        }
        if (!$v->fails()) {
            $number = (string) $v->validated()['po_number'];
            $current = $id !== null ? PurchaseOrder::find($id) : null;
            $changed = $current === null || mb_strtolower(trim((string) $current['po_number'])) !== mb_strtolower($number);
            if ($changed && ($taken = PurchaseOrder::numberTaken($number, $id)) !== null) {
                $v->addError('po_number', 'Nomor PO sudah dipakai (' . $taken['code'] . ($taken['customer_name'] ? ', ' . $taken['customer_name'] : '') . ').');
            }
        }
        return $v;
    }

    private function validateLine(array $input): Validator
    {
        return Validator::make($input, [
            'product_id' => 'required|integer|exists:products,id',
            'order_qty'  => 'required|integer|min:1',
            'unit'       => 'nullable|string|max:20',
            'unit_price' => 'nullable|numeric|min:0',
            'remark'     => 'nullable|string|max:500',
        ], ['product_id' => 'Produk', 'order_qty' => 'Qty order', 'unit' => 'Satuan', 'unit_price' => 'Harga satuan', 'remark' => 'Catatan baris']);
    }

    /**
     * Data header untuk disimpan. Subtotal & grand total dihitung ulang oleh
     * PurchaseOrder::recalcTotals (subtotal dari baris bila semua baris berharga).
     * @param array<string,mixed> $d
     */
    private function headerData(array $d, ?array $po): array
    {
        $d['currency'] = $d['currency'] !== null ? strtoupper((string) $d['currency']) : null;
        $d['price_includes_tax'] = (int) $d['price_includes_tax'];
        return $d;
    }

    /**
     * Validasi baris PO dari form (lines[i][product_id], lines[i][order_qty]).
     * Baris yang benar-benar kosong diabaikan.
     * @return array{0:list<array{product_id:int,order_qty:int,remark:?string}>,1:array<string,string>}
     */
    private function validateLines(): array
    {
        $raw = $_POST['lines'] ?? [];
        if (!is_array($raw)) {
            return [[], ['lines' => 'Format baris PO tidak valid.']];
        }
        if (count($raw) > 200) {
            return [[], ['lines' => 'Maksimal 200 baris per PO.']];
        }
        $lines = [];
        $errors = [];
        foreach (array_values($raw) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $product = trim((string) ($row['product_id'] ?? ''));
            $qty = trim((string) ($row['order_qty'] ?? ''));
            $remark = trim((string) ($row['remark'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $price = trim((string) ($row['unit_price'] ?? ''));
            if ($product === '' && $qty === '' && $remark === '' && $price === '') {
                continue;
            }
            $v = $this->validateLine(['product_id' => $product, 'order_qty' => $qty, 'remark' => $remark, 'unit' => $unit, 'unit_price' => $price]);
            if ($v->fails()) {
                foreach ($v->errors() as $field => $msg) {
                    $errors["lines.{$i}.{$field}"] = 'Baris ' . ($i + 1) . ': ' . $msg;
                }
                continue;
            }
            $data = $v->validated();
            $lines[] = ['product_id' => (int) $data['product_id'], 'order_qty' => (int) $data['order_qty'], 'remark' => $data['remark'],
                'unit' => $data['unit'], 'unit_price' => $data['unit_price'] !== null ? (string) $data['unit_price'] : null];
        }
        return [$lines, $errors];
    }

    /** @return list<array<string,string>> */
    private function postedLines(): array
    {
        $raw = $_POST['lines'] ?? [];
        $out = [];
        if (is_array($raw)) {
            foreach (array_values($raw) as $row) {
                if (is_array($row)) {
                    $out[] = ['product_id' => (string) ($row['product_id'] ?? ''), 'order_qty' => (string) ($row['order_qty'] ?? ''), 'remark' => (string) ($row['remark'] ?? ''),
                        'unit' => (string) ($row['unit'] ?? ''), 'unit_price' => (string) ($row['unit_price'] ?? '')];
                }
            }
        }
        return $out !== [] ? $out : [[]];
    }

    /** @return array<string,mixed> */
    private function oldHeader(): array
    {
        $old = [];
        foreach (self::HEADER_FIELDS as $f) {
            $old[$f] = $_POST[$f] ?? '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $po): array
    {
        return [
            'title'     => $po ? 'Edit PO' : 'Buat Purchase Order',
            'po'        => $po,
            'customers' => Customer::selectOptions(),
            'products'  => $po === null ? Product::selectOptions() : [],
        ];
    }
}
