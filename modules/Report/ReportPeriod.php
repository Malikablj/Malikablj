<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\I18n;

/**
 * Periode laporan (PRD §10.2): Mingguan (Senin–Minggu), Bulanan, atau rentang tanggal bebas.
 * Input tidak valid jatuh ke minggu berjalan (tidak pernah melempar error ke pengguna).
 */
final class ReportPeriod
{
    public const TYPES = ['week', 'month', 'range'];
    private const MAX_RANGE_DAYS = 731;

    private function __construct(
        public readonly string $type,
        public readonly string $from,
        public readonly string $to,
        public readonly string $week,
        public readonly string $month,
    ) {
    }

    /** @param array<string,mixed> $q period, week (YYYY-Www), month (YYYY-MM), from, to */
    public static function fromInput(array $q, string $today): self
    {
        $type = in_array($q['period'] ?? null, self::TYPES, true) ? (string) $q['period'] : 'week';
        $todayD = new \DateTimeImmutable($today);
        if ($type === 'month') {
            $m = (string) ($q['month'] ?? '');
            $d = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? new \DateTimeImmutable($m . '-01') : $todayD->modify('first day of this month');
            return self::make('month', $d->format('Y-m-01'), $d->format('Y-m-t'));
        }
        if ($type === 'range') {
            $from = self::date((string) ($q['from'] ?? ''));
            $to = self::date((string) ($q['to'] ?? ''));
            if ($from !== null && $to !== null) {
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                $max = (new \DateTimeImmutable($from))->modify('+' . self::MAX_RANGE_DAYS . ' days')->format('Y-m-d');
                return self::make('range', $from, min($to, $max));
            }
            $type = 'week';
        }
        $w = (string) ($q['week'] ?? '');
        if (preg_match('/^(\d{4})-W(\d{2})$/', $w, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 53) {
            $monday = (new \DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2], 1);
        } else {
            $monday = $todayD->modify('-' . ((int) $todayD->format('N') - 1) . ' days');
        }
        return self::make('week', $monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d'));
    }

    private static function make(string $type, string $from, string $to): self
    {
        $f = new \DateTimeImmutable($from);
        return new self($type, $from, $to, $f->format('o-\WW'), $f->format('Y-m'));
    }

    private static function date(string $v): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }

    public function label(): string
    {
        return match ($this->type) {
            'week' => I18n::t('report.period_week_label', ['week' => (int) substr($this->week, 6), 'from' => I18n::date($this->from), 'to' => I18n::date($this->to)]),
            'month' => I18n::t('report.month.' . (int) substr($this->month, 5)) . ' ' . substr($this->month, 0, 4),
            default => I18n::date($this->from) . ' – ' . I18n::date($this->to),
        };
    }

    /** Parameter URL untuk tautan/export. @return array<string,string> */
    public function query(): array
    {
        return match ($this->type) {
            'week' => ['period' => 'week', 'week' => $this->week],
            'month' => ['period' => 'month', 'month' => $this->month],
            default => ['period' => 'range', 'from' => $this->from, 'to' => $this->to],
        };
    }

    /** Periode sebelumnya/berikutnya (navigasi). */
    public function shift(int $dir): self
    {
        $f = new \DateTimeImmutable($this->from);
        return match ($this->type) {
            'week' => self::make('week', $f->modify(($dir * 7) . ' days')->format('Y-m-d'), $f->modify(($dir * 7 + 6) . ' days')->format('Y-m-d')),
            'month' => (static function () use ($f, $dir): self {
                $m = $f->modify('first day of this month')->modify(($dir > 0 ? '+' : '') . $dir . ' month');
                return self::make('month', $m->format('Y-m-01'), $m->format('Y-m-t'));
            })(),
            default => (function () use ($dir): self {
                $days = (int) (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days + 1;
                $f = (new \DateTimeImmutable($this->from))->modify(($dir * $days) . ' days');
                return self::make('range', $f->format('Y-m-d'), $f->modify(($days - 1) . ' days')->format('Y-m-d'));
            })(),
        };
    }

    /** Bulan-bulan (YYYY-MM) untuk grafik tren: $n bulan berakhir pada bulan akhir periode. @return list<string> */
    public function trendMonths(int $n = 6): array
    {
        $end = (new \DateTimeImmutable($this->to))->modify('first day of this month');
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $out[] = $end->modify('-' . $i . ' month')->format('Y-m');
        }
        return $out;
    }
}
