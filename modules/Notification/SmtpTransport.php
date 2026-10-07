<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Config;
use App\Core\Settings;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP via PHPMailer. Pengaturan Admin (application_settings mail.*) diutamakan; .env (config/mail.php) sebagai bawaan.
 * Password SMTP tersimpan terenkripsi (Settings 'secret').
 */
final class SmtpTransport implements MailTransport
{
    /** @return array<string,mixed> */
    public static function config(): array
    {
        $pick = static fn (string $setting, string $config) => (($v = Settings::get($setting)) !== null && $v !== '') ? $v : Config::get('mail.' . $config);
        return [
            'host' => (string) $pick('mail.smtp_host', 'host'),
            'port' => (int) $pick('mail.smtp_port', 'port'),
            'encryption' => (string) $pick('mail.smtp_encryption', 'encryption'),
            'username' => (string) $pick('mail.smtp_username', 'username'),
            'password' => (string) $pick('mail.smtp_password', 'password'),
            'from_address' => (string) $pick('mail.from_address', 'from_address'),
            'from_name' => (string) $pick('mail.from_name', 'from_name'),
        ];
    }

    public function send(string $to, string $toName, string $subject, string $html, string $text): void
    {
        $c = self::config();
        if ($c['host'] === '' || $c['from_address'] === '') {
            throw new \RuntimeException('SMTP belum dikonfigurasi (host / alamat pengirim kosong)');
        }
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $c['host'];
            $m->Port = $c['port'] ?: 587;
            $m->SMTPAuth = $c['username'] !== '';
            $m->Username = $c['username'];
            $m->Password = $c['password'];
            $m->SMTPSecure = match ($c['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $m->SMTPAutoTLS = $c['encryption'] !== 'none';
            $m->Timeout = 20;
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->setFrom($c['from_address'], $c['from_name']);
            $m->addAddress($to, $toName);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body = $html;
            $m->AltBody = $text;
            $m->send();
        } catch (MailException $e) {
            throw new \RuntimeException($m->ErrorInfo ?: $e->getMessage(), 0, $e);
        }
    }
}
