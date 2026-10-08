<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/**
 * Matriks otorisasi server-side untuk 8 role (PRD §2.3, brief §Keamanan "Authorization harus dilakukan di server"):
 * untuk setiap role, setiap halaman/aksi istimewa yang izinnya TIDAK dimiliki role tersebut (dibaca dari
 * tabel role_permissions, bukan disalin ke test) wajib menjawab 403 dan tidak mengubah data apa pun,
 * walaupun request dikirim langsung dengan token CSRF yang sah. Ditambah pemeriksaan IDOR untuk izin
 * ber-scope 'own' (Sales lain, PIC proses lain).
 */
final class RoleMatrixHttpTest extends HttpTestCase
{
    /** role => email user test (seed-http.php) */
    private const USERS = [
        'admin' => 'admin@test.local',
        'admin_sales' => 'sales2@test.local',
        'npd_staff' => 'npd@test.local',
        'drafter' => 'drafter@test.local',
        'purchasing' => 'purchasing@test.local',
        'production' => 'production@test.local',
        'quality' => 'quality@test.local',
        'management' => 'mgmt@test.local',
    ];

    /** Tabel yang tidak boleh berubah saat request ditolak (audit_logs memang mencatat penolakan). */
    private const TABLES = [
        'projects', 'project_parts', 'processes', 'process_dependencies', 'process_runs', 'hold_history',
        'revision_history', 'schedule_baselines', 'schedule_changes', 'comments', 'calendar_events', 'npr',
        'npr_parts', 'holidays', 'customers', 'master_options', 'workflow_template_versions', 'workflow_steps',
        'application_settings', 'documents', 'notifications', 'next_actions',
    ];

    private static int $projectId = 0;
    private static int $partId = 0;
    private static int $activeId = 0;
    private static int $pendingId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$projectId = (int) self::seedWith(dirname(__DIR__) . '/Support/seed-http-project.php');
        $one = static function (string $sql, array $p): int {
            $st = self::pdo()->prepare($sql);
            $st->execute($p);
            return (int) $st->fetchColumn();
        };
        self::$partId = $one('SELECT id FROM project_parts WHERE project_id = ? ORDER BY id LIMIT 1', [self::$projectId]);
        self::$activeId = $one("SELECT id FROM processes WHERE project_id = ? AND status = 'current' ORDER BY id LIMIT 1", [self::$projectId]);
        self::$pendingId = $one("SELECT id FROM processes WHERE project_id = ? AND status = 'not_started' AND part_id IS NOT NULL ORDER BY id LIMIT 1", [self::$projectId]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginAttempts();
    }

    /** @return array<string,string> permission => scope untuk role */
    private function permissions(string $role): array
    {
        $st = self::pdo()->prepare('SELECT p.code, rp.scope FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
                                     JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ?');
        $st->execute([$role]);
        return array_column($st->fetchAll(\PDO::FETCH_ASSOC), 'scope', 'code');
    }

    /** Sidik jari isi data: checksum tabel + kolom penting users (last_login dll. boleh berubah). */
    private function fingerprint(): string
    {
        $parts = [];
        foreach (self::TABLES as $t) {
            $row = self::pdo()->query('CHECKSUM TABLE `' . $t . '`')->fetch(\PDO::FETCH_NUM);
            $parts[] = $t . '=' . $row[1];
        }
        $parts[] = (string) self::pdo()->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, role_id, email, name, is_active, password_hash) ORDER BY id)) FROM users")->fetchColumn();
        return implode(';', $parts);
    }

    /**
     * Probe: [izin, method, path, data POST]. Data dibuat valid supaya satu-satunya alasan penolakan
     * adalah otorisasi.
     * @return list<array{0:string,1:string,2:string,3:array<string,mixed>}>
     */
    private function probes(): array
    {
        $p = self::$projectId;
        $proj = '/project.php?id=' . $p;
        $proc = '/process.php?id=' . self::$activeId;
        $reason = 'Uji otorisasi matriks role';
        return [
            ['user.manage', 'GET', '/settings/users.php', []],
            ['user.manage', 'POST', '/settings/users.php', ['action' => 'create', 'name' => 'Penyusup', 'email' => 'x@test.local', 'role_id' => '1']],
            ['customer.manage', 'GET', '/settings/customers.php', []],
            ['customer.manage', 'POST', '/settings/customers.php', ['action' => 'create', 'name' => 'PT Penyusup']],
            ['settings.manage', 'GET', '/settings/masters.php', []],
            ['settings.manage', 'GET', '/settings/holidays.php', []],
            ['settings.manage', 'POST', '/settings/holidays.php', ['action' => 'save', 'holiday_date' => '2026-12-24', 'name' => 'Libur palsu']],
            ['settings.manage', 'GET', '/settings/workflow.php', []],
            ['settings.manage', 'POST', '/settings/workflow.php', ['action' => 'draft', 'template' => '1']],
            ['settings.manage', 'GET', '/settings/notifications.php', []],
            ['settings.manage', 'GET', '/settings/email-queue.php', []],
            ['audit.view_full', 'GET', '/settings/audit.php', []],
            ['project.import', 'GET', '/settings/import.php', []],
            ['project.import', 'GET', '/settings/import.php?action=template', []],
            ['project.import', 'POST', '/settings/import.php', ['action' => 'cancel']],
            ['kpi.view', 'GET', '/reports.php?tab=kpi', []],
            ['kpi.view', 'GET', '/export.php?type=kpi_xlsx', []],
            ['kpi.view', 'GET', '/export.php?type=kpi_pdf', []],
            ['hold.manage', 'POST', $proj, ['action' => 'hold', 'reason' => $reason, 'expected_resume_date' => '2026-11-30']],
            ['hold.manage', 'GET', '/resume.php?id=' . $p, []],
            ['project.cancel', 'POST', $proj, ['action' => 'cancel_project', 'reason' => $reason]],
            ['project.cancel', 'POST', $proj, ['action' => 'cancel_part', 'part_id' => self::$partId, 'reason' => $reason]],
            ['project.archive', 'POST', $proj, ['action' => 'archive', 'reason' => $reason]],
            ['target.change', 'POST', $proj, ['action' => 'change_target', 'target_finish' => '2027-06-30', 'reason' => $reason]],
            ['baseline.create', 'POST', $proj, ['action' => 'baseline', 'reason' => $reason]],
            ['schedule.plan', 'POST', $proj, ['action' => 'part_pics', 'part_id' => self::$partId, 'pic' => []]],
            ['schedule.plan', 'POST', '/process.php?id=' . self::$pendingId, ['action' => 'plan', 'plan' => ['duration_days' => '3'], 'reason' => $reason]],
            ['process.manual_move', 'POST', $proc, ['action' => 'manual_move', 'target' => 'completed', 'reason' => $reason]],
            ['process.skip', 'POST', '/process.php?id=' . self::$pendingId, ['action' => 'skip', 'reason' => $reason]],
            ['dependency.edit', 'POST', '/process.php?id=' . self::$pendingId, ['action' => 'deps', 'deps' => [], 'reason' => $reason]],
            ['comment.create', 'POST', $proc, ['action' => 'comment', 'body' => 'Komentar tanpa izin']],
            ['calendar.manage', 'POST', '/calendar.php', ['action' => 'create', 'title' => 'Agenda palsu', 'event_date' => '2026-10-20']],
            ['npr.create', 'POST', '/npr.php', ['action' => 'create']],
        ];
    }

    public function testEveryRoleIsDeniedWhatItHasNoPermissionFor(): void
    {
        $probes = $this->probes();
        $checked = 0;
        foreach (self::USERS as $role => $email) {
            $perms = $this->permissions($role);
            $c = $this->loginAs($email);
            $c->get('/dashboard.php');
            $token = $c->csrf();
            $this->assertNotSame('', $token, $role . ': token CSRF');
            foreach ($probes as [$perm, $method, $path, $data]) {
                if (isset($perms[$perm])) {
                    continue; // role punya izin → bukan bagian matriks penolakan (fungsi positifnya diuji di test lain)
                }
                $before = $this->fingerprint();
                $res = $method === 'GET' ? $c->get($path) : $c->post($path, $data + ['_csrf' => $token]);
                $this->assertSame(403, $res['status'], "{$role} tanpa {$perm}: {$method} {$path} harus 403, dapat {$res['status']}");
                $this->assertStringNotContainsString('SQLSTATE', $res['body']);
                if ($method === 'POST') {
                    $this->assertSame($before, $this->fingerprint(), "{$role}: {$method} {$path} ({$perm}) tidak boleh mengubah data");
                }
                $checked++;
            }
        }
        // admin punya semua izin → 0 probe; role lain wajib menghasilkan banyak penolakan
        $this->assertGreaterThanOrEqual(100, $checked, 'jumlah kombinasi role × aksi yang ditolak');
        $denied = (int) self::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'authz.denied'")->fetchColumn();
        $this->assertGreaterThan(0, $denied, 'penolakan tercatat di audit log');
    }

    public function testRolesWithPermissionReachThePages(): void
    {
        $pages = [
            'user.manage' => '/settings/users.php', 'customer.manage' => '/settings/customers.php',
            'settings.manage' => '/settings/holidays.php', 'audit.view_full' => '/settings/audit.php',
            'kpi.view' => '/reports.php?tab=kpi', 'report.view' => '/dashboard.php', 'project.view' => '/projects.php',
        ];
        foreach (self::USERS as $role => $email) {
            $perms = $this->permissions($role);
            $c = $this->loginAs($email);
            foreach ($pages as $perm => $path) {
                if (isset($perms[$perm])) {
                    $this->assertSame(200, $c->get($path)['status'], "{$role} dengan {$perm}: {$path}");
                }
            }
        }
    }

    public function testOwnScopeCannotBeBypassedById(): void
    {
        // Sales lain (bukan Sales PIC project) tidak boleh mengubah project / isian Sales NPR milik orang lain
        $c = $this->loginAs('sales2@test.local');
        $c->get('/project.php?id=' . self::$projectId);
        $token = $c->csrf();
        $before = $this->fingerprint();
        $res = $c->post('/project.php?id=' . self::$projectId, ['action' => 'update_project', 'priority' => 'high', '_csrf' => $token]);
        $this->assertSame(403, $res['status'], 'project.edit ber-scope own');
        $nprId = (int) self::pdo()->query('SELECT npr_id FROM projects WHERE id = ' . self::$projectId)->fetchColumn();
        $res = $c->post('/npr-edit.php?id=' . $nprId, ['action' => 'save', 'npr' => ['product_name' => 'Diubah penyusup'], '_csrf' => $token]);
        $this->assertContains($res['status'], [403, 409], 'NPR orang lain (sudah selesai) tidak dapat diubah');
        $this->assertSame($before, $this->fingerprint());

        // PIC proses lain: role ber-scope own pada process.execute tidak dapat menyelesaikan proses yang bukan miliknya
        $st = self::pdo()->prepare("SELECT pr.id, pr.lock_version, r.code FROM processes pr JOIN roles r ON r.id = pr.pic_role_id
                                    WHERE pr.project_id = ? AND pr.status = 'current' ORDER BY pr.id");
        $st->execute([self::$projectId]);
        $tested = 0;
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $proc) {
            foreach (['drafter', 'purchasing', 'production', 'quality'] as $role) {
                if ($proc['code'] === $role) {
                    continue; // proses role sendiri (bisa jadi miliknya) — tidak dipakai di sini
                }
                $u = $this->loginAs(self::USERS[$role]);
                $u->get('/process.php?id=' . $proc['id']);
                $res = $u->post('/process.php?id=' . $proc['id'], [
                    'action' => 'complete', 'actual_finish' => '2026-10-07', 'lock_version' => $proc['lock_version'], '_csrf' => $u->csrf(),
                ]);
                $this->assertSame(403, $res['status'], "{$role} menyelesaikan proses #{$proc['id']} milik {$proc['code']}");
                $tested++;
            }
        }
        $this->assertGreaterThan(0, $tested);
        $this->assertSame($before, $this->fingerprint(), 'tidak ada proses yang berubah');
    }

    public function testDocumentsAreServedOnlyThroughAuthorizedDownload(): void
    {
        $c = $this->loginAs('mgmt@test.local');
        // id dokumen tidak ada → 404 (bukan isi file), path tidak dapat dipaksa lewat parameter
        $this->assertSame(404, $c->get('/download.php?id=999999')['status']);
        $this->assertContains($c->get('/download.php?id=1&path=../../config/app.php')['status'], [400, 404]);
        $guest = $this->client();
        $res = $guest->get('/download.php?id=1');
        $this->assertContains($res['status'], [302, 303], 'tamu diarahkan ke login');
        $this->assertStringContainsString('login.php', $res['headers']['location'][0] ?? '');
    }
}
