<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\Gate;
use Tests\Support\DbTestCase;

/** ROLE-01..05: matriks hak akses PRD §2.3 ditegakkan di server. */
final class GateTest extends DbTestCase
{
    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function matrix(): array
    {
        return [
            // Melihat & export: semua role
            'mgmt view' => ['management', 'project.view', true],
            'drafter view' => ['drafter', 'project.view', true],
            'mgmt export timeline' => ['management', 'export.timeline', true],
            // NPR
            'sales create npr' => ['admin_sales', 'npr.create', true],
            'npd create npr' => ['npd_staff', 'npr.create', true],
            'drafter create npr' => ['drafter', 'npr.create', false],
            'mgmt create npr' => ['management', 'npr.create', false],
            'sales pink' => ['admin_sales', 'npr.edit_npd_fields', false],
            'npd pink' => ['npd_staff', 'npr.edit_npd_fields', true],
            'npd return' => ['npd_staff', 'npr.return', true],
            'sales return' => ['admin_sales', 'npr.return', false],
            // Perencanaan
            'npd plan' => ['npd_staff', 'schedule.plan', true],
            'sales plan' => ['admin_sales', 'schedule.plan', false],
            'npd dependency' => ['npd_staff', 'dependency.edit', true],
            'quality dependency' => ['quality', 'dependency.edit', false],
            'npd hold' => ['npd_staff', 'hold.manage', true],
            'purchasing hold' => ['purchasing', 'hold.manage', false],
            'npd finish' => ['npd_staff', 'project.finish', true],
            // Admin-only
            'admin manual move' => ['admin', 'process.manual_move', true],
            'npd manual move' => ['npd_staff', 'process.manual_move', false],
            'npd archive' => ['npd_staff', 'project.archive', false],
            'admin settings' => ['admin', 'settings.manage', true],
            'npd settings' => ['npd_staff', 'settings.manage', false],
            'npd users' => ['npd_staff', 'user.manage', false],
            // KPI: Admin & Management saja
            'admin kpi' => ['admin', 'kpi.view', true],
            'mgmt kpi' => ['management', 'kpi.view', true],
            'sales kpi' => ['admin_sales', 'kpi.view', false],
            'npd kpi' => ['npd_staff', 'kpi.view', false],
            // Management read-only
            'mgmt plan' => ['management', 'schedule.plan', false],
            'mgmt upload' => ['management', 'document.upload', false],
            'mgmt comment' => ['management', 'comment.create', false],
            'mgmt approval' => ['management', 'approval.record_customer', false],
            // Record
            'purchasing material' => ['purchasing', 'record.material', true],
            'purchasing trial' => ['purchasing', 'record.trial', false],
            'quality trial' => ['quality', 'record.trial', true],
            'production validation' => ['production', 'record.validation', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('matrix')]
    public function testMatrix(string $role, string $ability, bool $expected): void
    {
        $user = $this->makeUser($role);
        // untuk scope 'own', user diberi kepemilikan agar yang diuji murni izin role
        $this->assertSame($expected, Gate::can($user, $ability, ['owner_ids' => [$user->id]]), "{$role} → {$ability}");
    }

    public function testManagementHasNoMutatingPermission(): void
    {
        $readOnly = ['project.view', 'document.view', 'report.view', 'export.npr_pdf', 'export.timeline', 'export.report', 'kpi.view'];
        $perms = array_keys(Gate::permissionsFor('management'));
        sort($perms);
        sort($readOnly);
        $this->assertSame($readOnly, $perms);
    }

    public function testOwnScopeRequiresOwnership(): void
    {
        $sales = $this->makeUser('admin_sales');
        $other = $this->makeUser('admin_sales');
        $this->assertTrue(Gate::can($sales, 'npr.edit_sales_fields', ['owner_ids' => [$sales->id]]));
        $this->assertFalse(Gate::can($sales, 'npr.edit_sales_fields', ['owner_ids' => [$other->id]]));
        $this->assertFalse(Gate::can($sales, 'npr.edit_sales_fields'), 'tanpa context kepemilikan = ditolak');
        $this->assertTrue(Gate::can($sales, 'npr.edit_sales_fields', ['any' => true]), 'mode tampilan menu');
    }

    public function testDrafterOnlyAssignedProcess(): void
    {
        $drafter = $this->makeUser('drafter');
        $this->assertTrue(Gate::can($drafter, 'process.execute', ['owner_ids' => [$drafter->id]]));
        $this->assertFalse(Gate::can($drafter, 'process.execute', ['owner_ids' => [null, 99999]]));
    }

    public function testInactiveOrGuestDenied(): void
    {
        $this->assertFalse(Gate::can(null, 'project.view'));
        $u = $this->makeUser('admin', ['is_active' => 0]);
        $this->assertFalse(Gate::can($u, 'project.view'));
    }

    public function testAuthorizeThrows403AndAudits(): void
    {
        $mgmt = $this->makeUser('management');
        try {
            Gate::authorize($mgmt, 'schedule.plan');
            $this->fail('harus ditolak');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->httpStatus());
        }
        $this->assertSame(1, (int) \App\Core\Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'authz.denied' AND user_id = ?", [$mgmt->id]));
    }
}
