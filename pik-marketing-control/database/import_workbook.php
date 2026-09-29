<?php

declare(strict_types=1);

/*
 * IMPORT DATA AWAL dari PIK_Master_Database_AppSheet.xlsx
 *
 * Contoh:
 *   # cek dulu tanpa menyimpan apa pun
 *   php database/import_workbook.php --master="/path/PIK_Master_Database_AppSheet.xlsx" --dry-run
 *
 *   # import ke database kosong (disarankan menyertakan file legacy untuk verifikasi)
 *   php database/import_workbook.php --master="/path/PIK_Master_Database_AppSheet.xlsx" \
 *       --legacy="/path/PIK X PT SCL.xlsx" --legacy="/path/PIK X ALL CUSTOMER.xlsx" \
 *       --legacy="/path/PIK x Scora x Facetology - RECAP ... .xlsx"
 *
 *   # hasilkan file SQL untuk diimpor via phpMyAdmin (tanpa koneksi database)
 *   php database/import_workbook.php --master=... --legacy=... --sql-out=storage/exports/data_migrasi.sql
 *
 * Opsi:
 *   --master=FILE            wajib, workbook master
 *   --legacy=FILE            opsional, boleh diulang (file spreadsheet asli) untuk verifikasi
 *   --no-legacy-corrections  jangan terapkan koreksi otomatis; semua temuan jadi issue "Needs Review"
 *   --dry-run                tampilkan ringkasan saja, tanpa menulis ke database
 *   --sql-out=FILE           tulis SQL INSERT ke file, bukan ke database
 *   --fresh                  KOSONGKAN data bisnis (customer, PO, CRM, dll.) sebelum import.
 *                            User, settings & audit log tidak dihapus. Meminta konfirmasi.
 *   --yes                    lewati konfirmasi --fresh (untuk otomasi)
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Audit;
use App\Helpers\Database;
use App\Services\Migration\WorkbookImporter;

$opts = getopt('', ['master:', 'legacy:', 'no-legacy-corrections', 'dry-run', 'sql-out:', 'fresh', 'yes', 'help']);
if (isset($opts['help']) || empty($opts['master'])) {
    echo "Pemakaian: php database/import_workbook.php --master=FILE [--legacy=FILE ...] [--dry-run] [--sql-out=FILE] [--fresh] [--no-legacy-corrections]\n";
    exit(isset($opts['help']) ? 0 : 1);
}
$legacy = isset($opts['legacy']) ? (array) $opts['legacy'] : [];

try {
    $start = microtime(true);
    echo 'Membaca workbook: ' . basename((string) $opts['master']) . "\n";
    foreach ($legacy as $l) {
        echo '  + legacy: ' . basename((string) $l) . "\n";
    }
    $importer = (new WorkbookImporter((string) $opts['master'], $legacy, !isset($opts['no-legacy-corrections'])))->build();
    $r = $importer->report;

    echo "\nData yang akan diimpor:\n";
    foreach ($r['counts'] as $table => $count) {
        printf("  %-20s %6d\n", $table, $count);
    }
    if ($r['legacy']) {
        echo "\nVerifikasi terhadap file legacy:\n";
        foreach ($r['legacy'] as $table => $s) {
            printf("  %-20s diperiksa %5d · cocok %5d · tidak terverifikasi %4d\n", $table, $s['checked'], $s['verified'], $s['unverified']);
        }
        printf("  Koreksi otomatis (didukung bukti): %d · Perlu ditinjau: %d · Master terbukti benar: %d\n", $r['auto_corrected'], $r['suggestions'], $r['master_confirmed'] ?? 0);
    }
    if ($r['linked_exact']) {
        echo "\nRelasi dibuat dari kecocokan PERSIS (bukan fuzzy):\n";
        foreach ($r['linked_exact'] as $label => $n) {
            printf("  %-60s %4d\n", $label, $n);
        }
    }
    echo "\nMigration issues:\n";
    foreach ($r['issues_by_type'] as $type => $n) {
        printf("  %-58s %5d\n", $type, $n);
    }
    foreach ($r['notes'] as $note) {
        echo "  catatan: {$note}\n";
    }

    if (isset($opts['sql-out'])) {
        $importer->writeSql((string) $opts['sql-out']);
        echo "\nFile SQL ditulis: {$opts['sql-out']}\nImpor file ini lewat phpMyAdmin SETELAH schema.sql, pada database kosong.\n";
        echo "PERINGATAN: file berisi data perusahaan — jangan di-commit / dibagikan.\n";
        exit(0);
    }
    if (isset($opts['dry-run'])) {
        echo "\nDry run selesai — tidak ada data yang ditulis.\n";
        exit(0);
    }

    echo "\nDatabase tujuan: " . Database::fetchValue('SELECT DATABASE()') . "\n";
    if (isset($opts['fresh'])) {
        $existing = WorkbookImporter::nonEmptyTables();
        if ($existing !== []) {
            echo "PERHATIAN: --fresh akan MENGHAPUS data bisnis berikut:\n";
            foreach ($existing as $t => $c) {
                printf("  %-20s %6d baris\n", $t, $c);
            }
            if (!isset($opts['yes'])) {
                echo "Ketik HAPUS untuk melanjutkan: ";
                if (trim((string) fgets(STDIN)) !== 'HAPUS') {
                    echo "Dibatalkan.\n";
                    exit(1);
                }
            }
            WorkbookImporter::wipeBusinessData();
            Audit::log('wipe_business_data', 'import', null, 'Data bisnis dikosongkan sebelum import (--fresh)', array_map(static fn ($c) => ['old' => $c, 'new' => 0], $existing));
            echo "Data bisnis dikosongkan.\n";
        }
    }
    $counts = $importer->execute(null);
    Audit::log('import', 'import', null, basename((string) $opts['master']), array_map(static fn ($c) => ['old' => null, 'new' => $c], $counts));
    Database::query(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['k' => 'last_import', 'v' => json_encode(['at' => date('Y-m-d H:i:s'), 'file' => basename((string) $opts['master']), 'counts' => $counts, 'legacy_files' => array_map('basename', $legacy), 'auto_corrected' => $r['auto_corrected']], JSON_UNESCAPED_UNICODE)]
    );
    printf("\nImport selesai dalam %.1f detik. Tinjau hasilnya di menu Settings › Migration Issues.\n", microtime(true) - $start);
} catch (Throwable $e) {
    fwrite(STDERR, "\nGAGAL: " . $e->getMessage() . "\nTidak ada data yang tersimpan (transaksi dibatalkan).\n");
    exit(1);
}
