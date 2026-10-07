<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\BusinessRuleException;
use App\Core\Config;

/** Pembuat instance mPDF dengan konfigurasi aman (temp dir privat, tanpa akses URL eksternal). */
final class PdfFactory
{
    /** @param array<string,mixed> $overrides */
    public static function make(string $orientation = 'P', array $overrides = []): \Mpdf\Mpdf
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new BusinessRuleException('Library PDF (mPDF) belum terpasang. Jalankan: composer install');
        }
        $tmp = rtrim((string) Config::get('storage.cache'), '/') . '/mpdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0750, true);
        }
        $mpdf = new \Mpdf\Mpdf(array_merge([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $orientation,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 34,
            'margin_bottom' => 16,
            'margin_header' => 8,
            'margin_footer' => 7,
            'default_font' => 'dejavusans',
            'default_font_size' => 8.5,
            'tempDir' => $tmp,
            'curlAllowUnsafeSslRequests' => false,
        ], $overrides));
        $mpdf->SetCreator('NPD Project Control');
        $mpdf->SetAuthor('PT. Permata Indo Kemas');
        // Tidak boleh memuat sumber daya dari internet (mencegah SSRF lewat HTML)
        $mpdf->showImageErrors = false;
        return $mpdf;
    }

    public static function logoPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/images/logo-light.png';
    }

    /** Nama file aman (tanpa / \ : * ? " < > |). */
    public static function safeFilename(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $name);
        $name = (string) preg_replace('/\s+/', '_', trim($name));
        return mb_substr($name !== '' ? $name : 'dokumen', 0, 150);
    }
}
