<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use DomainException;

/**
 * Bukti retur & komplain (gambar JPG/PNG/WEBP atau PDF).
 *
 * File disimpan di storage/uploads/returns/YYYY/MM/<acak>.<ext> — di luar folder
 * public — dan hanya bisa dibuka lewat aplikasi oleh user yang berhak.
 * Jenis file ditentukan dari ISI file (magic bytes), bukan dari nama/ekstensi.
 */
final class ReturnAttachment
{
    public const ENTITY = 'return_attachment';
    public const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    public static function root(): string
    {
        return APP_ROOT . '/storage/uploads';
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('app.upload.max_evidence_mb', 5)) * 1024 * 1024;
    }

    public static function maxFiles(): int
    {
        return max(1, (int) config('app.upload.max_evidence_files', 10));
    }

    /** Jenis file dari isinya; null bila bukan gambar/PDF yang didukung. */
    public static function detectMime(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 16);
        fclose($fh);
        $mime = match (true) {
            str_starts_with($head, "\xFF\xD8\xFF")                                        => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n")                                   => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'             => 'image/webp',
            str_starts_with($head, '%PDF-')                                               => 'application/pdf',
            default                                                                       => null,
        };
        // gambar harus benar-benar bisa dibaca sebagai gambar
        if ($mime !== null && $mime !== 'application/pdf' && @getimagesize($path) === false) {
            return null;
        }
        return $mime;
    }

    /**
     * Ambil file dari $_FILES[field] (input multiple) dan validasi.
     * @return array{0:list<array{tmp:string,name:string,mime:string,size:int}>,1:list<string>} [file valid, pesan error]
     */
    public static function fromRequest(string $field, int $existing = 0): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return [[], []];
        }
        $names = (array) $raw['name'];
        $files = [];
        $errors = [];
        foreach (array_keys($names) as $i) {
            $error = (int) ((array) $raw['error'])[$i];
            $name = self::cleanName((string) $names[$i]);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $errors[] = "{$name}: terlalu besar (batas server " . (ini_get('upload_max_filesize') ?: '?') . ').';
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = "{$name}: gagal diunggah (kode {$error}).";
                continue;
            }
            $tmp = (string) ((array) $raw['tmp_name'])[$i];
            $size = (int) ((array) $raw['size'])[$i];
            if (!is_uploaded_file($tmp)) {
                $errors[] = "{$name}: file tidak valid.";
                continue;
            }
            if ($size > self::maxBytes()) {
                $errors[] = "{$name}: melebihi " . (int) config('app.upload.max_evidence_mb', 5) . ' MB.';
                continue;
            }
            $mime = self::detectMime($tmp);
            if ($mime === null) {
                $errors[] = "{$name}: hanya gambar JPG, PNG, WEBP, atau PDF.";
                continue;
            }
            $files[] = ['tmp' => $tmp, 'name' => $name, 'mime' => $mime, 'size' => $size];
        }
        if ($existing + count($files) > self::maxFiles()) {
            $errors[] = 'Maksimal ' . self::maxFiles() . ' file bukti per retur/komplain (sudah ada ' . $existing . ').';
            $files = [];
        }
        return [$files, $errors];
    }

    /**
     * Simpan file yang sudah divalidasi ke storage + catat di database.
     * Panggil di dalam transaksi; bila transaksi gagal, panggil discard() dengan hasil ini.
     * @param list<array{tmp:string,name:string,mime:string,size:int}> $files
     * @return list<string> path file yang sudah dipindahkan (untuk dibersihkan bila gagal)
     */
    public static function store(int $returnId, array $files): array
    {
        $moved = [];
        foreach ($files as $f) {
            $relative = 'returns/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . self::TYPES[$f['mime']];
            $target = self::root() . '/' . $relative;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                throw new DomainException('Folder storage/uploads tidak dapat dibuat (cek izin folder).');
            }
            if (!@move_uploaded_file($f['tmp'], $target)) {
                throw new DomainException('File ' . $f['name'] . ' gagal disimpan (cek izin folder storage/uploads).');
            }
            $moved[] = $target;
            $id = Database::insert('return_attachments', [
                'return_id' => $returnId, 'original_name' => $f['name'], 'stored_name' => $relative, 'mime_type' => $f['mime'],
                'file_size' => $f['size'], 'uploaded_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            Audit::log('upload', self::ENTITY, $id, $f['name'], ['return_id' => ['old' => null, 'new' => $returnId], 'file' => ['old' => null, 'new' => $f['name']]]);
        }
        return $moved;
    }

    /** Hapus file yang sudah dipindahkan (transaksi gagal). @param list<string> $paths */
    public static function discard(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public static function forReturn(int $returnId): array
    {
        return Database::fetchAll(
            'SELECT a.*, u.name AS uploaded_by_name FROM return_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.return_id = :r ORDER BY a.id',
            ['r' => $returnId]
        );
    }

    public static function countFor(int $returnId): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM return_attachments WHERE return_id = :r', ['r' => $returnId]);
    }

    /** @return array<string,mixed>|null */
    public static function findFor(int $returnId, int $id): ?array
    {
        return Database::fetch('SELECT * FROM return_attachments WHERE id = :id AND return_id = :r', ['id' => $id, 'r' => $returnId]);
    }

    /** Path absolut file; null bila file hilang / di luar folder upload. */
    public static function path(array $att): ?string
    {
        $root = realpath(self::root());
        $real = realpath(self::root() . '/' . $att['stored_name']);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        return $real;
    }

    public static function remove(array $att): void
    {
        $path = self::path($att);
        Database::delete('return_attachments', 'id = :id', ['id' => $att['id']]);
        Audit::log('delete', self::ENTITY, (int) $att['id'], (string) $att['original_name'], ['return_id' => ['old' => $att['return_id'], 'new' => null]]);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Hapus file fisik semua bukti sebuah retur (baris DB ikut terhapus lewat ON DELETE CASCADE). */
    public static function unlinkAll(int $returnId): void
    {
        foreach (self::forReturn($returnId) as $att) {
            $path = self::path($att);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /** Nama file asli yang aman ditampilkan. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        return $name !== '' ? mb_substr($name, 0, 200) : 'file';
    }
}
