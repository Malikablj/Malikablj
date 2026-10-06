<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Backup tabel ke file SQL di storage/backups (tanpa mysqldump, sehingga juga
 * jalan di shared hosting). Dipakai otomatis sebelum migrasi & import.
 *
 * Restore: impor file .sql lewat phpMyAdmin, atau
 *   mysql -u USER -p NAMA_DATABASE < storage/backups/FILE.sql
 * File berisi DROP TABLE + CREATE TABLE + INSERT untuk tabel yang dibackup saja.
 * Untuk backup seluruh database tetap gunakan mysqldump / Export phpMyAdmin.
 */
final class DbBackup
{
    /**
     * @param list<string> $tables
     * @return string path file backup
     */
    public static function tables(array $tables, string $label): string
    {
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new RuntimeException('Folder storage/backups tidak dapat dibuat.');
        }
        $label = preg_replace('/[^a-z0-9_-]+/i', '-', $label) ?: 'backup';
        $path = $dir . '/' . date('Ymd-His') . '-' . $label . '.sql';
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException('File backup tidak dapat ditulis di storage/backups (periksa izin folder).');
        }
        $pdo = Database::connection();
        try {
            fwrite($fh, "-- PIK Marketing Control: backup tabel " . implode(', ', $tables) . "\n");
            fwrite($fh, '-- Dibuat ' . date('Y-m-d H:i:s') . ' dari database ' . Database::fetchValue('SELECT DATABASE()') . "\n");
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
            foreach ($tables as $table) {
                Database::assertIdentifier($table);
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $create = Database::fetch("SHOW CREATE TABLE `{$table}`");
                fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . ($create['Create Table'] ?? '') . ";\n\n");
                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $batch = [];
                $columns = null;
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $columns ??= '(`' . implode('`, `', array_keys($row)) . '`)';
                    $batch[] = '(' . implode(', ', array_map([self::class, 'quote'], array_values($row))) . ')';
                    if (count($batch) >= 200) {
                        fwrite($fh, "INSERT INTO `{$table}` {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    fwrite($fh, "INSERT INTO `{$table}` {$columns} VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($fh);
        }
        return $path;
    }

    private static function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return Database::connection()->quote((string) $value);
    }
}
