<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Pengirim email sederhana tanpa library tambahan.
 *
 * Driver (config/app.php › mail, diisi dari .env):
 *   log  : TIDAK mengirim; isi email ditulis ke storage/logs/mail-YYYY-MM.log
 *   smtp : SMTP dengan STARTTLS (port 587) / SSL (465) + AUTH LOGIN
 *   mail : fungsi mail() bawaan PHP/hosting
 *
 * Hasil send(): ['status' => SENT|LOGGED|FAILED, 'driver' => ..., 'error' => ?string].
 * Kegagalan kirim tidak melempar exception agar data utama tetap tersimpan;
 * pemanggil wajib menampilkan status ke user.
 */
final class Mailer
{
    public const SENT = 'SENT';
    public const LOGGED = 'LOGGED';
    public const FAILED = 'FAILED';

    /** Total ukuran lampiran per email; lebih dari ini lampiran tidak disertakan. */
    public const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    /** @var array<string,mixed>|null konfigurasi pengganti (dipakai test) */
    public static ?array $override = null;

    /** @return array<string,mixed> */
    public static function config(): array
    {
        return self::$override ?? (array) config('app.mail', []);
    }

    public static function driver(): string
    {
        $driver = (string) (self::config()['driver'] ?? 'log');
        return in_array($driver, ['smtp', 'mail', 'log'], true) ? $driver : 'log';
    }

    /** Penjelasan singkat konfigurasi untuk ditampilkan di aplikasi. */
    public static function describe(): string
    {
        $c = self::config();
        return match (self::driver()) {
            'smtp' => 'SMTP ' . ($c['host'] ?? '') . ':' . ($c['port'] ?? '') . ' dari ' . ($c['from'] ?: '(MAIL_FROM_ADDRESS belum diisi)'),
            'mail' => 'fungsi mail() server dari ' . ($c['from'] ?: '(MAIL_FROM_ADDRESS belum diisi)'),
            default => 'MAIL_DRIVER=log — email tidak benar-benar dikirim, hanya dicatat di storage/logs',
        };
    }

    /**
     * Pecah daftar email "a@x.com, b@y.com" (pemisah koma/titik koma/spasi).
     * @return list<string>|null daftar email unik, null bila ada yang tidak valid / terlalu banyak
     */
    public static function parseList(?string $value, int $max = 5): ?array
    {
        $parts = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_EMAIL) === false || strlen($p) > 190) {
                return null;
            }
            $out[strtolower($p)] ??= $p; // duplikat (beda huruf besar/kecil) diabaikan
        }
        return count($out) > $max ? null : array_values($out);
    }

    /**
     * @param list<string> $to
     * @param list<array{name:string,path:string,mime:string}> $attachments
     * @return array{status:string,driver:string,error:?string,attached:int}
     */
    public static function send(array $to, string $subject, string $body, array $attachments = []): array
    {
        $driver = self::driver();
        $to = array_values(array_filter($to, static fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($to === []) {
            return ['status' => self::FAILED, 'driver' => $driver, 'error' => 'Alamat email penerima tidak valid.', 'attached' => 0];
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        [$attachments, $skipped] = self::usableAttachments($attachments);
        if ($skipped !== '') {
            $body .= "\n\n" . $skipped;
        }
        try {
            if ($driver === 'log') {
                self::writeLog($to, $subject, $body, $attachments);
                return ['status' => self::LOGGED, 'driver' => $driver, 'error' => null, 'attached' => count($attachments)];
            }
            $c = self::config();
            $from = (string) ($c['from'] ?? '');
            if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('MAIL_FROM_ADDRESS di .env belum diisi dengan alamat email yang valid.');
            }
            [$headers, $message] = self::build($to, $subject, $body, $attachments, $from, (string) ($c['from_name'] ?? ''));
            if ($driver === 'mail') {
                $headerLines = array_filter($headers, static fn (string $h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
                if (!@mail(implode(', ', $to), self::encodeHeader($subject), $message, implode("\r\n", $headerLines), '-f' . $from)) {
                    throw new RuntimeException('Fungsi mail() server gagal mengirim (cek konfigurasi email hosting).');
                }
            } else {
                self::smtp($c, $from, $to, implode("\r\n", $headers) . "\r\n\r\n" . $message);
            }
            return ['status' => self::SENT, 'driver' => $driver, 'error' => null, 'attached' => count($attachments)];
        } catch (\Throwable $e) {
            Logger::error('Email gagal dikirim: ' . $e->getMessage(), ['to' => $to, 'subject' => $subject, 'driver' => $driver]);
            return ['status' => self::FAILED, 'driver' => $driver, 'error' => mb_substr($e->getMessage(), 0, 480), 'attached' => 0];
        }
    }

    /**
     * Lampiran yang filenya ada & total ukurannya dalam batas.
     * @param list<array{name:string,path:string,mime:string}> $attachments
     * @return array{0:list<array{name:string,path:string,mime:string}>,1:string}
     */
    private static function usableAttachments(array $attachments): array
    {
        $ok = [];
        $total = 0;
        $skipped = [];
        foreach ($attachments as $a) {
            $size = is_file($a['path']) ? (int) filesize($a['path']) : -1;
            if ($size < 0) {
                $skipped[] = $a['name'] . ' (file tidak ditemukan)';
            } elseif ($total + $size > self::MAX_ATTACHMENT_BYTES) {
                $skipped[] = $a['name'] . ' (melebihi batas ukuran email)';
            } else {
                $total += $size;
                $ok[] = $a;
            }
        }
        return [$ok, $skipped !== [] ? "Lampiran tidak disertakan: " . implode(', ', $skipped) . '. Lihat di aplikasi.' : ''];
    }

    /**
     * Susun header & body MIME (teks UTF-8 base64 + lampiran).
     * @param list<string> $to
     * @param list<array{name:string,path:string,mime:string}> $attachments
     * @return array{0:list<string>,1:string}
     */
    public static function build(array $to, string $subject, string $body, array $attachments, string $from, string $fromName): array
    {
        $host = substr((string) strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $from . '>',
            'To: ' . implode(', ', array_map(static fn ($a) => '<' . $a . '>', $to)),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
        ];
        $encodedBody = rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n"));
        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            return [$headers, $encodedBody];
        }
        $boundary = 'pik-' . bin2hex(random_bytes(10));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $message = "This is a multi-part message in MIME format.\r\n\r\n--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encodedBody}\r\n";
        foreach ($attachments as $a) {
            $name = self::safeFilename($a['name']);
            $mime = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', $a['mime']) ? $a['mime'] : 'application/octet-stream';
            $message .= "--{$boundary}\r\n"
                . "Content-Type: {$mime}; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . rtrim(chunk_split(base64_encode((string) file_get_contents($a['path'])), 76, "\r\n")) . "\r\n";
        }
        $message .= "--{$boundary}--";
        return [$headers, $message];
    }

    private static function encodeHeader(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Nama file aman untuk header (ASCII, tanpa tanda kutip/baris baru). */
    private static function safeFilename(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'lampiran';
        return trim($ascii) !== '' ? mb_substr(trim($ascii), 0, 120) : 'lampiran';
    }

    /**
     * @param array<string,mixed> $c
     * @param list<string> $to
     */
    private static function smtp(array $c, string $from, array $to, string $data): void
    {
        $host = (string) ($c['host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('MAIL_HOST di .env belum diisi.');
        }
        $port = (int) ($c['port'] ?? 587);
        $enc = (string) ($c['encryption'] ?? 'tls');
        $timeout = max(3, (int) ($c['timeout'] ?? 15));
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            throw new RuntimeException("Tidak dapat terhubung ke server email {$host}:{$port} ({$errstr}).");
        }
        stream_set_timeout($fp, $timeout);
        try {
            self::expect($fp, [220]);
            $ehlo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost');
            self::command($fp, $ehlo, [250]);
            if ($enc === 'tls') {
                self::command($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0))) {
                    throw new RuntimeException('Gagal mengaktifkan enkripsi TLS ke server email.');
                }
                self::command($fp, $ehlo, [250]);
            }
            $user = (string) ($c['username'] ?? '');
            if ($user !== '') {
                self::command($fp, 'AUTH LOGIN', [334]);
                self::command($fp, base64_encode($user), [334]);
                self::command($fp, base64_encode((string) ($c['password'] ?? '')), [235], 'Login ke server email ditolak (cek MAIL_USERNAME/MAIL_PASSWORD).');
            }
            self::command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $rcpt) {
                self::command($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            self::command($fp, 'DATA', [354]);
            // dot-stuffing: baris yang diawali "." diberi titik tambahan
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $data)) ?? $data;
            self::command($fp, $data . "\r\n.", [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp @param list<int> $codes */
    private static function command($fp, string $line, array $codes, ?string $failMessage = null): string
    {
        if (@fwrite($fp, $line . "\r\n") === false) {
            throw new RuntimeException('Koneksi ke server email terputus.');
        }
        return self::expect($fp, $codes, $failMessage);
    }

    /** @param resource $fp @param list<int> $codes */
    private static function expect($fp, array $codes, ?string $failMessage = null): string
    {
        $response = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $detail = trim(preg_replace('/\s+/', ' ', $response) ?? '');
            throw new RuntimeException(($failMessage ?? 'Server email menolak perintah') . ($detail !== '' ? ' [' . mb_substr($detail, 0, 200) . ']' : ' (tidak ada respons)'));
        }
        return $response;
    }

    /**
     * @param list<string> $to
     * @param list<array{name:string,path:string,mime:string}> $attachments
     */
    private static function writeLog(array $to, string $subject, string $body, array $attachments): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = "==== " . date('Y-m-d H:i:s') . " (MAIL_DRIVER=log, tidak dikirim) ====\n"
            . 'To: ' . implode(', ', $to) . "\nSubject: {$subject}\n"
            . ($attachments !== [] ? 'Lampiran: ' . implode(', ', array_map(static fn ($a) => $a['name'], $attachments)) . "\n" : '')
            . "\n{$body}\n\n";
        if (@file_put_contents($dir . '/mail-' . date('Y-m') . '.log', $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Tidak dapat menulis storage/logs (cek izin folder).');
        }
    }
}
