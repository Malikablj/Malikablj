<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Models\Customer;
use App\Models\Product;

/**
 * Customer & produk yang DIKETIK MANUAL (form OEF, stok) dihubungkan ke master
 * data, atau otomatis dibuat bila belum ada.
 *
 * Aturan pencocokan (tanpa fuzzy matching):
 *   - spasi berlebih & huruf besar/kecil diabaikan ("PT  ABC" = "pt abc");
 *   - cocok persis dengan label saran (mis. "Botol 60ml — Amber [BTL60]") → record itu;
 *   - cocok persis dengan nama dan hanya ada satu record → record itu;
 *   - nama sama dimiliki beberapa record → user diminta memilih dari daftar saran;
 *   - tidak ada yang cocok → record baru dibuat (sumber: OEF / Stok).
 */
final class MasterData
{
    /** @var array<string,list<array<string,mixed>>>|null */
    private static ?array $productCache = null;
    /** @var array<string,list<array<string,mixed>>>|null */
    private static ?array $customerCache = null;

    public static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /** Hapus cache (setelah record baru dibuat / di test). */
    public static function reset(): void
    {
        self::$productCache = null;
        self::$customerCache = null;
    }

    // ------------------------------------------------------------------ produk

    /** Label saran produk: nama — varian [kode]. */
    public static function productLabel(array $p): string
    {
        $label = trim((string) $p['name']);
        if (!empty($p['variant'])) {
            $label .= ' — ' . trim((string) $p['variant']);
        }
        if (!empty($p['product_code'])) {
            $label .= ' ' . trim((string) $p['product_code']);
        }
        return $label;
    }

    /** Label produk seperti di daftar saran (diberi kode bila ada produk lain dengan label sama). */
    public static function productLabelFor(int $productId): string
    {
        $p = Database::fetch('SELECT id, code, name, variant, product_code FROM products WHERE id = :id', ['id' => $productId]);
        if ($p === null) {
            return '';
        }
        $label = self::productLabel($p);
        $found = self::findProduct($label);
        return $found['id'] === (int) $p['id'] ? $label : $label . ' (' . $p['code'] . ')';
    }

    /** @return list<string> label saran produk aktif (untuk <datalist>); label kembar diberi kode produk */
    public static function productSuggestions(): array
    {
        $rows = Database::fetchAll('SELECT code, name, variant, product_code FROM products WHERE is_active = 1 ORDER BY name, variant, id');
        $counts = [];
        foreach ($rows as $p) {
            $k = self::normalize(self::productLabel($p));
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as $p) {
            $label = self::productLabel($p);
            $out[] = $counts[self::normalize($label)] > 1 ? $label . ' (' . $p['code'] . ')' : $label;
        }
        return $out;
    }

    /**
     * Cari produk dari teks yang diketik.
     * @return array{id:?int,error:?string,label:?string}
     */
    public static function findProduct(string $typed): array
    {
        $key = self::normalize($typed);
        if ($key === '') {
            return ['id' => null, 'error' => 'Nama produk wajib diisi.', 'label' => null];
        }
        if (self::$productCache === null) {
            self::$productCache = ['coded' => [], 'label' => [], 'name' => []];
            foreach (Database::fetchAll('SELECT id, code, name, variant, product_code, is_active FROM products ORDER BY is_active DESC, id') as $p) {
                self::$productCache['coded'][self::normalize(self::productLabel($p) . ' (' . $p['code'] . ')')][] = $p;
                self::$productCache['label'][self::normalize(self::productLabel($p))][] = $p;
                self::$productCache['name'][self::normalize((string) $p['name'])][] = $p;
            }
        }
        foreach (['coded', 'label', 'name'] as $by) {
            $hits = self::$productCache[$by][$key] ?? [];
            if (count($hits) === 1) {
                return ['id' => (int) $hits[0]['id'], 'error' => null, 'label' => self::productLabel($hits[0])];
            }
            if (count($hits) > 1) {
                $options = array_slice(array_map(static fn (array $p): string => '"' . self::productLabel($p) . (count(self::$productCache['label'][self::normalize(self::productLabel($p))] ?? []) > 1 ? ' (' . $p['code'] . ')' : '') . '"', $hits), 0, 2);
                return ['id' => null, 'error' => 'Ada ' . count($hits) . ' produk bernama sama (beda varian/kode). Ketik lalu pilih salah satu dari daftar saran, mis. ' . implode(' atau ', $options) . '.', 'label' => null];
            }
        }
        return ['id' => null, 'error' => null, 'label' => null];
    }

    /**
     * Produk dari teks yang diketik; dibuat otomatis bila belum ada.
     * Panggil di dalam transaksi bersama data yang memakainya.
     * @return array{id:int,created:bool}
     */
    public static function product(string $typed, string $source, ?string $unit = null): array
    {
        $found = self::findProduct($typed);
        if ($found['error'] !== null) {
            throw new \DomainException($found['error']);
        }
        if ($found['id'] !== null) {
            return ['id' => $found['id'], 'created' => false];
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $typed));
        $id = Product::create([
            'name'      => mb_substr($name, 0, 190),
            'unit'      => $unit !== null && trim($unit) !== '' ? mb_substr(trim($unit), 0, 20) : 'pcs',
            'is_active' => 1,
            'source'    => mb_substr($source, 0, 50),
        ]);
        self::$productCache = null;
        return ['id' => $id, 'created' => true];
    }

    // ---------------------------------------------------------------- customer

    /** @return list<string> label saran customer (nama; nama + kode bila ada nama kembar) */
    public static function customerSuggestions(): array
    {
        $rows = Database::fetchAll("SELECT code, name FROM customers WHERE status <> 'Inactive' ORDER BY name");
        $counts = [];
        foreach ($rows as $r) {
            $k = self::normalize((string) $r['name']);
            $counts[$k] = ($counts[$k] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = $counts[self::normalize((string) $r['name'])] > 1 ? $r['name'] . ' (' . $r['code'] . ')' : (string) $r['name'];
        }
        return $out;
    }

    /**
     * Cari customer dari teks yang diketik ("Nama" atau "Nama (CUS-…)").
     * @return array{id:?int,error:?string,name:?string}
     */
    public static function findCustomer(string $typed): array
    {
        $key = self::normalize($typed);
        if ($key === '') {
            return ['id' => null, 'error' => 'Nama customer wajib diisi.', 'name' => null];
        }
        if (self::$customerCache === null) {
            self::$customerCache = ['label' => [], 'name' => []];
            foreach (Database::fetchAll('SELECT id, code, name FROM customers ORDER BY id') as $c) {
                self::$customerCache['label'][self::normalize($c['name'] . ' (' . $c['code'] . ')')][] = $c;
                self::$customerCache['name'][self::normalize((string) $c['name'])][] = $c;
            }
        }
        foreach (['label', 'name'] as $by) {
            $hits = self::$customerCache[$by][$key] ?? [];
            if (count($hits) === 1) {
                return ['id' => (int) $hits[0]['id'], 'error' => null, 'name' => (string) $hits[0]['name']];
            }
            if (count($hits) > 1) {
                $options = array_slice(array_map(static fn (array $c): string => '"' . $c['name'] . ' (' . $c['code'] . ')"', $hits), 0, 2);
                return ['id' => null, 'error' => 'Ada ' . count($hits) . ' customer bernama sama. Ketik lalu pilih salah satu dari daftar saran, mis. ' . implode(' atau ', $options) . '.', 'name' => null];
            }
        }
        return ['id' => null, 'error' => null, 'name' => null];
    }

    /**
     * Customer dari teks yang diketik; dibuat otomatis bila belum ada.
     * @return array{id:int,created:bool}
     */
    public static function customer(string $typed, string $source, ?int $picId = null): array
    {
        $found = self::findCustomer($typed);
        if ($found['error'] !== null) {
            throw new \DomainException($found['error']);
        }
        if ($found['id'] !== null) {
            return ['id' => $found['id'], 'created' => false];
        }
        $id = Customer::create([
            'name'             => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $typed)), 0, 190),
            'status'           => 'Active',
            'source'           => mb_substr($source, 0, 100),
            'marketing_pic_id' => $picId,
        ]);
        self::$customerCache = null;
        return ['id' => $id, 'created' => true];
    }

    /** @return list<string> saran nama sales: user Sales/Marketing aktif + nama yang pernah dipakai */
    public static function salesSuggestions(): array
    {
        $names = array_map('strval', Database::fetchColumn("SELECT name FROM users WHERE is_active = 1 AND role IN ('Sales','Marketing') ORDER BY name"));
        $used = array_map('strval', Database::fetchColumn("SELECT DISTINCT sales_name FROM purchase_orders WHERE sales_name IS NOT NULL AND sales_name <> '' ORDER BY sales_name LIMIT 200"));
        return array_values(array_unique(array_merge($names, $used)));
    }
}
