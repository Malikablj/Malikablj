<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Db;
use App\Master\MasterService;
use Tests\Support\DbTestCase;

/**
 * NFR-05 (zona waktu Asia/Jakarta di PHP & MySQL), NFR-06 (konfigurasi lewat environment, tanpa rahasia
 * di kode), NPR-15 (master dinonaktifkan, bukan dihapus; data lama tetap terbaca).
 */
final class ConfigAndMastersTest extends DbTestCase
{
    public function testTimezoneIsAsiaJakartaInPhpAndMysql(): void
    {
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
        $this->assertSame('Asia/Jakarta', Config::get('app.timezone'));
        $this->assertSame('+07:00', Db::value('SELECT @@session.time_zone'));
        // NOW() MySQL sama dengan jam PHP (selisih wajar ≤ 5 detik)
        $diff = abs(strtotime((string) Db::value('SELECT NOW()')) - time());
        $this->assertLessThanOrEqual(5, $diff);
    }

    public function testConfigurationComesFromEnvironment(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame(getenv('DB_NAME') ?: 'npd_project_control', Config::get('db')['name'], 'DB_NAME dari environment');
        $example = (string) file_get_contents($root . '/.env.example');
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_KEY', 'APP_TIMEZONE', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
            'SESSION_SECURE', 'STORAGE_PATH', 'MAIL_HOST', 'MAIL_PASSWORD'] as $key) {
            $this->assertMatchesRegularExpression('/^' . $key . '=/m', $example, $key . ' terdokumentasi di .env.example');
        }
        // rahasia tidak diisi di contoh, dan file konfigurasi tidak memuat nilai rahasia langsung
        $this->assertMatchesRegularExpression('/^APP_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^DB_PASS=\s*$/m', $example);
        foreach (glob($root . '/config/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression("/'(pass|password|key)'\s*=>\s*'[^']+'/", $src, basename($file) . ' tanpa rahasia hardcode');
        }
        // .env tidak dapat dijangkau dari web root
        $this->assertFileDoesNotExist($root . '/public/.env');
        // bawaan aman bila variabel tidak diisi: production tanpa debug
        $cfg = (string) file_get_contents($root . '/config/config.php');
        $this->assertStringContainsString("Env::get('APP_ENV', 'production')", $cfg);
        $this->assertStringContainsString("Env::bool('APP_DEBUG', false)", $cfg);
    }

    public function testDeactivatedMasterOptionStaysReadableForOldData(): void
    {
        $admin = $this->makeUser('admin');
        $svc = new MasterService();
        $id = $svc->create($admin, 'resin', ['code' => 'pp_uji', 'label_id' => 'PP Uji', 'label_en' => 'Test PP']);
        $this->assertTrue(MasterService::isValid('resin', 'pp_uji'));

        $svc->setActive($admin, $id, false);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM master_options WHERE id = ?', [$id]), 'tidak dihapus');
        $this->assertFalse(MasterService::isValid('resin', 'pp_uji'), 'tidak dapat dipilih untuk data baru');
        $this->assertTrue(MasterService::isValid('resin', 'pp_uji', false), 'masih dikenali untuk data lama');
        $this->assertNotContains('pp_uji', array_column(MasterService::options('resin'), 'code'));
        $this->assertContains('pp_uji', array_column(MasterService::options('resin', true), 'code'));
        $this->assertSame('PP Uji', MasterService::label('resin', 'pp_uji', 'id'), 'label tetap tampil pada NPR lama');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'master.deactivate' AND entity_id = ?", [$id]));
    }
}
