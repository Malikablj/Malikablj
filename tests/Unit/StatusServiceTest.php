<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Project\StatusService;
use Tests\Support\TestCase;

/** Status turunan part & project (PRD §3.3). */
final class StatusServiceTest extends TestCase
{
    private static function part(array $o = []): array
    {
        return array_merge(['cancelled_at' => null, 'completed_at' => null, 'is_on_hold' => 0, 'start_date' => '2026-10-01'], $o);
    }

    private static function proc(int $approval = 0, int $external = 0): array
    {
        return ['is_customer_approval' => $approval, 'is_external' => $external];
    }

    private static function project(array $o = []): array
    {
        return array_merge(['cancelled_at' => null, 'finished_at' => null, 'is_on_hold' => 0], $o);
    }

    public function testPartStatusRules(): void
    {
        $this->assertSame('not_started', StatusService::partStatus(self::part(['start_date' => null]), []));
        $this->assertSame('on_progress', StatusService::partStatus(self::part(), []));
        $this->assertSame('on_progress', StatusService::partStatus(self::part(), [self::proc()]));
        $this->assertSame('waiting_external', StatusService::partStatus(self::part(), [self::proc(0, 1)]));
        $this->assertSame('waiting_approval', StatusService::partStatus(self::part(), [self::proc(1)]));
        // paralel: yang paling "menunggu" menang
        $this->assertSame('waiting_approval', StatusService::partStatus(self::part(), [self::proc(), self::proc(0, 1), self::proc(1)]));
        $this->assertSame('waiting_external', StatusService::partStatus(self::part(), [self::proc(), self::proc(0, 1)]));
        $this->assertSame('hold', StatusService::partStatus(self::part(['is_on_hold' => 1]), [self::proc(1)]));
        $this->assertSame('completed', StatusService::partStatus(self::part(['completed_at' => '2026-11-01 10:00:00']), []));
        $this->assertSame('cancelled', StatusService::partStatus(self::part(['cancelled_at' => '2026-11-01 10:00:00', 'completed_at' => '2026-11-01']), []));
    }

    public function testProjectStatusRules(): void
    {
        $this->assertSame('on_progress', StatusService::projectStatus(self::project(), []));
        $this->assertSame('on_progress', StatusService::projectStatus(self::project(), [1 => 'on_progress', 2 => 'not_started']));
        $this->assertSame('waiting', StatusService::projectStatus(self::project(), [1 => 'on_progress', 2 => 'waiting_external']));
        $this->assertSame('waiting', StatusService::projectStatus(self::project(), [1 => 'waiting_approval']));
        $this->assertSame('ready_to_finish', StatusService::projectStatus(self::project(), [1 => 'completed', 2 => 'cancelled']));
        $this->assertSame('cancelled', StatusService::projectStatus(self::project(), [1 => 'cancelled', 2 => 'cancelled']));
        // semua part aktif Hold → Hold; part selesai/batal diabaikan
        $this->assertSame('hold', StatusService::projectStatus(self::project(), [1 => 'hold', 2 => 'completed', 3 => 'cancelled']));
        $this->assertSame('waiting', StatusService::projectStatus(self::project(), [1 => 'hold', 2 => 'waiting_approval']));
        $this->assertSame('hold', StatusService::projectStatus(self::project(['is_on_hold' => 1]), [1 => 'on_progress']));
        $this->assertSame('completed', StatusService::projectStatus(self::project(['finished_at' => '2026-12-01 09:00:00']), [1 => 'completed']));
        $this->assertSame('cancelled', StatusService::projectStatus(self::project(['cancelled_at' => '2026-12-01 09:00:00']), [1 => 'on_progress']));
    }
}
