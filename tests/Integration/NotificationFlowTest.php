<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Settings;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\DailyDigest;
use App\Notification\MailQueue;
use App\Notification\MailTransport;
use App\Notification\NotificationCenter;
use App\Notification\Notifier;
use App\Notification\OverdueService;
use App\Project\NextActionService;
use App\Scheduling\WorkingCalendar;
use App\Settings\NotificationSettings;
use App\Workflow\WorkflowEngine;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Overdue (hari kerja, Hold), pemindaian cron + dedupe, ringkasan harian, antrean email, pusat notifikasi (PRD §7). */
final class NotificationFlowTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private User $drafter;
    private int $projectId;
    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        Settings::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        $this->drafter = $this->makeUser('drafter');
        [, , $this->projectId] = $this->completedNpr($this->sales, $this->npd, $this->makeCustomer(), [['cap', 'subcont']], ['feasible']);
        $this->sub = (int) Db::value('SELECT id FROM project_parts WHERE project_id = ?', [$this->projectId]);
        Db::execute("UPDATE processes SET pic_user_id = ? WHERE part_id = ? AND code = 'S1'", [$this->drafter->id, $this->sub]);
        Clock::freeze('2026-10-06 07:00:00');
        (new WorkflowEngine())->activateReady($this->projectId); // S1 aktif 6 Okt, rencana selesai 12 Okt (5 hk)
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        WorkingCalendar::flush();
        Settings::flush();
        parent::tearDown();
    }

    private function s1(): int
    {
        return (int) Db::value("SELECT id FROM processes WHERE part_id = ? AND code = 'S1'", [$this->sub]);
    }

    private function enableMail(): void
    {
        Settings::set('mail.enabled', '1');
        Settings::set('mail.types_enabled', json_encode(array_fill_keys(Notifier::EMAIL_TYPES, true)));
        Db::update('users', ['email' => 'drafter@example.com'], ['id' => $this->drafter->id]);
    }

    public function testOverdueCountsWorkingDaysAndExcludesHold(): void
    {
        $svc = new OverdueService();
        $this->assertSame([], $svc->overdueProcesses([], '2026-10-12'), 'hari Planned Finish belum overdue');
        $list = $svc->overdueProcesses([], '2026-10-13');
        $this->assertCount(1, $list);
        $this->assertSame([1, '2026-10-13'], [$list[0]['overdue_days'], $list[0]['overdue_since']]);
        $this->assertSame('internal', $list[0]['waiting']);
        // Sabtu/Minggu tidak menambah keterlambatan
        $this->assertSame(4, $svc->overdueProcesses([], '2026-10-17')[0]['overdue_days']);
        $this->assertSame(4, $svc->overdueProcesses([], '2026-10-18')[0]['overdue_days']);
        $this->assertSame(5, $svc->overdueProcesses([], '2026-10-19')[0]['overdue_days']);
        // Hold project / part → tidak dihitung
        Db::update('project_parts', ['is_on_hold' => 1], ['id' => $this->sub]);
        $this->assertSame([], $svc->overdueProcesses([], '2026-10-19'));
        Db::update('project_parts', ['is_on_hold' => 0], ['id' => $this->sub]);
        Db::update('projects', ['is_on_hold' => 1], ['id' => $this->projectId]);
        $this->assertSame([], $svc->overdueProcesses([], '2026-10-19'));
    }

    public function testScanSendsFirstDayOverdueOnceWithEmail(): void
    {
        $this->enableMail();
        Clock::freeze('2026-10-13 07:00:00');
        $stats = (new OverdueService())->scan();
        $this->assertSame(2, $stats['overdue_first'], 'PIC + NPD PIC');
        $this->assertSame('2026-10-13', Db::value("SELECT overdue_since FROM process_runs WHERE process_id = ? AND status = 'open'", [$this->s1()]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'project_overdue'", [$this->drafter->id]));
        $mail = Db::fetch("SELECT * FROM notification_deliveries WHERE user_id = ?", [$this->drafter->id]);
        $this->assertSame('pending', $mail['status']);
        $this->assertStringContainsString('S1', $mail['subject']);
        $this->assertStringContainsString('1 hari kerja', $mail['body_text']);
        // dedupe: pemindaian berikutnya tidak mengirim ulang
        Clock::freeze('2026-10-13 15:00:00');
        $this->assertSame(0, (new OverdueService())->scan()['overdue_first']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ?', [$this->drafter->id]));
    }

    public function testDueSoonNextActionAndNoUpdateScans(): void
    {
        Clock::freeze('2026-10-08 07:00:00'); // S1 selesai 12 Okt: tinggal 3 hk (8, 9, 12)
        Settings::set('notify.due_soon_days', '3');
        $stats = (new OverdueService())->scan();
        $this->assertSame(1, $stats['due_soon']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'deadline_approaching'", [$this->drafter->id]));
        (new NextActionService())->set($this->npd, $this->projectId, $this->sub, ['description' => 'Kirim artwork', 'due_date' => '2026-10-07', 'owner_user_id' => (string) $this->sales->id, 'waiting_for' => 'customer']);
        $stats = (new OverdueService())->scan();
        $this->assertGreaterThanOrEqual(1, $stats['next_action']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'next_action_overdue'", [$this->sales->id]));
        // tidak ada aktivitas 7 hari → NPD PIC
        Db::update('projects', ['last_activity_at' => '2026-09-25 10:00:00'], ['id' => $this->projectId]);
        Settings::set('notify.no_update_days', '7');
        $this->assertSame(1, (new OverdueService())->scan()['no_update']);
        $this->assertSame(0, (new OverdueService())->scan()['no_update'], 'dedupe');
    }

    public function testDailyDigestOnePerRecipientWorkingDaysOnly(): void
    {
        $this->enableMail();
        $digest = new DailyDigest();
        $this->assertSame(0, $digest->run('2026-10-13')['items'], 'hari pertama dikirim terpisah');
        $this->assertTrue($digest->run('2026-10-17')['skipped'], 'Sabtu dilewati');
        $r = $digest->run('2026-10-14');
        $this->assertSame([2, 1], [$r['recipients'], $r['items']]);
        $n = Db::fetch("SELECT * FROM notifications WHERE user_id = ? AND dedupe_key = 'overdue_daily:" . $this->drafter->id . ":2026-10-14'", [$this->drafter->id]);
        $this->assertStringContainsString('S1', (string) $n['body']);
        $this->assertSame(0, $digest->run('2026-10-14')['recipients'], 'dedupe per hari');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM notification_deliveries WHERE user_id = ?', [$this->drafter->id]));
    }

    public function testMailQueueSendsRetriesWithBackoffAndFails(): void
    {
        Notifier::queueEmail(null, $this->drafter->id, 'a@example.com', 'Subjek', 'Isi', 'process.php?id=1', 'id', 'test:1');
        $ok = new class implements MailTransport {
            public array $sent = [];
            public function send(string $to, string $toName, string $subject, string $html, string $text): void { $this->sent[] = $to; }
        };
        $this->assertSame(['sent' => 1, 'failed' => 0, 'retry' => 0], (new MailQueue($ok))->deliverDue());
        $this->assertSame(['a@example.com'], $ok->sent);
        $this->assertSame('sent', Db::value("SELECT status FROM notification_deliveries WHERE dedupe_key = 'test:1'"));

        Notifier::queueEmail(null, $this->drafter->id, 'b@example.com', 'Subjek', 'Isi', null, 'id', 'test:2');
        $bad = new class implements MailTransport {
            public function send(string $to, string $toName, string $subject, string $html, string $text): void { throw new \RuntimeException('SMTP connect() failed'); }
        };
        $q = new MailQueue($bad);
        $this->assertSame(1, $q->deliverDue()['retry']);
        $row = Db::fetch("SELECT * FROM notification_deliveries WHERE dedupe_key = 'test:2'");
        $this->assertSame(['pending', 1, 'SMTP connect() failed'], [$row['status'], (int) $row['attempts'], $row['last_error']]);
        $this->assertSame('2026-10-06 07:05:00', $row['next_attempt_at'], 'jeda 5 menit');
        $this->assertSame(0, array_sum($q->deliverDue()), 'belum jatuh tempo');
        foreach (['2026-10-06 07:06:00', '2026-10-06 07:22:00', '2026-10-06 08:23:00', '2026-10-06 12:24:00'] as $t) {
            Clock::freeze($t);
            $q->deliverDue();
        }
        $row = Db::fetch("SELECT * FROM notification_deliveries WHERE dedupe_key = 'test:2'");
        $this->assertSame(['failed', 5], [$row['status'], (int) $row['attempts']]);
        $admin = $this->makeUser('admin');
        $q->retry($admin, (int) $row['id']);
        $this->assertSame('pending', Db::value('SELECT status FROM notification_deliveries WHERE id = ?', [(int) $row['id']]));
        $this->assertSame(1, (new MailQueue($ok))->deliverDue()['sent']);
    }

    public function testNotificationCenterOwnershipAndSafeLinks(): void
    {
        Notifier::send([$this->drafter->id], 'comment', 'notif.comment.title', 'notif.comment.body', ['project' => 'X', 'process' => 'Y', 'user' => 'Z', 'excerpt' => 'e'], 'process.php?id=5', null, null, 'c:1');
        $center = new NotificationCenter();
        $list = $center->list($this->drafter->id, true, null);
        $this->assertGreaterThanOrEqual(1, $list['total']);
        $id = (int) $list['rows'][0]['id'];
        $this->assertNull($center->markRead($this->sales->id, $id), 'notifikasi orang lain');
        $this->assertSame(0, (int) Db::value('SELECT is_read FROM notifications WHERE id = ?', [$id]));
        $center->markRead($this->drafter->id, $id);
        $this->assertSame(1, (int) Db::value('SELECT is_read FROM notifications WHERE id = ?', [$id]));
        $this->assertGreaterThanOrEqual(0, $center->markAllRead($this->drafter->id));
        $this->assertSame(0, Notifier::unreadCount($this->drafter->id));
        $this->assertSame('process.php?id=5', NotificationCenter::safeLink('process.php?id=5'));
        foreach (['https://evil.example', '//evil.example', 'javascript:alert(1)', "a\r\nb", 'a\\b'] as $bad) {
            $this->assertNull(NotificationCenter::safeLink($bad), $bad);
        }
    }

    public function testNotificationSettingsValidationAndSecret(): void
    {
        $svc = new NotificationSettings();
        $admin = $this->makeUser('admin');
        try {
            $svc->save($admin, ['due_soon_days' => '0', 'no_update_days' => '45', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'mail_enabled' => '1']);
            $this->fail('validasi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('due_soon_days', $e->errors());
            $this->assertArrayHasKey('no_update_days', $e->errors());
            $this->assertArrayHasKey('mail_enabled', $e->errors());
        }
        $svc->save($admin, ['due_soon_days' => '5', 'no_update_days' => '10', 'smtp_host' => 'smtp.pik.co.id', 'smtp_port' => '587', 'smtp_encryption' => 'tls',
            'smtp_username' => 'npd', 'smtp_password' => 'Rahasia#2026', 'from_address' => 'npd@pik.co.id', 'from_name' => 'NPD', 'mail_enabled' => '1', 'types' => ['project_overdue' => '1']]);
        Settings::flush();
        $this->assertSame(5, Settings::int('notify.due_soon_days'));
        $this->assertSame('Rahasia#2026', Settings::get('mail.smtp_password'));
        $stored = (string) Db::value("SELECT setting_value FROM application_settings WHERE setting_key = 'mail.smtp_password'");
        $this->assertStringNotContainsString('Rahasia', $stored, 'tersimpan terenkripsi');
        $this->assertStringNotContainsString('Rahasia', (string) Db::value("SELECT GROUP_CONCAT(new_value) FROM audit_logs WHERE entity_id = 'mail.smtp_password'"));
        $this->assertFalse(Notifier::emailEnabledFor('npr_submitted'), 'jenis dinonaktifkan');
        $this->assertTrue(Notifier::emailEnabledFor('project_overdue'));
        $this->expectException(AuthorizationException::class);
        $svc->save($this->npd, []);
    }
}
