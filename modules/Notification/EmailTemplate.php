<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Config;
use App\Core\I18n;

/** Template email sederhana (HTML + teks), bahasa sesuai penerima. Semua nilai di-escape. */
final class EmailTemplate
{
    public static function absoluteUrl(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if (preg_match('#^https?://#', $link)) {
            return $link;
        }
        $base = (string) Config::get('app.url', '');
        return $base !== '' ? rtrim($base, '/') . '/' . ltrim($link, '/') : $link;
    }

    public static function render(string $subject, string $body, ?string $link, string $lang): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = self::absoluteUrl($link);
        $button = $url ? '<p style="margin:24px 0 0"><a href="' . $h($url) . '" style="display:inline-block;padding:10px 18px;border-radius:10px;background:#3B63F3;color:#ffffff;text-decoration:none;font-weight:600">'
            . $h(I18n::t('email.open', [], $lang)) . '</a></p>' : '';
        return '<!doctype html><html lang="' . $h($lang) . '"><head><meta charset="utf-8"><title>' . $h($subject) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#F7F7F5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1D1D1F">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #E5E5E7;border-radius:14px;padding:28px">'
            . '<p style="margin:0 0 4px;font-size:12px;color:#6E6E73;text-transform:uppercase;letter-spacing:.04em">' . $h(I18n::t('app.name', [], $lang)) . '</p>'
            . '<h1 style="margin:0 0 14px;font-size:19px;line-height:1.3">' . $h($subject) . '</h1>'
            . '<p style="margin:0;font-size:14px;line-height:1.6;white-space:pre-line">' . $h($body) . '</p>'
            . $button
            . '</div><p style="max-width:560px;margin:14px auto 0;font-size:11.5px;color:#86868B;text-align:center">'
            . $h(I18n::t('email.footer', [], $lang)) . '</p></body></html>';
    }

    public static function text(string $body, ?string $link, string $lang): string
    {
        $url = self::absoluteUrl($link);
        return $body . ($url ? "\n\n" . I18n::t('email.open', [], $lang) . ': ' . $url : '') . "\n\n— " . I18n::t('app.name', [], $lang);
    }
}
