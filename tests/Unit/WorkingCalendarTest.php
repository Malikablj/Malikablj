<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Scheduling\WorkingCalendar;
use Tests\Support\TestCase;

/** FR-SCH-02 / SCH-11: hari kerja Senin–Jumat + hari libur Admin. */
final class WorkingCalendarTest extends TestCase
{
    private WorkingCalendar $cal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cal = new WorkingCalendar();
    }

    public function testWeekendsAreNotWorkingDays(): void
    {
        $this->assertTrue($this->cal->isWorkingDay('2026-10-02'));  // Jumat
        $this->assertFalse($this->cal->isWorkingDay('2026-10-03')); // Sabtu
        $this->assertFalse($this->cal->isWorkingDay('2026-10-04')); // Minggu
        $this->assertTrue($this->cal->isWorkingDay('2026-10-05'));  // Senin
    }

    public function testAddWorkingDaysSkipsWeekend(): void
    {
        $this->assertSame('2026-10-01', $this->cal->addWorkingDays('2026-09-30', 1)); // Rabu → Kamis
        $this->assertSame('2026-10-05', $this->cal->addWorkingDays('2026-10-02', 1)); // Jumat → Senin
        $this->assertSame('2026-10-12', $this->cal->addWorkingDays('2026-10-02', 6));
        $this->assertSame('2026-10-02', $this->cal->addWorkingDays('2026-10-05', -1)); // mundur
        $this->assertSame('2026-10-05', $this->cal->addWorkingDays('2026-10-03', 1)); // dari Sabtu
        $this->assertSame('2026-10-02', $this->cal->addWorkingDays('2026-10-03', -1));
        $this->assertSame('2026-10-03', $this->cal->addWorkingDays('2026-10-03', 0));
    }

    public function testFinishIsStartPlusDurationMinusOneWorkingDay(): void
    {
        // Bukan start + durasi kalender: 3 hari kerja mulai Kamis 1 Okt → Senin 5 Okt
        $this->assertSame('2026-10-05', $this->cal->finishFromStart('2026-10-01', 3));
        $this->assertSame('2026-10-01', $this->cal->finishFromStart('2026-10-01', 1));
        $this->assertSame('2026-10-01', $this->cal->startFromFinish('2026-10-05', 3));
        // durasi 30 hk (Mold Machining) = 6 minggu kalender
        $this->assertSame('2026-11-11', $this->cal->finishFromStart('2026-10-01', 30));
    }

    public function testCountAndDeviation(): void
    {
        $this->assertSame(5, $this->cal->countWorkingDays('2026-10-01', '2026-10-07'));
        $this->assertSame(0, $this->cal->countWorkingDays('2026-10-07', '2026-10-01'));
        $this->assertSame(2, $this->cal->deviation('2026-10-05', '2026-10-07'));
        $this->assertSame(-2, $this->cal->deviation('2026-10-07', '2026-10-05'));
        $this->assertSame(0, $this->cal->deviation('2026-10-02', '2026-10-03')); // Jumat → Sabtu: 0 hari kerja
        $this->assertSame(1, $this->cal->deviation('2026-10-02', '2026-10-05'));
    }

    public function testHolidaysAndRecurringHolidays(): void
    {
        $cal = new WorkingCalendar([], [
            ['date' => '2026-10-06', 'recurring' => false],           // libur sekali
            ['date' => '2020-08-17', 'recurring' => true],            // 17 Agustus setiap tahun
        ]);
        $this->assertFalse($cal->isWorkingDay('2026-10-06'));
        $this->assertTrue($cal->isWorkingDay('2027-10-06'));
        $this->assertFalse($cal->isWorkingDay('2026-08-17'));
        $this->assertFalse($cal->isWorkingDay('2028-08-17'));
        $this->assertSame('2026-10-07', $cal->addWorkingDays('2026-10-05', 1));
        $this->assertSame('2026-10-07', $cal->finishFromStart('2026-10-05', 2));
        $this->assertSame(4, $cal->countWorkingDays('2026-10-05', '2026-10-09'));
    }

    public function testNextAndPrevWorkingDay(): void
    {
        $this->assertSame('2026-10-05', $this->cal->nextWorkingDay('2026-10-03'));
        $this->assertSame('2026-10-05', $this->cal->nextWorkingDay('2026-10-05'));
        $this->assertSame('2026-10-06', $this->cal->nextWorkingDay('2026-10-05', false));
        $this->assertSame('2026-10-02', $this->cal->prevWorkingDay('2026-10-04'));
    }

    public function testCustomWorkingWeekAndSafetyFallback(): void
    {
        $sixDays = new WorkingCalendar([1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => false]);
        $this->assertTrue($sixDays->isWorkingDay('2026-10-03'));
        $none = new WorkingCalendar([1 => false, 2 => false, 3 => false, 4 => false, 5 => false, 6 => false, 7 => false]);
        $this->assertTrue($none->isWorkingDay('2026-10-05'), 'tanpa hari kerja → fallback Senin–Jumat');
    }

    public function testAcrossYearBoundary(): void
    {
        $cal = new WorkingCalendar([], [['date' => '2027-01-01', 'recurring' => false]]);
        $this->assertSame('2027-01-04', $cal->addWorkingDays('2026-12-31', 1));
    }
}
