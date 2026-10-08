<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/**
 * Sapuan keamanan menyeluruh lewat HTTP nyata (brief §Keamanan, PRD §13.2):
 *  - CSRF wajib pada SEMUA endpoint yang menerima POST (daftar dicari otomatis dari kode),
 *  - XSS tersimpan di-escape pada semua halaman utama (data berbahaya disuntikkan langsung ke DB),
 *  - probe SQL injection, ID/parameter tidak valid, traversal unduhan → tidak pernah 500 / bocor SQL,
 *  - mass assignment diabaikan, header keamanan pada HTML/JSON/unduhan/error, storage di luar web root.
 */
final class SecuritySweepHttpTest extends HttpTestCase
{
    private const XSS = 'X<script>alert(1)</script><img src=x onerror=alert(2)>';
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

    private function scalar(string $sql, array $p = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    }

    private function exec(string $sql, array $p = []): void
    {
        self::pdo()->prepare($sql)->execute($p);
    }

    /** @return list<string> URL path endpoint yang menangani POST */
    private static function postEndpoints(): array
    {
        $root = dirname(__DIR__, 2) . '/public';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (preg_match('/isPost\(\)|require_post\(\)|isMutating\(\)/', $src)) {
                $out[] = substr($f->getPathname(), strlen($root));
            }
        }
        sort($out);
        return $out;
    }

    public function testEveryPostEndpointRequiresCsrfToken(): void
    {
        $endpoints = self::postEndpoints();
        $this->assertGreaterThanOrEqual(20, count($endpoints), 'endpoint POST terdeteksi');
        $c = $this->loginAs('admin@test.local');
        foreach ($endpoints as $path) {
            $q = str_contains($path, 'project.php') || str_contains($path, 'process.php') || str_contains($path, 'resume.php') ? '?id=' . self::$projectId . '&project=' . self::$projectId : '';
            $res = $c->post($path . $q, ['action' => 'save', 'name' => 'x', 'id' => '1']);
            $this->assertSame(419, $res['status'], $path . ' harus menolak POST tanpa token CSRF');
            $bad = $c->post($path . $q, ['_csrf' => str_repeat('a', 64), 'action' => 'save']);
            $this->assertSame(419, $bad['status'], $path . ' harus menolak token CSRF palsu');
        }
        // API JSON memakai header X-CSRF-Token
        $res = $c->request('POST', '/api/schedule-preview.php', '{}', ['Content-Type: application/json', 'Accept: application/json']);
        $this->assertSame(419, $res['status']);
        $this->assertStringStartsWith('application/json', $res['headers']['content-type'][0] ?? '');
        $this->assertSame('admin@test.local', $this->scalar("SELECT email FROM users WHERE email = 'admin@test.local'"), 'tidak ada perubahan');
    }

    public function testOversizedSubmissionExplainsLimitInsteadOfSessionExpired(): void
    {
        $c = $this->loginAs('admin@test.local');
        $c->get('/dashboard.php');
        $body = str_repeat('a', 8 * 1024 * 1024 + 1024); // > post_max_size server test (8M)
        $res = $c->request('POST', '/project.php?id=' . self::$projectId, $body, ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(413, $res['status']);
        $this->assertStringContainsString('Kiriman terlalu besar', $res['body']);
        $this->assertStringContainsString('8 MB', $res['body'], 'batas efektif = min(post_max_size, upload.max_mb)');
        $json = $c->request('POST', '/api/schedule-preview.php', $body, ['Content-Type: application/json', 'Accept: application/json']);
        $this->assertSame(413, $json['status']);
        $this->assertStringStartsWith('application/json', $json['headers']['content-type'][0] ?? '');
        // kiriman normal tanpa token tetap 419 (CSRF tidak dilemahkan)
        $this->assertSame(419, $c->post('/project.php?id=' . self::$projectId, ['action' => 'hold'])['status']);
        // input file membawa batas untuk pemeriksaan di browser
        $page = $c->get('/process.php?id=' . (int) $this->scalar("SELECT id FROM processes WHERE project_id = ? AND status = 'current' LIMIT 1", [self::$projectId]))['body'];
        $this->assertMatchesRegularExpression('/type="file"[^>]*data-max-bytes="\d+" data-max-message="[^"]*:name/', $page);
    }

    public function testStoredXssIsEscapedOnAllPages(): void
    {
        $pid = self::$projectId;
        $partId = (int) $this->scalar('SELECT id FROM project_parts WHERE project_id = ? ORDER BY id LIMIT 1', [$pid]);
        $procId = (int) $this->scalar("SELECT id FROM processes WHERE project_id = ? AND status = 'current' ORDER BY id LIMIT 1", [$pid]);
        $admin = (int) $this->scalar("SELECT id FROM users WHERE email = 'admin@test.local'");
        $customer = (int) $this->scalar('SELECT customer_id FROM projects WHERE id = ?', [$pid]);
        $this->exec('UPDATE projects SET name = ? WHERE id = ?', [self::XSS, $pid]);
        $this->exec('UPDATE customers SET name = ? WHERE id = ?', [self::XSS, $customer]);
        $this->exec('UPDATE project_parts SET name = ? WHERE id = ?', [self::XSS, $partId]);
        $this->exec('UPDATE processes SET name = ?, name_en = ? WHERE id = ?', [self::XSS, self::XSS, $procId]);
        $this->exec("UPDATE users SET job_title = ? WHERE email = 'sales@test.local'", [self::XSS]);
        $this->exec('INSERT INTO comments (project_id, process_id, user_id, body, created_at) VALUES (?, ?, ?, ?, NOW())', [$pid, $procId, $admin, self::XSS]);
        $this->exec("INSERT INTO next_actions (project_id, part_id, description, due_date, owner_user_id, waiting_for, waiting_for_note, status) VALUES (?, ?, ?, CURDATE(), ?, 'customer', ?, 'open')",
            [$pid, $partId, self::XSS, $admin, self::XSS]);
        $this->exec('INSERT INTO holidays (holiday_date, name) VALUES (?, ?)', [date('Y-m-d', strtotime('+3 days')), self::XSS]);
        $this->exec('INSERT INTO notifications (user_id, type, title, body, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$admin, 'comment', self::XSS, self::XSS, 'project.php?id=' . $pid]);
        $this->exec("INSERT INTO revision_history (project_id, revision_type, summary, details_json, user_id, created_at) VALUES (?, 'loop', ?, ?, ?, NOW())",
            [$pid, self::XSS, json_encode(['reason' => self::XSS, 'comment' => self::XSS]), $admin]);
        $this->exec("INSERT INTO audit_logs (user_id, user_name, action, entity_type, entity_id, reason, new_value, project_id, created_at) VALUES (?, ?, 'project.hold', 'project', ?, ?, ?, ?, NOW())",
            [$admin, self::XSS, (string) $pid, self::XSS, json_encode(['x' => self::XSS]), $pid]);

        $pages = ['/dashboard.php', '/projects.php', '/project.php?id=' . $pid];
        foreach (['processes', 'approvals', 'documents', 'records', 'history', 'activity'] as $tab) {
            $pages[] = '/project.php?id=' . $pid . '&tab=' . $tab;
        }
        array_push($pages, '/process.php?id=' . $procId, '/timeline.php?project=' . $pid, '/timeline.php?project=' . $pid . '&part=' . $partId, '/gantt.php', '/tracker.php',
            '/calendar.php', '/documents.php', '/approvals.php', '/npr.php', '/notifications.php', '/reports.php', '/reports.php?tab=analytics', '/reports.php?tab=kpi',
            '/settings/holidays.php', '/settings/users.php', '/settings/customers.php', '/settings/audit.php');
        $c = $this->loginAs('admin@test.local');
        $escapedSeen = 0;
        foreach ($pages as $path) {
            $res = $c->get($path);
            $this->assertSame(200, $res['status'], $path);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $res['body'], $path . ': skrip tersuntik tidak boleh tampil mentah');
            $this->assertStringNotContainsString('<img src=x onerror', $res['body'], $path . ': atribut event tersuntik tidak boleh tampil mentah');
            $escapedSeen += substr_count($res['body'], '&lt;script&gt;alert(1)&lt;/script&gt;');
        }
        $this->assertGreaterThan(10, $escapedSeen, 'data berbahaya tampil sebagai teks yang di-escape');
        // JSON (pratinjau jadwal) juga tidak membawa HTML mentah yang dapat dieksekusi
        $c->get('/process.php?id=' . $procId);
        $json = $c->request('POST', '/api/schedule-preview.php', json_encode(['process_id' => $procId, 'kind' => 'plan', 'plan' => ['duration' => 3]]),
            ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $c->csrf()]);
        $this->assertStringNotContainsString('<script>', $json['body']);
    }

    public function testInjectionProbesAndInvalidInputNeverCrash(): void
    {
        $c = $this->loginAs('admin@test.local');
        $sqli = rawurlencode("' OR 1=1 -- ");
        $probes = [
            '/projects.php?q=' . $sqli . '&status=' . $sqli . '&customer_id=' . $sqli . '&npd_pic_id=1%20OR%201=1&part_type=' . $sqli . '&priority=' . $sqli . '&page=-5',
            '/documents.php?q=' . $sqli . '&type=' . $sqli . '&project_id=' . $sqli,
            '/approvals.php?type=' . $sqli . '&status=' . $sqli . '&view=' . $sqli,
            '/npr.php?q=' . $sqli . '&status=' . $sqli,
            '/gantt.php?q=' . $sqli . '&zoom=' . $sqli . '&page=999999',
            '/tracker.php?q=' . $sqli . '&role=' . $sqli,
            '/calendar.php?month=' . $sqli . '&category=' . $sqli,
            '/reports.php?period=range&from=' . $sqli . '&to=2026-13-45&customer_id=' . $sqli,
            '/reports.php?tab=kpi&period=week&week=' . $sqli . '&role=' . $sqli . '&process=' . $sqli . '&pic=' . $sqli,
            '/dashboard.php?part_type=' . $sqli . '&customer_id=' . $sqli . '&priority=' . $sqli,
            '/settings/audit.php?q=' . $sqli . '&action=' . $sqli . '&user_id=' . $sqli,
            '/settings/holidays.php?year=' . $sqli,
            '/notifications.php?type=' . $sqli . '&view=' . $sqli,
        ];
        foreach ($probes as $path) {
            $res = $c->get($path);
            $this->assertSame(200, $res['status'], $path);
            $this->assertStringNotContainsString('SQLSTATE', $res['body'], $path);
        }
        foreach (['/project.php?id=abc', '/project.php?id=999999', '/process.php?id=1%20OR%201=1', '/timeline.php?project=999999', '/npr-edit.php?id=999999',
                  '/download.php?v=..%2F..%2F.env', '/download.php?v=999999', '/export.php?type=timeline_pdf&project=999999', '/export.php?type=npr_pdf&id=0',
                  '/settings/workflow.php?template=999999', '/resume.php?project=999999'] as $path) {
            $res = $c->get($path);
            $this->assertContains($res['status'], [303, 400, 403, 404], $path . ' → ' . $res['status']);
            $this->assertStringNotContainsString('SQLSTATE', $res['body'], $path);
            $this->assertStringNotContainsString('Stack trace', $res['body'], $path);
            $this->assertStringNotContainsString('DB_PASS', $res['body'], $path);
        }
        // berkas di luar web root tidak terjangkau
        foreach (['/../.env', '/../storage/logs/app.log', '/../composer.json', '/../config/config.php'] as $path) {
            $res = $c->get($path);
            $this->assertFalse($res['status'] === 200 && (str_contains($res['body'], 'DB_') || str_contains($res['body'], '<?php')), $path . ' tidak boleh terbaca lewat web');
        }
        $this->assertFalse(str_starts_with(realpath(dirname(__DIR__, 2) . '/storage') ?: '', realpath(dirname(__DIR__, 2) . '/public') ?: '/nonexistent'), 'storage di luar web root');
    }

    public function testMassAssignmentIsIgnored(): void
    {
        $pid = self::$projectId;
        $before = self::pdo()->query('SELECT status, is_archived, is_on_hold, target_finish, finished_at FROM projects WHERE id = ' . $pid)->fetch(\PDO::FETCH_ASSOC);
        $c = $this->loginAs('npd@test.local');
        $c->get('/project.php?id=' . $pid);
        $res = $c->post('/project.php?id=' . $pid, ['_csrf' => $c->csrf(), 'action' => 'update_project', 'priority' => 'high',
            'status' => 'completed', 'is_archived' => '1', 'is_on_hold' => '1', 'target_finish' => '2020-01-01', 'finished_at' => '2020-01-01 00:00:00', 'code' => 'HACK']);
        $this->assertSame(303, $res['status']);
        $after = self::pdo()->query('SELECT status, is_archived, is_on_hold, target_finish, finished_at FROM projects WHERE id = ' . $pid)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($before, $after, 'hanya kolom yang diizinkan (prioritas) yang berubah');
        $this->assertSame('high', $this->scalar('SELECT priority FROM projects WHERE id = ?', [$pid]));
        $this->assertNotSame('HACK', $this->scalar('SELECT code FROM projects WHERE id = ?', [$pid]));
        // role tidak dapat dinaikkan lewat profil
        $c->get('/profile.php');
        $c->post('/profile.php', ['_csrf' => $c->csrf(), 'action' => 'preferences', 'language' => 'id', 'role_id' => '1', 'is_active' => '0', 'email' => 'x@evil.test']);
        $this->assertSame('npd_staff', $this->scalar("SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = 'npd@test.local'"));
        $this->assertSame(1, (int) $this->scalar("SELECT is_active FROM users WHERE email = 'npd@test.local'"));
    }

    public function testSecurityHeadersOnEveryResponseType(): void
    {
        $c = $this->loginAs('admin@test.local');
        $responses = [
            'html' => $c->get('/dashboard.php'),
            'error404' => $c->get('/project.php?id=999999'),
            'json' => $c->request('POST', '/api/preferences.php', '{}', ['Accept: application/json']),
            'download' => $c->get('/export.php?type=projects_xlsx'),
        ];
        foreach ($responses as $kind => $res) {
            $h = $res['headers'];
            $this->assertSame('nosniff', $h['x-content-type-options'][0] ?? null, $kind);
            $this->assertArrayNotHasKey('x-powered-by', $h, $kind);
            if ($kind !== 'download') {
                $this->assertSame('DENY', $h['x-frame-options'][0] ?? null, $kind);
                $csp = $h['content-security-policy'][0] ?? '';
                $this->assertStringContainsString("frame-ancestors 'none'", $csp, $kind);
                $this->assertStringContainsString("object-src 'none'", $csp, $kind);
                $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp, $kind . ': tanpa unsafe-inline untuk skrip');
            } else {
                $this->assertStringContainsString('attachment', $h['content-disposition'][0] ?? '', 'unduhan dipaksa sebagai lampiran');
                $this->assertStringContainsString('no-store', $h['cache-control'][0] ?? '');
            }
        }
        // halaman ber-nonce: tidak ada skrip inline tanpa nonce
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $responses['html']['body'], $m);
        foreach ($m[1] as $attrs) {
            $this->assertMatchesRegularExpression('/nonce="[^"]+"/', $attrs, 'skrip inline wajib bernonce');
        }
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $responses['html']['body'], 'tidak ada handler event inline');
    }
}
