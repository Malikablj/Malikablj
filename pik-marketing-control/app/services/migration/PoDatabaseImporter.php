<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Helpers\Audit;
use App\Helpers\Code;
use App\Helpers\Database;
use App\Helpers\DbBackup;
use App\Helpers\Migrator;
use App\Helpers\Validator;
use App\Helpers\XlsxReader;
use App\Models\MigrationIssue;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Import "PIK PO DATABASE" (hasil digitalisasi PO fisik: PO_MASTER, PO_ITEMS,
 * CUSTOMERS, PRODUCTS, VALIDATION) ke tabel yang sudah ada:
 *   CUSTOMERS → customers, PRODUCTS → products, PO_MASTER → purchase_orders,
 *   PO_ITEMS → po_lines, VALIDATION → migration_issues.
 *
 * Prinsip (sama dengan importer workbook AppSheet):
 *  - Relasi hanya dari ID / nomor PO / kode / nama ternormalisasi yang PERSIS sama.
 *    Tidak ada fuzzy matching; kasus ragu masuk Migration Issues (NEEDS_REVIEW).
 *  - Idempoten: PO yang sudah ada (import_ref / nomor PO) dilengkapi, tidak dibuat ulang.
 *    Nilai yang sudah ada tidak pernah ditimpa, kecuali nilai itu ditulis import
 *    sebelumnya dan belum diubah user (dicek lewat import_snapshot).
 *  - Dry run memakai logika yang sama tanpa menulis apa pun.
 *  - Import nyata: backup tabel → satu transaksi (gagal = rollback) → log + rekonsiliasi.
 */
final class PoDatabaseImporter
{
    public const TYPE = 'PO_DATABASE';
    public const SOURCE = 'PO Database';
    /** Dry run untuk file yang sama wajib ada dalam rentang ini sebelum import nyata. */
    public const DRY_RUN_VALID_HOURS = 24;

    private const REQUIRED = [
        'PO_MASTER'  => ['PO_ID', 'PO_NUMBER', 'PO_DATE', 'CUSTOMER_ID', 'CUSTOMER_NAME', 'CURRENCY', 'SUBTOTAL', 'DISCOUNT', 'TAX', 'SHIPPING_COST', 'GRAND_TOTAL', 'PAYMENT_TERM', 'VALIDATION_STATUS'],
        'PO_ITEMS'   => ['ITEM_ID', 'PO_ID', 'ITEM_NAME', 'QUANTITY', 'UNIT', 'UNIT_PRICE', 'SUBTOTAL', 'PRODUCT_ID'],
        'CUSTOMERS'  => ['CUSTOMER_ID', 'CUSTOMER_NAME'],
        'PRODUCTS'   => ['PRODUCT_ID', 'PRODUCT_NAME'],
        'VALIDATION' => ['VALIDATION_ID', 'PO_ID', 'FIELD', 'PROBLEM', 'STATUS'],
    ];
    private const REVIEW_STATUSES = ['REVIEW REQUIRED', 'UNREADABLE', 'AMBIGUOUS', 'MISSING DATA', 'POSSIBLE DUPLICATE'];

    /** Kolom purchase_orders milik import (dokumen PO). */
    private const PO_OWNED = [
        'payment_term_code' => 'text', 'currency' => 'text', 'price_includes_tax' => 'int', 'subtotal' => 'money',
        'discount_amount' => 'money', 'tax_amount' => 'money', 'shipping_cost' => 'money', 'grand_total' => 'money',
        'requested_delivery_date' => 'date', 'delivery_address' => 'text', 'contact_person' => 'text',
        'doc_type' => 'text', 'doc_url' => 'text', 'data_confidence' => 'text',
    ];
    private const LINE_OWNED = [
        'line_no' => 'int', 'item_code' => 'text', 'item_description' => 'text', 'unit' => 'text', 'unit_price' => 'money',
        'line_subtotal' => 'money', 'tax_rate' => 'rate', 'requested_delivery_date' => 'date', 'data_confidence' => 'text',
    ];
    /** Kata umum yang tidak dipakai untuk mendeteksi nama customer yang mirip. */
    private const NAME_STOPWORDS = ['PT', 'CV', 'UD', 'TBK', 'INDONESIA', 'INTERNATIONAL', 'INTERNASIONAL', 'GLOBAL', 'GROUP', 'INDUSTRI', 'INDUSTRY'];

    // ---- isi workbook (sudah dinormalisasi) ----
    /** @var array<string,array<string,mixed>> */ private array $pos = [];
    /** @var array<string,array<string,mixed>> */ private array $items = [];
    /** @var array<string,list<string>> */ private array $itemsByPo = [];
    /** @var array<string,array<string,mixed>> */ private array $customers = [];
    /** @var array<string,array<string,mixed>> */ private array $products = [];
    /** @var list<array<string,mixed>> */ private array $validation = [];
    /** @var array<string,list<string>> field yang ditandai VALIDATION per PO_ID */ private array $flaggedFields = [];

    // ---- isi database ----
    /** @var array<int,array<string,mixed>> */ private array $dbCustomers = [];
    /** @var array<int,array<string,mixed>> */ private array $dbProducts = [];
    /** @var array<int,array<string,mixed>> */ private array $dbPos = [];
    /** @var array<int,list<array<string,mixed>>> */ private array $dbLines = [];
    /** @var array<string,list<int>> */ private array $poByNumber = [];
    /** @var array<string,list<int>> */ private array $poByAlnum = [];
    /** @var array<string,int> */ private array $poByImportRef = [];
    /** @var array<string,array<string,mixed>> */ private array $existingIssues = [];

    // ---- hasil pemetaan ----
    /** @var array<string,array<string,mixed>> */ private array $customerMap = [];
    /** @var array<string,array<string,mixed>> */ private array $productMap = [];
    /** @var array<string,array<string,mixed>> */ private array $poMap = [];
    /** @var array<string,array<string,mixed>> */ private array $lineMap = [];
    /** @var array<int,true> */ private array $claimed = [];
    /** @var list<array<string,mixed>> temuan per baris (sheet, row, field, problem, action) */ public array $findings = [];
    /** @var list<array<string,mixed>> issue yang dibuat/dicek */ private array $issues = [];
    /** @var array<string,true> */ private array $issueCodes = [];

    private bool $write = false;
    private ?int $userId = null;
    private ?int $logId = null;
    private int $fakeId = 0;
    private int $writes = 0;
    private string $now;
    /** Untuk test: paksa gagal setelah N penulisan (menguji rollback). */
    public ?int $failAfterWrites = null;

    private array $counts = [];
    /** @var array<string,true> nomor PO (ternormalisasi) yang dibuat pada run ini */
    private array $insertedNumbers = [];

    public function __construct(private string $path, private string $filename)
    {
        $this->now = date('Y-m-d H:i:s');
    }

    // =====================================================================
    // API
    // =====================================================================

    /** Dry run: baca, petakan, laporkan. Tidak menulis data bisnis; hanya mencatat import_logs. */
    public function dryRun(?int $userId): array
    {
        if (!Migrator::isCurrent()) {
            throw new RuntimeException('Struktur database belum diperbarui. Jalankan Settings › Pembaruan database (atau php database/migrate.php) terlebih dahulu.');
        }
        return $this->logged('DRY_RUN', $userId, function (): array {
            return $this->run(false);
        });
    }

    /**
     * Import nyata. Syarat: struktur database terbaru & dry run file yang sama
     * sudah selesai dalam 24 jam terakhir.
     */
    public function import(?int $userId): array
    {
        if (!Migrator::isCurrent()) {
            throw new RuntimeException('Struktur database belum diperbarui. Jalankan Settings › Pembaruan database (atau php database/migrate.php) terlebih dahulu.');
        }
        $sha = $this->sha256();
        $dry = Database::fetch(
            "SELECT id FROM import_logs WHERE import_type = :t AND mode = 'DRY_RUN' AND file_sha256 = :s
               AND status IN ('COMPLETED','COMPLETED_WITH_WARNING') AND started_at >= :since ORDER BY id DESC LIMIT 1",
            ['t' => self::TYPE, 's' => $sha, 'since' => date('Y-m-d H:i:s', time() - self::DRY_RUN_VALID_HOURS * 3600)]
        );
        if ($dry === null) {
            throw new RuntimeException('Jalankan dry run (Cek dulu) untuk file ini terlebih dahulu. Import nyata hanya boleh setelah dry run file yang sama berhasil dalam ' . self::DRY_RUN_VALID_HOURS . ' jam terakhir.');
        }
        return $this->logged('IMPORT', $userId, function (): array {
            $backup = DbBackup::tables(['customers', 'products', 'purchase_orders', 'po_lines', 'migration_issues'], 'before-po-import-' . $this->logId);
            Database::update('import_logs', ['backup_file' => basename($backup)], 'id = :id', ['id' => $this->logId]);
            $report = Database::transaction(fn (): array => $this->run(true));
            $report['reconciliation'] = $this->reconcileFromDatabase();
            $report['backup_file'] = basename($backup);
            Audit::log('import', 'import', $this->logId, $this->filename, [
                'purchase_orders' => ['old' => null, 'new' => $report['purchase_orders']['inserted'] . ' baru, ' . $report['purchase_orders']['updated'] . ' dilengkapi'],
                'po_lines'        => ['old' => null, 'new' => $report['po_items']['inserted'] . ' baru, ' . $report['po_items']['updated'] . ' dilengkapi'],
            ]);
            return $report;
        });
    }

    public function sha256(): string
    {
        return (string) hash_file('sha256', $this->path);
    }

    private function resetState(): void
    {
        foreach (['pos', 'items', 'itemsByPo', 'customers', 'products', 'validation', 'flaggedFields', 'dbCustomers', 'dbProducts', 'dbPos', 'dbLines',
                  'poByNumber', 'poByAlnum', 'poByImportRef', 'existingIssues', 'customerMap', 'productMap', 'poMap', 'lineMap', 'claimed',
                  'findings', 'issues', 'issueCodes', 'counts', 'insertedNumbers'] as $prop) {
            $this->{$prop} = [];
        }
        $this->fakeId = 0;
        $this->writes = 0;
    }

    // =====================================================================
    // Log import
    // =====================================================================

    /** @param callable():array $work */
    private function logged(string $mode, ?int $userId, callable $work): array
    {
        $this->userId = $userId;
        $this->logId = Database::insert('import_logs', [
            'import_type' => self::TYPE, 'mode' => $mode, 'filename' => mb_substr($this->filename, 0, 255),
            'file_sha256' => $this->sha256(), 'status' => 'RUNNING', 'started_at' => date('Y-m-d H:i:s'), 'created_by' => $userId,
        ]);
        try {
            $report = $work();
        } catch (Throwable $e) {
            $status = ($mode === 'IMPORT' && $this->writes > 0) ? 'ROLLED_BACK' : 'FAILED';
            $partial = $this->summary();
            $partial['error'] = $e->getMessage();
            Database::update('import_logs', [
                'status' => $status, 'completed_at' => date('Y-m-d H:i:s'), 'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'summary' => json_encode($partial, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ], 'id = :id', ['id' => $this->logId]);
            throw new ImportFailed($e->getMessage(), $this->logId, $status, $e);
        }
        $warnings = count(array_filter($this->findings, static fn (array $f): bool => $f['level'] !== 'info'));
        $status = ($warnings > 0 || $report['purchase_orders']['held'] > 0 || $report['purchase_orders']['failed'] > 0) ? 'COMPLETED_WITH_WARNING' : 'COMPLETED';
        $report['log_id'] = $this->logId;
        $report['status'] = $status;
        $report['mode'] = $mode;
        Database::update('import_logs', [
            'status' => $status, 'completed_at' => date('Y-m-d H:i:s'),
            'total_rows' => $report['rows']['total'], 'inserted_rows' => $report['rows']['inserted'], 'updated_rows' => $report['rows']['updated'],
            'skipped_rows' => $report['rows']['skipped'], 'failed_rows' => $report['rows']['failed'], 'warning_count' => $warnings,
            'summary' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ], 'id = :id', ['id' => $this->logId]);
        return $report;
    }

    // =====================================================================
    // Proses utama
    // =====================================================================

    private function run(bool $write): array
    {
        $this->resetState();
        $this->write = $write;
        $this->readWorkbook();
        $this->loadDatabase();
        $this->mapCustomers();
        $this->matchPurchaseOrders();
        $this->mapProducts();
        $this->applyCustomers();
        $this->applyProducts();
        $this->applyPurchaseOrders();
        $this->applyValidationIssues();
        $this->applyImportStatus();
        return $this->summary();
    }

    // ---------------------------------------------------------------------
    // 1. Baca & validasi workbook
    // ---------------------------------------------------------------------

    private function readWorkbook(): void
    {
        $x = new XlsxReader($this->path);
        $tables = [];
        foreach (self::REQUIRED as $sheet => $columns) {
            if (!$x->hasSheet($sheet)) {
                throw new RuntimeException("Sheet {$sheet} tidak ditemukan. Pastikan file adalah PIK PO DATABASE (sheet PO_MASTER, PO_ITEMS, CUSTOMERS, PRODUCTS, VALIDATION).");
            }
            $tables[$sheet] = $x->table($sheet);
            $missing = array_diff($columns, $tables[$sheet]['headers']);
            if ($missing !== []) {
                throw new RuntimeException("Sheet {$sheet}: kolom wajib tidak ditemukan: " . implode(', ', $missing) . '.');
            }
        }

        foreach ($tables['CUSTOMERS']['rows'] as $r) {
            $d = $r['data'];
            $id = $this->text($d['CUSTOMER_ID']);
            if ($id === null) {
                continue;
            }
            $this->customers[$id] = [
                'row' => $r['row'], 'id' => $id, 'name' => $this->text($d['CUSTOMER_NAME']), 'code' => $this->text($d['CUSTOMER_CODE'] ?? null),
                'address' => $this->text($d['ADDRESS'] ?? null), 'phone' => $this->text($d['PHONE'] ?? null), 'email' => $this->text($d['EMAIL'] ?? null),
                'contact' => $this->text($d['CONTACT_PERSON'] ?? null), 'notes' => $this->text($d['NOTES'] ?? null),
                'variants' => array_values(array_filter(array_map('trim', explode('|', (string) ($d['CUSTOMER_NAME_RAW_VARIANTS'] ?? ''))))),
            ];
        }
        foreach ($tables['PRODUCTS']['rows'] as $r) {
            $d = $r['data'];
            $id = $this->text($d['PRODUCT_ID']);
            if ($id === null) {
                continue;
            }
            $this->products[$id] = [
                'row' => $r['row'], 'id' => $id, 'code' => $this->text($d['PRODUCT_CODE'] ?? null), 'name' => $this->text($d['PRODUCT_NAME']),
                'description' => $this->text($d['DESCRIPTION'] ?? null), 'spec' => $this->text($d['SPECIFICATION'] ?? null),
                'unit' => $this->text($d['UNIT'] ?? null), 'customer' => $this->text($d['CUSTOMER_ID'] ?? null),
            ];
        }
        foreach ($tables['PO_MASTER']['rows'] as $r) {
            $d = $r['data'];
            $id = $this->text($d['PO_ID']);
            $number = $this->text($d['PO_NUMBER']);
            if ($id === null) {
                $this->finding('error', 'PO_MASTER', $r['row'], $number, 'PO_ID', 'PO_ID kosong', 'Isi PO_ID; baris tidak diimport.');
                $this->counts['po_invalid'] = ($this->counts['po_invalid'] ?? 0) + 1;
                continue;
            }
            $po = [
                'row' => $r['row'], 'id' => $id, 'number' => $number, 'date' => $this->date($d['PO_DATE']), 'date_raw' => $d['PO_DATE'],
                'customer' => $this->text($d['CUSTOMER_ID']), 'customer_name' => $this->text($d['CUSTOMER_NAME']),
                'currency' => $this->text($d['CURRENCY']), 'subtotal' => $this->money($d['SUBTOTAL']), 'discount' => $this->money($d['DISCOUNT']),
                'tax' => $this->money($d['TAX']), 'shipping' => $this->money($d['SHIPPING_COST']), 'grand_total' => $this->money($d['GRAND_TOTAL']),
                'payment_term' => $this->text($d['PAYMENT_TERM']), 'payment_term_code' => $this->text($d['PAYMENT_TERM_STANDARDIZED'] ?? null),
                'delivery_date' => $this->date($d['DELIVERY_DATE'] ?? null), 'delivery_address' => $this->text($d['DELIVERY_ADDRESS'] ?? null),
                'po_status' => $this->text($d['PO_STATUS'] ?? null), 'source_file' => $this->text($d['SOURCE_FILE'] ?? null),
                'confidence' => $this->text($d['DATA_CONFIDENCE'] ?? null), 'validation_status' => strtoupper((string) $this->text($d['VALIDATION_STATUS'])),
                'notes' => $this->text($d['NOTES'] ?? null), 'price_includes_tax' => $this->flag($d['PRICE_INCLUDES_TAX'] ?? null),
                'contact' => $this->text($d['CONTACT_PERSON'] ?? null), 'doc_type' => $this->text($d['DOC_TYPE'] ?? null),
                'count_in_summary' => strtoupper((string) ($this->text($d['COUNT_IN_SUMMARY'] ?? null) ?? 'Y')) !== 'N',
                'doc_url' => $this->safeUrl($this->text($d['SOURCE_URL'] ?? null)), 'valid' => true,
            ];
            $this->validatePo($po, $d);
            $this->pos[$id] = $po;
        }
        foreach ($tables['PO_ITEMS']['rows'] as $r) {
            $d = $r['data'];
            $id = $this->text($d['ITEM_ID']);
            $poId = $this->text($d['PO_ID']);
            if ($id === null) {
                $this->finding('error', 'PO_ITEMS', $r['row'], $this->text($d['PO_NUMBER'] ?? null), 'ITEM_ID', 'ITEM_ID kosong', 'Isi ITEM_ID; baris tidak diimport.');
                $this->counts['item_invalid'] = ($this->counts['item_invalid'] ?? 0) + 1;
                continue;
            }
            $item = [
                'row' => $r['row'], 'id' => $id, 'po' => $poId, 'number' => $this->text($d['PO_NUMBER'] ?? null),
                'code' => $this->text($d['ITEM_CODE'] ?? null), 'name' => $this->text($d['ITEM_NAME']),
                'description' => $this->joinText($this->text($d['ITEM_DESCRIPTION'] ?? null), $this->text($d['SPECIFICATION'] ?? null)),
                'spec' => $this->text($d['SPECIFICATION'] ?? null), 'qty_raw' => $d['QUANTITY'], 'qty' => $this->qty($d['QUANTITY']),
                'unit' => $this->text($d['UNIT']), 'unit_price' => $this->money($d['UNIT_PRICE']), 'subtotal' => $this->money($d['SUBTOTAL']),
                'tax_rate' => $this->rate($d['TAX'] ?? null), 'product' => $this->text($d['PRODUCT_ID']), 'line_no' => $this->qty($d['LINE_NO'] ?? null),
                'delivery_date' => $this->date($d['ITEM_DELIVERY_DATE'] ?? null), 'confidence' => $this->text($d['DATA_CONFIDENCE'] ?? null),
                'notes' => $this->text($d['NOTES'] ?? null), 'valid' => true,
            ];
            $this->validateItem($item);
            $this->items[$id] = $item;
            if ($item['valid']) {
                $this->itemsByPo[$poId][] = $id;
            }
        }
        foreach ($tables['VALIDATION']['rows'] as $r) {
            $d = $r['data'];
            $v = [
                'row' => $r['row'], 'id' => $this->text($d['VALIDATION_ID']), 'po' => $this->text($d['PO_ID']), 'number' => $this->text($d['PO_NUMBER'] ?? null),
                'field' => $this->text($d['FIELD']), 'original' => $this->text($d['ORIGINAL_TEXT'] ?? null), 'extracted' => $this->text($d['EXTRACTED_VALUE'] ?? null),
                'problem' => $this->text($d['PROBLEM']), 'confidence' => $this->text($d['CONFIDENCE'] ?? null), 'action' => $this->text($d['RECOMMENDED_ACTION'] ?? null),
                'status' => strtoupper((string) $this->text($d['STATUS'])), 'source_file' => $this->text($d['SOURCE_FILE'] ?? null),
                'check' => $this->text($d['CHECK_REF'] ?? null), 'resolution' => $this->text($d['REVIEWER_RESOLUTION'] ?? null),
            ];
            if ($v['id'] === null) {
                continue;
            }
            $this->validation[] = $v;
            if ($v['po'] !== null && $v['status'] !== 'OK' && $v['field'] !== null) {
                $this->flaggedFields[$v['po']][] = strtoupper($v['field']);
            }
        }
    }

    private function validatePo(array &$po, array $raw): void
    {
        $num = $po['number'];
        if ($po['customer'] === null || !isset($this->customers[$po['customer']])) {
            $this->finding('error', 'PO_MASTER', $po['row'], $num, 'CUSTOMER_ID', 'Customer tidak ditemukan di sheet CUSTOMERS (' . ($po['customer'] ?? 'kosong') . ')', 'Perbaiki CUSTOMER_ID; PO tidak diimport.');
            $po['valid'] = false;
        }
        if ($raw['PO_DATE'] !== null && $po['date'] === null) {
            $this->finding('warning', 'PO_MASTER', $po['row'], $num, 'PO_DATE', 'Tanggal PO tidak valid: ' . $this->text($raw['PO_DATE']), 'Periksa dokumen; tanggal dikosongkan.');
        }
        if ($po['currency'] !== null && !preg_match('/^[A-Z]{3}$/', $po['currency'])) {
            $this->finding('warning', 'PO_MASTER', $po['row'], $num, 'CURRENCY', 'Mata uang tidak valid: ' . $po['currency'], 'Gunakan kode 3 huruf, mis. IDR.');
            $po['currency'] = null;
        }
        foreach (['SUBTOTAL' => 'subtotal', 'DISCOUNT' => 'discount', 'TAX' => 'tax', 'SHIPPING_COST' => 'shipping', 'GRAND_TOTAL' => 'grand_total'] as $col => $key) {
            if ($raw[$col] !== null && $po[$key] === null) {
                $this->finding('warning', 'PO_MASTER', $po['row'], $num, $col, 'Nilai bukan angka: ' . $this->text($raw[$col]), 'Periksa dokumen; nilai dikosongkan.');
            }
        }
        if ($po['subtotal'] !== null && $po['grand_total'] !== null) {
            $calc = $this->cents($po['subtotal']) - $this->cents($po['discount']) + $this->cents($po['tax']) + $this->cents($po['shipping']);
            if (abs($calc - $this->cents($po['grand_total'])) > 100) {
                $this->finding('warning', 'PO_MASTER', $po['row'], $num, 'GRAND_TOTAL', 'Subtotal − diskon + PPN + ongkir (' . $this->fromCents($calc) . ') ≠ grand total (' . $po['grand_total'] . ')', 'Cocokkan dengan dokumen PO.');
            }
        }
        if ($po['payment_term'] !== null && mb_strlen($po['payment_term']) > 255) {
            $po['payment_term'] = mb_substr($po['payment_term'], 0, 255);
        }
    }

    private function validateItem(array &$item): void
    {
        $num = $item['number'];
        if ($item['po'] === null || !isset($this->pos[$item['po']])) {
            $this->finding('error', 'PO_ITEMS', $item['row'], $num, 'PO_ID', 'PO_ID tidak ditemukan di PO_MASTER (' . ($item['po'] ?? 'kosong') . ')', 'Perbaiki PO_ID; item tidak diimport.');
            $item['valid'] = false;
        } elseif (!$this->pos[$item['po']]['valid']) {
            $item['valid'] = false;
        }
        if ($item['qty'] === null || $item['qty'] <= 0) {
            $this->finding('error', 'PO_ITEMS', $item['row'], $num, 'QUANTITY', 'Quantity harus bilangan bulat > 0 (' . ($this->text($item['qty_raw']) ?? 'kosong') . ')', 'Perbaiki quantity; item tidak diimport.');
            $item['valid'] = false;
        }
        if ($item['product'] === null || !isset($this->products[$item['product']])) {
            $this->finding('error', 'PO_ITEMS', $item['row'], $num, 'PRODUCT_ID', 'Produk tidak ditemukan di sheet PRODUCTS (' . ($item['product'] ?? 'kosong') . ')', 'Perbaiki PRODUCT_ID; item tidak diimport.');
            $item['valid'] = false;
        }
        if ($item['name'] === null && $item['product'] !== null && isset($this->products[$item['product']])) {
            $item['name'] = $this->products[$item['product']]['name'];
            $this->finding('warning', 'PO_ITEMS', $item['row'], $num, 'ITEM_NAME', 'Nama item kosong; memakai nama produk ' . $item['product'], 'Periksa dokumen PO.');
        }
        if ($item['unit'] === null) {
            $this->finding('info', 'PO_ITEMS', $item['row'], $num, 'UNIT', 'Satuan tidak tertulis', 'Lengkapi satuan bila diketahui.');
        }
        // Harga belum termasuk PPN: qty × harga harus = subtotal baris. (Harga termasuk PPN: subtotal = DPP,
        // dicek di tingkat PO lewat subtotal − diskon + PPN + ongkir = grand total.)
        if ($item['valid'] && $item['unit_price'] !== null && $item['subtotal'] !== null && $this->pos[$item['po']]['price_includes_tax'] !== 1) {
            $gross = $item['qty'] * $this->cents($item['unit_price']);
            if (abs($gross - $this->cents($item['subtotal'])) > 100) {
                $this->finding('warning', 'PO_ITEMS', $item['row'], $num, 'SUBTOTAL', "Qty × harga ({$this->fromCents($gross)}) ≠ subtotal ({$item['subtotal']})", 'Cocokkan dengan dokumen PO.');
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. Data database
    // ---------------------------------------------------------------------

    private function loadDatabase(): void
    {
        foreach (Database::fetchAll('SELECT c.*, (SELECT COUNT(*) FROM purchase_orders p WHERE p.customer_id = c.id) AS po_count FROM customers c') as $c) {
            $this->dbCustomers[(int) $c['id']] = $c;
        }
        foreach (Database::fetchAll('SELECT pr.* FROM products pr') as $p) {
            $this->dbProducts[(int) $p['id']] = $p;
        }
        foreach (Database::fetchAll('SELECT * FROM purchase_orders') as $p) {
            $id = (int) $p['id'];
            $this->dbPos[$id] = $p;
            if ($p['po_number'] !== null && trim((string) $p['po_number']) !== '') {
                $this->poByNumber[$this->normNumber((string) $p['po_number'])][] = $id;
                $this->poByAlnum[$this->alnum((string) $p['po_number'])][] = $id;
            }
            if ($p['import_ref'] !== null) {
                $this->poByImportRef[(string) $p['import_ref']] = $id;
            }
        }
        foreach (Database::fetchAll('SELECT l.*, pr.name AS product_name, pr.product_code FROM po_lines l JOIN products pr ON pr.id = l.product_id ORDER BY l.po_id, l.id') as $l) {
            $this->dbLines[(int) $l['po_id']][] = $l;
        }
        foreach (Database::fetchAll("SELECT code, resolution_status, record_id FROM migration_issues WHERE code LIKE 'ISS-%'") as $i) {
            $this->existingIssues[(string) $i['code']] = $i;
        }
    }

    // ---------------------------------------------------------------------
    // 3. Customer
    // ---------------------------------------------------------------------

    private function mapCustomers(): void
    {
        $byName = [];
        foreach ($this->dbCustomers as $id => $c) {
            $byName[$this->alnum((string) $c['name'])][] = $id;
            if (!empty($c['company']) && $this->alnum((string) $c['company']) !== $this->alnum((string) $c['name'])) {
                $byName[$this->alnum((string) $c['company'])][] = $id;
            }
        }
        foreach ($this->customers as $xid => $c) {
            // bukti dari nomor PO yang sama persis
            $evidence = [];
            foreach ($this->pos as $po) {
                if ($po['customer'] !== $xid || $po['number'] === null) {
                    continue;
                }
                foreach ($this->poByNumber[$this->normNumber($po['number'])] ?? [] as $dbPo) {
                    $cid = $this->dbPos[$dbPo]['customer_id'];
                    if ($cid !== null) {
                        $evidence[(int) $cid] = ($evidence[(int) $cid] ?? 0) + 1;
                    }
                }
            }
            arsort($evidence);
            $names = [];
            foreach (array_merge([$c['name']], $c['variants']) as $n) {
                foreach ($byName[$this->alnum((string) $n)] ?? [] as $id) {
                    $names[$id] = true;
                }
            }
            $names = array_keys($names);
            $map = ['id' => null, 'method' => null, 'group' => [], 'evidence' => $evidence, 'candidates' => $names, 'action' => null];
            if ($names !== []) {
                $map['id'] = $this->pickCustomer($names, $evidence);
                $map['method'] = 'EXACT_NAME';
                $map['group'] = $names;
                $top = array_key_first($evidence);
                if ($top !== null && !in_array($top, $names, true) && $evidence[$top] >= 2) {
                    $this->issue('CUSTOMER EVIDENCE CONFLICT', 'CUSTOMERS', $map['id'], $xid, 'Nama customer "' . $c['name'] . '" cocok dengan ' . $this->custLabel($map['id'])
                        . ', tetapi ' . $evidence[$top] . ' nomor PO-nya tercatat atas customer ' . $this->custLabel($top) . '. Periksa apakah keduanya customer yang sama.', ['field_name' => 'customer']);
                }
            } elseif ($evidence !== [] && ($top = (int) array_key_first($evidence)) && $evidence[$top] >= 2 && $evidence[$top] >= 0.8 * array_sum($evidence)) {
                $map['id'] = $top;
                $map['method'] = 'PO_NUMBER';
                $map['group'] = [$top];
            } else {
                $similar = $this->similarCustomers((string) $c['name']);
                $map['group'] = [-1]; // customer baru/ambigu: jangan pakai PO milik customer lain
                if ($similar === []) {
                    $map['method'] = 'NEW';
                } else {
                    $map['method'] = 'REVIEW';
                    $this->issue('CUSTOMER NOT MAPPED', 'CUSTOMERS', null, $xid, 'Customer "' . $c['name'] . '" di file PO tidak cocok persis dengan customer mana pun, tetapi mirip dengan: '
                        . implode('; ', array_map(fn (int $id): string => $this->custLabel($id), $similar)) . '. Customer TIDAK dibuat agar tidak ganda; PO-nya diimport tanpa customer. Hubungkan lewat Edit PO.', ['field_name' => 'customer']);
                    $this->finding('warning', 'CUSTOMERS', $c['row'], null, 'CUSTOMER_NAME', 'Customer "' . $c['name'] . '" ambigu (mirip customer yang sudah ada)', 'Tinjau di Migration Issues, lalu hubungkan PO ke customer yang benar.');
                }
            }
            if (count($names) > 1) {
                $this->issue('CUSTOMER DUPLICATE RECORDS', 'CUSTOMERS', $map['id'], $xid, 'Customer "' . $c['name'] . '" tercatat ganda di aplikasi: '
                    . implode('; ', array_map(fn (int $id): string => $this->custLabel($id), $names)) . '. PO baru dihubungkan ke ' . $this->custLabel($map['id'])
                    . ' (paling banyak PO). Gabungkan data customer bila memang sama.', ['field_name' => 'customer']);
            }
            $this->customerMap[$xid] = $map;
        }
    }

    /** @param list<int> $ids @param array<int,int> $evidence */
    private function pickCustomer(array $ids, array $evidence): int
    {
        usort($ids, function (int $a, int $b) use ($evidence): int {
            return [($evidence[$b] ?? 0), (int) $this->dbCustomers[$b]['po_count'], -$b] <=> [($evidence[$a] ?? 0), (int) $this->dbCustomers[$a]['po_count'], -$a];
        });
        return $ids[0];
    }

    /** @return list<int> customer yang berbagi kata khas (bukan PT/CV/INDONESIA, dsb.) */
    private function similarCustomers(string $name): array
    {
        $tokens = $this->tokens($name);
        if ($tokens === []) {
            return [];
        }
        $out = [];
        foreach ($this->dbCustomers as $id => $c) {
            if (array_intersect($tokens, $this->tokens((string) $c['name'])) !== []) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, 5);
    }

    // ---------------------------------------------------------------------
    // 4. Purchase order
    // ---------------------------------------------------------------------

    private function matchPurchaseOrders(): void
    {
        foreach ($this->pos as $xid => $po) {
            if (!$po['valid']) {
                $this->poMap[$xid] = ['action' => 'failed', 'id' => null, 'method' => null];
                continue;
            }
            $group = $this->customerMap[$po['customer']]['group'];
            $qty = $this->excelQty($xid);
            $match = null;
            $method = null;

            if (isset($this->poByImportRef[$xid])) {
                $match = $this->poByImportRef[$xid];
                $method = 'IMPORT_REF';
            } elseif ($po['number'] !== null) {
                $all = $this->poByNumber[$this->normNumber($po['number'])] ?? [];
                $cands = array_values(array_filter($all, fn (int $id): bool => $this->available($id, $xid) && $this->customerOk($id, $group)));
                if (count($cands) === 1) {
                    $match = $cands[0];
                    $method = 'PO_NUMBER';
                } elseif (count($cands) > 1) {
                    $match = $this->pickByContent($cands, $qty, $po['date']);
                    $method = 'PO_NUMBER';
                    if ($match === null) {
                        $this->hold($xid, 'PO MATCH AMBIGUOUS', 'Nomor PO ' . $po['number'] . ' dipakai ' . count($cands) . ' PO di aplikasi (' . $this->poLabels($cands)
                            . ') dan isi item/tanggal tidak bisa membedakannya. Pilih PO yang sesuai dengan dokumen ini (' . ($po['source_file'] ?? $xid)
                            . ', grand total ' . ($po['grand_total'] ?? '-') . '), lalu jalankan import lagi.', $cands[0], $this->candidateExtra($xid, $cands));
                        continue;
                    }
                } elseif ($all !== [] && array_filter($all, fn (int $id): bool => $this->available($id, $xid)) !== []) {
                    $this->hold($xid, 'PO NUMBER USED BY OTHER CUSTOMER', 'Nomor PO ' . $po['number'] . ' sudah dipakai PO customer lain di aplikasi (' . $this->poLabels($all)
                        . '). PO dari file (customer ' . $po['customer_name'] . ') belum diimport agar nomor tidak ganda.', $all[0]);
                    continue;
                } else {
                    $alnum = $this->alnum($po['number']);
                    $cands = strlen($alnum) >= 4 ? array_values(array_filter($this->poByAlnum[$alnum] ?? [], fn (int $id): bool => $this->available($id, $xid) && $this->customerOk($id, $group, false))) : [];
                    if (count($cands) === 1) {
                        $match = $cands[0];
                        $method = 'PO_NUMBER_FORMAT';
                        $this->issue('PO NUMBER FORMAT DIFFERS', 'PURCHASE_ORDERS', $match, $xid, 'Nomor PO di dokumen "' . $po['number'] . '", di aplikasi "' . $this->dbPos[$match]['po_number']
                            . '" (beda tanda baca/spasi saja, customer sama). Data dokumen dihubungkan ke PO ini; perbaiki nomor bila perlu.', ['field_name' => 'po_number', 'master_value' => $this->dbPos[$match]['po_number'], 'suggested_value' => $po['number']]);
                    }
                }
            } else {
                $cands = array_values(array_filter(array_keys($this->dbPos), fn (int $id): bool => $this->available($id, $xid)
                    && trim((string) $this->dbPos[$id]['po_number']) === '' && $this->customerOk($id, $group, false)
                    && $this->dbPos[$id]['po_date'] === $po['date'] && $this->dbQty($id) === $qty));
                if (count($cands) === 1) {
                    $match = $cands[0];
                    $method = 'DATE_QTY';
                    $this->issue('PO MATCHED WITHOUT NUMBER', 'PURCHASE_ORDERS', $match, $xid, 'Dokumen PO tanpa nomor (' . ($po['source_file'] ?? '') . ') dihubungkan ke ' . $this->dbPos[$match]['code']
                        . ' karena customer, tanggal (' . $po['date'] . ') dan qty item sama persis. Periksa bila keliru.', ['field_name' => 'po_number']);
                }
            }

            if ($match === null) {
                $possible = $this->possibleExisting($xid, $po, $group, $qty);
                if ($possible !== []) {
                    $decision = $this->existingIssues[$this->issueCode('POSSIBLE EXISTING PO', $xid)]['resolution_status'] ?? null;
                    if ($decision !== 'Ignored') {
                        $this->hold($xid, 'POSSIBLE EXISTING PO', 'PO dari file (' . ($po['number'] ?? 'tanpa nomor') . ', ' . ($po['date'] ?? '?') . ', ' . $po['customer_name']
                            . ') kemungkinan sama dengan ' . $this->poLabels($possible) . ', tetapi tidak cocok persis. Pilih "Hubungkan" bila sama, atau "Abaikan" bila berbeda'
                            . ' (PO akan dibuat baru saat import berikutnya).', $possible[0], $this->candidateExtra($xid, $possible));
                        continue;
                    }
                }
                $this->poMap[$xid] = ['action' => 'insert', 'id' => null, 'method' => 'NEW'];
                continue;
            }
            $this->claimed[$match] = true;
            $this->poMap[$xid] = ['action' => 'match', 'id' => $match, 'method' => $method];
            $dbStatus = (string) $this->dbPos[$match]['status'];
            if ($po['count_in_summary'] === ($dbStatus === 'Cancelled')) {
                $this->issue('PO STATUS DIFFERS FROM DOCUMENT', 'PURCHASE_ORDERS', $match, $xid, $po['count_in_summary']
                    ? 'Dokumen PO berlaku (dihitung di file), tetapi PO di aplikasi berstatus Cancelled. Status tidak diubah; pastikan mana yang benar.'
                    : 'Dokumen ini versi lama yang sudah direvisi (COUNT_IN_SUMMARY = N), tetapi PO di aplikasi berstatus ' . $dbStatus . '. Status tidak diubah; pastikan versi mana yang berlaku.',
                    ['field_name' => 'status', 'master_value' => $dbStatus, 'suggested_value' => $po['count_in_summary'] ? 'ISSUED (berlaku)' : 'Versi lama', 'legacy_row' => $po['row']]);
            }
            $this->pairLines($xid, $match);
        }
    }

    private function available(int $dbPoId, string $xid): bool
    {
        $ref = $this->dbPos[$dbPoId]['import_ref'];
        return !isset($this->claimed[$dbPoId]) && ($ref === null || $ref === $xid);
    }

    /** @param list<int> $group */
    private function customerOk(int $dbPoId, array $group, bool $allowNull = true): bool
    {
        $cid = $this->dbPos[$dbPoId]['customer_id'];
        if ($cid === null) {
            return $allowNull;
        }
        return $group === [] || in_array((int) $cid, $group, true);
    }

    /** @param list<int> $cands */
    private function pickByContent(array $cands, string $qty, ?string $date): ?int
    {
        $sameQty = array_values(array_filter($cands, fn (int $id): bool => $this->dbQty($id) === $qty));
        if (count($sameQty) === 1) {
            return $sameQty[0];
        }
        $pool = $sameQty !== [] ? $sameQty : $cands;
        // Tanggal hanya jadi pembeda bila SEMUA kandidat bertanggal; kandidat tanpa tanggal
        // tidak boleh membuat kandidat lain "menang" begitu saja (itu tebakan).
        foreach ($pool as $id) {
            if ($this->dbPos[$id]['po_date'] === null || $date === null) {
                return null;
            }
        }
        $sameDate = array_values(array_filter($pool, fn (int $id): bool => $this->dbPos[$id]['po_date'] === $date));
        return count($sameDate) === 1 ? $sameDate[0] : null;
    }

    /** @param list<int> $group @return list<int> */
    private function possibleExisting(string $xid, array $po, array $group, string $qty): array
    {
        $out = [];
        $alnum = $po['number'] !== null ? $this->alnum($po['number']) : '';
        foreach ($this->dbPos as $id => $db) {
            if (!$this->available($id, $xid) || !$this->customerOk($id, $group, false)) {
                continue;
            }
            $dbAlnum = $this->alnum((string) $db['po_number']);
            $numberHint = $alnum !== '' && strlen($alnum) >= 5 && $dbAlnum !== '' && (str_ends_with($dbAlnum, $alnum) || str_ends_with($alnum, $dbAlnum));
            $noNumberHint = $alnum === '' && $dbAlnum === '' && $this->dbQty($id) === $qty;
            if ($numberHint || $noNumberHint) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, 3);
    }

    /**
     * Data untuk aksi "Hubungkan" di Migration Issues: suggested_value = ID PO di file,
     * master_value = kode PO kandidat di aplikasi (dipisah koma).
     * @param list<int> $cands
     */
    private function candidateExtra(string $xid, array $cands): array
    {
        return ['suggested_value' => $xid, 'master_value' => implode(',', array_map(fn (int $id): string => (string) $this->dbPos[$id]['code'], array_slice($cands, 0, 5)))];
    }

    private function hold(string $xid, string $type, string $description, ?int $relatedPo, array $extra = []): void
    {
        $po = $this->pos[$xid];
        $this->poMap[$xid] = ['action' => 'hold', 'id' => null, 'method' => $type];
        $this->issue($type, 'PURCHASE_ORDERS', $relatedPo, $xid, $description, $extra + ['field_name' => 'po_number', 'legacy_row' => $po['row']]);
        $this->finding('warning', 'PO_MASTER', $po['row'], $po['number'], 'PO_NUMBER', $description, 'Tinjau di Migration Issues, lalu jalankan import lagi.');
    }

    // ---------------------------------------------------------------------
    // 5. Baris PO
    // ---------------------------------------------------------------------

    private function pairLines(string $xid, int $dbPoId): void
    {
        $itemIds = $this->itemsByPo[$xid] ?? [];
        $lines = $this->dbLines[$dbPoId] ?? [];
        $byRef = [];
        foreach ($lines as $l) {
            if ($l['import_ref'] !== null) {
                $byRef[(string) $l['import_ref']] = $l;
            }
        }
        $freeLines = array_values(array_filter($lines, static fn (array $l): bool => $l['import_ref'] === null || !in_array((string) $l['import_ref'], $itemIds, true)));
        $freeItems = [];
        foreach ($itemIds as $iid) {
            if (isset($byRef[$iid])) {
                $this->lineMap[$iid] = ['action' => 'match', 'id' => (int) $byRef[$iid]['id'], 'line' => $byRef[$iid], 'method' => 'IMPORT_REF'];
            } else {
                $freeItems[] = $iid;
            }
        }
        if ($freeItems === []) {
            return;
        }
        $itemQty = array_map(fn (string $iid): int => (int) $this->items[$iid]['qty'], $freeItems);
        $lineQty = array_map(static fn (array $l): int => (int) $l['order_qty'], $freeLines);
        $a = $itemQty;
        $b = $lineQty;
        sort($a);
        sort($b);
        if ($a !== $b) {
            $po = $this->pos[$xid];
            foreach ($freeItems as $iid) {
                $this->lineMap[$iid] = ['action' => 'skip', 'id' => null, 'method' => 'ITEMS DIFFER'];
            }
            $this->issue('PO ITEMS DIFFER FROM DOCUMENT', 'PURCHASE_ORDERS', $dbPoId, $xid, 'Item di dokumen PO (qty: ' . implode(', ', array_map([$this, 'fmtQty'], $itemQty)) . ') berbeda dengan baris PO di aplikasi (qty: '
                . implode(', ', array_map([$this, 'fmtQty'], $lineQty)) . '). Baris PO di aplikasi TIDAK diubah; harga satuan dari dokumen belum masuk. Sesuaikan manual bila perlu.', ['field_name' => 'order_qty', 'legacy_row' => $po['row']]);
            $this->finding('warning', 'PO_ITEMS', $this->items[$freeItems[0]]['row'], $po['number'], 'QUANTITY', 'Item PO berbeda dengan baris PO di aplikasi', 'Tinjau di Migration Issues.');
            return;
        }
        // pasangkan per qty; qty kembar dibedakan lewat kode/nama produk, lalu urutan
        $usedLines = [];
        $byQty = [];
        foreach ($freeItems as $iid) {
            $byQty[(int) $this->items[$iid]['qty']][] = $iid;
        }
        foreach ($byQty as $q => $group) {
            $cands = array_values(array_filter($freeLines, static fn (array $l): bool => (int) $l['order_qty'] === $q));
            if (count($group) === 1) {
                $this->lineMap[$group[0]] = ['action' => 'match', 'id' => (int) $cands[0]['id'], 'line' => $cands[0], 'method' => 'QTY'];
                continue;
            }
            $remainingItems = [];
            foreach ($group as $iid) {
                $same = array_values(array_filter($cands, fn (array $l): bool => !isset($usedLines[(int) $l['id']]) && $this->sameProduct($iid, $l)));
                if (count($same) === 1) {
                    $usedLines[(int) $same[0]['id']] = true;
                    $this->lineMap[$iid] = ['action' => 'match', 'id' => (int) $same[0]['id'], 'line' => $same[0], 'method' => 'QTY_PRODUCT'];
                } else {
                    $remainingItems[] = $iid;
                }
            }
            if ($remainingItems === []) {
                continue;
            }
            usort($remainingItems, fn (string $x, string $y): int => [$this->items[$x]['line_no'] ?? 0, $this->items[$x]['row']] <=> [$this->items[$y]['line_no'] ?? 0, $this->items[$y]['row']]);
            $restLines = array_values(array_filter($cands, static fn (array $l): bool => !isset($usedLines[(int) $l['id']])));
            $prices = array_unique(array_map(fn (string $iid): string => (string) $this->items[$iid]['unit_price'], $remainingItems));
            foreach ($remainingItems as $k => $iid) {
                $usedLines[(int) $restLines[$k]['id']] = true;
                $this->lineMap[$iid] = ['action' => 'match', 'id' => (int) $restLines[$k]['id'], 'line' => $restLines[$k], 'method' => 'QTY_ORDER'];
            }
            if (count($remainingItems) > 1 && count($prices) > 1) {
                $po = $this->pos[$xid];
                $this->issue('LINE PAIRED BY ORDER', 'PURCHASE_ORDERS', $dbPoId, $xid . ':' . $q, 'Beberapa item dengan qty sama (' . $this->fmtQty($q) . ') dan harga berbeda dipasangkan ke baris PO menurut urutan. Periksa harga satuan tiap baris.',
                    ['field_name' => 'unit_price', 'legacy_row' => $po['row']]);
            }
        }
    }

    private function sameProduct(string $iid, array $line): bool
    {
        $item = $this->items[$iid];
        $codes = array_filter([$item['code'], $this->products[$item['product']]['code'] ?? null]);
        foreach ($codes as $code) {
            $c = $this->alnum($code);
            if ($line['product_code'] !== null && $c === $this->alnum((string) $line['product_code'])) {
                return true;
            }
            // nama produk lama sering memuat kode, mis. "[NBK060170170] Botol RF 50 ml"
            if (strlen($c) >= 6 && (str_contains($this->alnum((string) $line['product_name']), $c) || str_contains($this->alnum((string) $line['product_name_legacy']), $c))) {
                return true;
            }
        }
        $names = array_filter([$item['name'], $this->products[$item['product']]['name'] ?? null]);
        foreach ($names as $name) {
            if ($this->alnum($name) === $this->alnum((string) $line['product_name']) || $this->alnum($name) === $this->alnum((string) $line['product_name_legacy'])) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // 6. Produk (hanya untuk baris PO baru)
    // ---------------------------------------------------------------------

    private function mapProducts(): void
    {
        $byCode = [];
        $byName = [];
        foreach ($this->dbProducts as $id => $p) {
            if (!empty($p['product_code'])) {
                $byCode[$this->alnum((string) $p['product_code'])][] = $id;
            }
            $byName[$this->alnum((string) $p['name'])][] = $id;
        }
        $usage = [];
        foreach ($this->dbLines as $lines) {
            foreach ($lines as $l) {
                $usage[(int) $l['product_id']] = ($usage[(int) $l['product_id']] ?? 0) + 1;
            }
        }
        // produk yang dipakai baris PO yang sudah ada
        foreach ($this->lineMap as $iid => $m) {
            if ($m['action'] === 'match' && !isset($this->productMap[$this->items[$iid]['product']])) {
                $this->productMap[$this->items[$iid]['product']] = ['id' => (int) $m['line']['product_id'], 'method' => 'VIA_PO_LINE', 'action' => 'none'];
            }
        }
        $needed = [];
        foreach ($this->poMap as $xid => $m) {
            if ($m['action'] === 'insert') {
                foreach ($this->itemsByPo[$xid] ?? [] as $iid) {
                    $needed[$this->items[$iid]['product']] = true;
                }
            }
        }
        foreach (array_keys($needed) as $pid) {
            if (isset($this->productMap[$pid])) {
                continue; // sudah terpetakan lewat baris PO yang sudah ada
            }
            $p = $this->products[$pid];
            $codeCands = $p['code'] !== null ? ($byCode[$this->alnum($p['code'])] ?? []) : [];
            $nameCands = $byName[$this->alnum((string) $p['name'])] ?? [];
            $cands = $codeCands !== [] ? $codeCands : $nameCands;
            $method = $codeCands !== [] ? 'CODE' : ($nameCands !== [] ? 'EXACT_NAME' : 'NEW');
            if ($cands === []) {
                $this->productMap[$pid] = ['id' => null, 'method' => 'NEW', 'action' => 'insert'];
                continue;
            }
            if (count($cands) > 1 && $codeCands !== []) {
                $narrow = array_values(array_intersect($codeCands, $nameCands));
                $cands = $narrow !== [] ? $narrow : $cands;
            }
            usort($cands, static fn (int $a, int $b): int => [($usage[$b] ?? 0), -$b] <=> [($usage[$a] ?? 0), -$a]);
            $this->productMap[$pid] = ['id' => $cands[0], 'method' => $method, 'action' => 'none'];
            if (count($cands) > 1) {
                $this->issue('PRODUCT DUPLICATE RECORDS', 'PRODUCTS', $cands[0], $pid, 'Produk "' . $p['name'] . '"' . ($p['code'] ? ' (kode ' . $p['code'] . ')' : '') . ' cocok dengan beberapa produk di aplikasi: '
                    . implode('; ', array_map(fn (int $id): string => $this->dbProducts[$id]['code'] . ' ' . $this->dbProducts[$id]['name'], $cands)) . '. Baris PO baru memakai '
                    . $this->dbProducts[$cands[0]]['code'] . ' (paling sering dipakai). Gabungkan data produk bila memang sama.', ['field_name' => 'product']);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 7. Menulis (atau mensimulasikan) perubahan
    // ---------------------------------------------------------------------

    private function applyCustomers(): void
    {
        foreach ($this->customerMap as $xid => &$map) {
            $c = $this->customers[$xid];
            if ($map['method'] === 'NEW') {
                $map['id'] = $this->insert('customers', [
                    'code' => $this->write ? Code::generate('customers') : 'CUS-NEW', 'customer_code' => $this->cut($c['code'], 20),
                    'name' => $this->cut((string) $c['name'], 190), 'company' => $this->cut((string) $c['name'], 190), 'pic' => $this->cut($c['contact'], 120),
                    'phone' => $this->cut($c['phone'], 40), 'email' => $this->validEmail($c['email']), 'address' => $c['address'],
                    'status' => 'Active', 'source' => self::SOURCE, 'notes' => $c['notes'], 'created_by' => $this->userId, 'updated_by' => $this->userId,
                ]);
                $map['action'] = 'insert';
                $map['group'] = [$map['id']];
                continue;
            }
            if ($map['id'] === null) {
                $map['action'] = 'review';
                continue;
            }
            $db = $this->dbCustomers[$map['id']];
            $fill = array_filter([
                'customer_code' => $db['customer_code'] === null ? $this->cut($c['code'], 20) : null,
                'phone'         => empty($db['phone']) ? $this->cut($c['phone'], 40) : null,
                'email'         => empty($db['email']) ? $this->validEmail($c['email']) : null,
                'address'       => empty($db['address']) ? $c['address'] : null,
                'pic'           => empty($db['pic']) ? $this->cut($c['contact'], 120) : null,
            ], static fn ($v): bool => $v !== null && $v !== '');
            if ($fill !== []) {
                $this->update('customers', $map['id'], $fill + ['updated_by' => $this->userId]);
                $map['action'] = 'update';
            } else {
                $map['action'] = 'none';
            }
        }
        unset($map);
    }

    private function applyProducts(): void
    {
        foreach ($this->productMap as $pid => &$map) {
            if ($map['action'] !== 'insert') {
                continue;
            }
            $p = $this->products[$pid];
            $map['id'] = $this->insert('products', [
                'code' => $this->write ? Code::generate('products') : 'PRD-NEW', 'name' => $this->cut((string) $p['name'], 190),
                'product_code' => $this->cut($p['code'], 60), 'variant' => $this->cut($p['spec'], 255), 'unit' => $this->productUnit($p['unit']),
                'is_active' => 1, 'source' => self::SOURCE, 'notes' => $p['description'], 'created_by' => $this->userId, 'updated_by' => $this->userId,
            ]);
        }
        unset($map);
    }

    private function applyPurchaseOrders(): void
    {
        foreach ($this->poMap as $xid => &$map) {
            $po = $this->pos[$xid];
            if ($map['action'] === 'insert') {
                $customerId = $this->customerMap[$po['customer']]['id'];
                $owned = $this->poOwned($po);
                $data = [
                    'code' => $this->write ? Code::generate('purchase_orders') : 'PO-NEW', 'po_number' => $this->cut($po['number'], 80),
                    'customer_id' => $customerId, 'po_date' => $po['date'], 'payment_term' => $po['payment_term'],
                    'status' => $po['count_in_summary'] ? 'Open' : 'Cancelled', 'legacy_status' => $this->cut($po['po_status'], 40),
                    'remark' => $po['notes'], 'source_file' => $this->cut($po['source_file'], 255), 'source_sheet' => 'PO_MASTER', 'legacy_row' => $po['row'],
                    'import_ref' => $xid, 'import_log_id' => $this->logId, 'import_status' => 'OK', 'created_by' => $this->userId, 'updated_by' => $this->userId,
                ] + $owned;
                $data['import_snapshot'] = $this->snapshot(array_intersect_key($data, self::PO_OWNED + ['po_date' => 1, 'payment_term' => 1, 'po_number' => 1]));
                $map['id'] = $this->insert('purchase_orders', $data);
                if ($po['number'] !== null) {
                    $norm = $this->normNumber($po['number']);
                    if (isset($this->poByNumber[$norm]) || isset($this->insertedNumbers[$norm])) {
                        $this->issue('DUPLICATE PO NUMBER', 'PURCHASE_ORDERS', $map['id'], $xid, 'Nomor PO ' . $po['number'] . ' sudah dipakai PO lain (revisi dokumen atau salinan). PO dibuat terpisah sesuai file; periksa dan batalkan yang tidak berlaku.', ['field_name' => 'po_number', 'legacy_row' => $po['row']]);
                    }
                    $this->insertedNumbers[$norm] = true;
                }
                if (!$po['count_in_summary']) {
                    $this->issue('SUPERSEDED PO VERSION', 'PURCHASE_ORDERS', $map['id'], $xid, 'Dokumen ini versi lama yang sudah direvisi (COUNT_IN_SUMMARY = N di file). PO diimport berstatus Cancelled agar tidak terhitung ganda; ubah status bila keliru.', ['field_name' => 'status', 'legacy_row' => $po['row']]);
                }
                if ($customerId === null) {
                    $this->issue('PO WITHOUT CUSTOMER', 'PURCHASE_ORDERS', $map['id'], $xid, 'Customer "' . $po['customer_name'] . '" belum terpetakan. Buka PO lalu pilih customer lewat Edit.', ['field_name' => 'customer_id', 'legacy_row' => $po['row']]);
                }
                foreach ($this->itemsByPo[$xid] ?? [] as $iid) {
                    $item = $this->items[$iid];
                    $line = [
                        'code' => $this->write ? Code::generate('po_lines') : 'POL-NEW', 'po_id' => $map['id'], 'product_id' => $this->productMap[$item['product']]['id'],
                        'product_name_legacy' => $this->cut($item['name'], 255), 'variant_legacy' => $this->cut($item['spec'], 255), 'order_qty' => $item['qty'],
                        'remark' => $item['notes'], 'source_file' => $this->cut($po['source_file'], 255), 'source_sheet' => 'PO_ITEMS', 'legacy_row' => $item['row'],
                        'import_ref' => $iid, 'created_by' => $this->userId, 'updated_by' => $this->userId,
                    ] + $this->lineOwned($item);
                    $line['import_snapshot'] = $this->snapshot(array_intersect_key($line, self::LINE_OWNED));
                    $this->lineMap[$iid] = ['action' => 'insert', 'id' => $this->insert('po_lines', $line), 'method' => 'NEW'];
                }
                $map['result'] = 'inserted';
                continue;
            }
            if ($map['action'] !== 'match') {
                if ($map['action'] === 'hold' || $map['action'] === 'failed') {
                    foreach ($this->itemsByPo[$xid] ?? [] as $iid) {
                        $this->lineMap[$iid] = ['action' => 'skip', 'id' => null, 'method' => $map['action'] === 'hold' ? 'PO HELD' : 'PO FAILED'];
                    }
                }
                continue;
            }
            $changed = $this->updateExistingPo($xid, $map['id']);
            $lineChanges = 0;
            foreach ($this->itemsByPo[$xid] ?? [] as $iid) {
                if (($this->lineMap[$iid]['action'] ?? null) === 'match') {
                    $lineChanges += $this->updateExistingLine($xid, $iid) ? 1 : 0;
                }
            }
            $map['result'] = $changed ? 'updated' : 'unchanged';
            $map['line_changes'] = $lineChanges;
        }
        unset($map);
    }

    private function updateExistingPo(string $xid, int $dbId): bool
    {
        $po = $this->pos[$xid];
        $db = $this->dbPos[$dbId];
        $snap = $this->readSnapshot($db['import_snapshot']);
        $desired = $this->poOwned($po);
        $set = [];
        $newSnap = $snap;
        foreach (self::PO_OWNED as $col => $type) {
            $new = $desired[$col] ?? null;
            if ($new === null) {
                continue;
            }
            $cur = $db[$col];
            if ($cur === null || $cur === '') {
                $set[$col] = $new;
                $newSnap[$col] = $this->norm($new, $type);
            } elseif ($this->same($cur, $new, $type)) {
                $newSnap[$col] = $this->norm($new, $type);
            } elseif (array_key_exists($col, $snap) && $snap[$col] === $this->norm($cur, $type)) {
                $set[$col] = $new; // ditulis import sebelumnya & belum diubah user: aman diperbarui
                $newSnap[$col] = $this->norm($new, $type);
            } else {
                $this->issue('VALUE CONFLICT', 'PURCHASE_ORDERS', $dbId, $xid . ':' . $col, "Nilai {$col} di aplikasi (" . $this->norm($cur, $type) . ') berbeda dengan dokumen PO (' . $this->norm($new, $type)
                    . '). Nilai di aplikasi tidak diubah.', ['field_name' => $col, 'master_value' => $this->norm($cur, $type), 'suggested_value' => $this->norm($new, $type), 'legacy_row' => $po['row']]);
            }
        }
        $flagged = $this->flaggedFields[$xid] ?? [];
        if (($db['po_number'] === null || trim((string) $db['po_number']) === '') && $po['number'] !== null) {
            $set['po_number'] = $this->cut($po['number'], 80);
        }
        if ($db['customer_id'] === null && ($cid = $this->customerMap[$po['customer']]['id']) !== null) {
            $set['customer_id'] = $cid;
        }
        if ($po['date'] !== null) {
            if ($db['po_date'] === null) {
                if (!in_array('PO_DATE', $flagged, true)) {
                    $set['po_date'] = $po['date'];
                }
            } elseif ($db['po_date'] !== $po['date']) {
                $this->issue('PO DATE DIFFERS FROM DOCUMENT', 'PURCHASE_ORDERS', $dbId, $xid, 'Tanggal PO di aplikasi ' . $db['po_date'] . ', di dokumen PO ' . $po['date']
                    . (in_array('PO_DATE', $flagged, true) ? ' (tanggal dokumen juga ditandai perlu dicek di sheet VALIDATION)' : '')
                    . '. Tanggal di aplikasi tidak diubah; pilih nilai yang benar.', ['field_name' => 'po_date', 'master_value' => $db['po_date'], 'suggested_value' => $po['date'], 'legacy_row' => $po['row']]);
            }
        }
        if ((($db['payment_term'] ?? '') === '') && $po['payment_term'] !== null) {
            $set['payment_term'] = $po['payment_term'];
        }
        if ((($db['remark'] ?? '') === '') && $po['notes'] !== null) {
            $set['remark'] = $po['notes'];
        }
        if ($db['import_ref'] === null) {
            $set['import_ref'] = $xid;
        }
        if ($set === []) {
            return false;
        }
        $set['import_snapshot'] = $this->snapshot($newSnap);
        $set['import_log_id'] = $this->logId;
        $set['updated_by'] = $this->userId;
        $this->update('purchase_orders', $dbId, $set);
        if ($this->write) {
            $fixed = array_merge(isset($set['po_date']) ? ['PO DATE MISSING'] : [], isset($set['po_number']) ? ['PO NUMBER MISSING'] : [], isset($set['customer_id']) ? ['CUSTOMER NOT FOUND'] : []);
            MigrationIssue::resolveForRecord('PURCHASE_ORDERS', $dbId, $fixed, 'Dilengkapi dari dokumen PO (' . $this->filename . ').');
        }
        return true;
    }

    private function updateExistingLine(string $xid, string $iid): bool
    {
        $line = $this->lineMap[$iid]['line'];
        $item = $this->items[$iid];
        $snap = $this->readSnapshot($line['import_snapshot']);
        $desired = $this->lineOwned($item);
        $set = [];
        $newSnap = $snap;
        foreach (self::LINE_OWNED as $col => $type) {
            $new = $desired[$col] ?? null;
            if ($new === null) {
                continue;
            }
            $cur = $line[$col];
            if ($cur === null || $cur === '') {
                $set[$col] = $new;
                $newSnap[$col] = $this->norm($new, $type);
            } elseif ($this->same($cur, $new, $type)) {
                $newSnap[$col] = $this->norm($new, $type);
            } elseif (array_key_exists($col, $snap) && $snap[$col] === $this->norm($cur, $type)) {
                $set[$col] = $new;
                $newSnap[$col] = $this->norm($new, $type);
            } else {
                $this->issue('VALUE CONFLICT', 'PO_LINES', (int) $line['id'], $iid . ':' . $col, "Nilai {$col} baris PO di aplikasi (" . $this->norm($cur, $type) . ') berbeda dengan dokumen PO (' . $this->norm($new, $type)
                    . '). Nilai di aplikasi tidak diubah.', ['field_name' => $col, 'master_value' => $this->norm($cur, $type), 'suggested_value' => $this->norm($new, $type), 'legacy_row' => $item['row']]);
            }
        }
        if ($line['import_ref'] === null) {
            $set['import_ref'] = $iid;
        }
        if ($set === []) {
            return false;
        }
        $set['import_snapshot'] = $this->snapshot($newSnap);
        $set['updated_by'] = $this->userId;
        $this->update('po_lines', (int) $line['id'], $set);
        return true;
    }

    private function applyValidationIssues(): void
    {
        foreach ($this->validation as $v) {
            $map = $v['po'] !== null ? ($this->poMap[$v['po']] ?? null) : null;
            $recordId = $map['id'] ?? null;
            $desc = trim(($v['problem'] ?? '') . ($v['action'] ? ' Saran: ' . $v['action'] : '') . ($v['original'] !== null ? ' Teks asli: "' . mb_substr($v['original'], 0, 300) . '".' : '')
                . ' [' . $v['id'] . ($v['check'] ? ', cek ' . $v['check'] : '') . ($v['confidence'] ? ', confidence ' . $v['confidence'] : '') . ']');
            $this->issue($v['status'] !== '' ? $v['status'] : 'REVIEW REQUIRED', $v['po'] !== null ? 'PURCHASE_ORDERS' : 'PO_DATABASE', $recordId, 'VAL:' . $v['id'], $desc, [
                'field_name' => $v['field'] !== null ? strtolower($v['field']) : null, 'master_value' => $v['extracted'], 'legacy_po' => $v['number'],
                'source_file' => $v['source_file'], 'source_sheet' => 'VALIDATION', 'legacy_row' => $v['row'],
                'resolution_status' => $v['status'] === 'OK' ? 'Resolved' : 'Needs Review',
                'resolution_note' => $v['status'] === 'OK' ? 'Status OK di sheet VALIDATION.' : $v['resolution'],
            ]);
        }
        // PO yang ditandai bermasalah di PO_MASTER tetapi tidak punya baris VALIDATION
        $withRows = array_flip(array_filter(array_column($this->validation, 'po')));
        foreach ($this->pos as $xid => $po) {
            if ($po['valid'] && in_array($po['validation_status'], self::REVIEW_STATUSES, true) && !isset($withRows[$xid])) {
                $this->issue($po['validation_status'], 'PURCHASE_ORDERS', $this->poMap[$xid]['id'] ?? null, 'STATUS:' . $xid, 'Status validasi PO di file: ' . $po['validation_status'] . '. ' . ($po['notes'] ?? ''),
                    ['field_name' => 'validation_status', 'legacy_po' => $po['number'], 'source_file' => $po['source_file'], 'source_sheet' => 'PO_MASTER', 'legacy_row' => $po['row']]);
            }
        }
    }

    /** import_status = NEEDS_REVIEW selama PO masih punya issue terbuka. */
    private function applyImportStatus(): void
    {
        $open = [];
        foreach ($this->issues as $i) {
            if ($i['table_name'] === 'PURCHASE_ORDERS' && $i['record_id'] !== null && $i['open']) {
                $open[(int) $i['record_id']] = true;
            }
        }
        $ids = array_values(array_filter(array_map(static fn (array $m): ?int => $m['id'] ?? null, $this->poMap), static fn (?int $id): bool => $id !== null && $id > 0));
        if ($ids !== []) {
            foreach (array_chunk($ids, 200) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                foreach (Database::fetchAll("SELECT DISTINCT record_id FROM migration_issues WHERE table_name = 'PURCHASE_ORDERS' AND resolution_status = 'Needs Review' AND record_id IN ({$in})") as $r) {
                    $open[(int) $r['record_id']] = true;
                }
            }
        }
        foreach ($this->poMap as $xid => &$map) {
            if (!isset($map['id']) || $map['id'] === null) {
                continue;
            }
            $map['needs_review'] = isset($open[$map['id']]);
            $status = $map['needs_review'] ? 'NEEDS_REVIEW' : 'OK';
            if ($this->write && ($this->dbPos[$map['id']]['import_status'] ?? null) !== $status) {
                Database::update('purchase_orders', ['import_status' => $status, 'import_log_id' => $this->logId], 'id = :id', ['id' => $map['id']]);
            }
        }
        unset($map);
    }

    // =====================================================================
    // Ringkasan & rekonsiliasi
    // =====================================================================

    private function summary(): array
    {
        $count = static function (array $map, string $key, string $value): int {
            return count(array_filter($map, static fn (array $m): bool => ($m[$key] ?? null) === $value));
        };
        $customers = [
            'total' => count($this->customers), 'existing' => count(array_filter($this->customerMap, static fn (array $m): bool => in_array($m['method'], ['EXACT_NAME', 'PO_NUMBER'], true))),
            'by_po_number' => $count($this->customerMap, 'method', 'PO_NUMBER'), 'new' => $count($this->customerMap, 'method', 'NEW'),
            'merged' => count(array_filter($this->customerMap, static fn (array $m): bool => count($m['candidates']) > 1)),
            'needs_review' => $count($this->customerMap, 'method', 'REVIEW'), 'updated' => $count($this->customerMap, 'action', 'update'),
        ];
        $usedProducts = [];
        foreach ($this->items as $it) {
            if ($it['product'] !== null) {
                $usedProducts[$it['product']] = true;
            }
        }
        $products = [
            'total' => count($this->products), 'existing' => count(array_filter($this->productMap, static fn (array $m): bool => in_array($m['method'], ['CODE', 'EXACT_NAME', 'VIA_PO_LINE'], true))),
            'via_po_line' => $count($this->productMap, 'method', 'VIA_PO_LINE'), 'new' => $count($this->productMap, 'action', 'insert'),
            'merged' => count(array_filter($this->issues, static fn (array $i): bool => $i['issue_type'] === 'PRODUCT DUPLICATE RECORDS')),
            'needs_review' => 0, 'not_needed' => count(array_diff_key($this->products, $this->productMap)),
        ];
        $pos = [
            'total' => count($this->pos), 'existing' => $count($this->poMap, 'action', 'match'), 'inserted' => $count($this->poMap, 'result', 'inserted'),
            'updated' => $count($this->poMap, 'result', 'updated'), 'unchanged' => $count($this->poMap, 'result', 'unchanged'),
            'held' => $count($this->poMap, 'action', 'hold'), 'failed' => $count($this->poMap, 'action', 'failed') + ($this->counts['po_invalid'] ?? 0),
            'needs_review' => count(array_filter($this->poMap, static fn (array $m): bool => !empty($m['needs_review']))),
            'match_methods' => array_count_values(array_filter(array_map(static fn (array $m): ?string => $m['action'] === 'match' ? $m['method'] : null, $this->poMap))),
        ];
        $lineUpdated = 0;
        foreach ($this->poMap as $m) {
            $lineUpdated += (int) ($m['line_changes'] ?? 0);
        }
        $validItems = count(array_filter($this->items, static fn (array $i): bool => $i['valid']));
        $items = [
            'total' => count($this->items), 'valid' => $validItems, 'invalid' => count($this->items) - $validItems + ($this->counts['item_invalid'] ?? 0),
            'inserted' => $count($this->lineMap, 'action', 'insert'), 'matched' => $count($this->lineMap, 'action', 'match'), 'updated' => $lineUpdated,
            'not_imported' => $count($this->lineMap, 'action', 'skip'),
        ];
        $openIssues = array_values(array_filter($this->issues, static fn (array $i): bool => $i['open']));
        $issueSummary = [
            'new' => count(array_filter($this->issues, static fn (array $i): bool => $i['new'])),
            'existing' => count(array_filter($this->issues, static fn (array $i): bool => !$i['new'])),
            'open' => count($openIssues),
            'by_type' => array_count_values(array_map(static fn (array $i): string => $i['issue_type'], array_filter($this->issues, static fn (array $i): bool => $i['new']))),
        ];
        $rows = [
            'total' => count($this->pos) + count($this->items) + ($this->counts['po_invalid'] ?? 0) + ($this->counts['item_invalid'] ?? 0),
            'inserted' => $customers['new'] + $products['new'] + $pos['inserted'] + $items['inserted'],
            'updated' => $customers['updated'] + $pos['updated'] + $items['updated'],
            'skipped' => $pos['unchanged'] + $pos['held'] + ($items['matched'] - $items['updated']) + $items['not_imported'],
            'failed' => $pos['failed'] + $items['invalid'],
        ];
        return [
            'file' => $this->filename, 'customers' => $customers, 'products' => $products, 'purchase_orders' => $pos, 'po_items' => $items,
            'issues' => $issueSummary, 'rows' => $rows, 'reconciliation' => $this->reconcileFromPlan(),
            'findings' => array_slice($this->findings, 0, 500), 'findings_total' => count($this->findings),
            'issue_list' => array_slice(array_map(static fn (array $i): array => array_intersect_key($i, array_flip(['code', 'issue_type', 'table_name', 'record_id', 'ref', 'description', 'new', 'open'])), $this->issues), 0, 400),
        ];
    }

    /** Perkiraan hasil (dry run) — PO/item yang akan terhubung & nilainya. */
    private function reconcileFromPlan(): array
    {
        $excelTotal = 0;
        $appTotal = 0;
        $diffs = [];
        foreach ($this->pos as $xid => $po) {
            $excel = $po['grand_total'] !== null ? $this->cents($po['grand_total']) : null;
            $excelTotal += $excel ?? 0;
            $map = $this->poMap[$xid] ?? ['action' => 'failed'];
            $app = null;
            $reason = null;
            if ($map['action'] === 'insert') {
                $app = $excel;
            } elseif ($map['action'] === 'match') {
                $cur = $this->dbPos[$map['id']]['grand_total'] ?? null;
                $app = ($cur === null || $cur === '') ? $excel : $this->cents((string) $cur);
                if ($cur !== null && $cur !== '' && $excel !== null && $app !== $excel) {
                    $reason = 'Nilai di aplikasi berbeda & tidak ditimpa (VALUE CONFLICT)';
                }
            } else {
                $reason = $map['action'] === 'hold' ? 'PO ditahan untuk review (' . $map['method'] . ')' : 'Baris tidak valid';
            }
            $appTotal += $app ?? 0;
            if ($reason !== null || $app !== $excel) {
                $diffs[] = ['po_id' => $xid, 'po_number' => $po['number'], 'excel' => $excel !== null ? $this->fromCents($excel) : null,
                    'app' => $app !== null ? $this->fromCents($app) : null, 'difference' => $this->fromCents(($app ?? 0) - ($excel ?? 0)),
                    'reason' => $reason ?? ($excel === null ? 'Grand total kosong di file' : '')];
            }
        }
        $linkedPos = count(array_filter($this->poMap, static fn (array $m): bool => in_array($m['action'], ['insert', 'match'], true)));
        $linkedItems = count(array_filter($this->lineMap, static fn (array $m): bool => in_array($m['action'], ['insert', 'match'], true)));
        return [
            'source' => 'plan', 'excel_po_count' => count($this->pos), 'app_po_count' => $linkedPos, 'po_difference' => $linkedPos - count($this->pos),
            'excel_item_count' => count($this->items), 'app_item_count' => $linkedItems, 'item_difference' => $linkedItems - count($this->items),
            'excel_grand_total' => $this->fromCents($excelTotal), 'app_grand_total' => $this->fromCents($appTotal), 'grand_total_difference' => $this->fromCents($appTotal - $excelTotal),
            'differences' => $diffs, 'item_gaps' => $this->itemGaps(),
        ];
    }

    /** Rekonsiliasi dari isi database setelah import (bukan dari rencana). */
    private function reconcileFromDatabase(): array
    {
        $poIds = array_keys($this->pos);
        $itemIds = array_keys($this->items);
        $dbPos = [];
        foreach (array_chunk($poIds, 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare("SELECT import_ref, po_number, grand_total FROM purchase_orders WHERE import_ref IN ({$marks})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $dbPos[(string) $r['import_ref']] = $r;
            }
        }
        $dbItems = 0;
        foreach (array_chunk($itemIds, 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM po_lines WHERE import_ref IN ({$marks})");
            $stmt->execute($chunk);
            $dbItems += (int) $stmt->fetchColumn();
        }
        $excelTotal = 0;
        $appTotal = 0;
        $diffs = [];
        foreach ($this->pos as $xid => $po) {
            $excel = $po['grand_total'] !== null ? $this->cents($po['grand_total']) : null;
            $excelTotal += $excel ?? 0;
            $row = $dbPos[$xid] ?? null;
            $app = ($row !== null && $row['grand_total'] !== null) ? $this->cents((string) $row['grand_total']) : null;
            $appTotal += $app ?? 0;
            if ($row === null || $app !== $excel) {
                $map = $this->poMap[$xid] ?? ['action' => 'failed', 'method' => null];
                $reason = $row === null ? ($map['action'] === 'hold' ? 'PO ditahan untuk review (' . $map['method'] . ')' : 'PO tidak diimport (baris tidak valid)')
                    : ($excel === null ? 'Grand total kosong di file' : 'Nilai di aplikasi berbeda & tidak ditimpa (VALUE CONFLICT)');
                $diffs[] = ['po_id' => $xid, 'po_number' => $po['number'], 'excel' => $excel !== null ? $this->fromCents($excel) : null,
                    'app' => $app !== null ? $this->fromCents($app) : null, 'difference' => $this->fromCents(($app ?? 0) - ($excel ?? 0)), 'reason' => $reason];
            }
        }
        return [
            'source' => 'database', 'excel_po_count' => count($this->pos), 'app_po_count' => count($dbPos), 'po_difference' => count($dbPos) - count($this->pos),
            'excel_item_count' => count($this->items), 'app_item_count' => $dbItems, 'item_difference' => $dbItems - count($this->items),
            'excel_grand_total' => $this->fromCents($excelTotal), 'app_grand_total' => $this->fromCents($appTotal), 'grand_total_difference' => $this->fromCents($appTotal - $excelTotal),
            'differences' => $diffs, 'item_gaps' => $this->itemGaps(),
        ];
    }

    /** @return list<array<string,mixed>> item yang tidak masuk ke po_lines beserta alasannya */
    private function itemGaps(): array
    {
        $out = [];
        foreach ($this->items as $iid => $item) {
            $m = $this->lineMap[$iid] ?? null;
            if ($m !== null && in_array($m['action'], ['insert', 'match'], true)) {
                continue;
            }
            $reason = match ($m['method'] ?? null) {
                'ITEMS DIFFER' => 'Item dokumen berbeda dengan baris PO di aplikasi (PO ITEMS DIFFER FROM DOCUMENT)',
                'PO HELD'      => 'PO-nya ditahan untuk review',
                'PO FAILED'    => 'PO-nya tidak valid',
                default        => 'Baris item tidak valid',
            };
            $out[] = ['item_id' => $iid, 'po_number' => $item['number'], 'item' => $item['name'], 'qty' => $item['qty'], 'reason' => $reason];
        }
        return $out;
    }

    // =====================================================================
    // Penulisan & issue
    // =====================================================================

    private function insert(string $table, array $data): int
    {
        if (!$this->write) {
            return --$this->fakeId;
        }
        $this->tick();
        return Database::insert($table, $data);
    }

    private function update(string $table, int $id, array $data): void
    {
        if (!$this->write || $id < 0) {
            return;
        }
        $this->tick();
        Database::update($table, $data, 'id = :id', ['id' => $id]);
    }

    private function tick(): void
    {
        $this->writes++;
        if ($this->failAfterWrites !== null && $this->writes > $this->failAfterWrites) {
            throw new RuntimeException('Simulasi kegagalan import (test rollback).');
        }
    }

    private function issueCode(string $type, string $ref): string
    {
        return 'ISS-' . strtoupper(substr(sha1('PODB|' . $type . '|' . $ref), 0, 10));
    }

    private function issue(string $type, string $table, ?int $recordId, string $ref, string $description, array $extra = []): void
    {
        $code = $this->issueCode($type, $ref);
        if (isset($this->issueCodes[$code])) {
            return;
        }
        $this->issueCodes[$code] = true;
        $existing = $this->existingIssues[$code] ?? null;
        $status = $extra['resolution_status'] ?? 'Needs Review';
        $this->issues[] = [
            'code' => $code, 'issue_type' => $type, 'table_name' => $table, 'record_id' => ($recordId !== null && $recordId > 0) ? $recordId : null, 'ref' => $ref,
            'description' => $description, 'new' => $existing === null, 'open' => $existing !== null ? $existing['resolution_status'] === 'Needs Review' : $status === 'Needs Review',
        ];
        if ($existing !== null || !$this->write) {
            return;
        }
        $recordCode = null;
        if ($recordId !== null && $recordId > 0) {
            $recordCode = match ($table) {
                'PURCHASE_ORDERS' => (string) Database::fetchValue('SELECT code FROM purchase_orders WHERE id = :id', ['id' => $recordId]),
                'PO_LINES'        => (string) Database::fetchValue('SELECT code FROM po_lines WHERE id = :id', ['id' => $recordId]),
                'CUSTOMERS'       => (string) Database::fetchValue('SELECT code FROM customers WHERE id = :id', ['id' => $recordId]),
                'PRODUCTS'        => (string) Database::fetchValue('SELECT code FROM products WHERE id = :id', ['id' => $recordId]),
                default           => null,
            };
        }
        $this->tick();
        Database::insert('migration_issues', [
            'code' => $code, 'table_name' => $table, 'record_code' => $recordCode ?: null, 'record_id' => ($recordId !== null && $recordId > 0) ? $recordId : null,
            'issue_type' => mb_substr($type, 0, 60), 'field_name' => isset($extra['field_name']) ? mb_substr((string) $extra['field_name'], 0, 60) : null,
            'master_value' => isset($extra['master_value']) ? mb_substr((string) $extra['master_value'], 0, 255) : null,
            'suggested_value' => isset($extra['suggested_value']) ? mb_substr((string) $extra['suggested_value'], 0, 255) : null,
            'legacy_po' => isset($extra['legacy_po']) ? mb_substr((string) $extra['legacy_po'], 0, 120) : $this->refPoNumber($ref),
            'description' => $description, 'source_file' => isset($extra['source_file']) ? mb_substr((string) $extra['source_file'], 0, 255) : $this->filename,
            'source_sheet' => $extra['source_sheet'] ?? $this->refSheet($table), 'legacy_row' => $extra['legacy_row'] ?? null,
            'import_log_id' => $this->logId, 'resolution_status' => $status, 'resolution_note' => $extra['resolution_note'] ?? null,
            'resolved_at' => $status === 'Resolved' ? $this->now : null,
        ]);
    }

    private function refPoNumber(string $ref): ?string
    {
        $id = explode(':', $ref)[0];
        return isset($this->pos[$id]) ? $this->pos[$id]['number'] : null;
    }

    private function refSheet(string $table): string
    {
        return match ($table) {
            'CUSTOMERS' => 'CUSTOMERS', 'PRODUCTS' => 'PRODUCTS', 'PO_LINES' => 'PO_ITEMS', default => 'PO_MASTER',
        };
    }

    private function finding(string $level, string $sheet, ?int $row, ?string $poNumber, string $field, string $problem, string $action): void
    {
        $this->findings[] = ['level' => $level, 'sheet' => $sheet, 'row' => $row, 'po_number' => $poNumber, 'field' => $field, 'problem' => $problem, 'action' => $action];
    }

    // =====================================================================
    // Nilai
    // =====================================================================

    private function poOwned(array $po): array
    {
        return [
            'payment_term_code' => $this->cut($po['payment_term_code'], 60), 'currency' => $po['currency'], 'price_includes_tax' => $po['price_includes_tax'],
            'subtotal' => $po['subtotal'], 'discount_amount' => $po['discount'], 'tax_amount' => $po['tax'], 'shipping_cost' => $po['shipping'],
            'grand_total' => $po['grand_total'], 'requested_delivery_date' => $po['delivery_date'], 'delivery_address' => $po['delivery_address'],
            'contact_person' => $this->cut($po['contact'], 150), 'doc_type' => $this->cut($po['doc_type'], 40), 'doc_url' => $this->cut($po['doc_url'], 500),
            'data_confidence' => $this->cut($po['confidence'], 10),
        ];
    }

    private function lineOwned(array $item): array
    {
        return [
            'line_no' => $item['line_no'], 'item_code' => $this->cut($item['code'], 80), 'item_description' => $item['description'],
            'unit' => $this->cut($item['unit'], 20), 'unit_price' => $item['unit_price'], 'line_subtotal' => $item['subtotal'],
            'tax_rate' => $item['tax_rate'], 'requested_delivery_date' => $item['delivery_date'], 'data_confidence' => $this->cut($item['confidence'], 10),
        ];
    }

    private function snapshot(array $values): string
    {
        $out = [];
        foreach ($values as $col => $v) {
            $type = self::PO_OWNED[$col] ?? self::LINE_OWNED[$col] ?? 'text';
            if ($v !== null) {
                $out[$col] = $this->norm($v, $type);
            }
        }
        ksort($out);
        return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,string> */
    private function readSnapshot(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? array_map('strval', $data) : [];
    }

    private function norm(mixed $v, string $type): string
    {
        return match ($type) {
            'money' => $this->fromCents($this->cents((string) $v)),
            'rate'  => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'),
            'int'   => (string) (int) $v,
            default => trim((string) $v),
        };
    }

    private function same(mixed $a, mixed $b, string $type): bool
    {
        return $this->norm($a, $type) === $this->norm($b, $type);
    }

    private function excelQty(string $xid): string
    {
        $q = array_map(fn (string $iid): int => (int) $this->items[$iid]['qty'], $this->itemsByPo[$xid] ?? []);
        sort($q);
        return implode(',', $q);
    }

    private function dbQty(int $dbPoId): string
    {
        $q = array_map(static fn (array $l): int => (int) $l['order_qty'], $this->dbLines[$dbPoId] ?? []);
        sort($q);
        return implode(',', $q);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        $text = trim(str_replace("\u{00A0}", ' ', (string) $value));
        return $text === '' ? null : $text;
    }

    private function joinText(?string ...$parts): ?string
    {
        $parts = array_values(array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
        return $parts === [] ? null : implode(' — ', array_unique($parts));
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $y = (int) $value->format('Y');
            return ($y >= 2000 && $y <= 2100) ? $value->format('Y-m-d') : null;
        }
        if (is_string($value) && trim($value) !== '') {
            return Validator::parseDate(trim(substr($value, 0, 10)));
        }
        return null;
    }

    private function money(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format(round((float) $value, 2), 2, '.', '');
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return number_format(round((float) trim($value), 2), 2, '.', '');
        }
        return null;
    }

    private function rate(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $f = (float) $value;
            if ($f > 1 && $f <= 100) {
                $f /= 100; // 11 => 0.11
            }
            return ($f >= 0 && $f <= 1) ? rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.') ?: '0' : null;
        }
        return null;
    }

    private function qty(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value)) {
            return (int) $value;
        }
        return null;
    }

    private function flag(mixed $value): ?int
    {
        $t = strtoupper((string) $this->text($value));
        return match ($t) {
            'Y', 'YES', 'TRUE', '1' => 1,
            'N', 'NO', 'FALSE', '0' => 0,
            default => null,
        };
    }

    private function safeUrl(?string $url): ?string
    {
        return ($url !== null && preg_match('#^https?://#i', $url)) ? $url : null;
    }

    private function validEmail(?string $email): ?string
    {
        return ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) ? mb_substr($email, 0, 190) : null;
    }

    private function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private function productUnit(?string $unit): string
    {
        $u = strtolower((string) $unit);
        return match ($u) {
            '', 'pc', 'pcs', 'ea', 'unit', 'units', 'buah' => 'pcs',
            default => mb_substr($u, 0, 20),
        };
    }

    private function cents(?string $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '0');
        $frac = substr(str_pad($frac, 3, '0'), 0, 3);
        $cents = (int) $int * 100 + intdiv((int) $frac + 5, 10);
        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function fmtQty(int $q): string
    {
        return number_format($q, 0, ',', '.');
    }

    private function normNumber(string $number): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $number));
    }

    private function alnum(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value));
    }

    /** @return list<string> */
    private function tokens(string $name): array
    {
        $words = preg_split('/[^A-Z0-9]+/', mb_strtoupper($name)) ?: [];
        return array_values(array_unique(array_filter($words, static fn (string $w): bool => strlen($w) >= 3 && !in_array($w, self::NAME_STOPWORDS, true))));
    }

    private function custLabel(?int $id): string
    {
        if ($id === null || !isset($this->dbCustomers[$id])) {
            return '(baru)';
        }
        return $this->dbCustomers[$id]['code'] . ' ' . $this->dbCustomers[$id]['name'];
    }

    /** @param list<int> $ids */
    private function poLabels(array $ids): string
    {
        return implode('; ', array_map(fn (int $id): string => $this->dbPos[$id]['code'] . ' ' . ($this->dbPos[$id]['po_number'] ?? '(tanpa nomor)') . ' ' . ($this->dbPos[$id]['po_date'] ?? ''), array_slice($ids, 0, 3)));
    }
}
