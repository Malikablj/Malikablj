<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Target Finish, baseline berversi, risiko target (PRD §6.4–6.5, UAT-11/12). */
final class ScheduleServiceTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private int $projectId;
    private ScheduleService $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        [, , $this->projectId] = $this->completedNpr($this->sales, $this->npd, $this->makeCustomer(), [['body', 'new_mold']], ['feasible']);
        $this->schedule = new ScheduleService();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }

    public function testChangeTargetIsRecordedAndDoesNotTouchBaseline(): void
    {
        $baselines = (int) Db::value('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ?', [$this->projectId]);
        $old = Db::value('SELECT target_finish FROM projects WHERE id = ?', [$this->projectId]);
        $this->schedule->changeTarget($this->npd, $this->projectId, '2027-04-30', 'Customer menyetujui mundur');
        $this->assertSame('2027-04-30', Db::value('SELECT target_finish FROM projects WHERE id = ?', [$this->projectId]));
        $this->assertSame($baselines, (int) Db::value('SELECT COUNT(*) FROM schedule_baselines WHERE project_id = ?', [$this->projectId]));
        $row = Db::fetch("SELECT * FROM schedule_changes WHERE project_id = ? AND change_type = 'target_change'", [$this->projectId]);
        $this->assertSame([$old, '2027-04-30', 'Customer menyetujui mundur'], [$row['old_finish'], $row['new_finish'], $row['reason']]);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'project.target_change' AND project_id = ?", [$this->projectId]));

        try {
            $this->schedule->changeTarget($this->npd, $this->projectId, '2027-05-01', ' ');
            $this->fail('alasan wajib');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->expectException(AuthorizationException::class);
        $this->schedule->changeTarget($this->sales, $this->projectId, '2027-05-01', 'x');
    }

    public function testNewBaselineVersionDeactivatesPrevious(): void
    {
        $id = $this->schedule->setBaseline($this->npd, $this->projectId, null, 'Re-plan setelah kickoff');
        $this->assertSame(1, (int) Db::value('SELECT version_no FROM schedule_baselines WHERE id = ?', [$id]));
        $id2 = $this->schedule->setBaseline($this->npd, $this->projectId, null, 'Re-plan kedua');
        $this->assertSame(2, (int) Db::value('SELECT version_no FROM schedule_baselines WHERE id = ?', [$id2]));
        $this->assertSame(0, (int) Db::value('SELECT is_active FROM schedule_baselines WHERE id = ?', [$id]));
        $count = (int) Db::value('SELECT COUNT(*) FROM processes WHERE project_id = ?', [$this->projectId]);
        $this->assertSame($count, (int) Db::value('SELECT COUNT(*) FROM schedule_baseline_items WHERE baseline_id = ?', [$id2]));
        $this->expectException(AuthorizationException::class);
        $this->schedule->setBaseline($this->sales, $this->projectId, null, 'x');
    }

    public function testForecastPastTargetNotifiesNpdAndAdmin(): void
    {
        $admin = $this->makeUser('admin');
        Db::update('projects', ['target_finish' => '2026-10-30'], ['id' => $this->projectId]);
        $r = $this->schedule->recalculate($this->projectId, 'auto_shift');
        $this->assertGreaterThan('2026-10-30', $r['project_forecast']);
        foreach ([$this->npd->id, $admin->id] as $uid) {
            $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$uid, 'target_at_risk']));
        }
        // dedupe: hitung ulang tanpa perubahan tidak mengirim ulang
        $this->schedule->recalculate($this->projectId, 'auto_shift');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$this->npd->id, 'target_at_risk']));
    }

    public function testRecalculateIsIdempotent(): void
    {
        $r = $this->schedule->recalculate($this->projectId, 'auto_shift');
        $this->assertSame([], $r['changes']);
    }
}
