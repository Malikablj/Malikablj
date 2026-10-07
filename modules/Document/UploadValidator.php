<?php
declare(strict_types=1);

namespace App\Document;

use App\Core\I18n;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * Validasi unggahan di server (PRD §9.1, §13.2):
 *  - ukuran ≤ upload.max_mb (bawaan 25 MB)
 *  - ekstensi dalam whitelist Admin (upload.allowed_extensions)
 *  - MIME (finfo, dari ISI file) harus cocok dengan ekstensi
 *  - nama file disanitasi; file disimpan dengan nama acak di luar webroot
 * Ekstensi berbahaya (php, html, svg, js, exe, …) selalu ditolak walau dimasukkan Admin.
 */
final class UploadValidator
{
    /** Ekstensi → MIME yang diterima (hasil finfo). */
    public const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
        '7z' => ['application/x-7z-compressed'],
        'mp4' => ['video/mp4'],
        'mov' => ['video/quicktime'],
        // gambar teknik: MIME tidak baku → diterima sebagai biner/teks, nama acak & diunduh sebagai lampiran
        'dwg' => ['image/vnd.dwg', 'application/acad', 'application/octet-stream', 'image/x-dwg'],
        'dxf' => ['image/vnd.dxf', 'text/plain', 'application/dxf', 'application/octet-stream'],
        'step' => ['text/plain', 'application/step', 'application/octet-stream', 'model/step'],
        'stp' => ['text/plain', 'application/step', 'application/octet-stream', 'model/step'],
        'igs' => ['text/plain', 'application/iges', 'application/octet-stream', 'model/iges'],
        'iges' => ['text/plain', 'application/iges', 'application/octet-stream', 'model/iges'],
        'stl' => ['model/stl', 'application/sla', 'text/plain', 'application/octet-stream', 'model/x.stl-binary'],
        'x_t' => ['text/plain', 'application/octet-stream'],
    ];

    /** Tidak pernah diizinkan (dapat dieksekusi / XSS). */
    public const BLOCKED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs',
        'exe', 'bat', 'cmd', 'com', 'sh', 'bash', 'ps1', 'vbs', 'jar', 'msi', 'dll', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'htaccess', 'xml', 'swf'];

    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        $list = array_map(static fn ($e) => strtolower(trim($e)), explode(',', (string) Settings::get('upload.allowed_extensions', '')));
        return array_values(array_filter($list, static fn ($e) => $e !== '' && isset(self::MIME_MAP[$e]) && !in_array($e, self::BLOCKED, true)));
    }

    public static function maxBytes(): int
    {
        return max(1, Settings::int('upload.max_mb', 25)) * 1024 * 1024;
    }

    /**
     * @param array<string,mixed> $file entri $_FILES
     * @param list<string>|null $onlyExt batasi ekstensi (mis. hanya gambar/PDF)
     * @param bool $requireUploaded true di produksi (is_uploaded_file); test memakai false
     * @return array{tmp:string,original:string,ext:string,mime:string,size:int,sha256:string}
     */
    public static function validate(array $file, string $field = 'file', ?array $onlyExt = null, bool $requireUploaded = true): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException([$field => I18n::t('upload.too_large', ['mb' => (int) (self::maxBytes() / 1048576)])]);
        }
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException([$field => I18n::t('upload.none')]);
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => I18n::t('upload.failed')]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($requireUploaded && !is_uploaded_file($tmp))) {
            throw new ValidationException([$field => I18n::t('upload.failed')]);
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new ValidationException([$field => I18n::t('upload.empty')]);
        }
        if ($size > self::maxBytes()) {
            throw new ValidationException([$field => I18n::t('upload.too_large', ['mb' => (int) (self::maxBytes() / 1048576)])]);
        }
        $original = self::sanitizeName((string) ($file['name'] ?? 'file'));
        $ext = self::extension($original);
        $allowed = self::allowedExtensions();
        if ($onlyExt !== null) {
            $allowed = array_values(array_intersect($allowed, $onlyExt));
        }
        if ($ext === '' || in_array($ext, self::BLOCKED, true) || !in_array($ext, $allowed, true)) {
            throw new ValidationException([$field => I18n::t('upload.type_not_allowed', ['types' => implode(', ', $allowed)])]);
        }
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream');
        if (!in_array($mime, self::MIME_MAP[$ext], true)) {
            throw new ValidationException([$field => I18n::t('upload.mime_mismatch')]);
        }
        // Gambar harus benar-benar dapat dibaca sebagai gambar (cegah polyglot sederhana)
        if (in_array($ext, self::IMAGE_EXT, true) && @getimagesize($tmp) === false) {
            throw new ValidationException([$field => I18n::t('upload.mime_mismatch')]);
        }
        return [
            'tmp' => $tmp,
            'original' => $original,
            'ext' => $ext,
            'mime' => $mime,
            'size' => $size,
            'sha256' => (string) hash_file('sha256', $tmp),
        ];
    }

    /** Nama file aman untuk tampilan/unduhan: tanpa path, karakter kontrol, maks. 180 karakter. */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace(["\0", '\\'], ['', '/'], $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '_', $name);
        $name = trim($name, " .\t");
        if ($name === '') {
            $name = 'file';
        }
        if (mb_strlen($name) > 180) {
            $ext = self::extension($name);
            $name = mb_substr($name, 0, 170) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    public static function extension(string $name): string
    {
        $lower = strtolower($name);
        if (str_ends_with($lower, '.x_t')) {
            return 'x_t';
        }
        $pos = strrpos($lower, '.');
        return $pos === false ? '' : substr($lower, $pos + 1);
    }
}
