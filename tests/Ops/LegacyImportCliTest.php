<?php
declare(strict_types=1);

namespace Tests\Ops;

use Tests\Support\CliTestCase;
use Tests\Support\LegacyImportFixtures;

/**
 * bin/import-legacy.php (Terminal cPanel) dan migrasi 20261008_001_legacy_import pada database yang sudah berjalan.
 */
final class LegacyImportCliTest extends CliTestCase
{
    use LegacyImportFixtures;

    protected function tearDown(): void
    {
        $this->cleanLegacyFiles();
        parent::tearDown();
    }

    public function testTemplateCheckAndCommitFromTerminal(): void
    {
        $db = $this->freshDb('npd_test_cli_import');
        $pdo = $this->db($db);
        $hash = password_hash('Passw0rd!', PASSWORD_BCRYPT, ['cost' => 4]);
        foreach ([['admin', 'adm@cli.test'], ['admin_sales', 'sales@cli.test'], ['npd_staff', 'npd@cli.test']] as [$role, $email]) {
            $st = $pdo->prepare('INSERT INTO users (role_id, name, email, password_hash) SELECT id, ?, ?, ? FROM roles WHERE code = ?');
            $st->execute([ucfirst($role), $email, $hash, $role]);
        }
        $pdo->exec("INSERT INTO customers (code, name) VALUES ('CLI1', 'PT Terminal')");
        $env = ['DB_NAME' => $db, 'STORAGE_PATH' => $this->tmpDir('npd_cli_imp')];

        $tpl = $this->scratch('xlsx');
        $r = $this->php('bin/import-legacy.php', ['--template=' . $tpl], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $file = $this->legacyFile(
            [['ref' => 'CLI-7', 'name' => 'Jar 100g', 'customer' => 'CLI1', 'sales' => 'sales@cli.test', 'npd' => 'npd@cli.test', 'npr_date' => '2026-01-12', 'status' => 'Berjalan']],
            [['ref' => 'CLI-7', 'part' => 'Jar', 'type' => 'Subcont']],
            [['ref' => 'CLI-7', 'part' => 'Jar', 'process' => 'S1', 'status' => 'Selesai', 'start' => '2026-01-13', 'finish' => '2026-01-20']],
            (string) file_get_contents($tpl)
        );

        $r = $this->php('bin/import-legacy.php', ['--file=' . $file], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('1 project (1 berjalan', $r['out']);
        $this->assertStringContainsString('Tidak ada kesalahan', $r['out']);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn(), 'tanpa --commit tidak menyimpan');

        $r = $this->php('bin/import-legacy.php', ['--file=' . $file, '--commit'], $env);
        $this->assertSame(2, $r['code'], 'pelaku impor wajib');
        $r = $this->php('bin/import-legacy.php', ['--file=' . $file, '--commit', '--as=sales@cli.test'], $env);
        $this->assertSame(1, $r['code'], 'bukan Admin');
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());

        $r = $this->php('bin/import-legacy.php', ['--file=' . $file, '--commit', '--as=adm@cli.test'], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertMatchesRegularExpression('/\[OK\] CLI-7 → NPD-2026-\d{3}/u', $r['out']);
        $this->assertSame('cli: bin/import-legacy.php', $pdo->query("SELECT user_agent FROM audit_logs WHERE action = 'import.legacy'")->fetchColumn());

        $r = $this->php('bin/import-legacy.php', ['--file=' . $file], $env);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('[GAGAL] Project baris 2 [Ref Project]: Ref Project "CLI-7" sudah pernah diimpor', $r['out']);
    }

    public function testMigrationAddsImportColumnsToRunningDatabaseIdempotently(): void
    {
        $db = $this->freshDb('npd_test_cli_migrate');
        $pdo = $this->db($db);
        $columns = static fn (): array => $pdo->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME, ' ', COLUMN_TYPE, ' ', IS_NULLABLE, ' ', COALESCE(COLUMN_DEFAULT, 'NULL'))
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'projects' AND COLUMN_NAME IN ('legacy_ref', 'imported_at')) OR (TABLE_NAME = 'process_runs' AND COLUMN_NAME = 'is_imported'))
            ORDER BY TABLE_NAME, COLUMN_NAME")->fetchAll(\PDO::FETCH_COLUMN);
        $fresh = $columns();
        $this->assertCount(3, $fresh);
        // simulasikan database produksi sebelum fitur impor (berisi data)
        $pdo->exec("INSERT INTO customers (code, name) VALUES ('OLD', 'PT Lama')");
        $pdo->exec('ALTER TABLE projects DROP INDEX uq_projects_legacy_ref, DROP COLUMN legacy_ref, DROP COLUMN imported_at');
        $pdo->exec('ALTER TABLE process_runs DROP COLUMN is_imported');
        $pdo->exec("DELETE FROM schema_migrations WHERE migration = '20261008_001_legacy_import.sql'");
        $env = ['DB_NAME' => $db];
        $r = $this->php('bin/migrate.php', [], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('OK    20261008_001_legacy_import.sql', $r['out']);
        $this->assertSame($fresh, $columns(), 'hasil migrasi = skema instalasi baru');
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects' AND INDEX_NAME = 'uq_projects_legacy_ref' AND NON_UNIQUE = 0")->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE code = 'OLD'")->fetchColumn());
        // idempoten: dijalankan lagi pada skema yang sudah berubah tidak gagal
        $pdo->exec("DELETE FROM schema_migrations WHERE migration = '20261008_001_legacy_import.sql'");
        $r = $this->php('bin/migrate.php', [], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertSame($fresh, $columns());
    }
}
