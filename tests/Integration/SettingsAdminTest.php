<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Scheduling\WorkingCalendar;
use App\Settings\HolidayService;
use App\Workflow\WorkflowEngine;
use App\Workflow\WorkflowTemplateService;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/**
 * Pengaturan Admin: hari libur (PRD §6.2) dan template workflow berversi (PRD §5.8, §5.5, WF-15/16, FR-WF-04).
 */
final class SettingsAdminTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $admin;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->admin = $this->makeUser('admin');
        $this->customer = $this->makeCustomer();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    private function newMoldProject(): array
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold']], ['feasible']);
        $part = (int) Db::value('SELECT id FROM project_parts WHERE project_id = ?', [$projectId]);
        return [$projectId, $part];
    }

    /** @return array<string,mixed> */
    private function proc(int $partId, string $code): array
    {
        return Db::fetch('SELECT * FROM processes WHERE part_id = ? AND code = ?', [$partId, $code]) ?? [];
    }

    private function templateId(string $code): int
    {
        return (int) Db::value('SELECT id FROM workflow_templates WHERE code = ?', [$code]);
    }

    private function stepId(int $versionId, string $code): int
    {
        return (int) Db::value('SELECT id FROM workflow_steps WHERE template_version_id = ? AND code = ?', [$versionId, $code]);
    }

    // ------------------------------------------------------------------ Hari libur

    public function testHolidayChangesRescheduleNotStartedProcesses(): void
    {
        [$projectId, $part] = $this->newMoldProject();
        $svc = new HolidayService();
        // N2 (4 hk) dijadwalkan 15–20 Okt; libur Selasa 20 Okt → selesai Rabu 21 Okt
        $this->assertSame(['2026-10-15', '2026-10-20'], [$this->proc($part, 'N2')['planned_start'], $this->proc($part, 'N2')['planned_finish']]);
        $res = $svc->save($this->admin, null, ['holiday_date' => '2026-10-20', 'name' => 'Libur perusahaan', 'is_recurring' => '']);
        $this->assertSame(1, $res['projects']);
        $this->assertGreaterThan(0, $res['shifted']);
        $this->assertSame('2026-10-21', $this->proc($part, 'N2')['planned_finish']);
        $this->assertGreaterThan(0, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'calendar'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'holiday.create'"));

        // ubah → tanggal lain; hapus → jadwal kembali
        $svc->save($this->admin, $res['id'], ['holiday_date' => '2026-10-19', 'name' => 'Libur perusahaan', 'is_recurring' => '']);
        $this->assertSame('2026-10-21', $this->proc($part, 'N2')['planned_finish'], '15,16,(19 libur),20,21');
        $svc->delete($this->admin, $res['id']);
        $this->assertSame('2026-10-20', $this->proc($part, 'N2')['planned_finish']);

        // validasi & otorisasi
        try {
            $svc->save($this->admin, null, ['holiday_date' => '2026-02-30', 'name' => '']);
            $this->fail('validasi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('holiday_date', $e->errors());
            $this->assertArrayHasKey('name', $e->errors());
        }
        $svc->save($this->admin, null, ['holiday_date' => '2026-12-25', 'name' => 'Natal', 'is_recurring' => '1']);
        try {
            $svc->save($this->admin, null, ['holiday_date' => '2026-12-25', 'name' => 'Ganda']);
            $this->fail('tanggal ganda');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('holiday_date', $e->errors());
        }
        $this->expectException(AuthorizationException::class);
        $svc->save($this->npd, null, ['holiday_date' => '2026-12-31', 'name' => 'x']);
    }

    public function testRecurringHolidayAndImport(): void
    {
        $svc = new HolidayService();
        $svc->save($this->admin, null, ['holiday_date' => '2025-08-17', 'name' => 'Hari Kemerdekaan', 'is_recurring' => '1']);
        WorkingCalendar::flush();
        $this->assertTrue(WorkingCalendar::fromDb()->isHoliday('2026-08-17'), 'berulang tiap tahun');
        $this->assertContains('2027-08-17', array_column($svc->forYear(2027), 'date_in_year'));
        try {
            $svc->import($this->admin, "2026-12-24;Cuti bersama\nbukan-tanggal;X");
            $this->fail('baris tidak valid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('import', $e->errors());
        }
        $res = $svc->import($this->admin, "2026-12-24;Cuti bersama\n2026-12-26;Cuti bersama\n2025-08-17;Ganda");
        $this->assertSame(2, $res['added']);
        $this->assertSame(1, $res['skipped']);
        WorkingCalendar::flush();
        $this->assertFalse(WorkingCalendar::fromDb()->isWorkingDay('2026-12-24'));
    }

    // ------------------------------------------------------------------ Template workflow

    public function testDraftEditPublishAndNewPartsUseNewVersion(): void
    {
        $svc = new WorkflowTemplateService();
        $tid = $this->templateId('new_mold');
        $v1 = (int) Db::value('SELECT current_version_id FROM workflow_templates WHERE id = ?', [$tid]);
        $draft = $svc->draftFor($this->admin, $tid);
        $this->assertSame($draft, $svc->draftFor($this->admin, $tid), 'satu draf per template');
        $this->assertSame(count($svc->steps($v1)), count($svc->steps($draft)));
        $this->assertSame(
            (int) Db::value('SELECT COUNT(*) FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ?', [$v1]),
            (int) Db::value('SELECT COUNT(*) FROM workflow_step_dependencies d JOIN workflow_steps s ON s.id = d.step_id WHERE s.template_version_id = ?', [$draft])
        );

        // durasi & nama, dokumen wajib (Lampiran B), nonaktifkan N4 (bawaan), proses struktural tidak bisa dinonaktifkan
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N1'), ['default_duration' => '5', 'name' => 'Masterbatch Development (rev)']);
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N3'), ['required_doc_types' => ['prototype_3d_document']]);
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N4'), ['is_active' => '']);
        try {
            $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N14'), ['is_active' => '']);
            $this->fail('finish wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }
        try {
            $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N2'), ['default_duration' => '0']);
            $this->fail('durasi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('default_duration', $e->errors());
        }
        // keputusan: tujuan loop & label
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N2'), ['options' => [1 => ['label_id' => 'Ditolak', 'loop_to' => 'N1', 'comment_required' => true]]]);
        $opts = json_decode((string) Db::value('SELECT decision_options_json FROM workflow_steps WHERE id = ?', [$this->stepId($draft, 'N2')]), true);
        $this->assertSame('Ditolak', $opts[1]['label_id']);

        // proses khusus: tambah setelah N5, hapus bawaan ditolak
        $c1 = $svc->addStep($this->admin, $draft, ['name' => 'Cek Sampel Internal', 'step_type' => 'task', 'pic_role' => 'quality', 'default_duration' => '2', 'after_step_id' => $this->stepId($draft, 'N5')]);
        $this->assertSame('C1', Db::value('SELECT code FROM workflow_steps WHERE id = ?', [$c1]));
        try {
            $svc->removeStep($this->admin, $draft, $this->stepId($draft, 'N3'));
            $this->fail('bawaan tidak dapat dihapus');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
        // N6 menunggu C1 (selain N5)
        $svc->setDependencies($this->admin, $draft, $this->stepId($draft, 'N6'), [['predecessor_code' => 'N5', 'dep_type' => 'FS', 'lag_days' => '0'], ['predecessor_code' => 'C1', 'dep_type' => 'FS', 'lag_days' => '0']], 'Tambah cek sampel');

        // lingkaran ditolak & tidak tersimpan
        $before = Db::fetchAll('SELECT predecessor_code FROM workflow_step_dependencies WHERE step_id = ?', [$this->stepId($draft, 'N1')]);
        try {
            $svc->setDependencies($this->admin, $draft, $this->stepId($draft, 'N1'), [['predecessor_code' => 'N2', 'dep_type' => 'FS', 'lag_days' => '0']]);
            $this->fail('lingkaran');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('N1', $e->getMessage());
        }
        $this->assertSame($before, Db::fetchAll('SELECT predecessor_code FROM workflow_step_dependencies WHERE step_id = ?', [$this->stepId($draft, 'N1')]));
        $this->assertSame([], $svc->validate($draft));

        // hanya Admin
        try {
            $svc->updateStep($this->npd, $draft, $this->stepId($draft, 'N1'), ['default_duration' => '3']);
            $this->fail('hanya Admin');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, $svc->publish($this->admin, $draft, 'Revisi durasi'));
        $this->assertSame($draft, (int) Db::value('SELECT current_version_id FROM workflow_templates WHERE id = ?', [$tid]));
        $this->assertSame('retired', Db::value('SELECT status FROM workflow_template_versions WHERE id = ?', [$v1]));
        try {
            $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N1'), ['default_duration' => '3']);
            $this->fail('versi terbit baca saja');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        // part baru memakai v2: N1 5 hk, tanpa N4, N5 menunggu N2 & N3 (jembatan predecessor nonaktif), C1 ada
        [$projectId, $part] = $this->newMoldProject();
        $this->assertSame($draft, (int) Db::value('SELECT workflow_template_version_id FROM project_parts WHERE id = ?', [$part]));
        $this->assertSame(5, (int) $this->proc($part, 'N1')['duration']);
        $this->assertSame('Masterbatch Development (rev)', $this->proc($part, 'N1')['name']);
        $this->assertSame([], $this->proc($part, 'N4'));
        $preds = Db::column('SELECT p.code FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? ORDER BY p.code', [(int) $this->proc($part, 'N5')['id']]);
        $this->assertSame(['N2', 'N3'], $preds);
        $this->assertSame('quality', Db::value('SELECT r.code FROM processes p JOIN roles r ON r.id = p.pic_role_id WHERE p.part_id = ? AND p.code = ?', [$part, 'C1']));
        $n6preds = Db::column('SELECT p.code FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? ORDER BY p.code', [(int) $this->proc($part, 'N6')['id']]);
        $this->assertSame(['C1', 'N5'], $n6preds);
        $this->assertSame(['prototype_3d_document'], json_decode((string) $this->proc($part, 'N3')['required_doc_types_json'], true));
        $this->assertGreaterThanOrEqual(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'workflow.publish'"));
    }

    public function testApplyNewVersionToNotStartedProcessesWithPreview(): void
    {
        [$projectId, $part] = $this->newMoldProject();
        Clock::freeze('2026-10-06 07:00:00');
        (new WorkflowEngine())->activateReady($projectId); // N1 & N3 aktif
        $n2Before = $this->proc($part, 'N2');
        $this->assertSame(4, (int) $n2Before['duration']);

        $svc = new WorkflowTemplateService();
        $tid = $this->templateId('new_mold');
        $draft = $svc->draftFor($this->admin, $tid);
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N2'), ['default_duration' => '6']);
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N1'), ['default_duration' => '9']); // N1 sudah berjalan → tidak diubah
        $svc->updateStep($this->admin, $draft, $this->stepId($draft, 'N3'), ['is_skippable' => '']);  // N3 berjalan → tidak diubah
        $svc->publish($this->admin, $draft);

        $preview = $svc->previewApply($this->admin, $tid);
        $this->assertCount(1, $preview);
        $codes = array_column($preview[0]['plan']['changes'], 'code');
        $this->assertContains('N2', $codes);
        $this->assertNotContains('N1', $codes, 'proses yang sudah dimulai tidak disentuh');
        $this->assertNotContains('N3', $codes);
        $n2 = array_values(array_filter($preview[0]['plan']['changes'], static fn ($c) => $c['code'] === 'N2'))[0];
        $this->assertSame([4, 6], $n2['diff']['duration']);
        $this->assertGreaterThanOrEqual($preview[0]['forecast_old'], $preview[0]['forecast_new']);
        $this->assertSame(4, (int) $this->proc($part, 'N2')['duration'], 'pratinjau tidak menyimpan');

        $this->assertSame(1, $svc->apply($this->admin, $tid, [$part]));
        $n2After = $this->proc($part, 'N2');
        $this->assertSame(6, (int) $n2After['duration']);
        $this->assertSame('2026-10-22', $n2After['planned_finish'], 'N2 15–22 Okt (6 hk)');
        $this->assertSame(7, (int) $this->proc($part, 'N1')['duration']);
        $this->assertSame($draft, (int) Db::value('SELECT workflow_template_version_id FROM project_parts WHERE id = ?', [$part]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'workflow.apply' AND project_id = ?", [$projectId]));
        $this->assertGreaterThan(0, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'template_apply'", [$projectId]));
        $this->assertSame([], $svc->previewApply($this->admin, $tid), 'part sudah memakai versi terbaru');

        // draf dapat dibuang
        $d3 = $svc->draftFor($this->admin, $tid);
        $svc->discard($this->admin, $d3);
        $this->assertNull(Db::value('SELECT id FROM workflow_template_versions WHERE id = ?', [$d3]));
    }
}
