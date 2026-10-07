<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\MigrationIssue;
use App\Models\Product;
use App\Models\Stock;
use DomainException;

final class StockController extends Controller
{
    private const FIELDS = ['product_name', 'stock_type', 'box', 'qty_per_box', 'quantity', 'status', 'notes', 'confirm_qty_mismatch'];

    public function index(): void
    {
        $filters = [
            'q'          => Request::queryString('q'),
            'type'       => Request::queryString('type'),
            'link'       => Request::queryString('link'),
            'category'   => Request::queryString('category'),
            'product_id' => Request::queryInt('product_id'),
        ];
        $mode = Request::queryString('view', 'product') === 'entries' || $filters['link'] === 'unlinked' || $filters['product_id'] > 0 ? 'entries' : 'product';
        $this->view('stock/index', [
            'title'      => 'Stock',
            'mode'       => $mode,
            'rows'       => $mode === 'entries' ? Stock::paginate($filters, $this->page()) : Stock::paginateByProduct($filters, $this->page()),
            'summary'    => Stock::summary($filters),
            'filters'    => $filters,
            'categories' => Stock::categories(),
            'group'      => $filters['product_id'] > 0 ? Database::fetch('SELECT id, name, code FROM products WHERE id = :id', ['id' => $filters['product_id']]) : null,
            'canProduct' => Auth::can('products.view'),
        ]);
    }

    public function create(): void
    {
        $productId = Request::queryInt('product_id') ?: null;
        $preset = [
            'product_name' => $productId ? (string) Database::fetchValue('SELECT name FROM products WHERE id = :id', ['id' => $productId]) : '',
            'stock_type'   => 'FG',
        ];
        $this->view('stock/form', $this->formData(null) + ['errors' => [], 'preset' => $preset, 'askConfirm' => false]);
    }

    public function store(): void
    {
        [$v, $data, $askConfirm] = $this->validate(false);
        if ($v->fails()) {
            $this->invalid('stock/form', $this->formData(null) + ['preset' => [], 'askConfirm' => $askConfirm], $v->errors(), $this->old());
            return;
        }
        [$data, $name] = $this->splitProduct($data);
        $saved = Stock::saveStock(null, $data, $name);
        $this->success($this->savedMessage('Entri stok tersimpan', $name, $saved['product_created']), $this->returnTo('/stock?product_id=' . $saved['product_id']));
    }

    public function edit(int $id): void
    {
        $row = $this->found(Stock::findFull($id));
        $this->view('stock/form', $this->formData($row) + ['errors' => [], 'preset' => [], 'askConfirm' => false]);
    }

    public function update(int $id): void
    {
        $row = $this->found(Stock::findFull($id));
        [$v, $data, $askConfirm] = $this->validate($row['product_id'] === null);
        if ($v->fails()) {
            $this->invalid('stock/form', $this->formData($row) + ['preset' => [], 'askConfirm' => $askConfirm], $v->errors(), $this->old());
            return;
        }
        [$data, $name] = $this->splitProduct($data);
        try {
            $saved = Stock::saveStock($id, $data, $name);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/stock/' . $id . '/edit');
        }
        $this->success($this->savedMessage('Entri stok diperbarui', $name, $saved['product_created']), $this->returnTo('/stock?view=entries'));
    }

    public function destroy(int $id): void
    {
        $row = $this->found(Stock::find($id));
        try {
            Stock::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/stock/' . $id . '/edit');
        }
        $this->success('Entri stok dihapus.', $this->returnTo($row['product_id'] ? '/stock?product_id=' . $row['product_id'] : '/stock?view=entries'));
    }

    /**
     * Validasi + aturan qty:
     *  - Box dan Qty/box diisi berpasangan.
     *  - Qty kosong → dihitung dari Box × Qty/box.
     *  - Qty berbeda dari Box × Qty/box → wajib dikonfirmasi (mis. ada sisa di luar box).
     * @return array{0:Validator,1:array<string,mixed>,2:bool}
     */
    private function validate(bool $legacyUnlinked): array
    {
        $v = Validator::make($_POST, [
            'product_name' => ($legacyUnlinked ? 'nullable' : 'required') . '|string|max:190',
            'stock_type'  => ['required', ['in', Stock::TYPES]],
            'box'         => 'nullable|integer|min:0',
            'qty_per_box' => 'nullable|integer|min:1',
            'quantity'    => 'nullable|integer|min:0',
            'status'      => 'nullable|string|max:40',
            'notes'       => 'nullable|string|max:2000',
        ], [
            'product_name' => 'Nama produk', 'stock_type' => 'Tipe stok', 'box' => 'Jumlah box', 'qty_per_box' => 'Qty per box',
            'quantity' => 'Qty', 'status' => 'Status', 'notes' => 'Catatan',
        ]);
        $data = $v->validated();
        $askConfirm = false;
        if (!$v->fails()) {
            $box = $data['box'];
            $perBox = $data['qty_per_box'];
            if ($box !== null && $perBox === null) {
                $v->addError('qty_per_box', 'Isi juga Qty per box.');
            } elseif ($box === null && $perBox !== null) {
                $v->addError('box', 'Isi juga jumlah box.');
            }
            $computed = Stock::boxQuantity($box, $perBox);
            if ($computed !== null && $computed > 2147483647) {
                $v->addError('box', 'Box × Qty per box terlalu besar.');
                $computed = null;
            }
            if ($data['quantity'] === null) {
                if ($computed !== null) {
                    $data['quantity'] = $computed;
                } elseif (!$v->fails()) {
                    $v->addError('quantity', 'Qty wajib diisi (atau isi Jumlah box dan Qty per box).');
                }
            } elseif ($computed !== null && $data['quantity'] !== $computed && ($_POST['confirm_qty_mismatch'] ?? '') !== '1') {
                $askConfirm = true;
                $v->addError('quantity', 'Qty (' . fmt_qty($data['quantity']) . ') berbeda dengan Box × Qty per box (' . fmt_qty($computed) . '). Periksa kembali, atau centang konfirmasi bila memang ada sisa di luar box.');
            }
        }
        return [$v, $data, $askConfirm];
    }

    /**
     * Pisahkan nama produk (diketik manual) dari kolom stok.
     * @param array<string,mixed> $data
     * @return array{0:array<string,mixed>,1:?string}
     */
    private function splitProduct(array $data): array
    {
        $name = $data['product_name'] ?? null;
        unset($data['product_name']);
        return [$data, $name !== null ? (string) $name : null];
    }

    private function savedMessage(string $base, ?string $name, bool $created): string
    {
        if ($name === null) {
            return $base . '.';
        }
        return $base . ' di kelompok produk "' . $name . '".' . ($created ? ' Produk baru dicatat otomatis.' : '');
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
        $types = [];
        foreach (Stock::TYPES as $t) {
            $types[$t] = Stock::TYPE_LABELS[$t];
        }
        return [
            'title'    => $row ? 'Edit Stok' : 'Catat Stok',
            'row'      => $row,
            'productNames' => Product::nameSuggestions(),
            'types'    => $types,
            'statuses' => Stock::statuses(),
            'issues'   => $row && Auth::can('migration.view') ? MigrationIssue::openForRecord('STOCK', (int) $row['id']) : [],
            'return'   => $this->returnTo(''),
        ];
    }
}
