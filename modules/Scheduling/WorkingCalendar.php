<?php
declare(strict_types=1);

namespace App\Scheduling;

use App\Core\Db;

/**
 * Kalender kerja (PRD §6.2): hari kerja per hari ISO (bawaan Senin–Jumat) dan hari libur Admin
 * (termasuk libur berulang tahunan). Semua tanggal berupa string 'Y-m-d'.
 *
 * Definisi:
 *  - addWorkingDays(d, n): maju (n>0) / mundur (n<0) tepat n hari kerja dari d; d tidak dihitung; n=0 → d.
 *  - countWorkingDays(a, b): jumlah hari kerja di [a, b] inklusif (0 bila b < a).
 *  - finishFromStart(s, dur) = addWorkingDays(s, dur − 1)    (Finish = Start + durasi − 1 hari kerja)
 */
final class WorkingCalendar
{
    /** @var array<int,bool> 1..7 */
    private array $weekdays;
    /** @var array<string,true> 'Y-m-d' */
    private array $dates = [];
    /** @var array<string,true> 'm-d' (berulang) */
    private array $recurring = [];
    /** @var array<string,bool> */
    private array $cache = [];
    private static ?self $shared = null;

    /**
     * @param array<int,bool> $weekdays ISO weekday => hari kerja?
     * @param list<array{date:string,recurring?:bool}> $holidays
     */
    public function __construct(array $weekdays = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false, 7 => false], array $holidays = [])
    {
        $this->weekdays = [];
        for ($d = 1; $d <= 7; $d++) {
            $this->weekdays[$d] = (bool) ($weekdays[$d] ?? ($d <= 5));
        }
        if (!in_array(true, $this->weekdays, true)) {
            // pengaman: konfigurasi tanpa hari kerja → bawaan Senin–Jumat
            $this->weekdays = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => false, 7 => false];
        }
        foreach ($holidays as $h) {
            if (!empty($h['recurring'])) {
                $this->recurring[substr($h['date'], 5)] = true;
            } else {
                $this->dates[$h['date']] = true;
            }
        }
    }

    /** Kalender dari database (di-cache per request; panggil flush() setelah perubahan). */
    public static function fromDb(): self
    {
        if (self::$shared === null) {
            $weekdays = [];
            foreach (Db::fetchAll('SELECT weekday, is_working FROM working_calendar') as $r) {
                $weekdays[(int) $r['weekday']] = (bool) $r['is_working'];
            }
            $holidays = array_map(
                static fn ($r) => ['date' => (string) $r['holiday_date'], 'recurring' => (bool) $r['is_recurring']],
                Db::fetchAll('SELECT holiday_date, is_recurring FROM holidays')
            );
            self::$shared = new self($weekdays ?: [1 => true, 2 => true, 3 => true, 4 => true, 5 => true], $holidays);
        }
        return self::$shared;
    }

    public static function flush(): void
    {
        self::$shared = null;
    }

    public function isHoliday(string $date): bool
    {
        return isset($this->dates[$date]) || isset($this->recurring[substr($date, 5)]);
    }

    public function isWorkingDay(string $date): bool
    {
        if (!isset($this->cache[$date])) {
            $w = (int) date('N', strtotime($date . ' 12:00:00'));
            $this->cache[$date] = $this->weekdays[$w] && !$this->isHoliday($date);
        }
        return $this->cache[$date];
    }

    /** Hari kerja pertama ≥ date (inklusif) atau > date. */
    public function nextWorkingDay(string $date, bool $inclusive = true): string
    {
        $d = $inclusive ? $date : self::shift($date, 1);
        $guard = 0;
        while (!$this->isWorkingDay($d)) {
            $d = self::shift($d, 1);
            if (++$guard > 3660) {
                throw new \RuntimeException('Kalender kerja tidak memiliki hari kerja');
            }
        }
        return $d;
    }

    /** Hari kerja terakhir ≤ date (inklusif) atau < date. */
    public function prevWorkingDay(string $date, bool $inclusive = true): string
    {
        $d = $inclusive ? $date : self::shift($date, -1);
        $guard = 0;
        while (!$this->isWorkingDay($d)) {
            $d = self::shift($d, -1);
            if (++$guard > 3660) {
                throw new \RuntimeException('Kalender kerja tidak memiliki hari kerja');
            }
        }
        return $d;
    }

    public function addWorkingDays(string $date, int $n): string
    {
        $d = $date;
        $step = $n >= 0 ? 1 : -1;
        $left = abs($n);
        $guard = 0;
        while ($left > 0) {
            $d = self::shift($d, $step);
            if ($this->isWorkingDay($d)) {
                $left--;
            }
            if (++$guard > 36600) {
                throw new \RuntimeException('Kalender kerja tidak memiliki hari kerja');
            }
        }
        return $d;
    }

    public function countWorkingDays(string $from, string $to): int
    {
        if ($to < $from) {
            return 0;
        }
        $n = 0;
        for ($d = $from; $d <= $to; $d = self::shift($d, 1)) {
            if ($this->isWorkingDay($d)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Selisih bertanda dalam hari kerja: + bila $actual lebih lambat dari $planned, − bila lebih awal.
     * Contoh: planned Senin, actual Rabu → +2.
     */
    public function deviation(string $planned, string $actual): int
    {
        if ($actual === $planned) {
            return 0;
        }
        return $actual > $planned
            ? $this->countWorkingDays(self::shift($planned, 1), $actual)
            : -$this->countWorkingDays(self::shift($actual, 1), $planned);
    }

    public function finishFromStart(string $start, int $duration): string
    {
        return $this->addWorkingDays($start, max(1, $duration) - 1);
    }

    public function startFromFinish(string $finish, int $duration): string
    {
        return $this->addWorkingDays($finish, -(max(1, $duration) - 1));
    }

    /** Hari kerja yang dipakai dari $start sampai $end inklusif (untuk sisa durasi saat Hold). */
    public function usedWorkingDays(string $start, string $end): int
    {
        return $this->countWorkingDays($start, $end);
    }

    public static function shift(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' 12:00:00 ' . ($days >= 0 ? '+' : '') . $days . ' day'));
    }
}
