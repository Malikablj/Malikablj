<?php

declare(strict_types=1);

/*
 * Import "PIK PO DATABASE" (PO_MASTER, PO_ITEMS, CUSTOMERS, PRODUCTS, VALIDATION).
 *
 *   php database/import_po_database.php --file="/path/PIK_PO_DATABASE_AUG2025_AUG2026.xlsx"
 *       → DRY RUN (default): tampilkan rencana & rekonsiliasi, tidak mengubah data.
 *   php database/import_po_database.php --file=... --import
 *       → dry run, minta konfirmasi, backup tabel terkait, lalu import dalam satu transaksi.
 *   --yes   lewati konfirmasi (untuk otomasi)
 *
 * Aman dijalankan ulang: PO yang sudah ada tidak dibuat ganda (lihat README, bagian Import database PO).
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Migrator;
use App\Helpers\Requirements;
use App\Services\Migration\ImportFailed;
use App\Services\Migration\PoDatabaseImporter;

if (($missingExt = Requirements::excelMissing()) !== []) {
    fwrite(STDERR, Requirements::message($missingExt, 'Import Excel') . "\n");
    exit(1);
}
$opts = getopt('', ['file:', 'import', 'yes', 'help']);
if (isset($opts['help']) || empty($opts['file']) || !is_file((string) $opts['file'])) {
    echo "Pemakaian: php database/import_po_database.php --file=FILE.xlsx [--import] [--yes]\n";
    exit(isset($opts['help']) ? 0 : 1);
}
if (!Migrator::isCurrent()) {
    fwrite(STDERR, "Struktur database belum diperbarui. Jalankan dulu: php database/migrate.php\n");
    exit(1);
}

$file = (string) $opts['file'];
$importer = new PoDatabaseImporter($file, basename($file));

function rp(?string $v): string
{
    return $v === null ? '-' : 'Rp ' . number_format((float) $v, 2, ',', '.');
}

function show(array $r): void
{
    $c = $r['customers'];
    $p = $r['products'];
    $po = $r['purchase_orders'];
    $it = $r['po_items'];
    $rc = $r['reconciliation'];
    echo "\nCUSTOMERS   total {$c['total']} · sudah ada {$c['existing']} (via nomor PO {$c['by_po_number']}) · baru {$c['new']} · duplikat di aplikasi {$c['merged']} · perlu review {$c['needs_review']} · dilengkapi {$c['updated']}\n";
    echo "PRODUCTS    total {$p['total']} · sudah ada {$p['existing']} (via baris PO {$p['via_po_line']}) · baru {$p['new']} · duplikat di aplikasi {$p['merged']} · tidak diperlukan {$p['not_needed']}\n";
    echo "PO          total {$po['total']} · sudah ada {$po['existing']} · baru {$po['inserted']} · dilengkapi {$po['updated']} · identik {$po['unchanged']} · ditahan {$po['held']} · gagal {$po['failed']} · perlu review {$po['needs_review']}\n";
    echo "PO ITEMS    total {$it['total']} · valid {$it['valid']} · tidak valid {$it['invalid']} · baru {$it['inserted']} · cocok baris lama {$it['matched']} (dilengkapi {$it['updated']}) · tidak diimport {$it['not_imported']}\n";
    echo "ISSUES      baru {$r['issues']['new']} · sudah ada {$r['issues']['existing']} · terbuka {$r['issues']['open']}\n";
    foreach ($r['issues']['by_type'] as $type => $n) {
        printf("              %-34s %4d\n", $type, $n);
    }
    echo "\nREKONSILIASI (" . ($rc['source'] === 'database' ? 'dari database' : 'rencana') . ")\n";
    printf("  PO          : Excel %d · aplikasi %d · selisih %d\n", $rc['excel_po_count'], $rc['app_po_count'], $rc['po_difference']);
    printf("  Item        : Excel %d · aplikasi %d · selisih %d\n", $rc['excel_item_count'], $rc['app_item_count'], $rc['item_difference']);
    printf("  Grand total : Excel %s · aplikasi %s · selisih %s\n", rp($rc['excel_grand_total']), rp($rc['app_grand_total']), rp($rc['grand_total_difference']));
    foreach (array_slice($rc['differences'], 0, 40) as $d) {
        printf("    %-28s Excel %-22s App %-22s %s\n", mb_substr((string) ($d['po_number'] ?? $d['po_id']), 0, 28), rp($d['excel']), rp($d['app']), $d['reason']);
    }
    foreach (array_slice($rc['item_gaps'], 0, 40) as $g) {
        printf("    item %-12s %-24s qty %-9s %s\n", $g['item_id'], mb_substr((string) $g['po_number'], 0, 24), (string) $g['qty'], $g['reason']);
    }
    $warn = array_values(array_filter($r['findings'], static fn (array $f): bool => $f['level'] !== 'info'));
    if ($warn !== []) {
        echo "\nTEMUAN (" . count($warn) . ")\n";
        foreach (array_slice($warn, 0, 40) as $f) {
            printf("  [%s] %s baris %s · PO %s · %s: %s → %s\n", strtoupper($f['level']), $f['sheet'], $f['row'] ?? '-', $f['po_number'] ?? '-', $f['field'], $f['problem'], $f['action']);
        }
    }
}

try {
    echo "DRY RUN: {$file}\n";
    $dry = $importer->dryRun(null);
    show($dry);
    echo "\nLog dry run #{$dry['log_id']}: {$dry['status']}\n";
    if (!isset($opts['import'])) {
        echo "\nTidak ada data yang diubah. Untuk import: tambahkan --import\n";
        exit(0);
    }
    if (!isset($opts['yes'])) {
        echo "\nLanjutkan import ke database " . App\Helpers\Database::fetchValue('SELECT DATABASE()') . '? Ketik IMPORT: ';
        if (trim((string) fgets(STDIN)) !== 'IMPORT') {
            echo "Dibatalkan.\n";
            exit(1);
        }
    }
    $result = $importer->import(null);
    echo "\nIMPORT SELESAI (log #{$result['log_id']}: {$result['status']}) · backup: storage/backups/{$result['backup_file']}\n";
    show($result);
} catch (ImportFailed $e) {
    fwrite(STDERR, "\nGAGAL ({$e->status}, log #{$e->logId}): {$e->getMessage()}\n");
    fwrite(STDERR, $e->status === 'ROLLED_BACK' ? "Semua perubahan dibatalkan (rollback); database kembali seperti sebelum import.\n" : "Tidak ada data yang diubah.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
