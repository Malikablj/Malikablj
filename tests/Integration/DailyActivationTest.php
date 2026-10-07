<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Db;
use App\Cron\JobRunner;
use App\Scheduling\WorkingCalendar;
use App\Workflow\DailyActivation;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Cron harian: aktivasi pada Planned Start (OQ-10) & pencatatan job_runs. */
final class DailyActivationTest extends DbTestCase
{
    use NprFixtures;

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        parent::tearDown();
    }

    public function testActivatesProcessesWhenPlannedStartArrives(): void
    {
        Clock::freeze('2026-10-02 15:00:00'); // Jumat
        $sales = $this->makeUser('admin_sales');
        $npd = $this->makeUser('npd_staff');
        [, , $projectId] = $this->completedNpr($sales, $npd, $this->makeCustomer(), [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        $active = static fn (): int => (int) Db::value("SELECT COUNT(*) FROM processes WHERE project_id = ? AND part_id IS NOT NULL AND status = 'current'", [$projectId]);
        $this->assertSame(0, $active());

        Clock::freeze('2026-10-03 07:00:00'); // Sabtu: belum hari kerja
        (new DailyActivation())->run();
        $this->assertSame(0, $active());

        Clock::freeze('2026-10-05 06:00:00'); // Senin = Planned Start
        $r = (new DailyActivation())->run();
        $this->assertSame(3, $r['activated'], 'N1, N3, S1');
        $this->assertSame(3, $active());
        $this->assertSame([], $r['failed']);
        // idempoten
        $this->assertSame(0, (new DailyActivation())->run()['activated']);
    }

    public function testJobRunnerRecordsSuccessAndFailure(): void
    {
        $this->assertSame(0, JobRunner::run('test_ok', static fn () => 'beres', false));
        $this->assertSame(1, JobRunner::run('test_fail', static function (): string {
            throw new \RuntimeException('rusak');
        }, false));
        $this->assertSame('success', Db::value("SELECT status FROM job_runs WHERE job = 'test_ok' ORDER BY id DESC LIMIT 1"));
        $row = Db::fetch("SELECT status, message FROM job_runs WHERE job = 'test_fail' ORDER BY id DESC LIMIT 1");
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('rusak', $row['message']);
    }
}
