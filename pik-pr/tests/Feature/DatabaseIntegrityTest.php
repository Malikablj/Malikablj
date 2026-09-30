<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use PDOException;
use Tests\TestCase;

final class DatabaseIntegrityTest extends TestCase
{
    public function test_schema_uses_innodb_and_utf8mb4(): void
    {
        $tables = Database::connection()->query(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        )->fetchAll();

        self::assertGreaterThanOrEqual(15, count($tables));
        foreach ($tables as $table) {
            self::assertSame('InnoDB', $table['ENGINE'], $table['TABLE_NAME']);
            self::assertStringStartsWith('utf8mb4', (string) $table['TABLE_COLLATION'], $table['TABLE_NAME']);
        }
    }

    public function test_money_columns_are_decimal(): void
    {
        $columns = Database::connection()->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('subtotal', 'tax_amount', 'grand_total', 'unit_price', 'line_total', 'default_price', 'min_amount')",
        )->fetchAll();

        self::assertNotEmpty($columns);
        foreach ($columns as $column) {
            self::assertSame('decimal(15,2)', $column['COLUMN_TYPE'], $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME']);
        }
        self::assertSame(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('float', 'double')",
        ));
    }

    public function test_required_indexes_exist(): void
    {
        $indexed = array_column(Database::connection()->query(
            "SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_requisitions' AND SEQ_IN_INDEX = 1",
        )->fetchAll(), 'COLUMN_NAME');

        foreach (['pr_number', 'status', 'department_id', 'requester_id', 'supplier_id', 'created_at'] as $column) {
            self::assertContains($column, $indexed);
        }
    }

    public function test_foreign_key_prevents_orphan_item(): void
    {
        try {
            Database::connection()->exec(
                "INSERT INTO purchase_requisition_items (pr_id, line_no, item_name_snapshot, quantity, unit, unit_price, line_total)
                VALUES (999999, 1, 'Orphan', 1, 'pcs', 1, 1)",
            );
            self::fail('Insert item tanpa PR seharusnya ditolak foreign key');
        } catch (PDOException $e) {
            self::assertSame(1452, $e->errorInfo[1]);
        }
    }

    public function test_foreign_key_blocks_deleting_referenced_supplier(): void
    {
        try {
            Database::connection()->exec("DELETE FROM suppliers WHERE code = 'SHP'");
            self::fail('Supplier yang dipakai PR seharusnya tidak dapat dihapus');
        } catch (PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1]);
        }
    }

    public function test_pr_number_is_unique_at_database_level(): void
    {
        $existing = (string) $this->scalar('SELECT pr_number FROM purchase_requisitions WHERE pr_number IS NOT NULL LIMIT 1');
        $draft = (int) $this->scalar("SELECT id FROM purchase_requisitions WHERE status = 'draft' LIMIT 1");

        try {
            Database::connection()->prepare('UPDATE purchase_requisitions SET pr_number = ? WHERE id = ?')->execute([$existing, $draft]);
            self::fail('Nomor PR duplikat seharusnya ditolak');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_check_constraint_keeps_totals_consistent(): void
    {
        try {
            Database::connection()->exec("UPDATE purchase_requisitions SET grand_total = grand_total + 1 WHERE status = 'draft'");
            self::fail('Total yang tidak konsisten seharusnya ditolak CHECK constraint');
        } catch (PDOException $e) {
            self::assertSame(3819, $e->errorInfo[1]);
        }
    }

    public function test_concurrent_submissions_never_share_a_pr_number(): void
    {
        // Proses PHP terpisah (koneksi MySQL berbeda) membuat nomor secara bersamaan.
        // Ini berjalan di luar transaksi test sehingga data dibersihkan manual.
        Database::rollBack();
        $scope = 'ZZ-' . date('Y');
        Database::connection()->prepare('DELETE FROM pr_number_sequences WHERE scope = ?')->execute([$scope]);

        $workers = 6;
        $perWorker = 15;
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $processes[] = proc_open(
                [PHP_BINARY, BASE_PATH . '/tests/scripts/generate_numbers.php', 'ZZ', (string) $perWorker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                BASE_PATH,
                ['APP_ENV' => 'testing'] + getenv(),
            );
        }

        $numbers = [];
        foreach ($processes as $i => $process) {
            $output = stream_get_contents($pipes[$i][1]);
            $errors = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            self::assertSame(0, proc_close($process), 'Worker gagal: ' . $errors);
            array_push($numbers, ...array_filter(explode("\n", (string) $output)));
        }

        Database::connection()->prepare('DELETE FROM pr_number_sequences WHERE scope = ?')->execute([$scope]);
        Database::begin();

        self::assertCount($workers * $perWorker, $numbers);
        self::assertCount($workers * $perWorker, array_unique($numbers), 'Tidak ada nomor PR ganda');
        $sequences = array_map(static fn (string $n): int => (int) substr($n, -3), $numbers);
        sort($sequences);
        self::assertSame(range(1, $workers * $perWorker), $sequences, 'Nomor berurutan tanpa celah');
    }
}
