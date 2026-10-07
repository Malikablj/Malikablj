<?php
declare(strict_types=1);

namespace App\Scheduling;

/**
 * Overdue & Due Soon dihitung dalam HARI KERJA (PRD §7.1, OQ-11) — tidak disimpan sebagai status.
 * Proses overdue mulai hari kerja pertama setelah Planned Finish (Sabtu/Minggu belum terlambat).
 */
final class Lateness
{
    /** Hari kerja keterlambatan proses aktif (0 = tidak terlambat). */
    public static function overdueDays(WorkingCalendar $cal, ?string $plannedFinish, string $today): int
    {
        if ($plannedFinish === null || $plannedFinish === '' || $today <= $plannedFinish) {
            return 0;
        }
        return $cal->countWorkingDays(WorkingCalendar::shift($plannedFinish, 1), $today);
    }

    /** Planned Finish jatuh dalam N hari kerja ke depan (termasuk hari ini) dan belum lewat. */
    public static function isDueSoon(WorkingCalendar $cal, ?string $plannedFinish, string $today, int $days): bool
    {
        if ($plannedFinish === null || $plannedFinish === '' || $plannedFinish < $today) {
            return false;
        }
        return $cal->countWorkingDays($today, $plannedFinish) <= max(0, $days);
    }

    /** Hari sejak (tanggal mulai overdue) — hari kerja pertama setelah Planned Finish. */
    public static function overdueSince(WorkingCalendar $cal, string $plannedFinish): string
    {
        return $cal->nextWorkingDay(WorkingCalendar::shift($plannedFinish, 1));
    }
}
