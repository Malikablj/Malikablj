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
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use App\Services\MasterData;
use App\Services\OefWorkflow;
use DomainException;

/**
 * Order Entry Form (OEF) — menggantikan menu Purchase Order.
 * Customer & produk diketik manual; bila belum ada otomatis ditambahkan ke master.
 * Setiap OEF baru/diubah menunggu review PPIC ("Bisa diproses" / "Tidak bisa diproses").
 */
final class PurchaseOrderController extends Controller
{
    private const HEADER_FIELDS = [
        'order_number', 'po_date', 'sales_name', 'customer_name', 'po_number', 'requested_delivery_date', 'delivery_address', 'remark', 'status',
    ];
    private const LINE_FIELDS = ['product_name', 'item_description', 'order_qty', 'unit', 'subcont_supplier'];

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $ppic = Request::queryString('ppic');
        return [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'issue'       => Request::queryString('issue'),
            'month'       => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', Request::queryString('month')) ? Request::queryString('month') : '',
            'review'      => Request::queryString('review') === 'needs_review' ? 'needs_review' : '',
            'ppic'        => in_array($ppic, PurchaseOrder::REVIEW_STATUSES, true) ? $ppic : '',
        ];
    }

    public function index(): void
    {
        $filters = $this->filters();
        $sort = Request::queryString('sort', 'date');
        $dir = Request::queryString('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->view('purchase_orders/index', [
            'title'     => 'Order Entry Form',
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
        $customerId = Request::queryInt('customer_id');
        $preset = [
            'po_date'       => today(),
            'customer_name' => $customerId ? Database::fetchValue('SELECT name FROM customers WHERE id = :id', ['id' => $customerId]) : null,
            'status'        => 'Open',
        ];
        $this->view('purchase_orders/form', $this->formData(null) + ['errors' => [], 'preset' => $preset, 'lines' => [[]]]);
    }

    public function store(): void
    {
        $v = $this->validateHeader(null);
        [$lines, $lineErrors] = $this->validateLines();
        $errors = array_merge($v->errors(), $lineErrors);
        if ($lines === [] && $lineErrors === []) {
            $errors['lines'] = 'Tambahkan minimal satu produk beserta qty.';
        }
        if ($errors !== []) {
            $this->invalid('purchase_orders/form', $this->formData(null) + ['preset' => [], 'lines' => $this->postedLines()], $errors, $this->oldHeader());
            return;
        }
        $d = $v->validated();
        $header = $this->headerData($d) + ['status' => 'Open', 'review_status' => 'Pending'];
        try {
            [$id, $created] = Database::transaction(function () use ($header, $d, $lines): array {
                $customer = MasterData::customer((string) $d['customer_name'], 'OEF', $this->picId());
                $header['customer_id'] = $customer['id'];
                $newProducts = 0;
                foreach ($lines as $i => $line) {
                    $product = MasterData::product($line['product_name'], 'OEF', $line['unit']);
                    $lines[$i]['product_id'] = $product['id'];
                    $newProducts += $product['created'] ? 1 : 0;
                }
                return [PurchaseOrder::createWithLines($header, $lines), ['customer' => $customer['created'], 'products' => $newProducts]];
            });
        } catch (DomainException $e) {
            $this->invalid('purchase_orders/form', $this->formData(null) + ['preset' => [], 'lines' => $this->postedLines()], ['customer_name' => $e->getMessage()], $this->oldHeader());
            return;
        }
        OefWorkflow::submitted($id);
        $msg = 'OEF ' . $header['order_number'] . ' tersimpan dan menunggu review PPIC.';
        if ($created['customer']) {
            $msg .= ' Customer baru "' . trim((string) $d['customer_name']) . '" otomatis ditambahkan ke menu Customer.';
        }
        if ($created['products'] > 0) {
            $msg .= ' ' . $created['products'] . ' produk baru otomatis ditambahkan ke menu Produk.';
        }
        $this->success($msg, '/purchase-orders/' . $id);
    }

    public function show(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $canEdit = Auth::can('purchase_orders.edit');
        $this->view('purchase_orders/show', [
            'title'       => 'OEF ' . PurchaseOrder::label($po),
            'po'          => $po,
            'lines'       => PurchaseOrder::lines($id),
            'deliveries'  => Delivery::forPo($id),
            'returns'     => ProductReturn::forPo($id),
            'leadtimes'   => Auth::can('leadtime.view') ? LeadTime::forPo($id) : [],
            'issues'      => Auth::can('migration.view') ? MigrationIssue::openForRecord('PURCHASE_ORDERS', $id) : [],
            'suggestions' => $canEdit ? MasterData::productSuggestions() : [],
            'canReview'   => Auth::can(OefWorkflow::REVIEW_PERMISSION) && $po['review_status'] === 'Pending' && $po['status'] !== 'Cancelled',
            'errors'      => [],
        ]);
    }

    public function edit(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $po['customer_name'] = $this->customerLabel($po);
        $this->view('purchase_orders/form', $this->formData($po) + ['errors' => [], 'preset' => [], 'lines' => []]);
    }

    public function update(int $id): void
    {
        $po = $this->found(PurchaseOrder::findFull($id));
        $v = $this->validateHeader($po);
        if ($v->fails()) {
            $po['customer_name'] = $this->customerLabel($po);
            $this->invalid('purchase_orders/form', $this->formData($po) + ['preset' => [], 'lines' => []], $v->errors(), $this->oldHeader());
            return;
        }
        $d = $v->validated();
        $data = $this->headerData($d) + ['status' => $d['status']];
        try {
            [$changes, $newCustomer] = Database::transaction(function () use ($id, $po, $d, $data): array {
                $newCustomer = false;
                if (MasterData::normalize((string) $d['customer_name']) === MasterData::normalize($this->customerLabel($po)) && $po['customer_id'] !== null) {
                    $data['customer_id'] = (int) $po['customer_id'];
                } else {
                    $customer = MasterData::customer((string) $d['customer_name'], 'OEF', $this->picId());
                    $data['customer_id'] = $customer['id'];
                    $newCustomer = $customer['created'];
                }
                $changes = PurchaseOrder::update($id, $data, $po);
                return [$changes, $newCustomer];
            });
        } catch (DomainException $e) {
            $po['customer_name'] = $this->customerLabel($po);
            $this->invalid('purchase_orders/form', $this->formData($po) + ['preset' => [], 'lines' => []], ['customer_name' => $e->getMessage()], $this->oldHeader());
            return;
        }
        $msg = 'OEF diperbarui.';
        $reviewed = array_values(array_intersect(array_keys($changes), PurchaseOrder::REVIEWED_FIELDS));
        if ($reviewed !== [] && OefWorkflow::changed($id, 'Isi OEF diubah')) {
            $msg .= ' OEF kembali menunggu review PPIC.';
        }
        if ($newCustomer) {
            $msg .= ' Customer baru "' . trim((string) $d['customer_name']) . '" otomatis ditambahkan ke menu Customer.';
        }
        $this->success($msg, '/purchase-orders/' . $id);
    }

    public function destroy(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        try {
            PurchaseOrder::deleteSafely($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $this->success('OEF ' . PurchaseOrder::label($po) . ' dihapus.', '/purchase-orders');
    }

    /** PPIC: "Bisa diproses" (tombol hijau). */
    public function approve(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $v = Validator::make($_POST, ['review_note' => 'nullable|string|max:1000'], ['review_note' => 'Catatan PPIC']);
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/purchase-orders/' . $id);
        }
        try {
            $r = OefWorkflow::approve($id, $v->validated()['review_note']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $msg = 'OEF ' . PurchaseOrder::label($po) . ' ditandai BISA DIPROSES.';
        if ($r['skipped'] !== null) {
            $msg .= ' ' . $r['skipped'];
        } else {
            $parts = array_filter([
                $r['created'] > 0 ? $r['created'] . ' jadwal delivery dibuat' : null,
                $r['updated'] > 0 ? $r['updated'] . ' jadwal disesuaikan' : null,
                $r['cancelled'] > 0 ? $r['cancelled'] . ' jadwal dibatalkan' : null,
            ]);
            $msg .= $parts !== [] ? ' ' . ucfirst(implode(', ', $parts)) . ' otomatis di menu Delivery (bisa diubah bila jadwal berubah).' : ' Jadwal delivery tidak berubah.';
        }
        $this->success($msg, '/purchase-orders/' . $id);
    }

    /** PPIC: "Tidak bisa diproses" (tombol merah, alasan wajib). */
    public function reject(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $reason = trim((string) ($_POST['review_note'] ?? ''));
        if (mb_strlen($reason) > 2000) {
            $this->failure('Alasan maksimal 2000 karakter.', '/purchase-orders/' . $id);
        }
        try {
            OefWorkflow::reject($id, $reason);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $id);
        }
        $this->success('OEF ' . PurchaseOrder::label($po) . ' ditandai TIDAK BISA DIPROSES. Pembuat OEF menerima notifikasi beserta alasannya.', '/purchase-orders/' . $id);
    }

    public function addLine(int $id): void
    {
        $po = $this->found(PurchaseOrder::find($id));
        $v = $this->validateLine($_POST);
        if ($v->fails()) {
            $this->failure('Produk gagal ditambahkan: ' . implode(' ', $v->errors()), '/purchase-orders/' . $id);
        }
        $data = $v->validated();
        try {
            $created = Database::transaction(function () use ($id, $data, $po): bool {
                $product = MasterData::product((string) $data['product_name'], 'OEF', $data['unit']);
                PoLine::create(['po_id' => $id, 'product_id' => $product['id'], 'order_qty' => $data['order_qty'], 'unit' => $data['unit'],
                    'item_description' => $data['item_description'], 'subcont_supplier' => $data['subcont_supplier']]);
                PurchaseOrder::syncStatus($id);
                PurchaseOrder::recalcTotals($id);
                return $product['created'];
            });
        } catch (DomainException $e) {
            $this->failure('Produk gagal ditambahkan: ' . $e->getMessage(), '/purchase-orders/' . $id);
        }
        $msg = 'Produk ditambahkan ke OEF ' . PurchaseOrder::label($po) . '.' . ($created ? ' Produk baru otomatis masuk menu Produk.' : '');
        if (OefWorkflow::changed($id, 'Produk ditambahkan: ' . trim((string) $data['product_name']))) {
            $msg .= ' OEF kembali menunggu review PPIC.';
        }
        $this->success($msg, '/purchase-orders/' . $id);
    }

    public function editLine(int $id): void
    {
        $line = $this->found(PoLine::findFull($id));
        $this->view('purchase_orders/line_form', [
            'title'       => 'Edit Produk OEF',
            'line'        => $line + ['product_name_typed' => MasterData::productLabelFor((int) $line['product_id'])],
            'suggestions' => MasterData::productSuggestions(),
            'errors'      => [],
            'locked'      => PoLine::dependents($id) > 0,
        ]);
    }

    public function updateLine(int $id): void
    {
        $line = $this->found(PoLine::findFull($id));
        $line['product_label'] = MasterData::productLabelFor((int) $line['product_id']);
        $locked = PoLine::dependents($id) > 0;
        $v = $this->validateLine($_POST);
        $data = $v->validated();
        $sameProduct = !$v->fails() && MasterData::normalize((string) $data['product_name']) === MasterData::normalize($line['product_label']);
        if (!$v->fails() && $locked && !$sameProduct) {
            $v->addError('product_name', 'Produk tidak dapat diganti karena baris ini sudah memiliki delivery/retur.');
        }
        if ($v->fails()) {
            $this->invalid('purchase_orders/line_form', [
                'title' => 'Edit Produk OEF', 'line' => $line + ['product_name_typed' => $line['product_label']], 'suggestions' => MasterData::productSuggestions(), 'locked' => $locked,
            ], $v->errors(), $this->postedLine($_POST));
            return;
        }
        try {
            [$changes, $created] = Database::transaction(function () use ($id, $data, $line, $sameProduct): array {
                $productId = (int) $line['product_id'];
                $created = false;
                if (!$sameProduct) {
                    $product = MasterData::product((string) $data['product_name'], 'OEF', $data['unit']);
                    $productId = $product['id'];
                    $created = $product['created'];
                }
                $update = ['product_id' => $productId, 'order_qty' => $data['order_qty'], 'unit' => $data['unit'],
                    'item_description' => $data['item_description'], 'subcont_supplier' => $data['subcont_supplier']];
                if ($line['unit_price'] !== null) {
                    // nilai baris dari dokumen PO (arsip) mengikuti qty baru
                    $update['line_subtotal'] = PoLine::subtotalFor((int) $data['order_qty'], (string) $line['unit_price'], (bool) $line['price_includes_tax'],
                        $line['tax_rate'] !== null ? (string) $line['tax_rate'] : null);
                }
                $changes = PoLine::update($id, $update);
                PurchaseOrder::syncStatus((int) $line['po_id']);
                PurchaseOrder::recalcTotals((int) $line['po_id']);
                return [$changes, $created];
            });
        } catch (DomainException $e) {
            $this->invalid('purchase_orders/line_form', [
                'title' => 'Edit Produk OEF', 'line' => $line + ['product_name_typed' => $line['product_label']], 'suggestions' => MasterData::productSuggestions(), 'locked' => $locked,
            ], ['product_name' => $e->getMessage()], $this->postedLine($_POST));
            return;
        }
        $msg = 'Produk OEF diperbarui.' . ($created ? ' Produk baru otomatis masuk menu Produk.' : '');
        if (array_intersect(array_keys($changes), ['product_id', 'order_qty', 'unit', 'item_description', 'subcont_supplier']) !== []
            && OefWorkflow::changed((int) $line['po_id'], 'Produk diubah: ' . trim((string) $data['product_name']))) {
            $msg .= ' OEF kembali menunggu review PPIC.';
        }
        $this->success($msg, '/purchase-orders/' . $line['po_id']);
    }

    public function destroyLine(int $id): void
    {
        $line = $this->found(PoLine::findFull($id));
        try {
            PoLine::removeLine($id);
            PurchaseOrder::recalcTotals((int) $line['po_id']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/purchase-orders/' . $line['po_id']);
        }
        $msg = 'Produk dihapus dari OEF.';
        if (OefWorkflow::changed((int) $line['po_id'], 'Produk dihapus: ' . $line['product_name'])) {
            $msg .= ' OEF kembali menunggu review PPIC.';
        }
        $this->success($msg, '/purchase-orders/' . $line['po_id']);
    }

    /**
     * Validasi header OEF. Data PO lama (sebelum OEF, tanpa No order) boleh
     * tetap tanpa No order, nama sales, tanggal kirim, dan tujuan kirim.
     */
    private function validateHeader(?array $po): Validator
    {
        $legacy = $po !== null && trim((string) $po['order_number']) === '';
        $req = $legacy ? 'nullable' : 'required';
        $rules = [
            'order_number'            => $req . '|string|max:60',
            'po_date'                 => 'required|date',
            'sales_name'              => $req . '|string|max:120',
            'customer_name'           => 'required|string|max:190',
            'po_number'               => 'nullable|string|max:80',
            'requested_delivery_date' => $req . '|date',
            'delivery_address'        => $req . '|string|max:2000',
            'remark'                  => 'nullable|string|max:5000',
        ];
        if ($po !== null) {
            $rules['status'] = ['required', ['in', PurchaseOrder::STATUSES]];
        }
        $v = Validator::make($_POST, $rules, [
            'order_number' => 'No order', 'po_date' => 'Tanggal order', 'sales_name' => 'Nama sales', 'customer_name' => 'Nama customer',
            'po_number' => 'No PO dari customer', 'requested_delivery_date' => 'Permintaan selesai/kirim', 'delivery_address' => 'Tujuan kirim',
            'remark' => 'Keterangan', 'status' => 'Status',
        ]);
        if ($v->fails()) {
            return $v;
        }
        $d = $v->validated();
        if ($d['order_number'] !== null && ($taken = PurchaseOrder::orderNumberTaken((string) $d['order_number'], $po['id'] ?? null)) !== null) {
            $v->addError('order_number', 'No order sudah dipakai (' . $taken['order_number'] . ($taken['customer_name'] ? ', ' . $taken['customer_name'] : '') . ').');
        }
        if ($d['po_number'] !== null) {
            $changed = $po === null || mb_strtolower(trim((string) $po['po_number'])) !== mb_strtolower(trim((string) $d['po_number']));
            if ($changed && ($taken = PurchaseOrder::numberTaken((string) $d['po_number'], $po['id'] ?? null)) !== null) {
                $v->addError('po_number', 'No PO customer sudah dipakai di OEF lain (' . $taken['code'] . ($taken['customer_name'] ? ', ' . $taken['customer_name'] : '') . ').');
            }
        }
        if ($po === null && $d['requested_delivery_date'] !== null && $d['requested_delivery_date'] < $d['po_date']) {
            $v->addError('requested_delivery_date', 'Permintaan selesai/kirim tidak boleh sebelum tanggal order.');
        }
        $sameCustomer = $po !== null && $po['customer_id'] !== null && MasterData::normalize((string) $d['customer_name']) === MasterData::normalize($this->customerLabel($po));
        if (!$sameCustomer && ($err = MasterData::findCustomer((string) $d['customer_name'])['error']) !== null) {
            $v->addError('customer_name', $err);
        }
        return $v;
    }

    private function validateLine(array $input): Validator
    {
        $v = Validator::make($input, [
            'product_name'     => 'required|string|max:190',
            'item_description' => 'nullable|string|max:2000',
            'order_qty'        => 'required|integer|min:1',
            'unit'             => 'nullable|string|max:20',
            'subcont_supplier' => 'nullable|string|max:150',
        ], ['product_name' => 'Nama produk', 'item_description' => 'Spesifikasi produk', 'order_qty' => 'Qty', 'unit' => 'Satuan', 'subcont_supplier' => 'Supplier subcont']);
        if (!$v->fails() && ($err = MasterData::findProduct((string) $v->validated()['product_name'])['error']) !== null) {
            $v->addError('product_name', $err);
        }
        return $v;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function headerData(array $d): array
    {
        return [
            'order_number'            => $d['order_number'] !== null ? trim((string) $d['order_number']) : null,
            'po_date'                 => $d['po_date'],
            'sales_name'              => $d['sales_name'] !== null ? trim((string) preg_replace('/\s+/u', ' ', (string) $d['sales_name'])) : null,
            'po_number'               => $d['po_number'] !== null ? trim((string) $d['po_number']) : null,
            'requested_delivery_date' => $d['requested_delivery_date'],
            'delivery_address'        => $d['delivery_address'],
            'remark'                  => $d['remark'],
        ];
    }

    /**
     * Validasi baris produk dari form (lines[i][product_name] dst.). Baris kosong diabaikan.
     * @return array{0:list<array<string,mixed>>,1:array<string,string>}
     */
    private function validateLines(): array
    {
        $raw = $_POST['lines'] ?? [];
        if (!is_array($raw)) {
            return [[], ['lines' => 'Format baris produk tidak valid.']];
        }
        if (count($raw) > 200) {
            return [[], ['lines' => 'Maksimal 200 produk per OEF.']];
        }
        $lines = [];
        $errors = [];
        foreach (array_values($raw) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach (self::LINE_FIELDS as $f) {
                $values[$f] = trim((string) ($row[$f] ?? ''));
            }
            if (implode('', $values) === '') {
                continue;
            }
            $v = $this->validateLine($values);
            if ($v->fails()) {
                foreach ($v->errors() as $field => $msg) {
                    $errors["lines.{$i}.{$field}"] = 'Produk ' . ($i + 1) . ': ' . $msg;
                }
                continue;
            }
            $d = $v->validated();
            $lines[] = ['product_name' => (string) $d['product_name'], 'item_description' => $d['item_description'], 'order_qty' => (int) $d['order_qty'],
                'unit' => $d['unit'], 'subcont_supplier' => $d['subcont_supplier'], 'remark' => null];
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
                    $out[] = $this->postedLine($row);
                }
            }
        }
        return $out !== [] ? $out : [[]];
    }

    /** @return array<string,string> */
    private function postedLine(array $row): array
    {
        $out = [];
        foreach (self::LINE_FIELDS as $f) {
            $out[$f] = is_scalar($row[$f] ?? null) ? (string) $row[$f] : '';
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function oldHeader(): array
    {
        $old = [];
        foreach (self::HEADER_FIELDS as $f) {
            $old[$f] = is_scalar($_POST[$f] ?? null) ? (string) $_POST[$f] : '';
        }
        return $old;
    }

    /** Nama customer seperti di daftar saran (nama + kode bila ada nama kembar). */
    private function customerLabel(array $po): string
    {
        if ($po['customer_id'] === null) {
            return '';
        }
        $c = Database::fetch('SELECT code, name FROM customers WHERE id = :id', ['id' => $po['customer_id']]);
        if ($c === null) {
            return '';
        }
        $twins = (int) Database::fetchValue('SELECT COUNT(*) FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n))', ['n' => $c['name']]);
        return $twins > 1 ? $c['name'] . ' (' . $c['code'] . ')' : (string) $c['name'];
    }

    /** PIC marketing untuk customer baru: user yang menginput bila ia Marketing/Sales. */
    private function picId(): ?int
    {
        $user = Auth::user();
        return $user !== null && in_array($user['role'], ['Marketing', 'Sales'], true) ? (int) $user['id'] : null;
    }

    /** @return array<string,mixed> */
    private function formData(?array $po): array
    {
        return [
            'title'       => $po ? 'Edit OEF' : 'Buat Order Entry Form',
            'po'          => $po,
            'customers'   => MasterData::customerSuggestions(),
            'sales'       => MasterData::salesSuggestions(),
            'suggestions' => $po === null ? MasterData::productSuggestions() : [],
        ];
    }
}
