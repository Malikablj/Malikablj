<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Project & proses lewat HTTP nyata: otorisasi server (403 walau tombol disembunyikan), CSRF (419),
 * pratinjau jadwal JSON, lingkaran ditolak (422), escaping keluaran.
 */
final class ProjectHttpTest extends HttpTestCase
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

    private function pid(string $code, bool $part = true): int
    {
        $sql = $part
            ? 'SELECT p.id FROM processes p JOIN project_parts pp ON pp.id = p.part_id WHERE p.project_id = ? AND p.code = ? ORDER BY pp.sort_order LIMIT 1'
            : 'SELECT id FROM processes WHERE project_id = ? AND part_id IS NULL AND code = ?';
        $st = self::pdo()->prepare($sql);
        $st->execute([self::$projectId, $code]);
        return (int) $st->fetchColumn();
    }

    private function processStatus(int $processId): string
    {
        $st = self::pdo()->prepare('SELECT status FROM processes WHERE id = ?');
        $st->execute([$processId]);
        return (string) $st->fetchColumn();
    }

    public function testAllRolesCanViewProjectPages(): void
    {
        foreach (['mgmt@test.local', 'drafter@test.local', 'sales2@test.local'] as $email) {
            $c = $this->loginAs($email);
            foreach (['/projects.php', '/project.php?id=' . self::$projectId, '/project.php?id=' . self::$projectId . '&tab=processes',
                      '/project.php?id=' . self::$projectId . '&tab=history', '/process.php?id=' . $this->pid('N3')] as $path) {
                $res = $c->get($path);
                $this->assertSame(200, $res['status'], $email . ' ' . $path);
                $this->assertStringNotContainsString('Warning', $res['body']);
            }
        }
        $this->assertSame(404, $this->loginAs('npd@test.local')->get('/project.php?id=999999')['status']);
    }

    public function testManagementIsReadOnlyEvenWithForgedRequests(): void
    {
        $c = $this->loginAs('mgmt@test.local');
        $page = $c->get('/process.php?id=' . $this->pid('N3'));
        $this->assertStringNotContainsString('data-complete-form', $page['body'], 'tombol disembunyikan');
        $this->assertStringNotContainsString('data-preview-form="plan"', $page['body']);
        $n3 = $this->pid('N3');
        foreach ([['action' => 'complete'], ['action' => 'skip', 'reason' => 'x'], ['action' => 'plan', 'plan' => ['duration' => '3']],
                  ['action' => 'deps', 'deps' => []], ['action' => 'manual_move', 'target' => 'completed', 'reason' => 'x']] as $form) {
            $res = $c->post('/process.php?id=' . $n3, ['_csrf' => $c->csrf()] + $form);
            $this->assertSame(403, $res['status'], 'aksi ' . $form['action'] . ' harus ditolak server');
        }
        $this->assertSame('current', $this->processStatus($n3));
        $res = $c->post('/project.php?id=' . self::$projectId, ['_csrf' => $c->csrf(), 'action' => 'change_target', 'target_finish' => '2027-12-31', 'reason' => 'x']);
        $this->assertSame(403, $res['status']);
    }

    public function testDrafterOnlyCompletesOwnProcess(): void
    {
        $c = $this->loginAs('drafter@test.local');
        $n3 = $this->pid('N3');
        // PIC belum ditetapkan → bukan miliknya
        $c->get('/process.php?id=' . $n3);
        $res = $c->post('/process.php?id=' . $n3, ['_csrf' => $c->csrf(), 'action' => 'complete']);
        $this->assertSame(403, $res['status']);
        // NPD menetapkan drafter sebagai PIC part → boleh menyelesaikan
        $npd = $this->loginAs('npd@test.local');
        $npd->get('/project.php?id=' . self::$projectId);
        $drafterId = (int) self::pdo()->query("SELECT id FROM users WHERE email = 'drafter@test.local'")->fetchColumn();
        $partId = (int) self::pdo()->query('SELECT part_id FROM processes WHERE id = ' . $n3)->fetchColumn();
        $res = $npd->post('/project.php?id=' . self::$projectId, ['_csrf' => $npd->csrf(), 'action' => 'part_pics', 'part_id' => (string) $partId, 'pic' => ['drafter' => (string) $drafterId]]);
        $this->assertSame(303, $res['status']);
        $c->get('/process.php?id=' . $n3);
        $res = $c->post('/process.php?id=' . $n3, ['_csrf' => $c->csrf(), 'action' => 'complete', 'comment' => '<script>alert(1)</script>']);
        $this->assertSame(303, $res['status'], substr($res['body'], 0, 300));
        $this->assertSame('completed', $this->processStatus($n3));
        $page = $c->get('/process.php?id=' . $n3);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page['body']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page['body']);
    }

    public function testCsrfIsRequired(): void
    {
        $c = $this->loginAs('npd@test.local');
        $res = $c->post('/process.php?id=' . $this->pid('N1'), ['action' => 'start_early']);
        $this->assertSame(419, $res['status']);
        $res = $c->post('/api/schedule-preview.php', ['kind' => 'plan', 'process_id' => (string) $this->pid('N5'), 'plan' => ['duration' => '6']], ['Accept: application/json']);
        $this->assertSame(419, $res['status']);
    }

    public function testSchedulePreviewApi(): void
    {
        $c = $this->loginAs('npd@test.local');
        $c->get('/process.php?id=' . $this->pid('N5'));
        $headers = ['Accept: application/json', 'X-CSRF-Token: ' . $c->csrf()];
        $res = $c->post('/api/schedule-preview.php', ['kind' => 'plan', 'process_id' => (string) $this->pid('N5'), 'plan' => ['duration' => '6']], $headers);
        $this->assertSame(200, $res['status'], $res['body']);
        $data = json_decode($res['body'], true);
        $this->assertTrue($data['ok']);
        $this->assertContains('+2', array_column($data['changes'], 'shift'));
        // lingkaran: N1 bergantung pada N5
        $res = $c->post('/api/schedule-preview.php', ['kind' => 'dependency', 'process_id' => (string) $this->pid('N1'),
            'deps' => [['predecessor_id' => (string) $this->pid('N5'), 'type' => 'FS', 'lag' => '0']]], $headers);
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('lingkaran', json_decode($res['body'], true)['error']);
        // Sales: tidak berhak
        $s = $this->loginAs('sales@test.local');
        $s->get('/projects.php');
        $res = $s->post('/api/schedule-preview.php', ['kind' => 'plan', 'process_id' => (string) $this->pid('N5'), 'plan' => ['duration' => '6']], ['Accept: application/json', 'X-CSRF-Token: ' . $s->csrf()]);
        $this->assertSame(403, $res['status']);
    }

    public function testValidationErrorsRerenderWithoutSaving(): void
    {
        $c = $this->loginAs('npd@test.local');
        $n5 = $this->pid('N5');
        $c->get('/process.php?id=' . $n5);
        $res = $c->post('/process.php?id=' . $n5, ['_csrf' => $c->csrf(), 'action' => 'plan', 'plan' => ['duration' => '999']]);
        $this->assertSame(422, $res['status']);
        $this->assertStringContainsString('1–365', $res['body']);
        $res = $c->post('/project.php?id=' . self::$projectId, ['_csrf' => $c->csrf(), 'action' => 'change_target', 'target_finish' => '2027-13-45', 'reason' => 'x']);
        $this->assertSame(422, $res['status']);
    }
}
