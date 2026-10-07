<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/**
 * Dashboard & Laporan lewat HTTP nyata (PRD §10, UAT-20): semua role membuka dashboard & laporan;
 * KPI per PIC hanya Admin & Management — role lain tidak melihat tab dan ditolak server (halaman & export).
 */
final class ReportsHttpTest extends HttpTestCase
{
    private static int $projectId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$projectId = (int) self::seedWith(dirname(__DIR__) . '/Support/seed-http-project.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    public function testAllRolesOpenDashboardAndReports(): void
    {
        foreach (['sales@test.local', 'drafter@test.local', 'mgmt@test.local', 'npd@test.local'] as $email) {
            $c = $this->loginAs($email);
            $dash = $c->get('/dashboard.php');
            $this->assertSame(200, $dash['status'], $email);
            $this->assertSame(8, substr_count($dash['body'], 'data-kpi="'), $email . ': 8 kartu KPI');
            $this->assertStringContainsString('id="overdue"', $dash['body']);
            $this->assertSame(6, substr_count($dash['body'], 'class="card chart-card"'), '6 grafik');
            $this->assertSame(6, substr_count($dash['body'], 'data-attention="'));
            foreach (['/reports.php', '/reports.php?tab=analytics&period=month&month=2026-10', '/reports.php?tab=weekly&period=range&from=2026-09-01&to=2026-10-31'] as $path) {
                $res = $c->get($path);
                $this->assertSame(200, $res['status'], $email . ' ' . $path);
                $this->assertStringNotContainsString('Warning', $res['body']);
            }
        }
        // filter dashboard tidak valid diabaikan (bukan error), filter valid diterapkan
        $c = $this->loginAs('npd@test.local');
        $this->assertSame(200, $c->get('/dashboard.php?part_type=x&customer_id=abc&priority=%27')['status']);
        $this->assertStringContainsString('selected', $c->get('/dashboard.php?part_type=subcont')['body']);
    }

    public function testKpiOnlyForAdminAndManagement(): void
    {
        foreach (['sales@test.local', 'drafter@test.local', 'npd@test.local', 'quality@test.local'] as $email) {
            $c = $this->loginAs($email);
            $page = $c->get('/reports.php');
            $this->assertStringNotContainsString('tab=kpi', $page['body'], $email . ': tab KPI disembunyikan');
            $this->assertSame(403, $c->get('/reports.php?tab=kpi')['status'], $email . ': halaman KPI ditolak server');
            $this->assertSame(403, $c->get('/export.php?type=kpi_xlsx&period=month&month=2026-10')['status'], $email . ': export KPI Excel ditolak');
            $this->assertSame(403, $c->get('/export.php?type=kpi_pdf&period=month&month=2026-10')['status'], $email . ': export KPI PDF ditolak');
        }
        foreach (['mgmt@test.local', 'admin@test.local'] as $email) {
            $c = $this->loginAs($email);
            $this->assertStringContainsString('tab=kpi', $c->get('/reports.php')['body']);
            $res = $c->get('/reports.php?tab=kpi&period=month&month=2026-10');
            $this->assertSame(200, $res['status'], $email);
            $this->assertStringContainsString('data-kpi-table', $res['body']);
            $pdf = $c->get('/export.php?type=kpi_pdf&period=month&month=2026-10');
            $this->assertSame(200, $pdf['status']);
            $this->assertStringStartsWith('application/pdf', $pdf['headers']['content-type'][0]);
            $this->assertStringStartsWith('%PDF', $pdf['body']);
            $xl = $c->get('/export.php?type=kpi_xlsx&period=month&month=2026-10&role=npd_staff');
            $this->assertSame(200, $xl['status']);
            $this->assertStringStartsWith('PK', $xl['body']);
        }
        // drill-down PIC
        $c = $this->loginAs('admin@test.local');
        $npdId = (int) self::pdo()->query("SELECT id FROM users WHERE email = 'npd@test.local'")->fetchColumn();
        $res = $c->get('/reports.php?tab=kpi&period=month&month=2026-09&pic=' . $npdId);
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('id="kpi-drill"', $res['body']);
    }

    public function testReportAndProjectExportsAreAudited(): void
    {
        $c = $this->loginAs('sales@test.local');
        foreach (['weekly_xlsx' => '&period=week&week=2026-W40', 'analytics_xlsx' => '&period=month&month=2026-09', 'projects_xlsx' => '&overdue=1'] as $type => $q) {
            $res = $c->get('/export.php?type=' . $type . $q);
            $this->assertSame(200, $res['status'], $type);
            $this->assertStringStartsWith('PK', $res['body'], $type . ' adalah file xlsx');
            $this->assertStringContainsString('.xlsx', $res['headers']['content-disposition'][0]);
        }
        $st = self::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('export.weekly_xlsx', 'export.analytics_xlsx', 'export.projects_xlsx')");
        $this->assertGreaterThanOrEqual(3, (int) $st->fetchColumn());
        $this->assertSame(404, $c->get('/export.php?type=unknown')['status']);
        // daftar project: tombol export & filter baru
        $page = $c->get('/projects.php?part_type=new_mold&priority=normal&due_soon=1');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('type=projects_xlsx', $page['body']);
    }
}
