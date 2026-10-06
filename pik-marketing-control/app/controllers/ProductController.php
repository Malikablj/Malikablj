<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\InboundMaklon;
use App\Models\LeadTime;
use App\Models\Product;
use App\Models\Stock;
use DomainException;

final class ProductController extends Controller
{
    private const FIELDS = ['name', 'product_code', 'variant', 'category', 'unit', 'is_active', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'        => Request::queryString('q'),
            'category' => Request::queryString('category'),
            'status'   => Request::queryString('status'),
            'open'     => Request::queryString('open'),
        ];
        $sort = Request::queryString('sort', 'name');
        $dir = Request::queryString('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $this->view('products/index', [
            'title'      => 'Products',
            'products'   => Product::paginate($filters, $sort, $dir, $this->page()),
            'summary'    => Product::summary($filters),
            'filters'    => $filters,
            'sort'       => $sort,
            'dir'        => $dir,
            'categories' => Product::groups(),
            'showStock'  => Auth::can('stock.view'),
        ]);
    }

    public function create(): void
    {
        $this->view('products/form', $this->formData(null) + ['errors' => []]);
    }

    public function store(): void
    {
        $v = $this->validate(null);
        if ($v->fails()) {
            $this->invalid('products/form', $this->formData(null), $v->errors(), $this->old());
            return;
        }
        $id = Product::create($v->validated());
        $this->success('Produk berhasil ditambahkan.', '/products/' . $id);
    }

    public function show(int $id): void
    {
        $product = $this->found(Product::findFull($id));
        $this->view('products/show', [
            'title'      => $product['name'],
            'product'    => $product,
            'lines'      => Auth::can('purchase_orders.view') ? Product::poLines($id) : [],
            'stock'      => Auth::can('stock.view') ? Stock::forProduct($id) : [],
            'deliveries' => Auth::can('deliveries.view') ? Database::fetchAll(
                'SELECT d.id, d.code, d.sj_number, d.delivery_date, d.status, d.delivered_qty, p.po_number, p.code AS po_code, d.po_id, c.name AS customer_name
                 FROM deliveries d LEFT JOIN purchase_orders p ON p.id = d.po_id LEFT JOIN customers c ON c.id = p.customer_id
                 WHERE d.product_id = :id ORDER BY d.delivery_date DESC, d.id DESC LIMIT 10',
                ['id' => $id]
            ) : [],
            'leadtimes'  => Auth::can('leadtime.view') ? LeadTime::forProduct($id) : [],
            'inbound'    => Auth::can('inbound.view') ? InboundMaklon::forProduct($id, 10) : [],
            'history'    => Auth::can('audit.view') ? AuditLog::forEntity('product', $id, 5) : [],
        ]);
    }

    public function edit(int $id): void
    {
        $product = $this->found(Product::find($id));
        $this->view('products/form', $this->formData($product) + ['errors' => []]);
    }

    public function update(int $id): void
    {
        $product = $this->found(Product::find($id));
        $v = $this->validate($id);
        if ($v->fails()) {
            $this->invalid('products/form', $this->formData($product), $v->errors(), $this->old());
            return;
        }
        Product::update($id, $v->validated(), $product);
        $this->success('Perubahan produk disimpan.', '/products/' . $id);
    }

    public function destroy(int $id): void
    {
        $product = $this->found(Product::find($id));
        try {
            Product::deleteSafely($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/products/' . $id);
        }
        $this->success('Produk "' . $product['name'] . '" dihapus.', '/products');
    }

    private function validate(?int $id): Validator
    {
        $v = Validator::make($_POST, [
            'name'             => 'required|string|max:190',
            'product_code'     => 'nullable|string|max:60',
            'variant'          => 'nullable|string|max:255',
            'category'         => 'nullable|string|max:100',
            'unit'             => 'required|string|max:20',
            'is_active'        => 'boolean',
            'notes'            => 'nullable|string|max:5000',
        ], [
            'name' => 'Nama produk', 'product_code' => 'Kode produk', 'variant' => 'Varian', 'category' => 'Kategori',
            'unit' => 'Satuan', 'is_active' => 'Status aktif', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            $dup = Product::duplicateOf((string) $d['name'], $d['variant'], $d['product_code'], $id);
            if ($dup !== null) {
                $v->addError('name', 'Produk dengan nama, varian, dan kode yang sama sudah ada (' . $dup['code'] . ').');
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
    private function formData(?array $product): array
    {
        return [
            'title'      => $product ? 'Edit ' . $product['name'] : 'Tambah Produk',
            'product'    => $product,
            'categories' => Product::groups(),
            'units'      => Product::units(),
        ];
    }
}
