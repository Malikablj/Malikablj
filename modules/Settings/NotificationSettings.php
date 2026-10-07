<?php
declare(strict_types=1);

namespace App\Settings;

use App\Core\Gate;
use App\Core\I18n;
use App\Core\Settings;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;

/** Pengaturan notifikasi & SMTP oleh Admin (PRD §7.3: ambang 1–30 hari, SMTP, aktif/nonaktif jenis email). */
final class NotificationSettings
{
    /** @return array<string,mixed> */
    public function current(): array
    {
        return [
            'due_soon_days' => Settings::int('notify.due_soon_days', 3),
            'no_update_days' => Settings::int('notify.no_update_days', 7),
            'mail_enabled' => Settings::bool('mail.enabled', false),
            'smtp_host' => (string) Settings::get('mail.smtp_host', ''),
            'smtp_port' => Settings::int('mail.smtp_port', 587),
            'smtp_encryption' => (string) Settings::get('mail.smtp_encryption', 'tls'),
            'smtp_username' => (string) Settings::get('mail.smtp_username', ''),
            'smtp_password_set' => (string) Settings::get('mail.smtp_password', '') !== '',
            'from_address' => (string) Settings::get('mail.from_address', ''),
            'from_name' => (string) Settings::get('mail.from_name', 'NPD Project Control'),
            'types' => array_replace(array_fill_keys(Notifier::EMAIL_TYPES, true), Settings::json('mail.types_enabled')),
        ];
    }

    /** @param array<string,mixed> $in */
    public function save(User $actor, array $in): void
    {
        Gate::authorize($actor, 'settings.manage');
        $errors = [];
        foreach (['due_soon_days', 'no_update_days'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if (!preg_match('/^\d{1,2}$/', $v) || (int) $v < 1 || (int) $v > 30) {
                $errors[$k] = I18n::t('nset.range_1_30');
            }
        }
        $port = trim((string) ($in['smtp_port'] ?? '587'));
        if (!preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            $errors['smtp_port'] = I18n::t('validation.invalid');
        }
        $enc = (string) ($in['smtp_encryption'] ?? 'tls');
        if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
            $errors['smtp_encryption'] = I18n::t('validation.invalid');
        }
        $host = trim((string) ($in['smtp_host'] ?? ''));
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.-]{1,190}$/', $host)) {
            $errors['smtp_host'] = I18n::t('validation.invalid');
        }
        $from = trim((string) ($in['from_address'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = I18n::t('validation.email');
        }
        $enabled = !empty($in['mail_enabled']);
        if ($enabled && ($host === '' || $from === '')) {
            $errors['mail_enabled'] = I18n::t('nset.smtp_required');
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        Settings::set('notify.due_soon_days', (string) (int) $in['due_soon_days'], $actor->id);
        Settings::set('notify.no_update_days', (string) (int) $in['no_update_days'], $actor->id);
        Settings::set('mail.enabled', $enabled ? '1' : '0', $actor->id);
        Settings::set('mail.smtp_host', $host, $actor->id);
        Settings::set('mail.smtp_port', (string) (int) $port, $actor->id);
        Settings::set('mail.smtp_encryption', $enc, $actor->id);
        Settings::set('mail.smtp_username', mb_substr(trim((string) ($in['smtp_username'] ?? '')), 0, 190), $actor->id);
        $pw = (string) ($in['smtp_password'] ?? '');
        if ($pw !== '') { // kosong = tidak diubah
            Settings::set('mail.smtp_password', $pw, $actor->id, 'secret');
        } elseif (!empty($in['smtp_password_clear'])) {
            Settings::set('mail.smtp_password', '', $actor->id, 'secret');
        }
        Settings::set('mail.from_address', $from, $actor->id);
        Settings::set('mail.from_name', mb_substr(trim((string) ($in['from_name'] ?? '')), 0, 120) ?: 'NPD Project Control', $actor->id);
        $types = [];
        foreach (Notifier::EMAIL_TYPES as $t) {
            $types[$t] = !empty($in['types'][$t]);
        }
        Settings::set('mail.types_enabled', (string) json_encode($types), $actor->id);
    }
}
