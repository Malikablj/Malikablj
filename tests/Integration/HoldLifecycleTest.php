<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\OverdueService;
use App\Project\HoldService;
use App\Project\LifecycleService;
use App\Project\ProjectQuery;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/**
 * Hold, Resume, Cancel, buka kembali, Arsip (PRD §8, UAT-16/17/24, FR-HLD-01..05) pada MySQL.
 * Project uji: Body (New Mold) + Cap (Subcont), feedback selesai Senin 5 Okt 2026; proses pertama aktif Selasa 6 Okt.
 */
final class HoldLifecycleTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $admin;
    private User $drafter;
    private int $customer;
    private WorkflowEngine $engine;
    private HoldService $holds;
    private LifecycleService $life;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00'); // Senin
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->admin = $this->makeUser('admin');
        $this->drafter = $this->makeUser('drafter');
        $this->customer = $this->makeCustomer();
        $this->engine = new WorkflowEngine();
        $this->holds = new HoldService();
        $this->life = new LifecycleService();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    /** @return array{0:int,1:int,2:int} [projectId, moldPartId, subPartId] — proses pertama sudah aktif */
    private function runningProject(): array
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        $parts = Db::column('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [$projectId]);
        Clock::freeze('2026-10-06 06:00:00');
        $this->engine->activateReady($projectId);
        return [$projectId, (int) $parts[0], (int) $parts[1]];
    }

    /** @return array<string,mixed> */
    private function proc(int $projectId, ?int $partId, string $code): array
    {
        $row = Db::fetch('SELECT * FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [$projectId, $partId, $code]);
        $this->assertNotNull($row, "proses $code tidak ada");
        return $row;
    }

    // ------------------------------------------------------------------ UAT-16: Hold

    public function testHoldProjectFreezesProcessesAndIsNotOverdue(): void
    {
        [$projectId, $mold, $sub] = $this->runningProject();
        $this->assertSame('current', $this->proc($projectId, $mold, 'N1')['status']);
        $n2Before = $this->proc($projectId, $mold, 'N2');

        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Customer menunda launching', '2026-11-02');

        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertSame(1, (int) $project['is_on_hold']);
        $this->assertSame('hold', $project['status']);
        $this->assertSame(['hold', 'hold'], Db::column('SELECT status FROM project_parts WHERE project_id = ? ORDER BY id', [$projectId]), 'Hold project berlaku untuk semua part aktif');
        $h = Db::fetch('SELECT * FROM hold_history WHERE project_id = ?', [$projectId]);
        $this->assertSame('project', $h['scope']);
        $this->assertNull($h['part_id']);
        $this->assertSame('2026-11-02', $h['expected_resume_date']);
        $this->assertSame((string) $this->npd->id, (string) $h['held_by']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'hold'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.hold'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'hold_changed'", [$this->sales->id]), 'Sales PIC diberi tahu');

        // proses dibekukan: tidak dapat diselesaikan
        try {
            $this->engine->complete($this->npd, (int) $this->proc($projectId, $mold, 'N1')['id'], []);
            $this->fail('proses Hold tidak boleh diselesaikan');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Hold', $e->getMessage());
        }

        // jauh melewati Planned Finish N1 (14 Okt): tidak overdue, tidak ada notifikasi overdue, jadwal tidak bergeser
        Clock::freeze('2026-10-26 08:00:00');
        $this->assertSame([], (new OverdueService())->overdueProcesses(['project_id' => $projectId]));
        $stats = (new OverdueService())->scan();
        $this->assertSame(0, $stats['overdue_first']);
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE project_id = ? AND type = 'project_overdue'", [$projectId]));
        $this->assertSame([], $this->engine->activateReady($projectId));
        (new ScheduleService())->recalculate($projectId);
        $n2 = $this->proc($projectId, $mold, 'N2');
        $this->assertSame([$n2Before['planned_start'], $n2Before['planned_finish']], [$n2['planned_start'], $n2['planned_finish']], 'jadwal dibekukan selama Hold');
        foreach ((new ProjectQuery())->processes($projectId) as $p) {
            $this->assertSame(0, $p['overdue_days'], $p['code'] . ' tidak dihitung overdue selama Hold');
        }
        $this->assertSame(0, (new ProjectQuery())->list($this->admin, ['overdue' => true])['total']);
    }

    public function testHoldValidationAndAuthorization(): void
    {
        [$projectId, $mold] = $this->runningProject();
        try {
            $this->holds->hold($this->npd, $projectId, null, '  ');
            $this->fail('alasan wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        foreach ([$this->drafter, $this->sales] as $u) {
            try {
                $this->holds->hold($u, $projectId, null, 'x');
                $this->fail($u->roleCode . ' tidak boleh Hold');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->holds->hold($this->npd, $projectId, null, 'Tunggu customer');
        $this->expectException(BusinessRuleException::class);
        $this->holds->hold($this->npd, $projectId, $mold, 'Part juga');
    }

    public function testPartHoldOnlyAffectsThatPart(): void
    {
        [$projectId, $mold, $sub] = $this->runningProject();
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->admin, $projectId, $mold, 'Mold supplier tutup');

        $this->assertSame('hold', Db::value('SELECT status FROM project_parts WHERE id = ?', [$mold]));
        $this->assertNotSame('hold', Db::value('SELECT status FROM project_parts WHERE id = ?', [$sub]));
        $this->assertNotSame('hold', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]), 'project tetap berjalan karena part lain aktif');

        Clock::freeze('2026-10-26 08:00:00');
        $overdue = (new OverdueService())->overdueProcesses(['project_id' => $projectId]);
        $this->assertNotEmpty($overdue, 'part Cap tetap dihitung overdue');
        foreach ($overdue as $o) {
            $this->assertSame($sub, (int) $o['part_id'], 'part Hold dikecualikan');
        }

        // resume part: baseline baru untuk part, batas jadwal hanya pada part tsb
        $baselineId = $this->holds->resume($this->admin, $projectId, $mold, ['target_finish' => '2027-01-29']);
        $b = Db::fetch('SELECT * FROM schedule_baselines WHERE id = ?', [$baselineId]);
        $this->assertSame($mold, (int) $b['part_id']);
        $this->assertSame(2, (int) $b['version_no'], 'v1 dibuat saat part mulai; v2 setelah Resume');
        $this->assertSame(0, (int) Db::value('SELECT is_active FROM schedule_baselines WHERE part_id = ? AND version_no = 1', [$mold]), 'baseline lama tetap tersimpan (tidak aktif)');
        $this->assertSame('2026-10-26', Db::value('SELECT schedule_floor FROM project_parts WHERE id = ?', [$mold]));
        $this->assertNull(Db::value('SELECT schedule_floor FROM projects WHERE id = ?', [$projectId]));
    }

    // ------------------------------------------------------------------ UAT-17: Resume

    public function testResumeRequiresTargetPreviewsAndCreatesBaseline(): void
    {
        [$projectId, $mold] = $this->runningProject();
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Customer menunda');

        Clock::freeze('2026-10-19 09:00:00'); // Senin
        $ctx = $this->holds->resumeContext($projectId, null);
        $this->assertSame('2026-10-19', $ctx['restart']);
        $byCode = [];
        foreach ($ctx['processes'] as $p) {
            $byCode[($p['part_id'] === $mold ? 'M.' : 'X.') . $p['code']] = $p;
            // dipakai 1 hari kerja (Sel 6 Okt) sebelum Hold Rabu 7 Okt → sisa = durasi − 1
            $this->assertSame(1, $p['used'], $p['code']);
            $this->assertSame(max(1, (int) $p['duration'] - 1), $p['remaining'], $p['code']);
        }
        $this->assertArrayHasKey('M.N1', $byCode);
        $n1 = $byCode['M.N1'];

        // Target Finish baru wajib
        try {
            $this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '']);
            $this->fail('target wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_finish', $e->errors());
        }
        try {
            $this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2026-12-31', 'restart_date' => '2026-10-16']);
            $this->fail('tanggal mulai kembali tidak boleh di masa lalu');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('restart_date', $e->errors());
        }
        try {
            $this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2026-12-31', 'remaining' => [(int) $n1['id'] => '0']]);
            $this->fail('durasi sisa minimal 1');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remaining.' . $n1['id'], $e->errors());
        }

        // Pratinjau (server) — tidak menyimpan
        $before = $this->proc($projectId, $mold, 'N2');
        $preview = $this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2026-12-31']);
        $n1Preview = array_values(array_filter($preview['active'], static fn ($a) => (int) $a['id'] === (int) $n1['id']))[0];
        $this->assertSame('2026-10-26', $n1Preview['new_finish'], 'N1 sisa 6 hk mulai Senin 19 Okt → Senin 26 Okt');
        $this->assertNotEmpty($preview['changes']);
        $n2Change = array_values(array_filter($preview['changes'], static fn ($c) => $c['code'] === 'N2' && $c['part_id'] === $mold))[0];
        $this->assertSame('2026-10-27', $n2Change['new_start']);
        $this->assertSame($before['planned_start'], $this->proc($projectId, $mold, 'N2')['planned_start'], 'pratinjau tidak menyimpan');
        $this->assertSame(1, (int) Db::value('SELECT is_on_hold FROM projects WHERE id = ?', [$projectId]));
        $this->assertSame('2026-12-31', $preview['target_new']);
        $this->assertSame($preview['project_forecast_new'] > '2026-12-31', $preview['past_target']);
        $this->assertTrue($this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2026-10-30'])['past_target'], 'perbandingan dengan Target Finish baru');
        $this->assertFalse($this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2027-12-31'])['past_target']);

        // Simpan dengan durasi sisa N1 diubah NPD
        $baselineId = $this->holds->resume($this->npd, $projectId, null, ['target_finish' => '2026-12-31', 'remaining' => [(int) $n1['id'] => '4'], 'note' => 'Customer lanjut']);

        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertSame(0, (int) $project['is_on_hold']);
        $this->assertSame('on_progress', $project['status']);
        $this->assertSame('2026-12-31', $project['target_finish']);
        $this->assertSame('2026-10-19', $project['schedule_floor']);
        $this->assertSame('2026-10-22', $this->proc($projectId, $mold, 'N1')['planned_finish'], 'sisa 4 hk: 19–22 Okt');
        $this->assertSame('2026-10-23', $this->proc($projectId, $mold, 'N2')['planned_start']);
        // tidak ada proses belum mulai yang dijadwalkan sebelum tanggal mulai kembali
        $minStart = Db::value("SELECT MIN(planned_start) FROM processes WHERE project_id = ? AND part_id IS NOT NULL AND status = 'not_started' AND planned_start IS NOT NULL", [$projectId]);
        $this->assertGreaterThanOrEqual('2026-10-19', $minStart);

        // baseline baru (seluruh project), baseline part lama tetap tersimpan
        $b = Db::fetch('SELECT * FROM schedule_baselines WHERE id = ?', [$baselineId]);
        $this->assertNull($b['part_id']);
        $this->assertSame(1, (int) $b['is_active']);
        $this->assertSame('2026-12-31', $b['target_finish']);
        $this->assertSame('2026-10-22', Db::value('SELECT planned_finish FROM schedule_baseline_items WHERE baseline_id = ? AND process_id = ?', [$baselineId, (int) $n1['id']]));
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ? AND part_id IS NOT NULL', [$projectId]));

        // masa Hold: 7–16 Okt = 8 hari kerja; dikecualikan dari KPI run yang terbuka
        $h = Db::fetch('SELECT * FROM hold_history WHERE project_id = ?', [$projectId]);
        $this->assertNotNull($h['resumed_at']);
        $this->assertSame(8, (int) $h['hold_working_days']);
        $this->assertSame($baselineId, (int) $h['baseline_id']);
        $this->assertSame('2026-12-31', $h['new_target_finish']);
        $this->assertSame('Customer lanjut', $h['resume_note']);
        $run = Db::fetch("SELECT * FROM process_runs WHERE process_id = ? AND status = 'open'", [(int) $n1['id']]);
        $this->assertSame(8, (int) $run['hold_working_days']);
        $this->assertSame('2026-10-22', $run['planned_finish_at_activation']);

        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'resume'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.resume'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'target_change'", [$projectId]));
        $this->assertGreaterThan(0, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE project_id = ? AND type = 'hold_changed' AND title LIKE '%Resume%'", [$projectId]));

        // setelah Resume tidak ada lagi hold terbuka; resume kedua ditolak
        $this->assertSame([], $this->holds->openHolds($projectId));
        $this->expectException(BusinessRuleException::class);
        $this->holds->resume($this->npd, $projectId, null, ['target_finish' => '2026-12-31']);
    }

    public function testResumeProjectKeepsSeparatelyHeldPartOnHold(): void
    {
        [$projectId, $mold, $sub] = $this->runningProject();
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, $mold, 'Mold supplier tutup');
        Clock::freeze('2026-10-08 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Customer menunda');
        $n1 = $this->proc($projectId, $mold, 'N1');

        Clock::freeze('2026-10-19 09:00:00');
        $ctx = $this->holds->resumeContext($projectId, null);
        foreach ($ctx['processes'] as $p) {
            $this->assertSame($sub, (int) $p['part_id'], 'proses part yang Hold terpisah tidak dijadwal ulang');
        }
        $preview = $this->holds->previewResume($this->npd, $projectId, null, ['target_finish' => '2027-12-31']);
        foreach ($preview['changes'] as $c) {
            $this->assertNotSame($mold, $c['part_id'], 'pratinjau tidak menggeser part yang masih Hold');
        }
        $this->holds->resume($this->npd, $projectId, null, ['target_finish' => '2027-12-31']);

        $this->assertSame(0, (int) Db::value('SELECT is_on_hold FROM projects WHERE id = ?', [$projectId]));
        $this->assertSame('hold', Db::value('SELECT status FROM project_parts WHERE id = ?', [$mold]));
        $this->assertSame($n1['planned_finish'], $this->proc($projectId, $mold, 'N1')['planned_finish']);
        $this->assertSame(0, (int) Db::value("SELECT hold_working_days FROM process_runs WHERE process_id = ? AND status = 'open'", [(int) $n1['id']]));
        $this->assertCount(1, $this->holds->openHolds($projectId), 'Hold part tetap terbuka');
        $s1 = $this->proc($projectId, $sub, 'S1');
        $this->assertGreaterThanOrEqual('2026-10-19', $s1['planned_finish'], 'part lain dijadwal ulang dari tanggal mulai kembali');
    }

    public function testHoldRemindersAfterThirtyDaysThenWeekly(): void
    {
        [$projectId] = $this->runningProject();
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Tunggu PO');

        Clock::freeze('2026-11-05 11:00:00'); // 29 hari
        $this->assertSame(0, $this->holds->sendReminders());
        Clock::freeze('2026-11-06 11:00:00'); // 30 hari
        $sent = $this->holds->sendReminders();
        $this->assertGreaterThanOrEqual(2, $sent, 'Admin & NPD PIC');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'hold_reminder'", [$this->npd->id]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'hold_reminder'", [$this->admin->id]));
        $this->assertSame(0, $this->holds->sendReminders(), 'tidak diulang di hari yang sama');
        Clock::freeze('2026-11-12 11:00:00');
        $this->assertSame(0, $this->holds->sendReminders(), 'ulang tiap 7 hari');
        Clock::freeze('2026-11-13 11:00:00');
        $this->assertGreaterThanOrEqual(2, $this->holds->sendReminders());
        $this->assertSame(2, (int) Db::value('SELECT reminder_count FROM hold_history WHERE project_id = ?', [$projectId]));
        $body = (string) Db::value("SELECT body FROM notifications WHERE user_id = ? AND type = 'hold_reminder' ORDER BY id DESC LIMIT 1", [$this->npd->id]);
        $this->assertStringContainsString('37', $body);
    }

    // ------------------------------------------------------------------ Cancel & buka kembali (FR-HLD-05)

    public function testCancelPartStopsProcessesAndLastPartCancelsProject(): void
    {
        [$projectId, $mold, $sub] = $this->runningProject();
        $n1 = $this->proc($projectId, $mold, 'N1');
        try {
            $this->life->cancelPart($this->npd, $mold, '');
            $this->fail('alasan wajib');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->life->cancelPart($this->drafter, $mold, 'x');
            $this->fail('drafter tidak boleh cancel');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->life->cancelPart($this->npd, $mold, 'Customer batal Body');
        $part = Db::fetch('SELECT * FROM project_parts WHERE id = ?', [$mold]);
        $this->assertSame('cancelled', $part['status']);
        $this->assertSame('Customer batal Body', $part['cancel_reason']);
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM process_runs r JOIN processes p ON p.id = r.process_id WHERE p.part_id = ? AND r.status = 'open'", [$mold]), 'run ditutup');
        $g1 = (int) $this->proc($projectId, null, 'G1')['id'];
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? AND p.part_id = ?', [$g1, $mold]), 'gate tidak menunggu part batal');
        $this->assertNotSame('cancelled', Db::value('SELECT status FROM projects WHERE id = ?', [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE part_id = ? AND revision_type = 'cancel'", [$mold]));
        Clock::freeze('2026-10-26 08:00:00');
        $this->assertSame([], array_values(array_filter((new OverdueService())->overdueProcesses(['project_id' => $projectId]), static fn ($o) => (int) $o['part_id'] === $mold)), 'part batal tidak overdue');

        // proses part batal tidak dapat dikerjakan lagi
        try {
            $this->engine->complete($this->npd, (int) $n1['id'], []);
            $this->fail('part batal');
        } catch (BusinessRuleException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // part terakhir dibatalkan → project Cancelled; gate/Project Finish TIDAK ikut teraktifkan
        $this->life->cancelPart($this->npd, $sub, 'Seluruh project dibatalkan customer');
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertSame('cancelled', $project['status']);
        $this->assertNotNull($project['cancelled_at']);
        $this->assertSame('Seluruh project dibatalkan customer', $project['cancel_reason']);
        $this->assertSame('not_started', $this->proc($projectId, null, 'G1')['status']);
        $this->assertSame('not_started', $this->proc($projectId, null, 'PF')['status']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.cancel'", [$projectId]));
    }

    public function testCancelProjectRejectedWhenAPartIsCompleted(): void
    {
        [$projectId, $mold] = $this->runningProject();
        Db::update('project_parts', ['completed_at' => Clock::nowString()], ['id' => $mold]);
        $this->expectException(BusinessRuleException::class);
        $this->life->cancelProject($this->npd, $projectId, 'Batal');
    }

    public function testCancelProjectAndReopenByAdminOnly(): void
    {
        [$projectId, $mold, $sub] = $this->runningProject();
        $n1 = (int) $this->proc($projectId, $mold, 'N1')['id'];
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Tunggu');
        $this->life->cancelProject($this->npd, $projectId, 'Customer membatalkan produk');

        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertSame('cancelled', $project['status']);
        $this->assertSame(0, (int) $project['is_on_hold']);
        $this->assertSame(['cancelled', 'cancelled'], Db::column('SELECT status FROM project_parts WHERE project_id = ? ORDER BY id', [$projectId]));
        $this->assertNotNull(Db::value('SELECT resumed_at FROM hold_history WHERE project_id = ?', [$projectId]), 'Hold terbuka diakhiri');
        $this->assertSame(0, (int) Db::value("SELECT COUNT(*) FROM process_runs r JOIN processes p ON p.id = r.process_id WHERE p.project_id = ? AND r.status = 'open'", [$projectId]));

        // hanya Admin yang dapat membuka kembali
        try {
            $this->life->reopenProject($this->npd, $projectId, 'Lanjut');
            $this->fail('NPD tidak boleh membuka kembali');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->life->reopenPart($this->admin, $mold, 'Lanjut');
            $this->fail('project harus dibuka dulu');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        Clock::freeze('2026-10-09 10:00:00');
        $this->life->reopenProject($this->admin, $projectId, 'Customer melanjutkan');
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertNull($project['cancelled_at']);
        $this->assertNotSame('cancelled', $project['status']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND cancelled_at IS NOT NULL', [$projectId]));
        $this->assertSame('current', $this->proc($projectId, $mold, 'N1')['status']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM process_runs WHERE process_id = ? AND status = 'open'", [$n1]), 'run KPI baru untuk proses aktif');
        $g1 = (int) $this->proc($projectId, null, 'G1')['id'];
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM process_dependencies d JOIN processes p ON p.id = d.predecessor_id WHERE d.process_id = ? AND p.part_id IS NOT NULL', [$g1]), 'gate kembali menunggu milestone kedua part');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE project_id = ? AND revision_type = 'reopen_cancelled' AND part_id IS NULL", [$projectId]));

        // part yang sudah berjalan: dibatalkan lalu dibuka kembali per part
        $this->life->cancelPart($this->npd, $sub, 'Cap tidak jadi');
        $this->life->reopenPart($this->admin, $sub, 'Cap jadi lagi');
        $this->assertNull(Db::value('SELECT cancelled_at FROM project_parts WHERE id = ?', [$sub]));
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE part_id = ? AND revision_type = 'reopen_cancelled'", [$sub]), 'dibuka bersama project, lalu per part');
    }

    public function testPartCancelledBeforeStartCannotBeReopened(): void
    {
        [, , $projectId] = $this->completedNpr($this->sales, $this->npd, $this->customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'not_feasible']);
        $cap = (int) Db::value("SELECT id FROM project_parts WHERE project_id = ? AND cancelled_at IS NOT NULL", [$projectId]);
        $this->assertGreaterThan(0, $cap);
        $this->assertFalse($this->life->partAbilities($this->admin, Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]), Db::fetch('SELECT * FROM project_parts WHERE id = ?', [$cap]))['reopen']);
        $this->expectException(BusinessRuleException::class);
        $this->life->reopenPart($this->admin, $cap, 'Coba lagi');
    }

    // ------------------------------------------------------------------ UAT-24: Arsip & pulihkan

    public function testArchiveHidesProjectAndRestoreKeepsDataAndAudit(): void
    {
        [$projectId, $mold] = $this->runningProject();
        Clock::freeze('2026-10-07 10:00:00');
        $this->holds->hold($this->npd, $projectId, null, 'Hold lama');
        $docsBefore = (int) Db::value('SELECT COUNT(*) FROM documents WHERE project_id = ?', [$projectId]);
        $auditBefore = (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE project_id = ?', [$projectId]);

        try {
            $this->life->archive($this->npd, $projectId, 'x');
            $this->fail('hanya Admin');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->life->archive($this->admin, $projectId, '');
            $this->fail('alasan wajib');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        Clock::freeze('2026-12-01 10:00:00');
        $this->life->archive($this->admin, $projectId, 'Hold berkepanjangan');
        $q = new ProjectQuery();
        $this->assertNotContains($projectId, array_map(static fn ($r) => (int) $r['id'], $q->list($this->admin, [])['rows']), 'hilang dari daftar bawaan');
        $this->assertContains($projectId, array_map(static fn ($r) => (int) $r['id'], $q->list($this->admin, ['archived' => true])['rows']), 'muncul di filter Arsip');
        $this->assertSame(0, (new HoldService())->sendReminders(), 'project arsip tidak diingatkan');
        // arsip = baca saja
        try {
            $this->engine->complete($this->admin, (int) $this->proc($projectId, $mold, 'N1')['id'], []);
            $this->fail('arsip baca saja');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $this->life->restore($this->admin, $projectId, 'Customer aktif kembali');
        $project = Db::fetch('SELECT * FROM projects WHERE id = ?', [$projectId]);
        $this->assertSame(0, (int) $project['is_archived']);
        $this->assertSame(1, (int) $project['is_on_hold'], 'status Hold tetap (data utuh)');
        $this->assertSame($docsBefore, (int) Db::value('SELECT COUNT(*) FROM documents WHERE project_id = ?', [$projectId]));
        $this->assertSame($auditBefore + 2, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE project_id = ?', [$projectId]), 'audit lama utuh + archive + restore');
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.archive' AND reason = 'Hold berkepanjangan'", [$projectId]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE project_id = ? AND action = 'project.restore'", [$projectId]));
        $this->assertContains($projectId, array_map(static fn ($r) => (int) $r['id'], $q->list($this->admin, [])['rows']));
    }
}
