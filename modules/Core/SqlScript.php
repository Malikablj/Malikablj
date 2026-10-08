<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Menjalankan file SQL milik aplikasi (schema, seed, hardening, migrasi) — bukan input pengguna.
 * Baris CREATE DATABASE/USE dan komentar baris diabaikan agar file dapat dijalankan ke database mana pun.
 * Pemisah pernyataan: titik koma di akhir baris.
 */
final class SqlScript
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $out = [];
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
        }
        return $out;
    }

    /**
     * Jalankan seluruh pernyataan file; mengembalikan jumlah pernyataan.
     * @throws \RuntimeException berisi nomor pernyataan yang gagal
     */
    public static function runFile(PDO $pdo, string $file): int
    {
        $count = 0;
        foreach (self::statements((string) file_get_contents($file)) as $i => $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\PDOException $e) {
                throw new \RuntimeException(sprintf('%s, pernyataan #%d: %s', basename($file), $i + 1, $e->getMessage()), 0, $e);
            }
            $count++;
        }
        return $count;
    }
}
