<?php
declare(strict_types=1);

namespace Tests\Ops;

use Tests\Support\CliTestCase;

/** NFR-11: migrasi skema berurutan tanpa kehilangan data; gagal → tidak dicatat & berhenti; instalasi baru menandai migrasi. */
final class MigrationTest extends CliTestCase
{
    public function testMigrationsApplyInOrderKeepDataAndStopOnFailure(): void
    {
        $db = $this->freshDb('npd_test_migrate');
        $pdo = $this->db($db);
        $pdo->exec("INSERT INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('MIG1', 'PT Data Lama', 'Jl. A', 'Jl. B', '021')");
        $dir = $this->tmpDir('npd_mig');
        file_put_contents($dir . '/20261008_001_customer_npwp.sql', "-- kolom baru\nALTER TABLE customers ADD COLUMN npwp VARCHAR(30) NULL AFTER phone;\n");
        file_put_contents($dir . '/20261008_002_backfill.sql', "UPDATE customers SET npwp = '-' WHERE npwp IS NULL;\nCREATE INDEX idx_customers_npwp ON customers (npwp);\n");
        $env = ['DB_NAME' => $db];

        $r = $this->php('bin/migrate.php', ['--status', '--dir=' . $dir], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('[belum] 20261008_001_customer_npwp.sql', $r['out']);

        $r = $this->php('bin/migrate.php', ['--dir=' . $dir], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertMatchesRegularExpression('/OK\s+20261008_001_customer_npwp\.sql.*\n.*OK\s+20261008_002_backfill\.sql/s', $r['out'], 'urut sesuai nama file');
        $this->assertSame('-', $pdo->query("SELECT npwp FROM customers WHERE code = 'MIG1'")->fetchColumn(), 'data lama tetap ada & terisi');
        $this->assertSame(['20261008_001_customer_npwp.sql', '20261008_002_backfill.sql'],
            $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(\PDO::FETCH_COLUMN));

        // dijalankan ulang → tidak ada perubahan
        $r = $this->php('bin/migrate.php', ['--dir=' . $dir], $env);
        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('Tidak ada migrasi baru', $r['out']);

        // migrasi rusak: berhenti, tidak dicatat, migrasi sesudahnya tidak dijalankan, data utuh
        file_put_contents($dir . '/20261009_001_rusak.sql', "ALTER TABLE customers ADD COLUMN catatan TEXT NULL;\nALTER TABLE tabel_tidak_ada ADD COLUMN x INT;\n");
        file_put_contents($dir . '/20261009_002_sesudah.sql', "ALTER TABLE customers ADD COLUMN sesudah INT NULL;\n");
        $r = $this->php('bin/migrate.php', ['--dir=' . $dir], $env);
        $this->assertSame(1, $r['code'], $r['out']);
        $this->assertStringContainsString('20261009_001_rusak.sql, pernyataan #2', $r['out']);
        $migrated = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains('20261009_001_rusak.sql', $migrated);
        $this->assertNotContains('20261009_002_sesudah.sql', $migrated);
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotContains('sesudah', $cols);
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE code = 'MIG1'")->fetchColumn());
        $r = $this->php('bin/migrate.php', ['--status', '--dir=' . $dir], $env);
        $this->assertStringContainsString('[belum] 20261009_001_rusak.sql', $r['out']);
        $this->assertStringContainsString('[sudah] 20261008_002_backfill.sql', $r['out']);
    }

    public function testFreshInstallMarksShippedMigrationsAndExistingDbDoesNot(): void
    {
        $shipped = array_map('basename', glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: []);
        $db = $this->freshDb('npd_test_migrate');
        $marked = $this->db($db)->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(\PDO::FETCH_COLUMN);
        sort($shipped);
        $this->assertSame($shipped, $marked, 'instalasi baru = skema terbaru');
        // instalasi ulang di atas database berisi data tidak menandai apa pun dan tidak menghapus data
        $pdo = $this->db($db);
        $pdo->exec('DELETE FROM schema_migrations');
        $pdo->exec("INSERT INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('KEEP', 'PT Tetap', 'A', 'B', '1')");
        $r = $this->php('bin/install.php', ['--database=' . $db]);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE code = 'KEEP'")->fetchColumn());
    }

    public function testMigrateRefusesWebAccess(): void
    {
        foreach (['bin/migrate.php', 'bin/backup.php', 'bin/restore.php'] as $f) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $f);
            $this->assertStringContainsString("PHP_SAPI !== 'cli'", $src, $f . ' hanya CLI');
            $this->assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/' . basename($f));
        }
    }
}
