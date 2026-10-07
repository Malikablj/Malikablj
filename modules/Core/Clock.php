<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Sumber waktu tunggal (Asia/Jakarta). Dapat "dibekukan" untuk test dan simulasi cron:
 * Clock::freeze('2026-10-07 08:00:00').
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function tz(): DateTimeZone
    {
        return new DateTimeZone((string) Config::get('app.timezone', 'Asia/Jakarta'));
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::tz());
    }

    /** Tanggal hari ini (00:00) */
    public static function today(): DateTimeImmutable
    {
        return self::now()->setTime(0, 0);
    }

    public static function todayString(): string
    {
        return self::today()->format('Y-m-d');
    }

    public static function nowString(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function freeze(string|DateTimeImmutable|null $at): void
    {
        if ($at === null) {
            self::$frozen = null;
            return;
        }
        self::$frozen = $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable($at, self::tz());
    }
}
