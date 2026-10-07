<?php
declare(strict_types=1);

namespace App\Notification;

/** Pengirim email (SMTP lewat PHPMailer; dapat diganti saat test). */
interface MailTransport
{
    /** @throws \RuntimeException bila gagal */
    public function send(string $to, string $toName, string $subject, string $html, string $text): void;
}
