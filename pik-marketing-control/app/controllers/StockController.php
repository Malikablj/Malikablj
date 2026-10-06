<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\MigrationIssue;
use App\Helpers\Database;
use App\Models\Product;
use App\Models\Stock;
use App\Services\MasterData;
use DomainException;

final class StockController extends Controller
{
    private const FIELDS = ['product_name', 'stock_type', 'box', 'qty_per_box', 'quantity', 'status', 'notes', 'confirm_qty_mismatch'];

    public function index(): void
    {
        $filters = [
            'q'     => Request::queryString('q'),
            'type'  => Request::queryString('type'),
            'link'  => Request::queryString('link'),
            'group' => Request::queryString('group'),
        ];
        $mode = Request::queryString('view', 'product') === 'entries' || $filters['link'] === 'unlinked' ? 'entries' : 'product';
        $this->view('stock/index', [
            'title'      => 'Stock',
            'mode'       => $mode,
            'rows'       => $mode === 'entries' ? Stock::paginate($filters, $this->page()) : Stock::paginateByProduct($filters, $this->page()),
            'groups'     => $mode === 'product' ? Stock::groupTotals($filters) : [],
            'groupList'  => Product::groups(),
            'summary'    => Stock::summary($filters),
            'filters'    => $filters,
            'canProduct' => Auth::can('products.view'),
        ]);
    }

    public function create(): void
    {
        $productId = Request::queryInt('product_id') ?: null;
        $preset = ['product_name' => $productId ? MasterData::productLabelFor($productId) : null, 'stock_type' => 'FG'];
        $this->view('stock/form', $this->formData(null) + ['errors' => [], 'preset' => $preset, 'askConfirm' => false]);
    }

    public function store(): void
    {
        [$v, $data, $askConfirm] = $this->validate(null);
        if ($v->fails()) {
            $this->invalid('stock/form', $this->formData(null) + ['preset' => [], 'askConfirm' => $askConfirm], $v->errors(), $this->old());
            return;
        }
        $created = false;
        try {
            Database::transaction(function () use (&$data, &$created): void {
                $product = MasterData::product((string) $data['product_name'], 'Stok');
                $created = $product['created'];
                $data['product_id'] = $product['id'];
                unset($data['product_name']);
                Stock::saveStock(null, $data);
            });
        } catch (DomainException $e) {
            $this->invalid('stock/form', $this->formData(null) + ['preset' => [], 'askConfirm' => false], ['product_name' => $e->getMessage()], $this->old());
            return;
        }
        $msg = 'Entri stok tersimpan.' . ($created ? ' Produk baru "' . trim((string) $_POST['product_name']) . '" otomatis ditambahkan dan dikelompokkan.' : '');
        $this->success($msg, $this->returnTo('/stock'));
    }

    public function edit(int $id): void
    {
        $row = $this->found(Stock::findFull($id));
        $row['product_name'] = $row['product_id'] !== null ? MasterData::productLabelFor((int) $row['product_id']) : null;
        $this->view('stock/form', $this->formData($row) + ['errors' => [], 'preset' => [], 'askConfirm' => false]);
    }

    public function update(int $id): void
    {
        $row = $this->found(Stock::findFull($id));
        $row['product_name'] = $row['product_id'] !== null ? MasterData::productLabelFor((int) $row['product_id']) : null;
        [$v, $data, $askConfirm] = $this->validate($row);
        if ($v->fails()) {
            $this->invalid('stock/form', $this->formData($row) + ['preset' => [], 'askConfirm' => $askConfirm], $v->errors(), $this->old());
            return;
        }
        $created = false;
        try {
            Database::transaction(function () use (&$data, &$created, $row, $id): void {
                $typed = (string) ($data['product_name'] ?? '');
                if ($typed === '') {
                    $data['product_id'] = null; // legacy yang belum dihubungkan
                } elseif ($row['product_id'] !== null && MasterData::normalize($typed) === MasterData::normalize((string) $row['product_name'])) {
                    $data['product_id'] = (int) $row['product_id'];
                } else {
                    $product = MasterData::product($typed, 'Stok');
                    $created = $product['created'];
                    $data['product_id'] = $product['id'];
                }
                unset($data['product_name']);
                Stock::saveStock($id, $data);
            });
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/stock/' . $id . '/edit');
        }
        $this->success('Entri stok diperbarui.' . ($created ? ' Produk baru otomatis ditambahkan dan dikelompokkan.' : ''), $this->returnTo('/stock?view=entries'));
    }

    public function destroy(int $id): void
    {
        $row = $this->found(Stock::find($id));
        try {
            Stock::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/stock/' . $id . '/edit');
        }
        $this->success('Entri stok dihapus.', $this->returnTo($row['product_id'] && Auth::can('products.view') ? '/products/' . $row['product_id'] : '/stock?view=entries'));
    }

    /**
     * Validasi + aturan qty:
     *  - Box dan Qty/box diisi berpasangan.
     *  - Qty kosong → dihitung dari Box × Qty/box.
     *  - Qty berbeda dari Box × Qty/box → wajib dikonfirmasi (mis. ada sisa di luar box).
     * @return array{0:Validator,1:array<string,mixed>,2:bool}
     */
    private function validate(?array $row): array
    {
        $legacyUnlinked = $row !== null && $row['product_id'] === null;
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
        if (!$v->fails() && $data['product_name'] !== null) {
            $same = $row !== null && $row['product_id'] !== null && MasterData::normalize((string) $data['product_name']) === MasterData::normalize((string) $row['product_name']);
            if (!$same && ($err = MasterData::findProduct((string) $data['product_name'])['error']) !== null) {
                $v->addError('product_name', $err);
            }
        }
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
            'products' => MasterData::productSuggestions(),
            'types'    => $types,
            'statuses' => Stock::statuses(),
            'issues'   => $row && Auth::can('migration.view') ? MigrationIssue::openForRecord('STOCK', (int) $row['id']) : [],
            'return'   => $this->returnTo(''),
        ];
    }
}
