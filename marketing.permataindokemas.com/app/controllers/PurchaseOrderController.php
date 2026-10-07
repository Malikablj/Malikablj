<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\LeadTime;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\OrderEntry;
use DomainException;

final class PurchaseOrderController extends Controller
{
    /** Field form Order Entry Form (header + produk). */
    private const FORM_FIELDS = [
        'order_number', 'customer_name', 'sales_name', 'po_number', 'po_date', 'product_name', 'product_spec', 'order_qty',
        'is_subcont', 'supplier', 'requested_date', 'ship_to', 'remark', 'payment_term', 'status',
    ];

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'ppic'        => Request::queryString('ppic'),
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
            'title'        => 'Order Entry Form',
            'orders'       => PurchaseOrder::paginate($filters, $sort, $dir, $this->page()),
            'summary'      => PurchaseOrder::summary($filters),
            'ppicPending'  => PurchaseOrder::ppicPendingCount(),
            'filters'      => $filters,
            'sort'         => $sort,
            'dir'          => $dir,
            'customers'    => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $customerId = Request::queryInt('customer_id') ?: null;
        $customer = $customerId ? Database::fetch('SELECT name, address FROM customers WHERE id = :id', ['id' => $customerId]) : null;
        $preset = [
            'customer_name' => $customer['name'] ?? '',
            'po_date'       => today(),
            'sales_name'    => Auth::user()['name'] ?? '',
            'is_subcont'    => 0,
            'ship_to'       => $customer['address'] ?? null,
        ];
        $this->view('purchase_orders/form', $this->formData(null) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validateForm(null, true, true);
        if ($v->fails()) {
            $this->invalid('purchase_orders/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->oldInput());
            return;
        }
        $data = $v->validated();
        [$header, $product] = $this->split($data, true);
        try {
            $result = OrderEntry::create($header, (string) $data['customer_name'], $product['name'], $product['spec'], $product['qty']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/create');
        }
        $po = PurchaseOrder::find($result['id']) ?? [];
        $msg = 'Order Entry Form ' . PurchaseOrder::displayNumber($po) . ' tersimpan dan dikirim ke PPIC untuk konfirmasi.';
        if ($result['customer_created']) {
            $msg .= ' Customer baru "' . $data['customer_name'] . '" dicatat otomatis di menu Customers.';
        }
        if ($result['product_created']) {
            $msg .= ' Produk baru "' . $product['name'] . '" dicatat otomatis di menu Products.';
        }
        if (!empty($header['requested_date'])) {
            $msg .= ' Jadwal delivery ' . fmt_date((string) $header['requested_date']) . ' dibuat otomatis.';
        }
        $this->success($msg, '/purchase-orders/' . $result['id']);
    }

    public function show(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $this->view('purchase_orders/show', [
            'title'      => 'Order ' . PurchaseOrder::displayNumber($po),
            'po'         => $po,
            'lines'      => PurchaseOrder::lines($id),
            'deliveries' => Delivery::forPo($id),
            'returns'    => ProductReturn::forPo($id),
            'leadtimes'  => Auth::can('leadtime.view') ? LeadTime::forPo($id) : [],
            'issues'     => Auth::can('migration.view') ? MigrationIssue::openForRecord('PURCHASE_ORDERS', $id) : [],
            'productNames' => Auth::can('purchase_orders.edit') ? Product::nameSuggestions() : [],
            'errors'     => [],
        ]);
    }

    public function edit(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $this->view('purchase_orders/form', $this->formData($po) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $withProduct = $this->productEditable($po);
        $v = $this->validateForm($id, $withProduct, $po['ppic_status'] !== null);
        if ($v->fails()) {
            $this->invalid('purchase_orders/form', $this->formData($po) + ['preset' => []], $v->errors(), $this->oldInput());
            return;
        }
        $data = $v->validated();
        [$header, $product] = $this->split($data, $withProduct);
        try {
            $result = OrderEntry::update($id, $header, (string) $data['customer_name'], $product);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id . '/edit');
        }
        $msg = 'Order diperbarui.';
        if ($result['resubmitted']) {
            $msg .= ' Perubahan dikirim ulang ke PPIC untuk konfirmasi.';
        }
        if ($result['customer_created']) {
            $msg .= ' Customer baru dicatat otomatis di menu Customers.';
        }
        if ($result['product_created']) {
            $msg .= ' Produk baru dicatat otomatis di menu Products.';
        }
        $this->success($msg, '/purchase-orders/' . $id);
    }

    /** PPIC: "Bisa diproses" (hijau) / "Tidak bisa diproses" (merah, wajib alasan). */
    public function ppic(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $decision = (string) ($_POST['decision'] ?? '');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $this->failure('Pilih keputusan PPIC.', '/purchase-orders/' . $id);
        }
        $note = is_string($_POST['ppic_note'] ?? null) ? mb_substr(trim($_POST['ppic_note']), 0, 2000) : null;
        try {
            OrderEntry::decide($id, $decision, $note);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $this->success('Order ' . PurchaseOrder::displayNumber($po) . ($decision === 'approve' ? ' dikonfirmasi BISA diproses.' : ' ditandai TIDAK bisa diproses. Jadwal delivery dibatalkan.'), '/purchase-orders/' . $id);
    }

    /** Ubah jadwal delivery otomatis dari halaman order. */
    public function reschedule(int $id): void
    {
        $this->found(PurchaseOrder::find($id));
        $v = Validator::make($_POST, ['delivery_date' => 'required|date', 'reason' => 'nullable|string|max:300'], ['delivery_date' => 'Tanggal kirim baru', 'reason' => 'Alasan']);
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/purchase-orders/' . $id);
        }
        $data = $v->validated();
        try {
            OrderEntry::reschedule($id, (string) $data['delivery_date'], $data['reason']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $this->success('Jadwal delivery diubah ke ' . fmt_date((string) $data['delivery_date']) . '.', '/purchase-orders/' . $id);
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

    /** Tambah baris produk: nama produk diketik manual (produk baru dicatat otomatis). */
    public function addLine(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $v = Validator::make($_POST, [
            'product_name' => 'required|string|max:190',
            'order_qty'    => 'required|integer|min:1',
            'remark'       => 'nullable|string|max:500',
        ], ['product_name' => 'Nama produk', 'order_qty' => 'Qty order', 'remark' => 'Catatan baris']);
        if ($v->fails()) {
            $this->failure('Baris gagal ditambahkan: ' . implode(' ', $v->errors()), '/purchase-orders/' . $id);
        }
        $data = $v->validated();
        $label = PurchaseOrder::displayNumber($po);
        $created = Database::transaction(function () use ($id, $data, $label): bool {
            $product = Product::findOrCreateForOrder((string) $data['product_name'], null, $label, (int) $data['order_qty']);
            PoLine::create(['po_id' => $id, 'product_id' => $product['id'], 'order_qty' => $data['order_qty'], 'remark' => $data['remark']]);
            PurchaseOrder::syncStatus($id);
            return $product['created'];
        });
        $this->success('Baris produk ditambahkan ke order ' . $label . '.' . ($created ? ' Produk baru dicatat otomatis di menu Products.' : ''), '/purchase-orders/' . $id);
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

    /** Produk OEF bisa diubah dari form bila order hanya punya satu baris produk. */
    private function productEditable(array $po): bool
    {
        return OrderEntry::singleLine((int) $po['id']) !== null;
    }

    /** $isOef = false untuk PO lama hasil migrasi (field OEF boleh kosong). */
    private function validateForm(?int $id, bool $withProduct, bool $isOef): Validator
    {
        $req = $isOef ? 'required' : 'nullable';
        $rules = [
            'order_number'   => $req . '|string|max:60',
            'customer_name'  => 'required|string|max:190',
            'sales_name'     => $req . '|string|max:120',
            'po_number'      => 'nullable|string|max:80',
            'po_date'        => 'required|date',
            'is_subcont'     => 'boolean',
            'supplier'       => 'nullable|string|max:190',
            'requested_date' => $req . '|date|after_or_equal:po_date',
            'ship_to'        => 'nullable|string|max:255',
            'remark'         => 'nullable|string|max:5000',
            'payment_term'   => 'nullable|string|max:60',
        ];
        if ($withProduct) {
            $rules['product_name'] = 'required|string|max:190';
            $rules['product_spec'] = $req . '|string|max:5000';
            $rules['order_qty'] = 'required|integer|min:1';
        } else {
            $rules['product_spec'] = 'nullable|string|max:5000';
        }
        if ($id !== null) {
            $rules['status'] = ['required', ['in', PurchaseOrder::STATUSES]];
        }
        $v = Validator::make($_POST, $rules, [
            'order_number' => 'No. order', 'customer_name' => 'Nama customer', 'sales_name' => 'Nama sales', 'po_number' => 'No. PO dari customer', 'po_date' => 'Tanggal order',
            'product_name' => 'Nama produk', 'product_spec' => 'Spesifikasi produk', 'order_qty' => 'Qty produk', 'is_subcont' => 'Subcont',
            'supplier' => 'Supplier', 'requested_date' => 'Permintaan selesai / kirim', 'ship_to' => 'Tujuan kirim', 'remark' => 'Keterangan',
            'payment_term' => 'Termin pembayaran', 'status' => 'Status order',
        ]);
        $data = $v->validated();
        if ((int) ($data['is_subcont'] ?? 0) === 1 && empty($data['supplier'])) {
            $v->addError('supplier', 'Supplier wajib diisi untuk order subcont.');
        }
        if (!empty($data['order_number'])) {
            $orderNumber = (string) preg_replace('/\s+/u', ' ', (string) $data['order_number']);
            if (($taken = PurchaseOrder::orderNumberTaken($orderNumber, $id)) !== null) {
                $v->addError('order_number', 'No. order sudah dipakai order lain (' . $taken['order_number'] . ($taken['customer_name'] ? ', ' . $taken['customer_name'] : '') . ').');
            }
        }
        if (!empty($data['po_number'])) {
            $number = (string) $data['po_number'];
            $current = $id !== null ? PurchaseOrder::find($id) : null;
            $changed = $current === null || mb_strtolower(trim((string) $current['po_number'])) !== mb_strtolower($number);
            if ($changed && ($taken = PurchaseOrder::numberTaken($number, $id)) !== null) {
                $v->addError('po_number', 'No. PO customer sudah dipakai order lain (' . $taken['code'] . ($taken['customer_name'] ? ', ' . $taken['customer_name'] : '') . ').');
            }
        }
        return $v;
    }

    /**
     * Pisahkan data form menjadi header purchase_orders & data produk
     * (nama customer diproses terpisah oleh OrderEntry: dicari / dicatat otomatis).
     * @param array<string,mixed> $data
     * @return array{0:array<string,mixed>,1:array{name:string,spec:?string,qty:int}|null}
     */
    private function split(array $data, bool $withProduct): array
    {
        $header = [
            'order_number'   => $data['order_number'] !== null ? (string) preg_replace('/\s+/u', ' ', (string) $data['order_number']) : null,
            'sales_name'     => $data['sales_name'],
            'po_number'      => $data['po_number'],
            'po_date'        => $data['po_date'],
            'is_subcont'     => (int) $data['is_subcont'],
            'supplier'       => (int) $data['is_subcont'] === 1 ? $data['supplier'] : null,
            'requested_date' => $data['requested_date'],
            'ship_to'        => $data['ship_to'],
            'remark'         => $data['remark'],
            'payment_term'   => $data['payment_term'],
        ];
        if (array_key_exists('status', $data)) {
            $header['status'] = $data['status'];
        }
        if (!$withProduct) {
            $header['product_spec'] = $data['product_spec'];
            return [$header, null];
        }
        return [$header, ['name' => (string) $data['product_name'], 'spec' => $data['product_spec'], 'qty' => (int) $data['order_qty']]];
    }

    private function validateLine(array $input): Validator
    {
        return Validator::make($input, [
            'product_id' => 'required|integer|exists:products,id',
            'order_qty'  => 'required|integer|min:1',
            'remark'     => 'nullable|string|max:500',
        ], ['product_id' => 'Produk', 'order_qty' => 'Qty order', 'remark' => 'Catatan baris']);
    }

    /** @return array<string,mixed> */
    private function oldInput(): array
    {
        $old = [];
        foreach (self::FORM_FIELDS as $f) {
            $old[$f] = is_scalar($_POST[$f] ?? null) ? (string) $_POST[$f] : '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $po): array
    {
        $line = $po !== null ? OrderEntry::singleLine((int) $po['id']) : null;
        $product = null;
        if ($line !== null) {
            $product = Database::fetch('SELECT id, name FROM products WHERE id = :id', ['id' => $line['product_id']]);
        }
        return [
            'title'          => $po ? 'Edit Order ' . PurchaseOrder::displayNumber($po) : 'Order Entry Form',
            'po'             => $po,
            'customerNames'  => Customer::nameSuggestions(),
            'productNames'   => Product::nameSuggestions(),
            'lastOrderNumber' => PurchaseOrder::lastOrderNumber(),
            'salesNames'     => array_values(User::picOptions()),
            'productEditable' => $po === null || $line !== null,
            'productLocked'  => $po !== null && $line !== null && OrderEntry::lineLocked($po, (int) $line['id']),
            'currentProduct' => $product !== null ? (string) $product['name'] : '',
            'currentQty'     => $line !== null ? (string) $line['order_qty'] : '',
        ];
    }
}
