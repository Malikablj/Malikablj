<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use RuntimeException;

/**
 * Bukti complaint (gambar / PDF).
 * File disimpan di storage/uploads/complaints/<return_id>/ (di luar document root)
 * dan hanya bisa diunduh lewat aplikasi oleh user yang punya akses returns.view.
 */
final class ComplaintAttachment
{
    public const MAX_FILES = 10;
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** ekstensi => mime yang diizinkan */
    public const TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    public static function baseDir(): string
    {
        return APP_ROOT . '/storage/uploads/complaints';
    }

    public static function dir(int $returnId): string
    {
        return self::baseDir() . '/' . $returnId;
    }

    /** Batas ukuran efektif (min. dari batas aplikasi & upload_max_filesize PHP). */
    public static function maxBytes(): int
    {
        $ini = self::iniBytes((string) ini_get('upload_max_filesize'));
        return $ini > 0 ? min(self::MAX_BYTES, $ini) : self::MAX_BYTES;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (float) $value;
        return (int) match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    /**
     * Validasi file dari $_FILES[$field] (input multiple).
     * @return array{files:list<array{tmp:string,name:string,mime:string,size:int,ext:string}>,errors:list<string>}
     */
    public static function fromUpload(string $field, int $existing = 0): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return ['files' => [], 'errors' => []];
        }
        $names = (array) $raw['name'];
        $files = [];
        $errors = [];
        $max = self::maxBytes();
        foreach ($names as $i => $name) {
            $error = (int) ((array) $raw['error'])[$i];
            if ($error === UPLOAD_ERR_NO_FILE || (string) $name === '') {
                continue;
            }
            $label = mb_substr(basename((string) $name), 0, 120);
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = $label . ': ukuran melebihi batas ' . self::human($max) . '.';
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = $label . ': upload gagal (kode ' . $error . ').';
                continue;
            }
            $tmp = (string) ((array) $raw['tmp_name'])[$i];
            $size = (int) ((array) $raw['size'])[$i];
            if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
                $errors[] = $label . ': file tidak valid.';
                continue;
            }
            if ($size <= 0 || $size > $max) {
                $errors[] = $label . ': ukuran maksimal ' . self::human($max) . '.';
                continue;
            }
            $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));
            if (!isset(self::TYPES[$ext])) {
                $errors[] = $label . ': hanya gambar (JPG, PNG, GIF, WEBP) atau PDF.';
                continue;
            }
            $mime = self::detect($tmp);
            if ($mime === null || $mime !== self::TYPES[$ext]) {
                $errors[] = $label . ': isi file bukan ' . strtoupper($ext) . ' yang valid.';
                continue;
            }
            $files[] = ['tmp' => $tmp, 'name' => $label, 'mime' => $mime, 'size' => $size, 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
        }
        if ($existing + count($files) > self::MAX_FILES) {
            $errors[] = 'Maksimal ' . self::MAX_FILES . ' file bukti per complaint.';
        }
        return ['files' => $files, 'errors' => $errors];
    }

    /** Deteksi tipe file dari isi (magic bytes), bukan dari nama/ekstensi. */
    public static function detect(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 16);
        fclose($fh);
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    /**
     * Pindahkan file upload ke storage & catat di database.
     * @param array{tmp:string,name:string,mime:string,size:int,ext:string} $file
     * @return string path file tersimpan
     */
    public static function store(int $returnId, array $file): string
    {
        $dir = self::dir($returnId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder penyimpanan bukti tidak dapat dibuat. Pastikan folder storage/ dapat ditulis web server.');
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $file['ext'];
        $path = $dir . '/' . $stored;
        $moved = is_uploaded_file($file['tmp']) ? @move_uploaded_file($file['tmp'], $path) : @copy($file['tmp'], $path);
        if (!$moved) {
            throw new RuntimeException('File bukti "' . $file['name'] . '" gagal disimpan.');
        }
        @chmod($path, 0640);
        $id = Database::insert('complaint_attachments', [
            'return_id'     => $returnId,
            'original_name' => mb_substr($file['name'], 0, 190),
            'stored_name'   => $stored,
            'mime_type'     => $file['mime'],
            'file_size'     => $file['size'],
            'uploaded_by'   => Auth::id(),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        Audit::log('upload', 'complaint_attachment', $id, $file['name'], ['return_id' => ['old' => null, 'new' => $returnId]]);
        return $path;
    }

    /** @return list<array<string,mixed>> */
    public static function forReturn(int $returnId): array
    {
        return Database::fetchAll(
            'SELECT a.*, u.name AS uploaded_by_name FROM complaint_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.return_id = :r ORDER BY a.id',
            ['r' => $returnId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM complaint_attachments WHERE id = :id', ['id' => $id]);
    }

    public static function path(array $att): string
    {
        // stored_name dibuat aplikasi (hex + ekstensi); basename() sebagai pengaman tambahan
        return self::dir((int) $att['return_id']) . '/' . basename((string) $att['stored_name']);
    }

    public static function remove(array $att): void
    {
        Database::delete('complaint_attachments', 'id = :id', ['id' => $att['id']]);
        self::deleteFile($att);
        Audit::log('delete', 'complaint_attachment', (int) $att['id'], (string) $att['original_name'], ['return_id' => ['old' => (int) $att['return_id'], 'new' => null]]);
    }

    public static function deleteFile(array $att): void
    {
        $path = self::path($att);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function removeDir(int $returnId): void
    {
        $dir = self::dir($returnId);
        if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
            @rmdir($dir);
        }
    }

    public static function isImage(array $att): bool
    {
        return str_starts_with((string) $att['mime_type'], 'image/');
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
        }
        return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' KB';
    }
}
