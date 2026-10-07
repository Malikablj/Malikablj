<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Pembatasan percobaan login (PRD §2.4, §13.2).
 *  - per email+IP: kunci sementara setelah N gagal dalam jendela waktu (bawaan 5 gagal / 15 menit)
 *  - per IP: batas 4×N gagal lintas email (mencegah penebakan massal)
 * Login berhasil menghapus hitungan gagal untuk email+IP tersebut (secara logika: hanya
 * gagal setelah sukses terakhir yang dihitung).
 */
final class LoginThrottle
{
    public static function maxAttempts(): int
    {
        return max(3, Settings::int('security.login_max_attempts', 5));
    }

    public static function lockoutMinutes(): int
    {
        return max(1, Settings::int('security.login_lockout_minutes', 15));
    }

    /** Sisa detik penguncian (0 = tidak terkunci). */
    public static function lockedSeconds(string $email, string $ip): int
    {
        $email = self::normalize($email);
        $window = self::lockoutMinutes();
        $since = Clock::now()->modify("-{$window} minutes")->format('Y-m-d H:i:s');

        $lastSuccess = Db::value(
            'SELECT MAX(attempted_at) FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 1',
            [$email, $ip]
        );
        $from = ($lastSuccess !== null && $lastSuccess > $since) ? $lastSuccess : $since;

        $row = Db::fetch(
            'SELECT COUNT(*) AS c, MAX(attempted_at) AS last_at FROM login_attempts
             WHERE email = ? AND ip_address = ? AND success = 0 AND attempted_at > ?',
            [$email, $ip, $from]
        );
        $seconds = 0;
        if ($row && (int) $row['c'] >= self::maxAttempts()) {
            $seconds = self::remaining((string) $row['last_at'], $window);
        }

        $ipRow = Db::fetch(
            'SELECT COUNT(*) AS c, MAX(attempted_at) AS last_at FROM login_attempts
             WHERE ip_address = ? AND success = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        if ($ipRow && (int) $ipRow['c'] >= self::maxAttempts() * 4) {
            $seconds = max($seconds, self::remaining((string) $ipRow['last_at'], $window));
        }
        return $seconds;
    }

    public static function hit(string $email, string $ip, bool $success): void
    {
        Db::insert('login_attempts', [
            'email' => self::normalize($email),
            'ip_address' => mb_substr($ip, 0, 45),
            'success' => $success ? 1 : 0,
            'attempted_at' => Clock::nowString(),
        ]);
    }

    /** Bersihkan riwayat lama (dipanggil cron). */
    public static function prune(int $days = 30): int
    {
        return Db::execute('DELETE FROM login_attempts WHERE attempted_at < ?', [Clock::now()->modify("-{$days} days")->format('Y-m-d H:i:s')]);
    }

    private static function remaining(string $lastAt, int $windowMinutes): int
    {
        $unlock = (new \DateTimeImmutable($lastAt, Clock::tz()))->modify("+{$windowMinutes} minutes");
        return max(0, $unlock->getTimestamp() - Clock::now()->getTimestamp());
    }

    private static function normalize(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 190);
    }
}
