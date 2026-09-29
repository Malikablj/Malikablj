<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Helpers\Database;
use App\Helpers\Validator;
use App\Helpers\XlsxReader;
use DateTimeImmutable;
use RuntimeException;

/**
 * Import PIK_Master_Database_AppSheet.xlsx ke database.
 *
 * Prinsip:
 *  - ID stabil dari workbook (CUS-…, PO-…, dst.) disimpan di kolom `code`.
 *  - Jejak sumber (SourceFile, SourceSheet, LegacyRow) dipertahankan.
 *  - Relasi hanya dibuat bila ID-nya ada di workbook atau cocok PERSIS
 *    (mis. nomor PO yang unik). Tidak ada fuzzy matching.
 *  - Record yang tidak aman dipetakan tetap diimpor dan dicatat di
 *    migration_issues (status Needs Review).
 *  - Semua insert dalam satu transaksi: gagal = database tidak berubah.
 */
final class WorkbookImporter
{
    /** Urutan insert (mengikuti foreign key). */
    public const TABLES = [
        'customers', 'products', 'purchase_orders', 'po_lines', 'deliveries', 'returns',
        'stock', 'leadtime', 'inbound_maklon', 'invoices_payments', 'po_financials', 'migration_issues',
    ];

    /** Tabel data bisnis yang dikosongkan oleh --fresh (users, settings, audit log tetap). */
    public const WIPE_ORDER = [
        'notifications', 'migration_issues', 'po_financials', 'invoices_payments', 'inbound_maklon', 'leadtime', 'stock',
        'returns', 'deliveries', 'po_lines', 'follow_up', 'activities', 'leads', 'purchase_orders', 'contacts', 'customers', 'products',
    ];

    /** Sheet master => tabel database (untuk referensi record migration issue). */
    public const SHEET_TABLE = [
        'CUSTOMERS' => 'customers', 'CONTACTS' => 'contacts', 'PRODUCTS' => 'products', 'PURCHASE_ORDERS' => 'purchase_orders',
        'PO_LINES' => 'po_lines', 'DELIVERIES' => 'deliveries', 'RETURNS' => 'returns', 'STOCK' => 'stock',
        'LEADTIME' => 'leadtime', 'INBOUND_MAKLON' => 'inbound_maklon', 'INVOICES_PAYMENTS' => 'invoices_payments',
        'PO_FINANCIALS' => 'po_financials',
    ];

    private const REQUIRED = [
        'CUSTOMERS'         => ['CustomerID', 'CustomerName', 'CustomerStatus'],
        'PRODUCTS'          => ['ProductID', 'ProductName'],
        'PURCHASE_ORDERS'   => ['POID', 'PONumber', 'CustomerID', 'PODate', 'Status'],
        'PO_LINES'          => ['POLineID', 'POID', 'ProductID', 'OrderQuantity'],
        'DELIVERIES'        => ['DeliveryID', 'POID', 'POLineID', 'ProductID', 'DeliveryDate', 'DeliveredQuantity'],
        'RETURNS'           => ['ReturnID', 'POID', 'ReturnQuantity'],
        'STOCK'             => ['StockID', 'ProductID', 'StockType', 'Quantity'],
        'LEADTIME'          => ['LeadTimeID', 'POID', 'Quantity', 'DeliveryDate'],
        'INBOUND_MAKLON'    => ['InboundID', 'SJNumber', 'Quantity'],
        'INVOICES_PAYMENTS' => ['InvoicePaymentID', 'POID', 'InvoiceNumber', 'InvoiceAmount'],
        'PO_FINANCIALS'     => ['POFinancialID', 'POID', 'PONumberLegacy'],
        'MIGRATION_ISSUES'  => ['IssueID', 'TableName', 'RecordID', 'IssueType'],
    ];

    private XlsxReader $master;
    private ?LegacyVerifier $verifier = null;
    private string $now;

    /** @var array<string,list<array{row:int,data:array<string,mixed>}>> */
    private array $sheets = [];
    /** @var array<string,list<array<string,mixed>>> tabel => baris siap insert */
    private array $rows = [];
    /** @var array<string,array<string,array<string,mixed>>> tabel => code => baris (sebelum insert) */
    private array $index = [];
    /** @var array<string,true> */
    private array $issueCodes = [];

    /** @var array<string,mixed> ringkasan hasil import */
    public array $report = [
        'counts' => [], 'issues_by_type' => [], 'auto_corrected' => 0, 'suggestions' => 0,
        'legacy' => [], 'notes' => [], 'linked_exact' => [],
    ];

    /** @param list<string> $legacyPaths */
    public function __construct(string $masterPath, array $legacyPaths = [], bool $applyLegacyCorrections = true)
    {
        $this->master = new XlsxReader($masterPath);
        if ($legacyPaths !== []) {
            $this->verifier = new LegacyVerifier($legacyPaths, $applyLegacyCorrections);
        }
        $this->now = date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Tahap 1: baca & petakan
    // ------------------------------------------------------------------

    public function build(): self
    {
        foreach (self::REQUIRED as $sheet => $headers) {
            if (!$this->master->hasSheet($sheet)) {
                throw new RuntimeException("Sheet '{$sheet}' tidak ditemukan. Pastikan file yang dipilih adalah PIK_Master_Database_AppSheet.xlsx.");
            }
            $table = $this->master->table($sheet);
            $missing = array_diff($headers, $table['headers']);
            if ($missing !== []) {
                throw new RuntimeException("Sheet '{$sheet}' tidak memiliki kolom: " . implode(', ', $missing));
            }
            $this->sheets[$sheet] = $table['rows'];
        }

        if ($this->verifier !== null) {
            $this->verifier->verify($this->sheets);
            $this->applyLegacyFindings();
            $this->report['legacy'] = $this->verifier->stats;
            $this->report['notes'] = array_merge($this->report['notes'], $this->verifier->notes);
        } else {
            $this->report['notes'][] = 'File legacy tidak diberikan: verifikasi tanggal/angka terhadap spreadsheet asli dilewati.';
        }

        $this->mapCustomers();
        $this->mapProducts();
        $this->mapPurchaseOrders();
        $this->mapPoLines();
        $this->mapDeliveries();
        $this->mapReturns();
        $this->mapStock();
        $this->mapLeadtime();
        $this->mapInbound();
        $this->mapInvoices();
        $this->mapPoFinancials();
        $this->mapWorkbookIssues();

        foreach (self::TABLES as $t) {
            $this->report['counts'][$t] = count($this->rows[$t] ?? []);
        }
        foreach ($this->rows['migration_issues'] ?? [] as $issue) {
            $key = $issue['issue_type'] . ' (' . $issue['resolution_status'] . ')';
            $this->report['issues_by_type'][$key] = ($this->report['issues_by_type'][$key] ?? 0) + 1;
        }
        ksort($this->report['issues_by_type']);
        return $this;
    }

    /**
     * Terapkan hasil verifikasi legacy:
     *  - decision legacy  + auto-apply ⇒ nilai master diganti, issue "Auto-Corrected"
     *  - decision legacy  tanpa auto-apply, atau decision review ⇒ issue "Needs Review" berisi usulan
     *  - decision master  ⇒ nilai master dipertahankan (bukti mendukung master)
     */
    private function applyLegacyFindings(): void
    {
        $this->report['master_confirmed'] = 0;
        $bySheetCode = [];
        foreach ($this->verifier->corrections as $c) {
            if ($c['decision'] === 'master') {
                $this->report['master_confirmed']++;
                continue;
            }
            $bySheetCode[$c['table']][$c['code']][] = $c;
        }
        foreach ($bySheetCode as $sheet => $codes) {
            $idField = array_key_first($this->sheets[$sheet][0]['data'] ?? []);
            foreach ($this->sheets[$sheet] as &$row) {
                $code = (string) ($row['data'][$idField] ?? '');
                foreach ($codes[$code] ?? [] as $c) {
                    $isDate = $c['type'] === 'date';
                    $shownLegacy = $c['legacy_raw'] !== $c['legacy'] ? "{$c['legacy']} (teks \"{$c['legacy_raw']}\")" : $c['legacy'];
                    $description = 'Master: ' . ($c['master'] ?? 'kosong') . ' · Legacy: ' . $shownLegacy . '. Bukti: ' . $c['why'] . ' Sumber: ' . $c['source'] . '.';
                    if ($c['decision'] === 'legacy') {
                        $type = $isDate ? 'DATE CORRECTED FROM LEGACY' : 'NUMBER CORRECTED FROM LEGACY';
                        if ($c['apply']) {
                            $row['data'][$c['master_col']] = $isDate ? new DateTimeImmutable($c['legacy']) : (float) $c['legacy'];
                            $this->report['auto_corrected']++;
                        } else {
                            $this->report['suggestions']++;
                        }
                    } else {
                        $type = $isDate ? 'DATE DIFFERS FROM LEGACY' : 'NUMBER DIFFERS FROM LEGACY';
                        $this->report['suggestions']++;
                    }
                    $this->issue($sheet, $code, $type, $description, [
                        'field_name'        => $c['db_col'],
                        'master_value'      => $c['master'],
                        'suggested_value'   => $c['legacy'],
                        'resolution_status' => $c['apply'] ? 'Auto-Corrected' : 'Needs Review',
                        'resolution_note'   => $c['apply'] ? 'Diterapkan otomatis saat import karena didukung bukti independen. Gunakan "Kembalikan ke nilai master" bila tidak setuju.' : null,
                    ]);
                }
            }
            unset($row);
        }
    }

    private function mapCustomers(): void
    {
        $normalized = [];
        foreach ($this->sheets['CUSTOMERS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['CustomerID'] ?? null);
            if ($code === null || ($name = $this->text($d['CustomerName'] ?? null)) === null) {
                $this->report['notes'][] = "CUSTOMERS baris {$r} dilewati: CustomerID/CustomerName kosong.";
                continue;
            }
            $status = $this->text($d['CustomerStatus'] ?? null) ?? 'Active';
            if (!in_array($status, ['Active', 'Inactive', 'Potential', 'Dormant'], true)) {
                $this->issue('CUSTOMERS', $code, 'STATUS MAPPED', "Status customer '{$status}' tidak dikenal; disimpan sebagai Active.", ['field_name' => 'status', 'master_value' => $status]);
                $status = 'Active';
            }
            $notes = $this->text($d['Notes'] ?? null);
            if (($mp = $this->text($d['MarketingPIC'] ?? null)) !== null) {
                $notes = trim(($notes ?? '') . "\nMarketingPIC (legacy): " . $mp);
                $this->issue('CUSTOMERS', $code, 'MARKETING PIC NOT MAPPED', "MarketingPIC '{$mp}' belum dipetakan ke user aplikasi. Pilih PIC Marketing di data customer.");
            }
            $email = $this->text($d['Email'] ?? null);
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->issue('CUSTOMERS', $code, 'INVALID EMAIL', "Email '{$email}' tidak valid; dipindahkan ke catatan.");
                $notes = trim(($notes ?? '') . "\nEmail (legacy): " . $email);
                $email = null;
            }
            $this->add('customers', [
                'code'       => $code,
                'name'       => $this->limit($name, 190, 'CUSTOMERS', $code, 'name'),
                'company'    => $this->limit($this->text($d['Company'] ?? null), 190, 'CUSTOMERS', $code, 'company'),
                'pic'        => $this->limit($this->text($d['PIC'] ?? null), 120, 'CUSTOMERS', $code, 'pic'),
                'phone'      => $this->limit($this->text($d['Phone'] ?? null), 40, 'CUSTOMERS', $code, 'phone'),
                'email'      => $email !== null ? mb_strtolower($email) : null,
                'industry'   => $this->limit($this->text($d['Industry'] ?? null), 100, 'CUSTOMERS', $code, 'industry'),
                'status'     => $status,
                'source'     => $this->limit($this->text($d['Source'] ?? null), 100, 'CUSTOMERS', $code, 'source'),
                'notes'      => $notes,
                'created_at' => ($created = $this->date($d['CreatedAt'] ?? null)) !== null ? $created . ' 00:00:00' : $this->now,
            ]);
            $normalized[\App\Models\Customer::normalizeName($name)][] = ['code' => $code, 'name' => $name];
        }
        foreach ($normalized as $group) {
            if (count($group) < 2) {
                continue;
            }
            $first = $group[0];
            foreach (array_slice($group, 1) as $dup) {
                $this->issue('CUSTOMERS', $dup['code'], 'POSSIBLE DUPLICATE CUSTOMER',
                    "Nama '{$dup['name']}' sama dengan '{$first['name']}' ({$first['code']}) setelah mengabaikan tanda baca/huruf besar. Tidak digabung otomatis — periksa apakah customer yang sama.");
            }
        }
    }

    private function mapProducts(): void
    {
        $seen = [];
        foreach ($this->sheets['PRODUCTS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['ProductID'] ?? null);
            if ($code === null || ($name = $this->text($d['ProductName'] ?? null)) === null) {
                $this->report['notes'][] = "PRODUCTS baris {$r} dilewati: ProductID/ProductName kosong.";
                continue;
            }
            $variant = $this->text($d['Variant'] ?? null);
            $active = $d['Active'] ?? true;
            $this->add('products', [
                'code'             => $code,
                'name'             => $this->limit($name, 190, 'PRODUCTS', $code, 'name'),
                'product_code'     => $this->limit($this->text($d['ProductCode'] ?? null), 60, 'PRODUCTS', $code, 'product_code'),
                'variant'          => $this->limit($variant, 255, 'PRODUCTS', $code, 'variant'),
                'category'         => $this->limit($this->text($d['Category'] ?? null), 100, 'PRODUCTS', $code, 'category'),
                'capacity_per_day' => ($cap = $this->int($d['ProductionCapacityPerDay'] ?? null)) !== null && $cap >= 0 ? $cap : null,
                'unit'             => $this->limit($this->text($d['Unit'] ?? null) ?? 'pcs', 20, 'PRODUCTS', $code, 'unit'),
                'is_active'        => ($active === false || in_array(strtolower((string) $active), ['0', 'false', 'no'], true)) ? 0 : 1,
                'source'           => $this->limit($this->text($d['Source'] ?? null), 50, 'PRODUCTS', $code, 'source'),
                'created_at'       => $this->now,
            ]);
            $key = mb_strtolower($name . '|' . ($variant ?? ''));
            if (isset($seen[$key])) {
                $this->issue('PRODUCTS', $code, 'POSSIBLE DUPLICATE PRODUCT', "Nama & varian sama persis dengan produk {$seen[$key]}. Tidak digabung otomatis.");
            } else {
                $seen[$key] = $code;
            }
        }
    }

    private function mapPurchaseOrders(): void
    {
        $numbers = [];
        foreach ($this->sheets['PURCHASE_ORDERS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['POID'] ?? null);
            if ($code === null) {
                $this->report['notes'][] = "PURCHASE_ORDERS baris {$r} dilewati: POID kosong.";
                continue;
            }
            $customer = $this->ref('customers', $d['CustomerID'] ?? null);
            if ($customer === null) {
                $this->issue('PURCHASE_ORDERS', $code, 'CUSTOMER NOT FOUND', 'PO tidak memiliki CustomerID yang valid di master. Tentukan customer secara manual.', $this->legacy($d));
            }
            $legacyStatus = $this->text($d['Status'] ?? null);
            [$status, $mappedNote] = $this->mapPoStatus($legacyStatus);
            if ($mappedNote !== null) {
                $this->issue('PURCHASE_ORDERS', $code, 'STATUS MAPPED', $mappedNote, ['field_name' => 'status', 'master_value' => $legacyStatus] + $this->legacy($d));
            }
            $poNumber = $this->text($d['PONumber'] ?? null);
            $poDate = $this->date($d['PODate'] ?? null);
            if ($poNumber === null || strcasecmp($poNumber, 'Tidak Ada Nomor PO') === 0) {
                $this->issue('PURCHASE_ORDERS', $code, 'PO NUMBER MISSING', 'Nomor PO kosong/tidak tersedia di sumber.', $this->legacy($d));
            } else {
                $numbers[$poNumber][] = $code;
            }
            if ($poDate === null) {
                $this->issue('PURCHASE_ORDERS', $code, 'PO DATE MISSING', 'Tanggal PO kosong di master; PO tidak muncul di laporan berbasis tanggal sampai tanggal diisi.', $this->legacy($d));
            } elseif ($poDate > date('Y-m-d')) {
                $this->issue('PURCHASE_ORDERS', $code, 'DATE IN FUTURE', "Tanggal PO {$poDate} berada di masa depan (saat import).", ['field_name' => 'po_date', 'master_value' => $poDate] + $this->legacy($d));
            }
            $this->add('purchase_orders', [
                'code'          => $code,
                'po_number'     => $this->limit($poNumber, 80, 'PURCHASE_ORDERS', $code, 'po_number'),
                'customer_id'   => $customer,
                'po_date'       => $poDate,
                'payment_term'  => $this->limit($this->text($d['PaymentTerm'] ?? null), 60, 'PURCHASE_ORDERS', $code, 'payment_term'),
                'status'        => $status,
                'legacy_status' => $this->limit($legacyStatus, 40, 'PURCHASE_ORDERS', $code, 'legacy_status'),
                'remark'        => $this->text($d['Remark'] ?? null),
                'created_at'    => $this->now,
            ] + $this->trace($d));
        }
        foreach ($numbers as $number => $codes) {
            if (count($codes) > 1) {
                foreach ($codes as $code) {
                    $this->issue('PURCHASE_ORDERS', $code, 'DUPLICATE PO NUMBER', "Nomor PO '{$number}' dipakai oleh " . count($codes) . ' PO: ' . implode(', ', $codes) . '. Periksa apakah PO yang sama atau berbeda.');
                }
            }
        }
    }

    /** @return array{0:string,1:?string} [status aplikasi, catatan pemetaan bila tidak 1:1] */
    private function mapPoStatus(?string $legacy): array
    {
        $key = strtolower(trim((string) $legacy));
        return match ($key) {
            'closed', 'close' => ['Closed', null],
            'on process', 'on proses', 'process', 'proses' => ['On Process', null],
            'cancel', 'cancelled', 'canceled' => ['Cancelled', null],
            'partial' => ['Partial', null],
            'open' => ['Open', null],
            'hold', 'on hold' => ['Open', "Status legacy '{$legacy}' tidak ada di daftar status PRD (Open, On Process, Partial, Closed, Cancelled); disimpan sebagai Open. Sesuaikan bila perlu."],
            '' => ['Open', 'Status PO kosong di master; disimpan sebagai Open.'],
            default => ['Open', "Status legacy '{$legacy}' tidak dikenal; disimpan sebagai Open."],
        };
    }

    private function mapPoLines(): void
    {
        foreach ($this->sheets['PO_LINES'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['POLineID'] ?? null);
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $product = $this->ref('products', $d['ProductID'] ?? null);
            $qty = $this->int($d['OrderQuantity'] ?? null);
            if ($code === null || $po === null || $product === null || $qty === null) {
                // tidak dapat disimpan tanpa PO/produk/qty (kolom wajib); dicatat di laporan
                $this->report['notes'][] = "PO_LINES baris {$r} (" . ($code ?? 'tanpa ID') . ') tidak diimpor: POID/ProductID/OrderQuantity tidak valid.';
                continue;
            }
            if ($qty <= 0) {
                $this->issue('PO_LINES', $code, 'INVALID QUANTITY', "Order quantity {$qty} tidak valid (harus > 0).", $this->legacy($d));
            }
            $this->add('po_lines', [
                'code'                   => $code,
                'po_id'                  => $po,
                'product_id'             => $product,
                'product_name_legacy'    => $this->limit($this->text($d['ProductNameLegacy'] ?? null), 255, 'PO_LINES', $code, 'product_name_legacy'),
                'variant_legacy'         => $this->limit($this->text($d['VariantLegacy'] ?? null), 255, 'PO_LINES', $code, 'variant_legacy'),
                'order_qty'              => $qty,
                'capacity_per_day'       => ($cap = $this->int($d['CapacityPerDay'] ?? null)) !== null && $cap >= 0 ? $cap : null,
                'remark'                 => $this->text($d['Remark'] ?? null),
                'legacy_delivered_qty'   => $this->int($d['DeliveredQuantitySource'] ?? null),
                'legacy_return_qty'      => $this->int($d['ReturnQuantitySource'] ?? null),
                'legacy_outstanding_qty' => $this->int($d['OutstandingSource'] ?? null),
                'legacy_status'          => $this->limit($this->text($d['StatusSource'] ?? null), 40, 'PO_LINES', $code, 'legacy_status'),
                'created_at'             => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapDeliveries(): void
    {
        $enrich = $this->verifier?->enrichment['DELIVERIES'] ?? [];
        foreach ($this->sheets['DELIVERIES'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['DeliveryID'] ?? null);
            if ($code === null) {
                $this->report['notes'][] = "DELIVERIES baris {$r} dilewati: DeliveryID kosong.";
                continue;
            }
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $line = $this->ref('po_lines', $d['POLineID'] ?? null);
            $product = $this->ref('products', $d['ProductID'] ?? null);
            // Konsistensi: PO line harus milik PO yang sama & produk yang sama
            if ($line !== null) {
                $lineRow = $this->index['po_lines'][$line->code];
                if ($po !== null && $lineRow['po_id']->code !== $po->code) {
                    $this->issue('DELIVERIES', $code, 'PO LINE MISMATCH', "POLineID {$line->code} milik PO {$lineRow['po_id']->code}, bukan {$po->code}. Relasi PO line tidak dipakai.", $this->legacy($d));
                    $line = null;
                } elseif ($product !== null && $lineRow['product_id']->code !== $product->code) {
                    $this->issue('DELIVERIES', $code, 'PO LINE MISMATCH', "Produk delivery ({$product->code}) berbeda dengan produk PO line ({$lineRow['product_id']->code}). Relasi PO line tidak dipakai.", $this->legacy($d));
                    $line = null;
                } else {
                    $po ??= $lineRow['po_id'];
                    $product ??= $lineRow['product_id'];
                }
            }
            $date = $this->date($d['DeliveryDate'] ?? null);
            $qty = $this->int($d['DeliveredQuantity'] ?? null);
            if ($date === null || $qty === null) {
                $this->issue('DELIVERIES', $code, 'INCOMPLETE RECORD', 'Tanggal dan/atau qty delivery kosong di master.', $this->legacy($d));
            }
            if ($qty !== null && $qty < 0) {
                $this->issue('DELIVERIES', $code, 'NEGATIVE QUANTITY', "Qty delivery negatif ({$qty}) — kemungkinan koreksi/retur di spreadsheet. Nilai tetap dihitung apa adanya; periksa kebenarannya.", ['field_name' => 'delivered_qty', 'master_value' => (string) $qty] + $this->legacy($d));
            }
            if ($date !== null && $date > date('Y-m-d')) {
                $this->issue('DELIVERIES', $code, 'DATE IN FUTURE', "Tanggal delivery {$date} berada di masa depan (saat import).", ['field_name' => 'delivery_date', 'master_value' => $date] + $this->legacy($d));
            }
            $flag = $this->text($d['MigrationFlag'] ?? null);
            $this->add('deliveries', [
                'code'           => $code,
                'po_id'          => $po,
                'po_line_id'     => $line,
                'product_id'     => $product,
                'delivery_date'  => $date,
                'sj_number'      => $this->limit($this->text($d['SJNumber'] ?? null), 60, 'DELIVERIES', $code, 'sj_number'),
                'destination'    => $this->limit($this->text($d['Destination'] ?? null), 255, 'DELIVERIES', $code, 'destination'),
                'delivered_qty'  => $qty,
                // data legacy = pengiriman yang sudah terjadi (punya nomor surat jalan)
                'status'         => 'Delivered',
                'note'           => $this->text($d['Note'] ?? null),
                'attachment'     => $this->limit($this->text($d['Attachment'] ?? null), 500, 'DELIVERIES', $code, 'attachment'),
                'migration_flag' => ($flag === null || strtoupper($flag) === 'OK') && $line !== null ? null : ($flag !== null && strtoupper($flag) !== 'OK' ? $flag : 'LINE NOT LINKED'),
                'created_at'     => $this->now,
            ] + $this->trace($d));
            if (isset($enrich[$code])) {
                $this->index['deliveries'][$code]['__legacy'] = $enrich[$code];
            }
        }
    }

    private function mapReturns(): void
    {
        foreach ($this->sheets['RETURNS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['ReturnID'] ?? null);
            if ($code === null) {
                continue;
            }
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $product = $this->ref('products', $d['ProductID'] ?? null);
            $line = null;
            // Hubungkan ke PO line HANYA bila produk diketahui dan PO memiliki tepat satu line dengan produk tsb.
            if ($po !== null && $product !== null) {
                $matches = array_values(array_filter($this->rows['po_lines'] ?? [], static fn ($l) => $l['po_id']->code === $po->code && $l['product_id']->code === $product->code));
                if (count($matches) === 1) {
                    $line = new Ref('po_lines', $matches[0]['code']);
                    $this->report['linked_exact']['returns → PO line (PO & produk sama persis)'] = ($this->report['linked_exact']['returns → PO line (PO & produk sama persis)'] ?? 0) + 1;
                }
            }
            if ($line === null) {
                $this->issue('RETURNS', $code, 'RETURN NOT LINKED TO PO LINE',
                    'Retur belum dapat dihubungkan ke PO line secara pasti' . ($product === null ? ' (produk tidak terpetakan)' : '') . '. Retur belum mengurangi/menambah outstanding sampai dihubungkan.',
                    ['legacy_po' => $this->text($d['PONumberLegacy'] ?? null), 'legacy_product' => $this->text($d['ProductLegacy'] ?? null)] + $this->legacy($d));
            }
            $date = $this->date($d['ReturnDate'] ?? null);
            $this->add('returns', [
                'code'             => $code,
                'po_id'            => $po,
                'po_line_id'       => $line,
                'product_id'       => $product,
                'return_date'      => $date,
                'sj_number'        => $this->limit($this->text($d['SJNumber'] ?? null), 60, 'RETURNS', $code, 'sj_number'),
                'destination'      => $this->limit($this->text($d['Destination'] ?? null), 255, 'RETURNS', $code, 'destination'),
                'return_qty'       => $this->int($d['ReturnQuantity'] ?? null),
                'reason'           => null,
                'attachment'       => $this->limit($this->text($d['Attachment'] ?? null), 500, 'RETURNS', $code, 'attachment'),
                'note'             => $this->text($d['Note'] ?? null),
                'po_number_legacy' => $this->limit($this->text($d['PONumberLegacy'] ?? null), 80, 'RETURNS', $code, 'po_number_legacy'),
                'product_legacy'   => $this->limit($this->text($d['ProductLegacy'] ?? null), 255, 'RETURNS', $code, 'product_legacy'),
                'created_at'       => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapStock(): void
    {
        foreach ($this->sheets['STOCK'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['StockID'] ?? null);
            if ($code === null) {
                continue;
            }
            $type = strtoupper((string) $this->text($d['StockType'] ?? null));
            $typeMap = ['FG' => 'FG', 'WIP' => 'WIP', 'READY' => 'Ready', 'RESERVED' => 'Reserved'];
            if (!isset($typeMap[$type])) {
                $this->issue('STOCK', $code, 'STATUS MAPPED', "StockType '{$type}' tidak dikenal; disimpan sebagai FG.", $this->legacy($d));
            }
            $product = $this->ref('products', $d['ProductID'] ?? null);
            $legacyName = $this->text($d['ProductLegacy'] ?? null);
            $qty = $this->int($d['Quantity'] ?? null);
            if ($legacyName !== null && strcasecmp($legacyName, 'Nama Barang') === 0) {
                $this->issue('STOCK', $code, 'LEGACY HEADER ROW', "Baris ini adalah judul kolom ('Nama Barang') dari spreadsheet, bukan data stok. Aman untuk dihapus setelah diperiksa.", $this->legacy($d));
            } elseif ($product === null) {
                $this->issue('STOCK', $code, 'STOCK PRODUCT NOT MATCHED', 'Nama barang stok belum dapat dipetakan ke master produk secara pasti. Pilih produk yang sesuai.', ['legacy_product' => $legacyName] + $this->legacy($d));
            }
            $this->add('stock', [
                'code'           => $code,
                'product_id'     => $product,
                'product_legacy' => $this->limit($legacyName, 255, 'STOCK', $code, 'product_legacy'),
                'stock_type'     => $typeMap[$type] ?? 'FG',
                'quantity'       => $qty,
                'box'            => $this->int($d['Box'] ?? null),
                'qty_per_box'    => $this->int($d['QtyPerBox'] ?? null),
                'status'         => $this->limit($this->text($d['Status'] ?? null), 40, 'STOCK', $code, 'status'),
                'created_at'     => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapLeadtime(): void
    {
        foreach ($this->sheets['LEADTIME'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['LeadTimeID'] ?? null);
            if ($code === null) {
                continue;
            }
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $poLegacy = $this->text($d['PONumberLegacy'] ?? null);
            if ($po === null && $poLegacy !== null && ($exact = $this->poByExactNumber($poLegacy)) !== null) {
                $po = $exact;
                $this->report['linked_exact']['leadtime → PO (nomor PO sama persis & unik)'] = ($this->report['linked_exact']['leadtime → PO (nomor PO sama persis & unik)'] ?? 0) + 1;
            }
            $legacyStatus = $this->text($d['Status'] ?? null);
            $status = match (strtolower((string) $legacyStatus)) {
                'terkirim', 'delivered', 'sent' => 'Delivered',
                'on proses', 'on process', 'proses', 'process' => 'On Process',
                'cancel', 'cancelled' => 'Cancelled',
                'delay', 'delayed', 'terlambat' => 'Delayed',
                default => 'Planned',
            };
            $isSummary = $poLegacy !== null && stripos($poLegacy, 'total') !== false;
            if ($isSummary) {
                $this->issue('LEADTIME', $code, 'LEGACY SUMMARY ROW', "Baris '{$poLegacy}' adalah baris ringkasan/total di spreadsheet, bukan jadwal delivery. Periksa lalu hapus bila tidak diperlukan.", $this->legacy($d));
            } elseif ($po === null) {
                $this->issue('LEADTIME', $code, 'PO NOT FOUND', 'Lead time tidak memiliki POID di master' . ($poLegacy ? " (PO legacy: {$poLegacy})" : '') . '. Hubungkan ke PO bila diketahui.', ['legacy_po' => $poLegacy, 'legacy_product' => $this->text($d['ProductLegacy'] ?? null)] + $this->legacy($d));
            }
            $this->add('leadtime', [
                'code'             => $code,
                'po_id'            => $po,
                'product_id'       => $this->ref('products', $d['ProductID'] ?? null),
                'po_number_legacy' => $this->limit($poLegacy, 80, 'LEADTIME', $code, 'po_number_legacy'),
                'product_legacy'   => $this->limit($this->text($d['ProductLegacy'] ?? null), 255, 'LEADTIME', $code, 'product_legacy'),
                'quantity'         => $this->int($d['Quantity'] ?? null),
                'delivery_date'    => $this->date($d['DeliveryDate'] ?? null),
                'status'           => $status,
                'legacy_status'    => $this->limit($legacyStatus, 40, 'LEADTIME', $code, 'legacy_status'),
                'created_at'       => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapInbound(): void
    {
        $productsByCode = [];
        foreach ($this->rows['products'] ?? [] as $p) {
            if ($p['product_code'] !== null) {
                $productsByCode[mb_strtolower(trim($p['product_code']))][] = $p['code'];
            }
        }
        foreach ($this->sheets['INBOUND_MAKLON'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['InboundID'] ?? null);
            if ($code === null) {
                continue;
            }
            $poLegacy = $this->text($d['PONumberLegacy'] ?? null);
            $po = $poLegacy !== null ? $this->poByExactNumber($poLegacy) : null;
            if ($po !== null) {
                $this->report['linked_exact']['inbound maklon → PO (nomor PO sama persis & unik)'] = ($this->report['linked_exact']['inbound maklon → PO (nomor PO sama persis & unik)'] ?? 0) + 1;
            }
            $factoryCode = $this->text($d['FactoryComponentCode'] ?? null);
            $product = null;
            if ($factoryCode !== null && count($productsByCode[mb_strtolower($factoryCode)] ?? []) === 1) {
                $product = new Ref('products', $productsByCode[mb_strtolower($factoryCode)][0]);
                $this->report['linked_exact']['inbound maklon → produk (kode produk sama persis & unik)'] = ($this->report['linked_exact']['inbound maklon → produk (kode produk sama persis & unik)'] ?? 0) + 1;
            }
            $this->add('inbound_maklon', [
                'code'                    => $code,
                'vendor'                  => $this->limit($this->text($d['Vendor'] ?? null), 120, 'INBOUND_MAKLON', $code, 'vendor'),
                'receiver'                => $this->limit($this->text($d['Receiver'] ?? null), 120, 'INBOUND_MAKLON', $code, 'receiver'),
                'actual_inbound_date'     => $this->date($d['ActualInboundDate'] ?? null),
                'sj_date'                 => $this->date($d['SJDate'] ?? null),
                'sj_number'               => $this->limit($this->text($d['SJNumber'] ?? null), 60, 'INBOUND_MAKLON', $code, 'sj_number'),
                'po_id'                   => $po,
                'po_number_legacy'        => $this->limit($poLegacy, 80, 'INBOUND_MAKLON', $code, 'po_number_legacy'),
                'product_id'              => $product,
                'internal_component_code' => $this->limit($this->text($d['InternalComponentCode'] ?? null), 60, 'INBOUND_MAKLON', $code, 'internal_component_code'),
                'type'                    => $this->limit($this->text($d['Type'] ?? null), 60, 'INBOUND_MAKLON', $code, 'type'),
                'component_name'          => $this->limit($this->text($d['ComponentName'] ?? null), 255, 'INBOUND_MAKLON', $code, 'component_name'),
                'factory_component_code'  => $this->limit($factoryCode, 60, 'INBOUND_MAKLON', $code, 'factory_component_code'),
                'quantity'                => $this->int($d['Quantity'] ?? null),
                'reject_qty'              => $this->int($d['RejectQuantity'] ?? null),
                'total_in'                => $this->int($d['TotalIn'] ?? null),
                'attachment'              => $this->limit($this->text($d['Attachment'] ?? null), 500, 'INBOUND_MAKLON', $code, 'attachment'),
                'notes'                   => $this->text($d['Notes'] ?? null),
                'odoo_checklist'          => $this->limit($this->text($d['OdooChecklist'] ?? null), 60, 'INBOUND_MAKLON', $code, 'odoo_checklist'),
                'created_at'              => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapInvoices(): void
    {
        $seenNumbers = [];
        foreach ($this->sheets['INVOICES_PAYMENTS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['InvoicePaymentID'] ?? null);
            if ($code === null) {
                continue;
            }
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $poLegacy = $this->text($d['PONumberLegacy'] ?? null);
            if ($po === null && $poLegacy !== null && ($exact = $this->poByExactNumber($poLegacy)) !== null) {
                $po = $exact;
                $this->report['linked_exact']['invoice → PO (nomor PO sama persis & unik)'] = ($this->report['linked_exact']['invoice → PO (nomor PO sama persis & unik)'] ?? 0) + 1;
            }
            $customer = $po !== null ? ($this->index['purchase_orders'][$po->code]['customer_id'] ?? null) : null;
            if ($po === null) {
                $this->issue('INVOICES_PAYMENTS', $code, 'PO NOT FOUND', "Invoice tidak terhubung ke PO (PO legacy: " . ($poLegacy ?? '—') . '); customer belum diketahui. Tentukan PO/customer secara manual.', ['legacy_po' => $poLegacy] + $this->legacy($d));
            }
            $number = $this->text($d['InvoiceNumber'] ?? null);
            if ($number !== null) {
                if (isset($seenNumbers[mb_strtolower($number)])) {
                    $this->issue('INVOICES_PAYMENTS', $code, 'DUPLICATE INVOICE NUMBER', "Nomor invoice '{$number}' duplikat dengan {$seenNumbers[mb_strtolower($number)]}; disimpan dengan akhiran kode agar tetap unik.", $this->legacy($d));
                    $number .= ' [' . $code . ']';
                } else {
                    $seenNumbers[mb_strtolower($number)] = $code;
                }
            }
            $amount = $this->dec($d['InvoiceAmount'] ?? null) ?? '0';
            $paid = $this->dec($d['PaymentAmount'] ?? null) ?? '0';
            $receipt = $this->text($d['PaymentReceiptNumber'] ?? null);
            if ($receipt !== null && preg_match('/^(\d+)\.0$/', $receipt, $m)) {
                $receipt = $m[1]; // artefak konversi angka→teks di master ("24082000832742.0")
            }
            $legacyOutstanding = $this->dec($d['InvoiceOutstanding'] ?? null);
            $computed = (float) $amount - (float) $paid;
            if ($legacyOutstanding !== null && abs($computed - (float) $legacyOutstanding) > 1) {
                $this->issue('INVOICES_PAYMENTS', $code, 'INVOICE OUTSTANDING DIFFERS',
                    sprintf('Sisa tagihan dihitung %s (invoice − payment) tetapi di master tertulis %s. Periksa pembayaran.', number_format($computed, 0, ',', '.'), number_format((float) $legacyOutstanding, 0, ',', '.')),
                    ['field_name' => 'paid_amount', 'master_value' => $paid] + $this->legacy($d));
            }
            $status = (float) $paid >= (float) $amount && (float) $amount > 0 ? 'Paid' : ((float) $paid > 0 ? 'Partial' : 'Unpaid');
            if ($status !== 'Paid') {
                $this->issue('INVOICES_PAYMENTS', $code, 'DUE DATE MISSING', 'Invoice belum lunas tetapi tanggal jatuh tempo tidak ada di master; status Overdue tidak dapat dihitung sampai jatuh tempo diisi.', $this->legacy($d));
            }
            $this->add('invoices_payments', [
                'code'                          => $code,
                'customer_id'                   => $customer,
                'po_id'                         => $po,
                'po_number_legacy'              => $this->limit($poLegacy, 80, 'INVOICES_PAYMENTS', $code, 'po_number_legacy'),
                'invoice_number'                => $this->limit($number, 80, 'INVOICES_PAYMENTS', $code, 'invoice_number'),
                'invoice_date'                  => $this->date($d['InvoiceDate'] ?? null),
                'due_date'                      => null,
                'invoice_amount'                => $amount,
                'paid_amount'                   => $paid,
                'payment_date'                  => $this->date($d['PaymentDate'] ?? null),
                'payment_receipt_number'        => $this->limit($receipt, 80, 'INVOICES_PAYMENTS', $code, 'payment_receipt_number'),
                'status'                        => $status,
                'legacy_invoice_outstanding'    => $legacyOutstanding,
                'legacy_cumulative_outstanding' => $this->dec($d['CumulativeOutstanding'] ?? null),
                'invoice_attachment'            => $this->limit($this->text($d['InvoiceAttachment'] ?? null), 500, 'INVOICES_PAYMENTS', $code, 'invoice_attachment'),
                'payment_attachment'            => $this->limit($this->text($d['PaymentAttachment'] ?? null), 500, 'INVOICES_PAYMENTS', $code, 'payment_attachment'),
                'created_at'                    => $this->now,
            ] + $this->trace($d));
        }
    }

    private function mapPoFinancials(): void
    {
        foreach ($this->sheets['PO_FINANCIALS'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['POFinancialID'] ?? null);
            if ($code === null) {
                continue;
            }
            $po = $this->ref('purchase_orders', $d['POID'] ?? null);
            $poLegacy = $this->text($d['PONumberLegacy'] ?? null);
            if ($po === null) {
                $this->issue('PO_FINANCIALS', $code, 'PO NOT FOUND', 'Ringkasan finansial tidak terhubung ke PO (PO legacy: ' . ($poLegacy ?? '—') . ').', ['legacy_po' => $poLegacy, 'legacy_product' => $this->text($d['ProductLegacy'] ?? null)] + $this->legacy($d));
            }
            $qty = $this->int($d['OrderQuantity'] ?? null);
            $price = $this->dec($d['UnitPrice'] ?? null);
            $total = $this->dec($d['TotalOrderAmount'] ?? null);
            if ($qty !== null && $price !== null && $total !== null && (float) $total > 0) {
                $calc = $qty * (float) $price;
                if (abs($calc - (float) $total) / (float) $total > 0.01) {
                    $this->issue('PO_FINANCIALS', $code, 'AMOUNT INCONSISTENT',
                        sprintf('Qty × Unit Price = %s tidak sama dengan Total Order Amount %s (selisih > 1%%). Periksa unit price.', number_format($calc, 2, ',', '.'), number_format((float) $total, 2, ',', '.')),
                        ['field_name' => 'unit_price', 'master_value' => $price] + $this->legacy($d));
                }
            }
            $legacyStatus = $this->text($d['Status'] ?? null);
            $paymentStatus = match (strtoupper((string) $legacyStatus)) {
                'LUNAS', 'PAID' => 'Paid',
                'BELUM LUNAS', 'UNPAID' => 'Unpaid',
                'SEBAGIAN', 'PARTIAL' => 'Partial',
                default => null,
            };
            $this->add('po_financials', [
                'code'                => $code,
                'po_id'               => $po,
                'po_number_legacy'    => $this->limit($poLegacy, 80, 'PO_FINANCIALS', $code, 'po_number_legacy'),
                'brand'               => $this->limit($this->text($d['Brand'] ?? null), 100, 'PO_FINANCIALS', $code, 'brand'),
                'po_date'             => $this->date($d['PODate'] ?? null),
                'product_legacy'      => $this->limit($this->text($d['ProductLegacy'] ?? null), 255, 'PO_FINANCIALS', $code, 'product_legacy'),
                'product_code_legacy' => $this->limit($this->text($d['ProductCodeLegacy'] ?? null), 60, 'PO_FINANCIALS', $code, 'product_code_legacy'),
                'order_qty'           => $qty,
                'unit_price'          => $price,
                'total_order_amount'  => $total,
                'ppn'                 => $this->dec($d['PPN'] ?? null),
                'total_incl_ppn'      => $this->dec($d['TotalInclPPN'] ?? null),
                'delivered_qty'       => $this->int($d['DeliveredQuantity'] ?? null),
                'undelivered_qty'     => $this->int($d['UndeliveredQuantity'] ?? null),
                'outstanding_amount'  => $this->dec($d['Outstanding'] ?? null),
                'payment_status'      => $paymentStatus,
                'legacy_status'       => $this->limit($legacyStatus, 40, 'PO_FINANCIALS', $code, 'legacy_status'),
                'attachment'          => $this->limit($this->text($d['POAttachment'] ?? null), 500, 'PO_FINANCIALS', $code, 'attachment'),
                'created_at'          => $this->now,
            ] + $this->trace($d));
        }
    }

    /** Issue yang sudah ada di sheet MIGRATION_ISSUES workbook. */
    private function mapWorkbookIssues(): void
    {
        foreach ($this->sheets['MIGRATION_ISSUES'] as ['row' => $r, 'data' => $d]) {
            $code = $this->code($d['IssueID'] ?? null);
            $sheet = strtoupper((string) $this->text($d['TableName'] ?? null));
            $recordCode = $this->code($d['RecordID'] ?? null);
            if ($code === null || isset($this->issueCodes[$code])) {
                continue;
            }
            $legacyPo = $this->text($d['LegacyPO'] ?? null);
            $legacyProduct = $this->text($d['LegacyProduct'] ?? null);
            $dbTable = self::SHEET_TABLE[$sheet] ?? null;
            if ($dbTable !== null && $recordCode !== null && isset($this->index[$dbTable][$recordCode]['__legacy'])) {
                $legacyPo ??= $this->index[$dbTable][$recordCode]['__legacy']['po'];
                $legacyProduct ??= $this->index[$dbTable][$recordCode]['__legacy']['product'];
            }
            $status = $this->text($d['ResolutionStatus'] ?? null);
            $this->issueCodes[$code] = true;
            $this->add('migration_issues', [
                'code'              => $code,
                'table_name'        => $sheet !== '' ? $sheet : 'UNKNOWN',
                'record_code'       => $recordCode,
                'record_id'         => ($dbTable !== null && $recordCode !== null && isset($this->index[$dbTable][$recordCode])) ? new Ref($dbTable, $recordCode) : null,
                'issue_type'        => mb_substr((string) ($this->text($d['IssueType'] ?? null) ?? 'UNKNOWN'), 0, 60),
                'field_name'        => null,
                'master_value'      => null,
                'suggested_value'   => null,
                'legacy_po'         => $legacyPo !== null ? mb_substr($legacyPo, 0, 120) : null,
                'legacy_product'    => $legacyProduct !== null ? mb_substr($legacyProduct, 0, 255) : null,
                'description'       => $this->text($d['Description'] ?? null),
                'source_file'       => $this->text($d['SourceFile'] ?? null),
                'source_sheet'      => $this->text($d['SourceSheet'] ?? null),
                'legacy_row'        => $this->int($d['LegacyRow'] ?? null),
                'resolution_status' => in_array($status, ['Needs Review', 'Resolved', 'Ignored'], true) ? $status : 'Needs Review',
                'resolution_note'   => null,
                'created_at'        => $this->now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Helper pemetaan
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function add(string $table, array $row): void
    {
        $this->rows[$table][] = $row;
        if (isset($row['code'])) {
            $this->index[$table][$row['code']] = $row;
        }
    }

    /** Referensi ke record yang ADA di workbook (berdasarkan ID); selain itu null. */
    private function ref(string $table, mixed $code): ?Ref
    {
        $code = $this->code($code);
        return ($code !== null && isset($this->index[$table][$code])) ? new Ref($table, $code) : null;
    }

    private function poByExactNumber(string $number): ?Ref
    {
        $matches = [];
        foreach ($this->rows['purchase_orders'] ?? [] as $po) {
            if ($po['po_number'] !== null && $po['po_number'] === $number) {
                $matches[] = $po['code'];
            }
        }
        return count($matches) === 1 ? new Ref('purchase_orders', $matches[0]) : null;
    }

    private function code(mixed $value): ?string
    {
        $text = $this->text($value);
        return $text !== null ? mb_substr($text, 0, 20) : null;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value)) {
            $value = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        $text = trim(str_replace("\u{00A0}", ' ', (string) $value));
        return $text === '' ? null : $text;
    }

    private function limit(?string $value, int $max, string $sheet, string $code, string $field): ?string
    {
        if ($value === null || mb_strlen($value) <= $max) {
            return $value;
        }
        $this->issue($sheet, $code, 'VALUE TRUNCATED', "Nilai {$field} lebih dari {$max} karakter dan dipotong. Nilai asli: " . mb_substr($value, 0, 500), ['field_name' => $field]);
        return mb_substr($value, 0, $max);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $y = (int) $value->format('Y');
            return ($y >= 1900 && $y <= 2100) ? $value->format('Y-m-d') : null;
        }
        if (is_string($value) && trim($value) !== '') {
            return Validator::parseDate(trim(substr($value, 0, 10))) ?? LegacyVerifier::parseIndonesianDate(trim($value));
        }
        return null;
    }

    private function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        if (is_string($value) && preg_match('/^\s*-?\d+(\.0+)?\s*$/', $value)) {
            return (int) $value;
        }
        return null;
    }

    private function dec(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value);
        }
        return null;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function trace(array $d): array
    {
        return [
            'source_file'  => $this->text($d['SourceFile'] ?? null),
            'source_sheet' => $this->text($d['SourceSheet'] ?? null),
            'legacy_row'   => $this->int($d['LegacyRow'] ?? null),
        ];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function legacy(array $d): array
    {
        return ['source_file' => $this->text($d['SourceFile'] ?? null), 'source_sheet' => $this->text($d['SourceSheet'] ?? null), 'legacy_row' => $this->int($d['LegacyRow'] ?? null)];
    }

    /** @param array<string,mixed> $extra */
    private function issue(string $sheet, ?string $recordCode, string $type, string $description, array $extra = []): void
    {
        $seed = $sheet . '|' . ($recordCode ?? '') . '|' . $type . '|' . ($extra['field_name'] ?? '');
        $n = 0;
        do {
            $code = 'ISS-' . strtoupper(substr(sha1($seed . ($n > 0 ? '#' . $n : '')), 0, 10));
            $n++;
        } while (isset($this->issueCodes[$code]));
        $this->issueCodes[$code] = true;
        $dbTable = self::SHEET_TABLE[$sheet] ?? null;
        $this->rows['migration_issues'][] = [
            'code'              => $code,
            'table_name'        => $sheet,
            'record_code'       => $recordCode,
            'record_id'         => ($dbTable !== null && $recordCode !== null) ? new Ref($dbTable, $recordCode) : null,
            'issue_type'        => $type,
            'field_name'        => $extra['field_name'] ?? null,
            'master_value'      => isset($extra['master_value']) ? mb_substr((string) $extra['master_value'], 0, 255) : null,
            'suggested_value'   => isset($extra['suggested_value']) ? mb_substr((string) $extra['suggested_value'], 0, 255) : null,
            'legacy_po'         => isset($extra['legacy_po']) ? mb_substr((string) $extra['legacy_po'], 0, 120) : null,
            'legacy_product'    => isset($extra['legacy_product']) ? mb_substr((string) $extra['legacy_product'], 0, 255) : null,
            'description'       => $description,
            'source_file'       => $extra['source_file'] ?? null,
            'source_sheet'      => $extra['source_sheet'] ?? null,
            'legacy_row'        => $extra['legacy_row'] ?? null,
            'resolution_status' => $extra['resolution_status'] ?? 'Needs Review',
            'resolution_note'   => $extra['resolution_note'] ?? null,
            'created_at'        => $this->now,
        ];
    }

    // ------------------------------------------------------------------
    // Tahap 2: eksekusi ke database / file SQL
    // ------------------------------------------------------------------

    /** @return array<string,int> tabel bisnis yang sudah berisi data */
    public static function nonEmptyTables(): array
    {
        $out = [];
        foreach (array_merge(self::TABLES, ['contacts', 'leads', 'activities', 'follow_up']) as $table) {
            $count = (int) Database::fetchValue('SELECT COUNT(*) FROM `' . $table . '`');
            if ($count > 0) {
                $out[$table] = $count;
            }
        }
        return $out;
    }

    /** Hapus seluruh data bisnis (users, settings, audit log tidak disentuh). */
    public static function wipeBusinessData(): void
    {
        Database::transaction(static function (): void {
            foreach (self::WIPE_ORDER as $table) {
                Database::query('DELETE FROM `' . $table . '`');
            }
        });
    }

    /**
     * Simpan hasil pemetaan ke database dalam satu transaksi.
     * @return array<string,int> jumlah baris per tabel
     */
    public function execute(?int $userId = null): array
    {
        $nonEmpty = self::nonEmptyTables();
        if ($nonEmpty !== []) {
            throw new RuntimeException('Database sudah berisi data (' . implode(', ', array_map(static fn ($t, $c) => "{$t}: {$c}", array_keys($nonEmpty), $nonEmpty)) . '). Import hanya untuk database kosong; gunakan opsi --fresh (CLI) untuk mengosongkan data bisnis terlebih dahulu.');
        }
        $ids = [];
        $counts = [];
        Database::transaction(function () use (&$ids, &$counts, $userId): void {
            foreach (self::TABLES as $table) {
                $counts[$table] = 0;
                foreach ($this->rows[$table] ?? [] as $row) {
                    $data = [];
                    foreach ($row as $col => $value) {
                        if (str_starts_with($col, '__')) {
                            continue;
                        }
                        if ($value instanceof Ref) {
                            $value = $ids[$value->table][$value->code] ?? throw new RuntimeException("Referensi {$value->table} {$value->code} tidak ditemukan saat import.");
                        }
                        $data[$col] = $value;
                    }
                    if ($userId !== null && in_array($table, ['customers', 'products', 'purchase_orders', 'po_lines', 'deliveries', 'returns', 'stock', 'leadtime', 'inbound_maklon', 'invoices_payments', 'po_financials'], true)) {
                        $data['created_by'] = $userId;
                        $data['updated_by'] = $userId;
                    }
                    $id = Database::insert($table, $data);
                    if (isset($row['code'])) {
                        $ids[$table][$row['code']] = $id;
                    }
                    $counts[$table]++;
                }
            }
        });
        return $counts;
    }

    /** Tulis hasil pemetaan sebagai file SQL (untuk diimpor lewat phpMyAdmin). */
    public function writeSql(string $path): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Tidak dapat menulis file: ' . $path);
        }
        fwrite($fh, "-- PIK Marketing Control — data hasil migrasi PIK_Master_Database_AppSheet.xlsx\n");
        fwrite($fh, '-- Dibuat: ' . $this->now . "\n-- Jalankan SETELAH schema.sql pada database KOSONG. File ini berisi data perusahaan: jangan dibagikan.\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSTART TRANSACTION;\n\n");
        foreach (self::TABLES as $table) {
            foreach ($this->rows[$table] ?? [] as $row) {
                $cols = [];
                $vals = [];
                foreach ($row as $col => $value) {
                    if (str_starts_with($col, '__')) {
                        continue;
                    }
                    $cols[] = '`' . $col . '`';
                    $vals[] = $value instanceof Ref
                        ? '(SELECT id FROM `' . $value->table . '` WHERE code = ' . self::quote($value->code) . ')'
                        : self::quote($value);
                }
                fwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "COMMIT;\n");
        fclose($fh);
    }

    public static function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $s = (string) $value;
        return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
    }

    /** @return array<string,list<array<string,mixed>>> (untuk test) */
    public function mappedRows(): array
    {
        return $this->rows;
    }
}
