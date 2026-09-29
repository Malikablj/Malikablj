<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use DomainException;

final class PurchaseOrderController extends Controller
{
    private const HEADER_FIELDS = ['po_number', 'customer_id', 'po_date', 'payment_term', 'status', 'remark'];

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
        $header = $v->validated();
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
            'invoices'   => $finance ? Database::fetchAll('SELECT *, (invoice_amount - paid_amount) AS outstanding_amount FROM invoices_payments WHERE po_id = :id ORDER BY invoice_date DESC', ['id' => $id]) : [],
            'financials' => $finance ? Database::fetchAll('SELECT * FROM po_financials WHERE po_id = :id ORDER BY id', ['id' => $id]) : [],
            'leadtimes'  => Database::fetchAll('SELECT * FROM leadtime WHERE po_id = :id ORDER BY delivery_date', ['id' => $id]),
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
        PurchaseOrder::update($id, $v->validated());
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
        Database::transaction(function () use ($id, $data): void {
            PoLine::create(['po_id' => $id, 'product_id' => $data['product_id'], 'order_qty' => $data['order_qty'], 'remark' => $data['remark']]);
            PurchaseOrder::syncStatus($id);
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
            ], $v->errors(), ['product_id' => $_POST['product_id'] ?? '', 'order_qty' => $_POST['order_qty'] ?? '', 'remark' => $_POST['remark'] ?? '']);
            return;
        }
        Database::transaction(function () use ($id, $data, $line): void {
            PoLine::update($id, ['product_id' => $data['product_id'], 'order_qty' => $data['order_qty'], 'remark' => $data['remark']]);
            PurchaseOrder::syncStatus((int) $line['po_id']);
        });
        $this->success('Baris PO diperbarui.', '/purchase-orders/' . $line['po_id']);
    }

    public function destroyLine(int $id): void
    {
        $line = $this->found(PoLine::find($id));
        try {
            PoLine::removeLine($id);
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
            'payment_term' => 'nullable|string|max:60',
            'status'       => ['required', ['in', PurchaseOrder::STATUSES]],
            'remark'       => 'nullable|string|max:5000',
        ], ['po_number' => 'Nomor PO', 'customer_id' => 'Customer', 'po_date' => 'Tanggal PO', 'payment_term' => 'Termin pembayaran', 'status' => 'Status', 'remark' => 'Catatan']);
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
            'remark'     => 'nullable|string|max:500',
        ], ['product_id' => 'Produk', 'order_qty' => 'Qty order', 'remark' => 'Catatan baris']);
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
            if ($product === '' && $qty === '' && $remark === '') {
                continue;
            }
            $v = $this->validateLine(['product_id' => $product, 'order_qty' => $qty, 'remark' => $remark]);
            if ($v->fails()) {
                foreach ($v->errors() as $field => $msg) {
                    $errors["lines.{$i}.{$field}"] = 'Baris ' . ($i + 1) . ': ' . $msg;
                }
                continue;
            }
            $data = $v->validated();
            $lines[] = ['product_id' => (int) $data['product_id'], 'order_qty' => (int) $data['order_qty'], 'remark' => $data['remark']];
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
                    $out[] = ['product_id' => (string) ($row['product_id'] ?? ''), 'order_qty' => (string) ($row['order_qty'] ?? ''), 'remark' => (string) ($row['remark'] ?? '')];
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
