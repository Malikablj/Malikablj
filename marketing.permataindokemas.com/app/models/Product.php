<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class Product extends Model
{
    public const TABLE = 'products';
    public const ENTITY = 'product';
    public const LABEL = 'name';

    public const UNITS = ['pcs', 'set', 'box', 'roll', 'lembar', 'kg', 'liter'];

    private const SORTS = [
        'name'        => 'pr.name',
        'code'        => 'pr.product_code',
        'category'    => 'pr.category',
        'qty'         => 'pr.qty',
        'outstanding' => 'po.open_outstanding',
        'created'     => 'pr.created_at',
    ];

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(pr.name LIKE :q1 OR pr.code LIKE :q2 OR pr.product_code LIKE :q3 OR pr.variant LIKE :q4 OR pr.category LIKE :q5)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['category'])) {
            $where[] = 'pr.category = :cat';
            $params['cat'] = (string) $f['category'];
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'active') {
            $where[] = 'pr.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'pr.is_active = 0';
        }
        if (($f['open'] ?? '') === '1') {
            $where[] = 'COALESCE(po.open_outstanding, 0) > 0';
        }
        return [implode(' AND ', $where), $params];
    }

    /** Derived table: jumlah baris PO & outstanding PO terbuka per produk. */
    private static function poSql(): string
    {
        return "SELECT t.product_id, COUNT(*) AS line_count,
                       SUM(CASE WHEN p.status IN ('Open','On Process','Partial') THEN GREATEST(t.outstanding_qty, 0) ELSE 0 END) AS open_outstanding,
                       SUM(t.delivered_qty) AS delivered_qty, SUM(t.order_qty) AS ordered_qty
                FROM (" . PoLine::totalsSql() . ') t JOIN purchase_orders p ON p.id = t.po_id
                GROUP BY t.product_id';
    }

    public static function paginate(array $f, string $sort, string $dir, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        $order = (self::SORTS[$sort] ?? 'pr.name') . ($dir === 'desc' ? ' DESC' : ' ASC') . ', pr.id ASC';
        return Paginator::query(
            'SELECT pr.*, COALESCE(po.line_count, 0) AS line_count, COALESCE(po.open_outstanding, 0) AS open_outstanding,
                    COALESCE(st.fg, 0) AS stock_fg, COALESCE(st.wip, 0) AS stock_wip, COALESCE(st.ready, 0) AS stock_ready,
                    COALESCE(st.reserved, 0) AS stock_reserved, COALESCE(st.entries, 0) AS stock_entries
             FROM products pr
             LEFT JOIN (' . self::poSql() . ') po ON po.product_id = pr.id
             LEFT JOIN (' . Stock::byProductSql() . ') st ON st.product_id = pr.id
             WHERE ' . $where,
            $params,
            $order,
            $page
        );
    }

    /** @return array<string,mixed> ringkasan untuk filter aktif */
    public static function summary(array $f): array
    {
        [$where, $params] = self::filters($f);
        return Database::fetch(
            'SELECT COUNT(*) AS n, COALESCE(SUM(pr.is_active = 1), 0) AS active,
                    COALESCE(SUM(COALESCE(po.open_outstanding, 0) > 0), 0) AS with_open_po
             FROM products pr LEFT JOIN (' . self::poSql() . ') po ON po.product_id = pr.id WHERE ' . $where,
            $params
        ) ?? [];
    }

    /** @return array<string,mixed>|null produk + ringkasan PO & stok */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT pr.*, cu.name AS created_by_name, uu.name AS updated_by_name,
                    COALESCE(po.line_count, 0) AS line_count, COALESCE(po.open_outstanding, 0) AS open_outstanding,
                    COALESCE(po.delivered_qty, 0) AS delivered_qty, COALESCE(po.ordered_qty, 0) AS ordered_qty,
                    COALESCE(st.fg, 0) AS stock_fg, COALESCE(st.wip, 0) AS stock_wip, COALESCE(st.ready, 0) AS stock_ready,
                    COALESCE(st.reserved, 0) AS stock_reserved, COALESCE(st.entries, 0) AS stock_entries
             FROM products pr
             LEFT JOIN users cu ON cu.id = pr.created_by
             LEFT JOIN users uu ON uu.id = pr.updated_by
             LEFT JOIN (' . self::poSql() . ') po ON po.product_id = pr.id
             LEFT JOIN (' . Stock::byProductSql() . ') st ON st.product_id = pr.id
             WHERE pr.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array<string,mixed>> baris PO untuk produk ini (PO terbuka lebih dulu) */
    public static function poLines(int $id, int $limit = 50): array
    {
        return Database::fetchAll(
            "SELECT pl.id, pl.code, pl.po_id, COALESCE(p.order_number, p.po_number) AS po_number, p.code AS po_code, p.po_date, p.status AS po_status,
                    c.name AS customer_name, p.customer_id, t.order_qty, t.delivered_qty, t.return_qty, t.outstanding_qty
             FROM po_lines pl
             JOIN purchase_orders p ON p.id = pl.po_id
             LEFT JOIN customers c ON c.id = p.customer_id
             JOIN (" . PoLine::totalsSql() . ") t ON t.line_id = pl.id
             WHERE pl.product_id = :id
             ORDER BY p.status IN ('Open','On Process','Partial') DESC, p.po_date DESC, pl.id DESC
             LIMIT " . max(1, $limit),
            ['id' => $id]
        );
    }

    /**
     * Data lain yang merujuk produk ini.
     * @return array<string,int>
     */
    public static function dependents(int $id): array
    {
        $row = Database::fetch(
            'SELECT (SELECT COUNT(*) FROM po_lines WHERE product_id = :a) AS po_lines,
                    (SELECT COUNT(*) FROM stock WHERE product_id = :b) AS stock,
                    (SELECT COUNT(*) FROM deliveries WHERE product_id = :c) AS deliveries,
                    (SELECT COUNT(*) FROM returns WHERE product_id = :d) AS returns,
                    (SELECT COUNT(*) FROM leadtime WHERE product_id = :e) AS leadtime,
                    (SELECT COUNT(*) FROM inbound_maklon WHERE product_id = :f) AS inbound',
            ['a' => $id, 'b' => $id, 'c' => $id, 'd' => $id, 'e' => $id, 'f' => $id]
        ) ?? [];
        return array_filter(array_map('intval', $row));
    }

    public static function deleteSafely(int $id): void
    {
        $product = self::find($id);
        if ($product === null) {
            throw new DomainException('Produk tidak ditemukan.');
        }
        $deps = self::dependents($id);
        if ($deps !== []) {
            $labels = ['po_lines' => 'baris PO', 'stock' => 'data stok', 'deliveries' => 'delivery', 'returns' => 'retur', 'leadtime' => 'lead time', 'inbound' => 'inbound maklon'];
            $parts = [];
            foreach ($deps as $k => $n) {
                $parts[] = $n . ' ' . $labels[$k];
            }
            throw new DomainException('Produk tidak dapat dihapus karena masih dipakai oleh ' . implode(', ', $parts) . '. Nonaktifkan produk bila tidak dijual lagi.');
        }
        self::delete($id, $product);
    }

    /**
     * Produk lain dengan nama + varian + kode produk yang sama persis
     * (tidak peka huruf besar/kecil & spasi di awal/akhir).
     * @return array<string,mixed>|null
     */
    public static function duplicateOf(string $name, ?string $variant, ?string $productCode, ?int $exceptId = null): ?array
    {
        return Database::fetch(
            "SELECT id, code, name FROM products
             WHERE LOWER(TRIM(name)) = LOWER(TRIM(:n))
               AND LOWER(TRIM(COALESCE(variant, ''))) = LOWER(TRIM(:v))
               AND LOWER(TRIM(COALESCE(product_code, ''))) = LOWER(TRIM(:c))
               AND id <> :ex
             LIMIT 1",
            ['n' => $name, 'v' => $variant ?? '', 'c' => $productCode ?? '', 'ex' => $exceptId ?? 0]
        );
    }

    /** Sumber produk yang dicatat otomatis (kolom products.source). */
    public const SOURCE_LABELS = [
        'OEF'  => 'Dicatat otomatis dari Order Entry Form',
        'Stok' => 'Dicatat otomatis dari input Stock (Produksi/Gudang)',
    ];

    /** Rapikan nama produk yang diketik manual: spasi ganda & spasi di awal/akhir dihapus. */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Cari produk dengan nama yang sama persis (tidak peka huruf besar/kecil & spasi).
     * Produk aktif dan tanpa varian didahulukan.
     * @return array<string,mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return null;
        }
        return Database::fetch(
            'SELECT id, name, spec, qty, is_active FROM products WHERE LOWER(TRIM(name)) = LOWER(:n)
             ORDER BY is_active DESC, (variant IS NULL OR variant = \'\') DESC, id ASC LIMIT 1',
            ['n' => $name]
        );
    }

    /**
     * Produk untuk Order Entry Form: nama produk diketik manual. Bila sudah ada
     * produk dengan nama yang sama persis (tidak peka huruf besar/kecil), produk
     * itu dipakai; bila belum, produk baru dicatat otomatis di menu Products.
     * Spesifikasi & qty OEF terakhir disimpan di produk sebagai arsip.
     * @return array{id:int,created:bool}
     */
    public static function findOrCreateForOrder(string $name, ?string $spec, ?string $orderLabel = null, ?int $qty = null): array
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new DomainException('Nama produk wajib diisi.');
        }
        $existing = self::findByName($name);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $changes = [];
            if ($spec !== null && $spec !== '' && (string) $existing['spec'] !== $spec) {
                $changes['spec'] = $spec;
            }
            if ($qty !== null && $qty >= 0 && (string) $existing['qty'] !== (string) $qty) {
                $changes['qty'] = $qty;
            }
            if ((int) $existing['is_active'] !== 1) {
                $changes['is_active'] = 1;
            }
            if ($changes !== []) {
                self::update($id, $changes);
            }
            return ['id' => $id, 'created' => false];
        }
        $id = self::create([
            'name'      => mb_substr($name, 0, 190),
            'spec'      => $spec !== '' ? $spec : null,
            'qty'       => $qty !== null && $qty >= 0 ? $qty : null,
            'unit'      => 'pcs',
            'is_active' => 1,
            'source'    => 'OEF',
            'notes'     => 'Dicatat otomatis dari Order Entry Form' . ($orderLabel ? ' ' . $orderLabel : '') . '.',
        ]);
        return ['id' => $id, 'created' => true];
    }

    /**
     * Produk untuk input Stock: nama produk diketik manual oleh Produksi/Gudang.
     * Nama yang sama (tidak peka huruf besar/kecil & spasi) selalu masuk ke produk
     * yang sama sehingga stok otomatis terkelompok per produk.
     * @return array{id:int,created:bool}
     */
    public static function findOrCreateForStock(string $name, ?string $unit = null): array
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new DomainException('Nama produk wajib diisi.');
        }
        $existing = self::findByName($name);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = self::create([
            'name'      => mb_substr($name, 0, 190),
            'unit'      => $unit !== null && $unit !== '' ? mb_substr($unit, 0, 20) : 'pcs',
            'is_active' => 1,
            'source'    => 'Stok',
            'notes'     => 'Dicatat otomatis dari input Stock.',
        ]);
        return ['id' => $id, 'created' => true];
    }

    /** @return list<string> nama produk aktif (untuk saran input OEF / Stock) */
    public static function nameSuggestions(int $limit = 1500): array
    {
        return array_map('strval', Database::fetchColumn(
            'SELECT DISTINCT name FROM products WHERE is_active = 1 ORDER BY name LIMIT ' . max(1, $limit)
        ));
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return array_map('strval', Database::fetchColumn("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category"));
    }

    /** @return list<string> satuan standar + satuan yang sudah dipakai */
    public static function units(): array
    {
        $existing = array_map('strval', Database::fetchColumn("SELECT DISTINCT unit FROM products WHERE unit <> '' ORDER BY unit"));
        return array_values(array_unique(array_merge(self::UNITS, $existing)));
    }

    /** Label singkat produk: nama — varian [kode]. */
    public static function displayName(array $p): string
    {
        $label = (string) ($p['name'] ?? '');
        if (!empty($p['variant'])) {
            $label .= ' — ' . $p['variant'];
        }
        return $label;
    }

    /**
     * Pilihan produk untuk form (nama · varian · kode).
     * @return array<int,string>
     */
    public static function selectOptions(bool $activeOnly = true, ?int $includeId = null): array
    {
        $rows = Database::fetchAll(
            'SELECT id, name, variant, product_code FROM products WHERE ' . ($activeOnly ? '(is_active = 1 OR id = :inc)' : '(1=1 OR id = :inc)') . ' ORDER BY name, variant',
            ['inc' => $includeId ?? 0]
        );
        $out = [];
        foreach ($rows as $r) {
            $label = $r['name'];
            if ($r['variant']) {
                $label .= ' — ' . $r['variant'];
            }
            if ($r['product_code']) {
                $label .= ' ' . $r['product_code'];
            }
            $out[(int) $r['id']] = mb_strlen($label) > 140 ? mb_substr($label, 0, 139) . '…' : $label;
        }
        return $out;
    }
}
