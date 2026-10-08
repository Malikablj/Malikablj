<?php
declare(strict_types=1);

namespace Tests\Ops;

use Tests\Support\CliTestCase;

/** bin/check-deployment.php: konfigurasi bukan produksi, migrasi tertunda, dan tanpa Admin terdeteksi sebagai GAGAL. */
final class DeploymentCheckTest extends CliTestCase
{
    public function testFlagsUnsafeConfigurationPendingMigrationsAndMissingAdmin(): void
    {
        $db = $this->freshDb('npd_test_deploycheck');
        $storage = $this->tmpDir('npd_deploy_storage');
        foreach (['documents', 'exports', 'logs', 'cache', 'backups'] as $d) {
            mkdir($storage . '/' . $d);
        }
        // migrasi tertunda: database berjalan yang belum mencatat migrasi yang dikirim
        $migDir = $this->tmpDir('npd_deploy_mig');
        file_put_contents($migDir . '/99991231_999_uji_deploy_check.sql', "SELECT 1;\n");
        $r = $this->php('bin/check-deployment.php', ['--migrations=' . $migDir], [
            'DB_NAME' => $db, 'APP_ENV' => 'development', 'APP_DEBUG' => '1', 'APP_URL' => 'http://npd.local',
            'STORAGE_PATH' => $storage, 'BACKUP_PATH' => $storage . '/backups',
        ]);
        $this->assertSame(1, $r['code'], $r['out']);
        $this->assertStringContainsString('[GAGAL] APP_ENV=development', $r['out']);
        $this->assertStringContainsString('[GAGAL] APP_DEBUG aktif', $r['out']);
        $this->assertStringContainsString('[GAGAL] APP_URL harus https://', $r['out']);
        $this->assertStringContainsString('[GAGAL] migrasi belum dijalankan: 99991231_999_uji_deploy_check.sql', $r['out']);
        $this->assertStringContainsString('[GAGAL] belum ada Admin', $r['out']);
        $this->assertStringContainsString('[OK] storage/documents di luar web root', $r['out']);
        $this->assertStringContainsString('[PERINGATAN] backup: belum pernah', $r['out']);
        $this->assertMatchesRegularExpression('/Ringkasan: \d+ OK, \d+ peringatan, [1-9]\d* gagal/', $r['out']);
    }
}
