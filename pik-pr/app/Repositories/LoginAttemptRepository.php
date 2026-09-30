<?php

declare(strict_types=1);

namespace App\Repositories;

final class LoginAttemptRepository extends Repository
{
    public function recentFailures(string $email, string $ip, int $minutes): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM login_attempts
            WHERE email = ? AND ip_address = ? AND attempted_at >= (NOW() - INTERVAL ? MINUTE)',
            [mb_strtolower($email), $ip, $minutes],
        );
    }

    public function record(string $email, string $ip): void
    {
        $this->run('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)', [mb_strtolower(mb_substr($email, 0, 190)), $ip]);
    }

    public function clear(string $email, string $ip): void
    {
        $this->run('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?', [mb_strtolower($email), $ip]);
    }

    public function prune(int $olderThanMinutes): void
    {
        $this->run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? MINUTE)', [$olderThanMinutes]);
    }
}
