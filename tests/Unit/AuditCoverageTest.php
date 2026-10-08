<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * FR-AUD-02 (PRD §9.4): setiap kategori yang wajib diaudit memiliki pencatatan AuditLogger di kode aplikasi.
 * Uji dinamis per kategori ada di test modul masing-masing (mis. DocumentApprovalRecordTest untuk approval,
 * HoldLifecycleTest untuk Hold, ScheduleServiceTest untuk shift otomatis, AuthTest untuk login).
 */
final class AuditCoverageTest extends TestCase
{
    /** kategori PRD → pola nama aksi audit yang harus ada di kode */
    private const REQUIRED = [
        'NPR' => ['npr.create', 'npr.save_draft', 'npr.submit', 'npr.return'],
        'feedback NPD' => ['npr.feedback_save', 'npr.feedback_complete'],
        'perubahan jadwal' => ['process.plan', 'schedule.baseline'],
        'dependency' => ['dependency.update'],
        'shift otomatis' => ["'schedule.' . \$changeType"],
        'skip' => ['process.skip', 'process.unskip'],
        'hold' => ["'project.hold' : 'part.hold'", "'project.resume' : 'part.resume'"],
        'target' => ['project.target_change'],
        'approval' => ['approval.decide'],
        'dokumen' => ['document.upload', 'document.remove', "'document.preview' : 'document.download'"],
        'pengaturan' => ['settings.update', 'holiday.create', 'master.create', 'workflow.publish', 'user.create'],
        'arsip' => ['project.archive', 'project.restore'],
        'export' => ["'export.' . \$type", 'export.projects_xlsx'],
        'login' => ['auth.login', 'auth.login_failed', 'auth.logout'],
    ];

    public function testEveryRequiredCategoryIsAudited(): void
    {
        $root = dirname(__DIR__, 2);
        $code = '';
        foreach (['modules', 'public', 'includes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php') {
                    $code .= file_get_contents($f->getPathname()) . "\n";
                }
            }
        }
        // hanya teks di dalam panggilan AuditLogger::log( … ) yang dihitung
        preg_match_all('/AuditLogger::log\(\s*(.{0,160})/s', $code, $m);
        $calls = implode("\n", $m[1]);
        foreach (self::REQUIRED as $category => $actions) {
            foreach ($actions as $a) {
                $needle = str_contains($a, "'") ? $a : "'{$a}'";
                $this->assertStringContainsString($needle, $calls, "kategori audit '{$category}': aksi {$a} tidak dicatat");
            }
        }
    }
}
