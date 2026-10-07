<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\AuditLogger;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Db;
use App\Core\User;

/**
 * Pengiriman antrean email (cron/notifications.php): ambil antrean yang jatuh tempo dengan penguncian
 * baris (SKIP LOCKED), kirim, catat hasil. Gagal → percobaan ulang dengan jeda 5m/15m/1j/4j, maks. 5 kali,
 * lalu status "failed" yang terlihat Admin (PRD §7.3). Tidak pernah memengaruhi transaksi bisnis.
 */
final class MailQueue
{
    public function __construct(private MailTransport $transport = new SmtpTransport())
    {
    }

    /** @return array{sent:int,failed:int,retry:int} */
    public function deliverDue(int $limit = 50): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        $now = Clock::nowString();
        // pemulihan: status "sending" yang tertinggal (proses cron terhenti) dikembalikan ke antrean
        Db::execute("UPDATE notification_deliveries SET status = 'pending' WHERE status = 'sending' AND updated_at < ?", [Clock::now()->modify('-15 minutes')->format('Y-m-d H:i:s')]);
        $batch = Db::transaction(static function () use ($limit, $now): array {
            $rows = Db::fetchAll(
                "SELECT d.*, u.name AS user_name FROM notification_deliveries d LEFT JOIN users u ON u.id = d.user_id
                 WHERE d.status = 'pending' AND d.next_attempt_at <= ? ORDER BY d.next_attempt_at, d.id LIMIT " . max(1, min(500, $limit)) . ' FOR UPDATE SKIP LOCKED',
                [$now]
            );
            foreach ($rows as $r) {
                Db::update('notification_deliveries', ['status' => 'sending'], ['id' => (int) $r['id']]);
            }
            return $rows;
        });
        $backoff = (array) Config::get('mail.retry_backoff_minutes', [5, 15, 60, 240]);
        foreach ($batch as $r) {
            $attempt = (int) $r['attempts'] + 1;
            try {
                $this->transport->send((string) $r['to_email'], (string) ($r['user_name'] ?? ''), (string) $r['subject'], (string) $r['body_html'], (string) ($r['body_text'] ?? ''));
                Db::update('notification_deliveries', ['status' => 'sent', 'attempts' => $attempt, 'sent_at' => Clock::nowString(), 'last_error' => null], ['id' => (int) $r['id']]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 1000);
                if ($attempt >= (int) $r['max_attempts']) {
                    Db::update('notification_deliveries', ['status' => 'failed', 'attempts' => $attempt, 'last_error' => $error], ['id' => (int) $r['id']]);
                    $stats['failed']++;
                } else {
                    $wait = (int) ($backoff[$attempt - 1] ?? end($backoff) ?: 60);
                    Db::update('notification_deliveries', [
                        'status' => 'pending', 'attempts' => $attempt, 'last_error' => $error,
                        'next_attempt_at' => Clock::now()->modify('+' . $wait . ' minutes')->format('Y-m-d H:i:s'),
                    ], ['id' => (int) $r['id']]);
                    $stats['retry']++;
                }
            }
        }
        return $stats;
    }

    /** Admin: kirim ulang (reset percobaan) atau batalkan. */
    public function retry(User $actor, int $id): void
    {
        Db::execute("UPDATE notification_deliveries SET status = 'pending', attempts = 0, next_attempt_at = ?, last_error = NULL WHERE id = ? AND status IN ('failed', 'cancelled')", [Clock::nowString(), $id]);
        AuditLogger::log('email.retry', 'notification_delivery', $id, null, ['status' => 'pending'], null, null, $actor);
    }

    public function cancel(User $actor, int $id): void
    {
        Db::execute("UPDATE notification_deliveries SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$id]);
        AuditLogger::log('email.cancel', 'notification_delivery', $id, null, ['status' => 'cancelled'], null, null, $actor);
    }

    /** @return array<string,int> jumlah per status */
    public function counts(): array
    {
        $out = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach (Db::fetchAll('SELECT status, COUNT(*) AS c FROM notification_deliveries GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }
}
