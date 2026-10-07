<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\Setting;
use RuntimeException;

/**
 * Pengirim email tanpa library eksternal.
 *
 * Transport (Settings › Pengaturan › Email):
 *   - "mail" : fungsi mail() PHP (umumnya sudah aktif di hosting cPanel)
 *   - "smtp" : SMTP dengan AUTH LOGIN (SSL port 465 atau STARTTLS port 587)
 *
 *   Mailer::send(['qc@perusahaan.co.id'], 'Subjek', '<p>HTML</p>', 'teks', [['name' => 'a.pdf', 'path' => '/..', 'mime' => 'application/pdf']]);
 */
final class Mailer
{
    public const TRANSPORTS = ['mail' => 'PHP mail() bawaan hosting', 'smtp' => 'SMTP (akun email perusahaan)'];
    public const ENCRYPTIONS = ['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'Tanpa enkripsi'];
    /** Batas total lampiran per email (byte). Lampiran yang melebihi batas dikirim sebagai link. */
    public const MAX_ATTACH_BYTES = 8 * 1024 * 1024;

    /**
     * Pecah daftar email (dipisah koma / titik koma / spasi / baris baru).
     * @return array{valid:list<string>,invalid:list<string>}
     */
    public static function parseList(?string $value): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', (string) $value) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n<>"]/', $part)) {
                $valid[mb_strtolower($part)] = mb_strtolower($part);
            } else {
                $invalid[] = $part;
            }
        }
        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    public static function configured(): bool
    {
        if (self::transport() === 'smtp') {
            return (string) Setting::get('smtp_host', '') !== '' && self::fromAddress() !== '';
        }
        return function_exists('mail');
    }

    public static function transport(): string
    {
        $t = (string) Setting::get('mail_transport', 'mail');
        return isset(self::TRANSPORTS[$t]) ? $t : 'mail';
    }

    public static function fromAddress(): string
    {
        $from = trim((string) Setting::get('mail_from_address', ''));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $from = self::transport() === 'smtp' ? trim((string) Setting::get('smtp_username', '')) : '';
        }
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $host = preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? 'localhost')) ?? 'localhost';
            $from = 'no-reply@' . (preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $host) ? $host : 'localhost.localdomain');
        }
        return $from;
    }

    public static function fromName(): string
    {
        $name = trim((string) Setting::get('mail_from_name', ''));
        return $name !== '' ? $name : (string) (Setting::get('app_name') ?? 'PIK Marketing Control');
    }

    /**
     * Kirim email. Melempar RuntimeException bila gagal.
     * @param list<string> $to
     * @param list<array{name:string,path:string,mime:string}> $attachments
     */
    public static function send(array $to, string $subject, string $html, ?string $text = null, array $attachments = []): void
    {
        if ($to === []) {
            throw new RuntimeException('Tidak ada alamat email penerima.');
        }
        foreach ($to as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $addr)) {
                throw new RuntimeException('Alamat email tidak valid: ' . $addr);
            }
        }
        $text ??= self::htmlToText($html);
        $from = self::fromAddress();
        [$headers, $body] = self::build($to, $subject, $html, $text, $attachments, $from);

        if (self::transport() === 'smtp') {
            self::sendSmtp($from, $to, $headers, $body);
            return;
        }
        if (!function_exists('mail')) {
            throw new RuntimeException('Fungsi mail() tidak tersedia di server. Gunakan transport SMTP di Settings.');
        }
        // mail() menerima To & Subject terpisah; header lain lewat $additional_headers
        $extra = [];
        foreach ($headers as $name => $value) {
            if (!in_array($name, ['To', 'Subject'], true)) {
                $extra[] = $name . ': ' . $value;
            }
        }
        $ok = @mail(implode(', ', $to), $headers['Subject'], $body, implode("\r\n", $extra), '-f' . $from);
        if (!$ok) {
            // beberapa hosting menolak parameter -f
            $ok = @mail(implode(', ', $to), $headers['Subject'], $body, implode("\r\n", $extra));
        }
        if (!$ok) {
            $err = error_get_last();
            throw new RuntimeException('Server menolak mengirim email lewat mail()' . ($err ? ': ' . $err['message'] : '.') . ' Coba gunakan SMTP di Settings.');
        }
    }

    /**
     * Susun header & body MIME (multipart/mixed → multipart/alternative + lampiran).
     * @param list<string> $to
     * @param list<array{name:string,path:string,mime:string}> $attachments
     * @return array{0:array<string,string>,1:string}
     */
    public static function build(array $to, string $subject, string $html, string $text, array $attachments, string $from): array
    {
        $boundaryMixed = 'pik-mixed-' . bin2hex(random_bytes(8));
        $boundaryAlt = 'pik-alt-' . bin2hex(random_bytes(8));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date'         => date('r'),
            'From'         => self::encodeHeader(self::fromName()) . ' <' . $from . '>',
            'To'           => implode(', ', $to),
            'Subject'      => self::encodeHeader($subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'X-Mailer'     => 'PIK Marketing Control',
        ];
        $alt = '--' . $boundaryAlt . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundaryAlt . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundaryAlt . "--\r\n";

        if ($attachments === []) {
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundaryAlt . '"';
            return [$headers, $alt];
        }
        $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundaryMixed . '"';
        $body = '--' . $boundaryMixed . "\r\n"
            . 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . "\"\r\n\r\n"
            . $alt;
        foreach ($attachments as $att) {
            $data = @file_get_contents($att['path']);
            if ($data === false) {
                continue;
            }
            $name = self::encodeHeader(str_replace(['"', "\r", "\n"], '', $att['name']));
            $body .= '--' . $boundaryMixed . "\r\n"
                . 'Content-Type: ' . preg_replace('/[^a-z0-9.\/+\-]/i', '', $att['mime']) . '; name="' . $name . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }
        $body .= '--' . $boundaryMixed . "--\r\n";
        return [$headers, $body];
    }

    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    public static function htmlToText(string $html): string
    {
        $text = preg_replace(['/<(br|\/p|\/tr|\/h[1-6]|\/li)\s*\/?>/i', '/<\/t[dh]>/i'], ["\n", "\t"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    // ------------------------------------------------------------------ SMTP

    /**
     * @param list<string> $to
     * @param array<string,string> $headers
     */
    private static function sendSmtp(string $from, array $to, array $headers, string $body): void
    {
        $host = trim((string) Setting::get('smtp_host', ''));
        $port = (int) (Setting::get('smtp_port', '') ?: 465);
        $enc = (string) Setting::get('smtp_encryption', 'ssl');
        $user = (string) Setting::get('smtp_username', '');
        $pass = (string) Setting::get('smtp_password', '');
        if ($host === '') {
            throw new RuntimeException('SMTP host belum diisi di Settings.');
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            throw new RuntimeException("Tidak dapat terhubung ke SMTP {$host}:{$port} ({$errstr}).");
        }
        stream_set_timeout($fp, 30);
        try {
            self::expect($fp, [220]);
            $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
            self::cmd($fp, 'EHLO ' . $ehloHost, [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('STARTTLS gagal.');
                }
                self::cmd($fp, 'EHLO ' . $ehloHost, [250]);
            }
            if ($user !== '') {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode($user), [334]);
                self::cmd($fp, base64_encode($pass), [235], 'AUTH (password disembunyikan)');
            }
            self::cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $rcpt) {
                self::cmd($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            self::cmd($fp, 'DATA', [354]);
            $data = '';
            foreach ($headers as $name => $value) {
                $data .= $name . ': ' . $value . "\r\n";
            }
            $data .= "\r\n" . $body;
            // dot-stuffing
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], ["\n", "\n"], $data)) ?? $data;
            $data = str_replace("\n", "\r\n", $data);
            fwrite($fp, $data . "\r\n.\r\n");
            self::expect($fp, [250]);
            self::cmd($fp, 'QUIT', [221], null, false);
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp @param list<int> $ok */
    private static function cmd($fp, string $command, array $ok, ?string $logAs = null, bool $strict = true): string
    {
        fwrite($fp, $command . "\r\n");
        try {
            return self::expect($fp, $ok);
        } catch (RuntimeException $e) {
            if (!$strict) {
                return '';
            }
            throw new RuntimeException('SMTP ' . ($logAs ?? strtok($command, ' ')) . ' ditolak: ' . $e->getMessage());
        }
    }

    /** @param resource $fp @param list<int> $ok */
    private static function expect($fp, array $ok): string
    {
        $response = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException(trim($response) !== '' ? trim(mb_substr($response, 0, 300)) : 'tidak ada respons dari server');
        }
        return $response;
    }
}
