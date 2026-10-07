<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** Timeline, Gantt, Tracker, Kalender, export timeline lewat HTTP nyata (UAT-18/19). */
final class TimelineHttpTest extends HttpTestCase
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

    private function partId(int $i = 0): int
    {
        $st = self::pdo()->prepare('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id');
        $st->execute([self::$projectId]);
        return (int) $st->fetchAll(\PDO::FETCH_COLUMN)[$i];
    }

    public function testPagesRenderForAllRoles(): void
    {
        foreach (['mgmt@test.local', 'drafter@test.local', 'sales@test.local', 'npd@test.local'] as $email) {
            $c = $this->loginAs($email);
            foreach (['/timeline.php?project=' . self::$projectId, '/timeline.php?part=' . $this->partId(), '/timeline.php?part=' . $this->partId(1) . '&status=active',
                      '/gantt.php', '/gantt.php?level=part&part_type=new_mold', '/tracker.php', '/tracker.php?overdue=1', '/calendar.php', '/calendar.php?month=2026-12&category=trial'] as $path) {
                $res = $c->get($path);
                $this->assertSame(200, $res['status'], $email . ' ' . $path);
                $this->assertStringNotContainsString('Warning:', $res['body']);
            }
        }
        $c = $this->loginAs('npd@test.local');
        $page = $c->get('/timeline.php?part=' . $this->partId());
        $this->assertStringContainsString('data-gantt', $page['body']);
        $this->assertStringContainsString('dlg-plan-', $page['body'], 'NPD dapat mengubah planning dari timeline');
        $mgmt = $this->loginAs('mgmt@test.local')->get('/timeline.php?part=' . $this->partId());
        $this->assertStringNotContainsString('dlg-plan-', $mgmt['body'], 'Management hanya melihat');
        $this->assertSame(404, $c->get('/timeline.php?part=999999')['status']);
    }

    public function testExports(): void
    {
        $c = $this->loginAs('mgmt@test.local');
        $pdf = $c->get('/export.php?type=timeline_pdf&project=' . self::$projectId . '&scope=all');
        $this->assertSame(200, $pdf['status']);
        $this->assertStringStartsWith('%PDF', $pdf['body']);
        $this->assertStringContainsString('application/pdf', $pdf['headers']['content-type'][0]);
        $this->assertMatchesRegularExpression('/filename="Timeline_NPD-\d{4}-\d{3}_\d{4}-\d{2}-\d{2}\.pdf"/', $pdf['headers']['content-disposition'][0]);
        $xlsx = $c->get('/export.php?type=timeline_xlsx&project=' . self::$projectId . '&scope=part&part=' . $this->partId());
        $this->assertSame(200, $xlsx['status']);
        $this->assertStringStartsWith('PK', $xlsx['body']);
        $this->assertStringContainsString('spreadsheetml', $xlsx['headers']['content-type'][0]);
        $this->assertSame(422, $c->get('/export.php?type=timeline_pdf&project=' . self::$projectId . '&scope=xx')['status']);
        $this->assertSame(404, $c->get('/export.php?type=timeline_pdf&project=' . self::$projectId . '&scope=part&part=999999')['status']);
        $log = self::pdo()->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('export.timeline_pdf', 'export.timeline_xlsx')")->fetchColumn();
        $this->assertGreaterThanOrEqual(2, (int) $log);
    }

    public function testCalendarAgendaAuthorization(): void
    {
        $d = $this->loginAs('drafter@test.local');
        $d->get('/calendar.php');
        $res = $d->post('/calendar.php', ['_csrf' => $d->csrf(), 'action' => 'create', 'title' => 'x', 'event_date' => '2026-10-20']);
        $this->assertSame(403, $res['status']);
        $s = $this->loginAs('sales@test.local');
        $s->get('/calendar.php');
        $this->assertSame(419, $s->post('/calendar.php', ['action' => 'create', 'title' => 'x', 'event_date' => '2026-10-20'])['status']);
        $res = $s->post('/calendar.php', ['_csrf' => $s->csrf(), 'action' => 'create', 'title' => 'Follow-up <b>sampel</b>', 'event_type' => 'follow_up', 'event_date' => '2026-10-20']);
        $this->assertSame(303, $res['status']);
        $page = $s->get('/calendar.php?month=2026-10');
        $this->assertStringContainsString('Follow-up &lt;b&gt;sampel&lt;/b&gt;', $page['body']);
        $res = $s->post('/calendar.php', ['_csrf' => $s->csrf(), 'action' => 'create', 'title' => '', 'event_date' => 'bukan-tanggal']);
        $this->assertSame(422, $res['status']);
    }

    public function testPlanFromTimelineReturnsSafely(): void
    {
        $c = $this->loginAs('npd@test.local');
        $st = self::pdo()->prepare("SELECT id, lock_version FROM processes WHERE part_id = ? AND code = 'N5'");
        $st->execute([$this->partId()]);
        $n5 = $st->fetch(\PDO::FETCH_ASSOC);
        $c->get('/timeline.php?part=' . $this->partId());
        $return = '/timeline.php?part=' . $this->partId();
        $res = $c->post('/process.php?id=' . $n5['id'], ['_csrf' => $c->csrf(), 'action' => 'plan', 'lock_version' => (string) $n5['lock_version'], 'plan' => ['duration' => '5'], 'return' => $return]);
        $this->assertSame(303, $res['status']);
        $this->assertStringEndsWith($return, $res['headers']['location'][0]);
        // tujuan eksternal ditolak → kembali ke halaman proses
        $st->execute([$this->partId()]);
        $n5 = $st->fetch(\PDO::FETCH_ASSOC);
        $c->get('/process.php?id=' . $n5['id']); // token CSRF dari halaman
        $res = $c->post('/process.php?id=' . $n5['id'], ['_csrf' => $c->csrf(), 'action' => 'plan', 'lock_version' => (string) $n5['lock_version'], 'plan' => ['duration' => '6'], 'return' => '//evil.example/x']);
        $this->assertSame(303, $res['status']);
        $this->assertStringContainsString('/process.php?id=' . $n5['id'], $res['headers']['location'][0]);
    }
}
