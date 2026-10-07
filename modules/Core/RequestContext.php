<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Konteks request saat ini (pengguna, IP, user agent) untuk audit log dan service.
 * Di CLI/cron, user = null dan ip = 'cli'.
 */
final class RequestContext
{
    private static ?User $user = null;
    private static string $ip = 'cli';
    private static string $userAgent = 'cli';

    public static function set(?User $user, ?string $ip = null, ?string $userAgent = null): void
    {
        self::$user = $user;
        if ($ip !== null) {
            self::$ip = $ip;
        }
        if ($userAgent !== null) {
            self::$userAgent = mb_substr($userAgent, 0, 255);
        }
    }

    public static function user(): ?User
    {
        return self::$user;
    }

    public static function ip(): string
    {
        return self::$ip;
    }

    public static function userAgent(): string
    {
        return self::$userAgent;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$ip = 'cli';
        self::$userAgent = 'cli';
    }
}
