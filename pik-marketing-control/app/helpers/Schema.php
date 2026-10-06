<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Pengecekan & perubahan struktur tabel untuk migrasi (database/migrations).
 * Setiap perubahan dicek dulu lewat information_schema sehingga migrasi aman
 * dijalankan ulang, baik di MySQL 8 maupun MariaDB (tanpa sintaks IF NOT EXISTS
 * pada ALTER yang tidak didukung MySQL).
 */
final class Schema
{
    public static function hasTable(string $table): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table]
        );
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
    }

    public static function hasIndex(string $table, string $index): bool
    {
        return (bool) Database::fetchValue(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
            ['t' => $table, 'i' => $index]
        );
    }

    public static function hasForeignKey(string $table, string $name): bool
    {
        return (bool) Database::fetchValue(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            ['t' => $table, 'n' => $name]
        );
    }

    /** Panjang maksimum kolom teks (CHARACTER_MAXIMUM_LENGTH), null bila bukan teks/tidak ada. */
    public static function columnLength(string $table, string $column): ?int
    {
        $len = Database::fetchValue(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            ['t' => $table, 'c' => $column]
        );
        return $len === null || $len === false ? null : (int) $len;
    }

    /** CREATE TABLE IF NOT EXISTS ... (SQL lengkap ditulis di file migrasi). */
    public static function createTable(string $sql): bool
    {
        if (!preg_match('/^\s*CREATE TABLE IF NOT EXISTS `?([a-z0-9_]+)`?/i', $sql, $m)) {
            throw new \InvalidArgumentException('createTable hanya menerima CREATE TABLE IF NOT EXISTS.');
        }
        if (self::hasTable($m[1])) {
            return false;
        }
        Database::connection()->exec($sql);
        return true;
    }

    /** Tambah kolom bila belum ada. $definition mis. "DECIMAL(18,2) NULL AFTER subtotal". */
    public static function addColumn(string $table, string $column, string $definition): bool
    {
        Database::assertIdentifier($table);
        Database::assertIdentifier($column);
        if (self::hasColumn($table, $column)) {
            return false;
        }
        Database::connection()->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        return true;
    }

    /** Ubah definisi kolom yang sudah ada (hanya untuk perubahan non-destruktif, mis. memperlebar VARCHAR). */
    public static function modifyColumn(string $table, string $column, string $definition): void
    {
        Database::assertIdentifier($table);
        Database::assertIdentifier($column);
        Database::connection()->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
    }

    /** @param list<string> $columns */
    public static function addIndex(string $table, string $index, array $columns, bool $unique = false): bool
    {
        Database::assertIdentifier($table);
        Database::assertIdentifier($index);
        array_map([Database::class, 'assertIdentifier'], $columns);
        if (self::hasIndex($table, $index)) {
            return false;
        }
        $cols = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        Database::connection()->exec("ALTER TABLE `{$table}` ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX `{$index}` ({$cols})");
        return true;
    }

    public static function addForeignKey(string $table, string $name, string $column, string $refTable, string $onDelete = 'SET NULL'): bool
    {
        foreach ([$table, $name, $column, $refTable] as $identifier) {
            Database::assertIdentifier($identifier);
        }
        if (!in_array($onDelete, ['SET NULL', 'RESTRICT', 'CASCADE'], true)) {
            throw new \InvalidArgumentException('ON DELETE tidak valid.');
        }
        if (self::hasForeignKey($table, $name)) {
            return false;
        }
        Database::connection()->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$refTable}` (id) ON DELETE {$onDelete}");
        return true;
    }
}
