<?php
declare(strict_types=1);

namespace App\Document;

use App\Core\Clock;
use App\Core\Config;

/** Penyimpanan file privat di storage/documents (di luar webroot) dengan nama acak. */
final class DocumentStorage
{
    public static function root(): string
    {
        return rtrim((string) Config::get('storage.documents'), '/');
    }

    /**
     * Pindahkan file ter-validasi ke storage. Mengembalikan path relatif (YYYY/MM/<acak>.<ext>).
     * @param array{tmp:string,ext:string} $file
     */
    public static function store(array $file, bool $isUploaded = true): string
    {
        $dir = Clock::now()->format('Y/m');
        $abs = self::root() . '/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0750, true) && !is_dir($abs)) {
            throw new \RuntimeException('Folder dokumen tidak dapat dibuat');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $file['ext'];
        $target = $abs . '/' . $name;
        $ok = $isUploaded ? move_uploaded_file($file['tmp'], $target) : copy($file['tmp'], $target);
        if (!$ok) {
            throw new \RuntimeException('File tidak dapat disimpan');
        }
        @chmod($target, 0640);
        return $dir . '/' . $name;
    }

    /** Path absolut aman (menolak traversal). */
    public static function absolute(string $relative): string
    {
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9_]{1,8}$#', $relative)) {
            throw new \RuntimeException('Path dokumen tidak valid');
        }
        return self::root() . '/' . $relative;
    }
}
