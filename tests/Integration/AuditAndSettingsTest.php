<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuditLogger;
use App\Core\Db;
use App\Core\RequestContext;
use App\Core\Settings;
use Tests\Support\DbTestCase;

/** FR-AUD-01: audit append-only berisi user, waktu, IP, aksi, entitas, lama/baru, alasan. */
final class AuditAndSettingsTest extends DbTestCase
{
    public function testAuditRecordsAllRequiredFields(): void
    {
        $u = $this->makeUser('npd_staff');
        RequestContext::set($u, '192.168.1.7', 'PHPUnit');
        $id = AuditLogger::log('schedule.change', 'process', 42, ['planned_finish' => '2026-10-05'], ['planned_finish' => '2026-10-07'], 'Mold maker terlambat', null);
        $row = Db::fetch('SELECT * FROM audit_logs WHERE id = ?', [$id]);
        $this->assertSame($u->id, (int) $row['user_id']);
        $this->assertSame($u->name, $row['user_name']);
        $this->assertSame('192.168.1.7', $row['ip_address']);
        $this->assertSame('schedule.change', $row['action']);
        $this->assertSame('process', $row['entity_type']);
        $this->assertSame('42', $row['entity_id']);
        $this->assertSame(['planned_finish' => '2026-10-05'], json_decode($row['old_value'], true));
        $this->assertSame(['planned_finish' => '2026-10-07'], json_decode($row['new_value'], true));
        $this->assertSame('Mold maker terlambat', $row['reason']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testSensitiveValuesRedacted(): void
    {
        $id = AuditLogger::log('x', 'user', 1, ['password' => 'rahasia'], ['nested' => ['password_hash' => 'h']]);
        $row = Db::fetch('SELECT old_value, new_value FROM audit_logs WHERE id = ?', [$id]);
        $this->assertStringNotContainsString('rahasia', $row['old_value']);
        $this->assertStringNotContainsString('"h"', $row['new_value']);
    }

    public function testAuditLoggerHasNoMutationApi(): void
    {
        $methods = array_map(static fn ($m) => $m->getName(), (new \ReflectionClass(AuditLogger::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        $this->assertSame(['diff', 'log'], $methods, 'AuditLogger hanya boleh menulis (append)');
    }

    public function testNoApplicationCodeUpdatesOrDeletesAuditLogs(): void
    {
        $root = dirname(__DIR__, 2);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/legacy/')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/(UPDATE|DELETE\s+FROM)\s+`?audit_logs/i', $src, $path);
        }
    }

    public function testDiffOnlyChangedFields(): void
    {
        [$o, $n] = AuditLogger::diff(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3]);
        $this->assertSame(['b' => 2], $o);
        $this->assertSame(['b' => 3], $n);
    }

    public function testSecretSettingEncryptedAtRest(): void
    {
        $admin = $this->makeUser('admin');
        Settings::set('mail.smtp_password', 'smtp-secret', $admin->id);
        $raw = (string) Db::value("SELECT setting_value FROM application_settings WHERE setting_key = 'mail.smtp_password'");
        $this->assertStringStartsWith('v1:', $raw);
        $this->assertStringNotContainsString('smtp-secret', $raw);
        Settings::flush();
        $this->assertSame('smtp-secret', Settings::get('mail.smtp_password'));
        $audit = (string) Db::value("SELECT new_value FROM audit_logs WHERE action = 'settings.update' AND entity_id = 'mail.smtp_password'");
        $this->assertStringNotContainsString('smtp-secret', $audit);
    }

    public function testSettingDefaults(): void
    {
        $this->assertSame(480, Settings::int('security.session_timeout_minutes'));
        $this->assertSame(3, Settings::int('notify.due_soon_days'));
        $this->assertSame(25, Settings::int('upload.max_mb'));
        $this->assertTrue(Settings::bool('schedule.pull_forward_on_early_finish'));
    }
}
