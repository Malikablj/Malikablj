<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Helpers\XlsxReader;
use DateTimeImmutable;

/**
 * Verifikasi nilai di master workbook terhadap file spreadsheet asli (legacy),
 * memakai jejak SourceFile + SourceSheet + LegacyRow di setiap baris master.
 *
 * 1. Baris legacy hanya dipakai bila kolom kunci (nomor PO / nomor SJ + qty /
 *    nomor invoice) SAMA PERSIS dengan baris master. Tidak ada fuzzy matching.
 * 2. Setiap perbedaan nilai TIDAK langsung dianggap "legacy benar". Contoh
 *    nyata: tanggal yang diketik d/m/y di Excel berlocale US tersimpan dengan
 *    hari/bulan tertukar di file legacy — sehingga kadang master yang benar.
 * 3. Keputusan diambil dari BUKTI INDEPENDEN:
 *      - bulan di nomor dokumen (mis. 020/BDD/IV/2024, PIK/SEPT/24/INV/…)
 *      - urutan nomor Surat Jalan (PIK-SJ-02470 < PIK-SJ-02497 ⇒ tanggal urut)
 *      - tanggal bayar tidak boleh sebelum tanggal invoice
 *      - Qty × Unit Price ≈ Total Order Amount
 *    decision = 'legacy' (bukti mendukung legacy) | 'master' (bukti mendukung
 *    master, tidak ada perubahan) | 'review' (tidak cukup bukti ⇒ Needs Review).
 */
final class LegacyVerifier
{
    private const SPECS = [
        'PURCHASE_ORDERS' => [
            'id'      => 'POID',
            'keys'    => [['master' => 'PONumber', 'header' => ['no. po']]],
            'dates'   => [['master' => 'PODate', 'header' => ['tanggal'], 'db' => 'po_date']],
            'numbers' => [],
        ],
        'DELIVERIES' => [
            'id'      => 'DeliveryID',
            'keys'    => [['master' => 'SJNumber', 'header' => ['no. surat jalan']], ['master' => 'DeliveredQuantity', 'header' => ['delivered quantity']]],
            'dates'   => [['master' => 'DeliveryDate', 'header' => ['delivery date'], 'db' => 'delivery_date']],
            'numbers' => [],
            'enrich'  => ['po' => ['po number', 'po'], 'product' => ['product']],
        ],
        'INVOICES_PAYMENTS' => [
            'id'      => 'InvoicePaymentID',
            'keys'    => [['master' => 'InvoiceNumber', 'header' => ['invoice number']]],
            'dates'   => [
                ['master' => 'InvoiceDate', 'header' => ['invoice date'], 'db' => 'invoice_date'],
                ['master' => 'PaymentDate', 'header' => ['payment date'], 'db' => 'payment_date'],
            ],
            'numbers' => [],
        ],
        'PO_FINANCIALS' => [
            'id'      => 'POFinancialID',
            'keys'    => [['master' => 'PONumberLegacy', 'header' => ['po number']]],
            'dates'   => [['master' => 'PODate', 'header' => ['tanggal po'], 'db' => 'po_date']],
            'numbers' => [
                ['master' => 'UnitPrice', 'header' => ['unit price'], 'db' => 'unit_price'],
                ['master' => 'TotalOrderAmount', 'header' => ['total order amount'], 'db' => 'total_order_amount'],
                ['master' => 'PPN', 'header' => ['ppn'], 'db' => 'ppn'],
                ['master' => 'TotalInclPPN', 'header' => ['total order amount (include ppn)'], 'db' => 'total_incl_ppn'],
            ],
        ],
        'RETURNS' => [
            'id'      => 'ReturnID',
            'keys'    => [['master' => 'PONumberLegacy', 'header' => ['po number']], ['master' => 'ReturnQuantity', 'header' => ['delivered quantity']]],
            'dates'   => [['master' => 'ReturnDate', 'header' => ['delivery date'], 'db' => 'return_date']],
            'numbers' => [],
        ],
    ];

    private const MONTHS = [
        'januari' => 1, 'january' => 1, 'jan' => 1, 'februari' => 2, 'february' => 2, 'feb' => 2, 'pebruari' => 2, 'febr' => 2,
        'maret' => 3, 'march' => 3, 'mar' => 3, 'mart' => 3, 'april' => 4, 'apr' => 4, 'mei' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6, 'jun' => 6, 'juli' => 7, 'july' => 7, 'jul' => 7,
        'agustus' => 8, 'august' => 8, 'agu' => 8, 'aug' => 8, 'agt' => 8, 'agust' => 8, 'agus' => 8, 'ags' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9, 'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'nop' => 11, 'desember' => 12, 'december' => 12, 'des' => 12, 'dec' => 12,
    ];

    private const ROMAN = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12];

    /** @var array<string,XlsxReader> */
    private array $files = [];
    /** @var array<string,list<array<int,mixed>>> */
    private array $rowCache = [];
    /** @var list<array<string,mixed>> kandidat perbedaan sebelum diputuskan */
    private array $candidates = [];
    /** @var array<string,list<array{sj:int,date:string}>> urutan SJ yang tanggalnya tidak ambigu, per sheet sumber */
    private array $sjTimeline = [];
    /** @var array<string,string> kode invoice => tanggal invoice master (sebelum koreksi) */
    private array $invoiceDates = [];
    /** @var array<string,array<string,mixed>> kode PO financial => data master */
    private array $financialRows = [];

    /** @var list<array<string,mixed>> hasil akhir: perbedaan + keputusan */
    public array $corrections = [];
    /** @var array<string,array<string,array{po:?string,product:?string}>> */
    public array $enrichment = [];
    /** @var list<string> */
    public array $notes = [];
    /** @var array<string,array{checked:int,verified:int,unverified:int}> */
    public array $stats = [];

    /** @param list<string> $paths */
    public function __construct(array $paths, private readonly bool $autoApply = true)
    {
        foreach ($paths as $path) {
            $this->files[self::normalizeFileKey(basename($path))] = new XlsxReader($path);
        }
    }

    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    private function readerFor(string $sourceFile): ?XlsxReader
    {
        $key = self::normalizeFileKey($sourceFile);
        foreach ($this->files as $fileKey => $reader) {
            if ($key !== '' && str_contains($fileKey, $key)) {
                return $reader;
            }
        }
        return null;
    }

    public static function normalizeFileKey(string $name): string
    {
        $name = preg_replace('/\.xlsx$/i', '', $name) ?? $name;
        return preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
    }

    public static function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d');
        }
        $s = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $value) ?? '');
        return ($s === '' || $s === '-') ? null : $s;
    }

    /**
     * Verifikasi semua tabel lalu putuskan setiap perbedaan berdasarkan bukti.
     * @param array<string,list<array{row:int,data:array<string,mixed>}>> $sheets
     */
    public function verify(array $sheets): void
    {
        foreach (array_keys(self::SPECS) as $table) {
            if (isset($sheets[$table])) {
                $this->verifyTable($table, $sheets[$table]);
            }
        }
        $this->decide();
    }

    /** @param list<array{row:int,data:array<string,mixed>}> $masterRows */
    private function verifyTable(string $table, array $masterRows): void
    {
        $spec = self::SPECS[$table];
        $this->stats[$table] = ['checked' => 0, 'verified' => 0, 'unverified' => 0];
        $groups = [];
        foreach ($masterRows as $r) {
            $d = $r['data'];
            if ($table === 'INVOICES_PAYMENTS' && isset($d['InvoiceDate']) && $d['InvoiceDate'] instanceof DateTimeImmutable) {
                $this->invoiceDates[(string) $d['InvoicePaymentID']] = $d['InvoiceDate']->format('Y-m-d');
            }
            if ($table === 'PO_FINANCIALS') {
                $this->financialRows[(string) $d['POFinancialID']] = $d;
            }
            if (empty($d['SourceFile']) || empty($d['SourceSheet']) || !is_int($d['LegacyRow'] ?? null)) {
                continue;
            }
            $groups[$d['SourceFile'] . '|' . $d['SourceSheet']][] = $d;
        }
        foreach ($groups as $groupKey => $rows) {
            [$sourceFile, $sourceSheet] = explode('|', $groupKey, 2);
            $reader = $this->readerFor($sourceFile);
            if ($reader === null || !$reader->hasSheet($sourceSheet)) {
                if ($reader !== null) {
                    $this->notes[] = "Sheet '{$sourceSheet}' tidak ada di {$sourceFile}; {$table} dari sheet ini tidak diverifikasi.";
                }
                continue;
            }
            $legacy = $this->rowCache[$groupKey] ??= $reader->rows($sourceSheet);
            $columns = $this->detectColumns($legacy, $spec);
            if ($columns === null) {
                $this->notes[] = "Header kolom kunci tidak ditemukan di {$sourceFile} › {$sourceSheet}; {$table} tidak diverifikasi.";
                continue;
            }
            $offset = $this->detectOffset($legacy, $rows, $spec, $columns);
            if ($offset === null) {
                $this->notes[] = "Posisi baris legacy tidak dapat dipastikan untuk {$sourceFile} › {$sourceSheet}; {$table} tidak diverifikasi.";
                continue;
            }
            foreach ($rows as $d) {
                $this->stats[$table]['checked']++;
                $index = (int) $d['LegacyRow'] - 1 + $offset;
                $legacyRow = $legacy[$index] ?? null;
                if ($legacyRow === null || !$this->keysMatch($d, $legacyRow, $spec, $columns)) {
                    $this->stats[$table]['unverified']++;
                    continue;
                }
                $this->stats[$table]['verified']++;
                $code = (string) $d[$spec['id']];
                $evidence = sprintf('%s › %s baris %d', $sourceFile, $sourceSheet, $index + 1);
                foreach ($spec['dates'] as $dateSpec) {
                    if (isset($columns[$dateSpec['master']])) {
                        $this->compareDate($table, $code, $dateSpec, $d, $legacyRow[$columns[$dateSpec['master']]] ?? null, $evidence, $groupKey);
                    }
                }
                foreach ($spec['numbers'] as $numSpec) {
                    if (isset($columns[$numSpec['master']])) {
                        $this->compareNumber($table, $code, $numSpec, $d, $legacyRow[$columns[$numSpec['master']]] ?? null, $evidence);
                    }
                }
                if (!empty($spec['enrich'])) {
                    $this->enrichment[$table][$code] = [
                        'po'      => isset($columns['__po']) ? self::normalizeText($legacyRow[$columns['__po']] ?? null) : null,
                        'product' => isset($columns['__product']) ? self::normalizeText($legacyRow[$columns['__product']] ?? null) : null,
                    ];
                }
            }
        }
    }

    /** @return array<string,int>|null */
    private function detectColumns(array $legacy, array $spec): ?array
    {
        $wanted = [];
        foreach (array_merge($spec['keys'], $spec['dates'], $spec['numbers']) as $item) {
            $wanted[$item['master']] = $item['header'];
        }
        for ($r = 0; $r < min(20, count($legacy)); $r++) {
            $headers = array_map(static fn ($h) => strtolower((string) self::normalizeText($h)), $legacy[$r]);
            $found = [];
            foreach ($wanted as $master => $candidates) {
                foreach ($headers as $idx => $h) {
                    if (in_array($h, $candidates, true)) {
                        $found[$master] = $idx;
                        break;
                    }
                }
            }
            $keysFound = array_filter($spec['keys'], static fn ($k) => isset($found[$k['master']]));
            if (count($keysFound) === count($spec['keys'])) {
                if (!empty($spec['enrich'])) {
                    foreach ($headers as $idx => $h) {
                        if (!isset($found['__po']) && in_array($h, $spec['enrich']['po'], true)) {
                            $found['__po'] = $idx;
                        }
                        if (!isset($found['__product']) && in_array($h, $spec['enrich']['product'], true)) {
                            $found['__product'] = $idx;
                        }
                    }
                    if (!isset($found['__po']) && isset($found['__product']) && $found['__product'] > 0) {
                        $found['__po'] = $found['__product'] - 1;
                    }
                }
                return $found;
            }
        }
        return null;
    }

    private function detectOffset(array $legacy, array $rows, array $spec, array $columns): ?int
    {
        $best = null;
        $bestCount = 0;
        for ($offset = -2; $offset <= 20; $offset++) {
            $count = 0;
            foreach ($rows as $d) {
                $row = $legacy[(int) $d['LegacyRow'] - 1 + $offset] ?? null;
                if ($row !== null && $this->keysMatch($d, $row, $spec, $columns)) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $offset;
            }
        }
        return $bestCount >= max(1, (int) ceil(count($rows) * 0.5)) ? $best : null;
    }

    private function keysMatch(array $master, array $legacyRow, array $spec, array $columns): bool
    {
        foreach ($spec['keys'] as $key) {
            $mv = $master[$key['master']] ?? null;
            $lv = $legacyRow[$columns[$key['master']]] ?? null;
            if ((is_int($mv) || is_float($mv)) && (is_int($lv) || is_float($lv))) {
                if (abs((float) $mv - (float) $lv) >= 0.5) {
                    return false;
                }
                continue;
            }
            if (self::normalizeText($mv) !== self::normalizeText($lv)) {
                return false;
            }
        }
        return true;
    }

    private function compareDate(string $table, string $code, array $spec, array $d, mixed $legacyValue, string $evidence, string $group): void
    {
        $masterValue = $d[$spec['master']] ?? null;
        $master = $masterValue instanceof DateTimeImmutable ? $masterValue->format('Y-m-d') : null;
        $legacyText = null;
        if ($legacyValue instanceof DateTimeImmutable) {
            $legacy = $legacyValue->format('Y-m-d');
        } elseif (is_string($legacyValue) && self::normalizeText($legacyValue) !== null) {
            $legacyText = (string) self::normalizeText($legacyValue);
            $legacy = self::parseIndonesianDate($legacyText);
            if ($legacy === null) {
                return;
            }
        } else {
            return;
        }
        $sj = $table === 'DELIVERIES' ? self::sjSequence((string) ($d['SJNumber'] ?? '')) : null;
        if ($master === $legacy) {
            // tanggal sama di kedua sumber: jadi titik acuan urutan SJ bila tidak ambigu
            if ($sj !== null && $master !== null) {
                $this->sjTimeline[$group][] = ['sj' => $sj, 'date' => $master];
            }
            return;
        }
        $this->candidates[] = [
            'table'      => $table,
            'code'       => $code,
            'master_col' => $spec['master'],
            'db_col'     => $spec['db'],
            'master'     => $master,
            'legacy'     => $legacy,
            'legacy_raw' => $legacyText ?? $legacy,
            'swap'       => $master !== null && self::swapDayMonth($legacy) === $master,
            'type'       => 'date',
            'source'     => $evidence,
            'group'      => $group,
            'doc'        => (string) ($d['PONumber'] ?? $d['InvoiceNumber'] ?? $d['PONumberLegacy'] ?? ''),
            'sj'         => $sj,
        ];
    }

    private function compareNumber(string $table, string $code, array $spec, array $d, mixed $legacyValue, string $evidence): void
    {
        $masterValue = $d[$spec['master']] ?? null;
        $master = (is_int($masterValue) || is_float($masterValue)) ? (float) $masterValue : null;
        $legacyText = null;
        if (is_string($legacyValue) && self::normalizeText($legacyValue) !== null) {
            $legacyText = (string) self::normalizeText($legacyValue);
            $parsed = self::parseIdNumber($legacyText);
            if ($parsed === null) {
                return;
            }
            $legacy = (float) $parsed['value'];
            $unambiguous = $parsed['unambiguous'];
        } elseif (is_int($legacyValue) || is_float($legacyValue)) {
            $legacy = (float) $legacyValue;
            $unambiguous = true;
        } else {
            return;
        }
        if ($master !== null && abs($master - $legacy) <= 0.000001 * max(1.0, abs($legacy))) {
            return;
        }
        $this->candidates[] = [
            'table'      => $table,
            'code'       => $code,
            'master_col' => $spec['master'],
            'db_col'     => $spec['db'],
            'master'     => $master === null ? null : self::numString($master),
            'legacy'     => self::numString($legacy),
            'legacy_raw' => $legacyText ?? self::numString($legacy),
            'text'       => $legacyText !== null,
            'unambiguous' => $unambiguous,
            'type'       => 'number',
            'source'     => $evidence,
        ];
    }

    /** Putuskan setiap kandidat berdasarkan bukti independen. */
    private function decide(): void
    {
        // tanggal invoice diputuskan lebih dulu (dipakai untuk memeriksa tanggal bayar)
        usort($this->candidates, static fn ($a, $b) => ($a['db_col'] === 'payment_date') <=> ($b['db_col'] === 'payment_date'));
        $finalInvoiceDate = $this->invoiceDates;
        $finalFinancial = [];
        foreach ($this->candidates as $c) {
            [$decision, $why] = match (true) {
                $c['type'] === 'number'                          => $this->decideNumber($c, $finalFinancial),
                $c['db_col'] === 'payment_date'                  => $this->decidePayment($c, $finalInvoiceDate),
                $c['table'] === 'DELIVERIES'                     => $this->decideBySjSequence($c),
                in_array($c['table'], ['PURCHASE_ORDERS', 'INVOICES_PAYMENTS', 'PO_FINANCIALS'], true) => $this->decideByDocumentMonth($c),
                default                                          => ['review', 'Tidak ada bukti independen untuk memilih salah satu nilai.'],
            };
            if ($c['db_col'] === 'invoice_date' && $decision === 'legacy') {
                $finalInvoiceDate[$c['code']] = $c['legacy'];
            }
            if ($c['type'] === 'number' && $decision === 'legacy') {
                $finalFinancial[$c['code']][$c['master_col']] = (float) $c['legacy'];
            }
            $c['decision'] = $decision;
            $c['why'] = $why;
            $c['apply'] = $decision === 'legacy' && $this->autoApply;
            $this->corrections[] = $c;
        }
    }

    /** @return array{0:string,1:string} */
    private function decideByDocumentMonth(array $c): array
    {
        $docMonth = self::documentMonth($c['doc']);
        $lm = (int) substr($c['legacy'], 5, 2);
        $mm = $c['master'] !== null ? (int) substr($c['master'], 5, 2) : null;
        if ($docMonth === null) {
            return ['review', "Nomor dokumen '{$c['doc']}' tidak memuat bulan, sehingga tidak ada bukti untuk memilih."];
        }
        if ($docMonth === $lm && $docMonth !== $mm) {
            return ['legacy', "Nomor dokumen '{$c['doc']}' menunjukkan bulan {$docMonth}, sesuai tanggal legacy."];
        }
        if ($docMonth === $mm && $docMonth !== $lm) {
            return ['master', "Nomor dokumen '{$c['doc']}' menunjukkan bulan {$docMonth}, sesuai tanggal master."];
        }
        return ['review', "Bulan pada nomor dokumen '{$c['doc']}' ({$docMonth}) tidak cocok dengan kedua tanggal."];
    }

    /** @param array<string,string> $invoiceDates @return array{0:string,1:string} */
    private function decidePayment(array $c, array $invoiceDates): array
    {
        $inv = $invoiceDates[$c['code']] ?? null;
        if ($inv === null) {
            return ['review', 'Tanggal invoice tidak diketahui, tanggal bayar tidak dapat dibandingkan.'];
        }
        $legacyOk = $c['legacy'] >= $inv;
        $masterOk = $c['master'] !== null && $c['master'] >= $inv;
        if ($legacyOk && !$masterOk) {
            return ['legacy', "Tanggal bayar master ({$c['master']}) lebih awal dari tanggal invoice ({$inv}); nilai legacy konsisten."];
        }
        if ($masterOk && !$legacyOk) {
            return ['master', "Tanggal bayar legacy ({$c['legacy']}) lebih awal dari tanggal invoice ({$inv}); nilai master konsisten."];
        }
        return ['review', "Kedua tanggal bayar sama-sama setelah tanggal invoice ({$inv})."];
    }

    /** @return array{0:string,1:string} */
    private function decideBySjSequence(array $c): array
    {
        if ($c['sj'] === null || $c['master'] === null) {
            return ['review', 'Nomor Surat Jalan tidak berurutan numerik; tidak ada bukti urutan.'];
        }
        $timeline = $this->sjTimeline[$c['group']] ?? [];
        $lower = null;
        $upper = null;
        foreach ($timeline as $p) {
            if ($p['sj'] < $c['sj'] && ($lower === null || $p['sj'] > $lower['sj'])) {
                $lower = $p;
            }
            if ($p['sj'] > $c['sj'] && ($upper === null || $p['sj'] < $upper['sj'])) {
                $upper = $p;
            }
        }
        if ($lower === null || $upper === null) {
            return ['review', 'Tidak ada Surat Jalan acuan sebelum/sesudah nomor ini.'];
        }
        $fits = static function (string $date) use ($lower, $upper): bool {
            $from = date('Y-m-d', strtotime($lower['date'] . ' -7 days'));
            $to = date('Y-m-d', strtotime($upper['date'] . ' +7 days'));
            return $date >= $from && $date <= $to;
        };
        $range = "SJ sebelumnya {$lower['date']}, SJ sesudahnya {$upper['date']}";
        $legacyFits = $fits($c['legacy']);
        $masterFits = $fits($c['master']);
        if ($legacyFits && !$masterFits) {
            return ['legacy', "Urutan nomor Surat Jalan mendukung tanggal legacy ({$range})."];
        }
        if ($masterFits && !$legacyFits) {
            return ['master', "Urutan nomor Surat Jalan mendukung tanggal master ({$range})."];
        }
        return ['review', "Urutan nomor Surat Jalan tidak cukup membedakan ({$range})."];
    }

    /** @param array<string,array<string,float>> $final @return array{0:string,1:string} */
    private function decideNumber(array $c, array $final): array
    {
        $row = $this->financialRows[$c['code']] ?? [];
        $qty = is_numeric($row['OrderQuantity'] ?? null) ? (float) $row['OrderQuantity'] : null;
        $total = $final[$c['code']]['TotalOrderAmount'] ?? (is_numeric($row['TotalOrderAmount'] ?? null) ? (float) $row['TotalOrderAmount'] : null);
        if ($c['master_col'] === 'UnitPrice' && $qty && $total) {
            $legacyOk = abs($qty * (float) $c['legacy'] - $total) <= $total * 0.01;
            $masterOk = $c['master'] !== null && abs($qty * (float) $c['master'] - $total) <= $total * 0.01;
            if ($legacyOk && !$masterOk) {
                return ['legacy', 'Qty × unit price legacy sesuai Total Order Amount (selisih ≤ 1%).'];
            }
            if ($masterOk && !$legacyOk) {
                return ['master', 'Qty × unit price master sesuai Total Order Amount.'];
            }
        }
        if (($c['text'] ?? false) && ($c['unambiguous'] ?? false)) {
            return ['legacy', "Teks legacy \"{$c['legacy_raw']}\" hanya dapat dibaca sebagai format angka Indonesia = {$c['legacy']}."];
        }
        return ['review', 'Tidak ada bukti independen untuk memilih salah satu nilai.'];
    }

    // ------------------------------------------------------------------
    // Utilitas
    // ------------------------------------------------------------------

    public static function swapDayMonth(string $ymd): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($d > 12 || $d === $mo || !checkdate($d, $mo, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $d, $mo);
    }

    /** "24 Januari 2024", "31/7/24", "4/9/2024", "2024-09-04" => Y-m-d (hari lebih dulu). */
    public static function parseIndonesianDate(string $text): ?string
    {
        $t = strtolower(trim($text));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $t, $m)) {
            $y = (int) $m[3];
            $y = $y < 100 ? 2000 + $y : $y;
            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{1,2})[\s\-]+([a-z]+)[\s\-]+(\d{2}|\d{4})$/', $t, $m) && isset(self::MONTHS[$m[2]])) {
            $y = (int) $m[3];
            $y = $y < 100 ? 2000 + $y : $y;
            $mo = self::MONTHS[$m[2]];
            return checkdate($mo, (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $m[1]) : null;
        }
        return null;
    }

    /**
     * Teks angka format Indonesia. unambiguous = hanya ada satu cara membacanya
     * ("1.846,85", "62.162.220,00", "270,27"). "1,846" (3 digit setelah koma)
     * dianggap ambigu karena bisa berarti 1846 dalam format Inggris.
     * @return array{value:string,unambiguous:bool}|null
     */
    public static function parseIdNumber(string $text): ?array
    {
        $t = str_replace([' ', "\u{00A0}"], '', trim($text));
        if (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $t)) {
            return ['value' => str_replace(['.', ','], ['', '.'], $t), 'unambiguous' => true];
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $t)) {
            return ['value' => str_replace('.', '', $t), 'unambiguous' => !preg_match('/^-?\d{1,3}\.\d{3}$/', $t)];
        }
        if (preg_match('/^-?\d+,(\d+)$/', $t, $m)) {
            return ['value' => str_replace(',', '.', $t), 'unambiguous' => strlen($m[1]) !== 3];
        }
        return null;
    }

    /**
     * Bulan yang tertulis di nomor dokumen, bila ada:
     *   020/BDD/IV/2024 · 001/GNI-EXT/PO/IV/26 → 4 (angka romawi)
     *   PIK/SEPT/24/INV/0238 → 9 · PURC/20260204/… → 2 · PO-11.04.26.011 → 4
     *   NIC/202603-074 → 3 · PO/260409/0017 → 4 · 0001/RPI-PO/06/2026 → 6
     */
    public static function documentMonth(string $number): ?int
    {
        $s = strtoupper(trim($number));
        if ($s === '') {
            return null;
        }
        if (preg_match('/(?<!\d)20\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?!\d)/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(?<!\d)\d{2}\.(0[1-9]|1[0-2])\.\d{2}(?!\d)/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(?<!\d)20\d{2}(0[1-9]|1[0-2])(?!\d)/', $s, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#/\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])/#', $s, $m)) {
            return (int) $m[1];
        }
        foreach (preg_split('/[\/\-\s_.]+/', $s) ?: [] as $token) {
            if (isset(self::ROMAN[$token])) {
                return self::ROMAN[$token];
            }
            $lower = strtolower($token);
            if (isset(self::MONTHS[$lower]) && strlen($lower) >= 3) {
                return self::MONTHS[$lower];
            }
        }
        if (preg_match('#/(0?[1-9]|1[0-2])/(20)?\d{2}(?!\d)#', $s, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** "PIK-SJ-02497" => 2497 (hanya format nomor SJ PIK yang berurutan). */
    public static function sjSequence(string $sj): ?int
    {
        return preg_match('/^PIK-SJ-(\d{3,6})$/i', trim($sj), $m) ? (int) $m[1] : null;
    }

    private static function numString(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
