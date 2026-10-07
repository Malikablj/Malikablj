<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** Dokumen, approval, catatan, next action, komentar lewat HTTP nyata (otorisasi server, CSRF, escaping). */
final class RecordHttpTest extends HttpTestCase
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

    private function pid(string $code): int
    {
        $st = self::pdo()->prepare('SELECT p.id FROM processes p JOIN project_parts pp ON pp.id = p.part_id WHERE p.project_id = ? AND p.code = ? ORDER BY pp.sort_order LIMIT 1');
        $st->execute([self::$projectId, $code]);
        return (int) $st->fetchColumn();
    }

    public function testPagesForAllRoles(): void
    {
        foreach (['mgmt@test.local', 'sales@test.local', 'purchasing@test.local'] as $email) {
            $c = $this->loginAs($email);
            foreach (['/documents.php', '/documents.php?project_id=' . self::$projectId, '/approvals.php', '/approvals.php?view=history',
                      '/project.php?id=' . self::$projectId . '&tab=approvals', '/project.php?id=' . self::$projectId . '&tab=documents',
                      '/project.php?id=' . self::$projectId . '&tab=records', '/project.php?id=' . self::$projectId . '&tab=activity',
                      '/process.php?id=' . $this->pid('N8'), '/process.php?id=' . $this->pid('N12')] as $path) {
                $res = $c->get($path);
                $this->assertSame(200, $res['status'], $email . ' ' . $path);
                $this->assertStringNotContainsString('Warning:', $res['body']);
            }
        }
    }

    public function testRecordPermissions(): void
    {
        $n8 = $this->pid('N8');
        $s = $this->loginAs('sales@test.local');
        $s->get('/process.php?id=' . $n8);
        $this->assertSame(403, $s->post('/process.php?id=' . $n8, ['_csrf' => $s->csrf(), 'action' => 'record', 'rec' => ['machine' => 'x']])['status']);
        $p = $this->loginAs('production@test.local');
        $page = $p->get('/process.php?id=' . $n8);
        $this->assertStringContainsString('name="rec[machine]"', $page['body']);
        $res = $p->post('/process.php?id=' . $n8, ['_csrf' => $p->csrf(), 'action' => 'record', 'rec' => ['machine' => 'ASB <70>', 'trial_date' => '2026-12-07']]);
        $this->assertSame(303, $res['status']);
        $page = $p->get('/project.php?id=' . self::$projectId . '&tab=records');
        $this->assertStringContainsString('ASB &lt;70&gt;', $page['body']);
        $res = $p->post('/process.php?id=' . $n8, ['_csrf' => $p->csrf(), 'action' => 'record', 'rec' => ['trial_date' => '31-12-2026']]);
        $this->assertSame(422, $res['status']);
        // purchasing memperbarui material
        $n12 = $this->pid('N12');
        $pu = $this->loginAs('purchasing@test.local');
        $pu->get('/process.php?id=' . $n12);
        $this->assertSame(303, $pu->post('/process.php?id=' . $n12, ['_csrf' => $pu->csrf(), 'action' => 'material', 'rec' => ['material' => 'HDPE', 'quantity_kg' => '100']])['status']);
        $pu->get('/process.php?id=' . $n8);
        $this->assertSame(403, $pu->post('/process.php?id=' . $n8, ['_csrf' => $pu->csrf(), 'action' => 'record', 'rec' => ['machine' => 'x']])['status']);
    }

    public function testCommentsNextActionsAndDocumentRemovalAuthorization(): void
    {
        $n3 = $this->pid('N3');
        $m = $this->loginAs('mgmt@test.local');
        $m->get('/process.php?id=' . $n3);
        $this->assertSame(403, $m->post('/process.php?id=' . $n3, ['_csrf' => $m->csrf(), 'action' => 'comment', 'body' => 'x'])['status']);
        $d = $this->loginAs('drafter@test.local');
        $d->get('/process.php?id=' . $n3);
        $this->assertSame(303, $d->post('/process.php?id=' . $n3, ['_csrf' => $d->csrf(), 'action' => 'comment', 'body' => '<img src=x onerror=alert(1)>'])['status']);
        $page = $d->get('/process.php?id=' . $n3);
        $this->assertStringNotContainsString('<img src=x onerror', $page['body']);
        $d->get('/project.php?id=' . self::$projectId);
        $this->assertSame(403, $d->post('/project.php?id=' . self::$projectId, ['_csrf' => $d->csrf(), 'action' => 'next_action', 'description' => 'x'])['status']);
        $s = $this->loginAs('sales@test.local');
        $s->get('/project.php?id=' . self::$projectId);
        $this->assertSame(303, $s->post('/project.php?id=' . self::$projectId, ['_csrf' => $s->csrf(), 'action' => 'next_action', 'description' => 'Kirim sampel', 'waiting_for' => 'customer', 'due_date' => '2026-10-20'])['status']);
        $this->assertStringContainsString('Kirim sampel', $s->get('/project.php?id=' . self::$projectId)['body']);
        $s2 = $this->loginAs('sales2@test.local');
        $s2->get('/project.php?id=' . self::$projectId);
        $this->assertSame(403, $s2->post('/project.php?id=' . self::$projectId, ['_csrf' => $s2->csrf(), 'action' => 'next_action', 'description' => 'x'])['status']);
        $npd = $this->loginAs('npd@test.local');
        $npd->get('/documents.php');
        $this->assertSame(403, $npd->post('/documents.php', ['_csrf' => $npd->csrf(), 'action' => 'remove', 'document_id' => '1', 'reason' => 'x'])['status']);
    }
}
