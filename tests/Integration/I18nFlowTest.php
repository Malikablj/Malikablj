<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\I18n;
use App\Core\Settings;
use App\Notification\Notifier;
use Tests\Support\DbTestCase;

/** UAT-23: label/status/email mengikuti bahasa pengguna; isian pengguna (nama project, alasan) tidak diterjemahkan. */
final class I18nFlowTest extends DbTestCase
{
    public function testNotificationAndEmailUseRecipientLanguage(): void
    {
        Settings::set('mail.enabled', '1');
        Settings::set('mail.types_enabled', json_encode(['hold_reminder' => true]));
        Settings::flush();
        $id = $this->makeUser('admin', ['language' => 'id', 'email' => 'id-user@pik.test']);
        $en = $this->makeUser('admin', ['language' => 'en', 'email' => 'en-user@pik.test']);
        $sent = Notifier::send([$id->id, $en->id], 'hold_reminder', 'notif.hold_reminder.title', 'notif.hold_reminder.body',
            ['project' => 'NPD-2026-009', 'scope' => 'Botol Lotion', 'days' => 30, 'reason' => 'Menunggu PO customer'], 'project.php?id=1', null, null, 'i18n-test');
        $this->assertSame(2, $sent);
        $titleId = (string) Db::value("SELECT title FROM notifications WHERE user_id = ? AND dedupe_key = 'i18n-test'", [$id->id]);
        $titleEn = (string) Db::value("SELECT title FROM notifications WHERE user_id = ? AND dedupe_key = 'i18n-test'", [$en->id]);
        $this->assertSame('NPD-2026-009: Hold sudah 30 hari', $titleId);
        $this->assertSame('NPD-2026-009: on Hold for 30 days', $titleEn);
        $bodyEn = (string) Db::value("SELECT body FROM notifications WHERE user_id = ? AND dedupe_key = 'i18n-test'", [$en->id]);
        $this->assertStringContainsString('Menunggu PO customer', $bodyEn, 'isian pengguna tidak diterjemahkan');
        $this->assertSame($titleEn, (string) Db::value("SELECT subject FROM notification_deliveries WHERE to_email = 'en-user@pik.test'"), 'email dalam bahasa penerima');
        $this->assertSame($titleId, (string) Db::value("SELECT subject FROM notification_deliveries WHERE to_email = 'id-user@pik.test'"));
        $this->assertStringContainsString('lang="en"', (string) Db::value("SELECT body_html FROM notification_deliveries WHERE to_email = 'en-user@pik.test'"));
    }

    public function testStatusLabelsAndReportTextFollowLocale(): void
    {
        $this->assertSame('Belum Mulai', I18n::t('status.not_started', [], 'id'));
        $this->assertSame('Not Started', I18n::t('status.not_started', [], 'en'));
        $this->assertSame('Oktober', I18n::t('report.month.10', [], 'id'));
        $this->assertSame('October', I18n::t('report.month.10', [], 'en'));
        $this->assertSame('KPI per PIC', I18n::t('report.tab.kpi', [], 'en'));
    }
}
