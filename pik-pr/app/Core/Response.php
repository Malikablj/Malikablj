<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $filePath = null,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $location = preg_match('#^https?://#i', $to) ? $to : url($to);

        return new self('', $status, ['Location' => $location]);
    }

    /**
     * Konten biner yang sudah ada di memori (mis. PDF yang baru dibuat).
     */
    public static function download(string $content, string $filename, string $mime, bool $inline = false): self
    {
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => self::disposition($filename, $inline),
            'Content-Length' => (string) strlen($content),
        ]);
    }

    /**
     * File dari disk, dikirim tanpa dibaca penuh ke memori.
     */
    public static function file(string $path, string $filename, string $mime, bool $inline = false): self
    {
        return new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => self::disposition($filename, $inline),
            'Content-Length' => (string) filesize($path),
        ], $path);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);

            return;
        }
        echo $this->body;
    }

    private static function disposition(string $filename, bool $inline): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $fallback,
            rawurlencode($filename),
        );
    }
}
