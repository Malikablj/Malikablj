<?php
declare(strict_types=1);

namespace Tests\Http;

use Tests\Support\HttpTestCase;

/** Halaman Hari Libur & Pengaturan Workflow lewat HTTP: hanya Admin (403 untuk lainnya), CSRF, alur dasar. */
final class SettingsAdminHttpTest extends HttpTestCase
{
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

    public function testOnlyAdminCanOpenOrChange(): void
    {
        foreach (['npd@test.local', 'mgmt@test.local', 'sales@test.local'] as $email) {
            $c = $this->loginAs($email);
            $this->assertSame(403, $c->get('/settings/holidays.php')['status'], $email);
            $this->assertSame(403, $c->get('/settings/workflow.php')['status'], $email);
            $c->get('/dashboard.php');
            $this->assertSame(403, $c->post('/settings/holidays.php', ['_csrf' => $c->csrf(), 'action' => 'save', 'holiday_date' => '2026-12-31', 'name' => 'x'])['status']);
            $this->assertSame(403, $c->post('/settings/workflow.php', ['_csrf' => $c->csrf(), 'action' => 'draft', 'template' => '2'])['status']);
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM holidays WHERE holiday_date = '2026-12-31'"));
    }

    public function testAdminManagesHolidaysAndWorkflowDraft(): void
    {
        $c = $this->loginAs('admin@test.local');
        $page = $c->get('/settings/holidays.php?year=2026');
        $this->assertSame(200, $page['status']);
        $this->assertSame(419, $c->post('/settings/holidays.php', ['action' => 'save', 'holiday_date' => '2026-12-31', 'name' => 'x'])['status']);
        $c->get('/settings/holidays.php?year=2026');
        $res = $c->post('/settings/holidays.php?year=2026', ['_csrf' => $c->csrf(), 'action' => 'save', 'holiday_date' => '2026-12-31', 'name' => 'Libur <b>akhir</b> tahun', 'is_recurring' => '1']);
        $this->assertSame(303, $res['status']);
        $list = $c->get('/settings/holidays.php?year=2027');
        $this->assertStringContainsString('Libur &lt;b&gt;akhir&lt;/b&gt; tahun', $list['body'], 'berulang tampil di tahun berikutnya, di-escape');
        $res = $c->post('/settings/holidays.php?year=2026', ['_csrf' => $c->csrf(), 'action' => 'save', 'holiday_date' => '2026-12-31', 'name' => 'Ganda']);
        $this->assertSame(422, $res['status']);

        // workflow: daftar → template → draf → edit proses → terbitkan
        $this->assertStringContainsString('New Mold', $c->get('/settings/workflow.php')['body']);
        $tid = (int) $this->scalar("SELECT id FROM workflow_templates WHERE code = 'subcont'");
        $this->assertSame(200, $c->get('/settings/workflow.php?template=' . $tid)['status']);
        $this->assertSame(303, $c->post('/settings/workflow.php', ['_csrf' => $c->csrf(), 'action' => 'draft', 'template' => (string) $tid])['status']);
        $draft = (int) $this->scalar("SELECT id FROM workflow_template_versions WHERE template_id = ? AND status = 'draft'", [$tid]);
        $this->assertGreaterThan(0, $draft);
        $s1 = (int) $this->scalar("SELECT id FROM workflow_steps WHERE template_version_id = ? AND code = 'S1'", [$draft]);
        $edit = $c->get('/settings/workflow.php?template=' . $tid . '&step=' . $s1);
        $this->assertSame(200, $edit['status']);
        $this->assertStringContainsString('name="deps[0][predecessor_code]"', $edit['body']);
        $res = $c->post('/settings/workflow.php', ['_csrf' => $c->csrf(), 'action' => 'update_step', 'template' => (string) $tid, 'version' => (string) $draft, 'step' => (string) $s1,
            'name' => 'Artwork Development', 'pic_role' => 'drafter', 'default_duration' => '6', 'is_mandatory' => '1', 'is_active' => '1',
            'deps' => [['predecessor_code' => 'P2', 'dep_type' => 'FS', 'lag_days' => '0']]]);
        $this->assertSame(303, $res['status']);
        $this->assertSame(6, (int) $this->scalar('SELECT default_duration FROM workflow_steps WHERE id = ?', [$s1]));
        // lingkaran ditolak lewat form (S1 bergantung pada S3 yang bergantung pada S1)
        $c->get('/settings/workflow.php?template=' . $tid . '&step=' . $s1);
        $c->post('/settings/workflow.php', ['_csrf' => $c->csrf(), 'action' => 'update_step', 'template' => (string) $tid, 'version' => (string) $draft, 'step' => (string) $s1,
            'name' => 'Artwork Development', 'pic_role' => 'drafter', 'default_duration' => '7', 'is_active' => '1', 'deps' => [['predecessor_code' => 'S3', 'dep_type' => 'FS', 'lag_days' => '0']]]);
        $this->assertSame(6, (int) $this->scalar('SELECT default_duration FROM workflow_steps WHERE id = ?', [$s1]), 'gagal → atribut ikut dibatalkan (satu transaksi)');
        $this->assertSame('P2', $this->scalar('SELECT predecessor_code FROM workflow_step_dependencies WHERE step_id = ?', [$s1]));
        $c->get('/settings/workflow.php?template=' . $tid);
        $this->assertSame(303, $c->post('/settings/workflow.php', ['_csrf' => $c->csrf(), 'action' => 'publish', 'template' => (string) $tid, 'version' => (string) $draft, 'notes' => 'Uji'])['status']);
        $this->assertSame($draft, (int) $this->scalar('SELECT current_version_id FROM workflow_templates WHERE id = ?', [$tid]));
        $this->assertSame(200, $c->get('/settings/workflow.php?template=' . $tid . '&apply=preview')['status']);
    }
}
