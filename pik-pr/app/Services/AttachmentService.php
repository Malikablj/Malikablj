<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\AttachmentRepository;
use finfo;
use RuntimeException;
use ZipArchive;

/**
 * Upload lampiran: validasi ekstensi + MIME (dari isi file) + ukuran,
 * nama file acak, dan penyimpanan di luar folder public.
 */
final class AttachmentService
{
    private AttachmentRepository $attachments;
    private AuditService $audit;

    /** @var list<string> file yang sudah dipindahkan di request ini (untuk dibersihkan jika transaksi gagal) */
    private array $moved = [];

    public function __construct()
    {
        $this->attachments = new AttachmentRepository();
        $this->audit = new AuditService();
    }

    public static function storageRoot(): string
    {
        $path = (string) Config::get('app.upload.path', 'storage/uploads');

        return str_starts_with($path, '/') ? rtrim($path, '/') : BASE_PATH . '/' . trim($path, '/');
    }

    public static function maxBytes(): int
    {
        return (int) Config::get('app.upload.max_mb', 5) * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return array_keys((array) Config::get('app.upload.allowed', []));
    }

    /**
     * Memvalidasi file upload. File kosong (tidak dipilih) diabaikan.
     *
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     * @return list<array{tmp: string, original: string, extension: string, mime: string, size: int}>
     */
    public function validate(array $files, int $existingCount): array
    {
        $valid = [];
        $maxMb = (int) Config::get('app.upload.max_mb', 5);

        foreach ($files as $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $original = self::cleanName($file['name']);
            $fail = static function (string $message) use ($original): never {
                throw new ValidationException(['attachments' => "{$original}: {$message}"], "Lampiran {$original} ditolak: {$message}");
            };

            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $fail("ukuran melebihi batas {$maxMb} MB.");
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $fail('file gagal diunggah, silakan coba lagi.');
            }
            if (!self::isUploadedFile($file['tmp_name'])) {
                $fail('file upload tidak valid.');
            }

            $size = (int) filesize($file['tmp_name']);
            if ($size <= 0) {
                $fail('file kosong.');
            }
            if ($size > self::maxBytes()) {
                $fail("ukuran melebihi batas {$maxMb} MB.");
            }

            $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $allowed = (array) Config::get('app.upload.allowed', []);
            if (!isset($allowed[$extension])) {
                $fail('tipe file tidak diizinkan. Gunakan: ' . implode(', ', array_keys($allowed)) . '.');
            }

            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, $allowed[$extension], true) || !self::contentMatches($file['tmp_name'], $extension, $mime)) {
                $fail('isi file tidak sesuai dengan ekstensinya.');
            }

            $valid[] = ['tmp' => $file['tmp_name'], 'original' => $original, 'extension' => $extension, 'mime' => $mime, 'size' => $size];
        }

        $max = (int) Config::get('app.upload.max_files_per_pr', 10);
        if ($existingCount + count($valid) > $max) {
            throw new ValidationException(['attachments' => "Maksimal {$max} lampiran per PR."], "Maksimal {$max} lampiran per PR.");
        }

        return $valid;
    }

    /**
     * Menyimpan file yang sudah divalidasi. Dipanggil di dalam transaksi database.
     *
     * @param list<array{tmp: string, original: string, extension: string, mime: string, size: int}> $files
     * @return list<int>
     */
    public function store(array $files, int $prId, int $userId): array
    {
        $ids = [];
        foreach ($files as $file) {
            $relativeDir = date('Y/m');
            $directory = self::storageRoot() . '/' . $relativeDir;
            if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Folder penyimpanan lampiran tidak dapat dibuat.');
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $target = $directory . '/' . $storedName;
            $ok = PHP_SAPI === 'cli' ? @copy($file['tmp'], $target) : @move_uploaded_file($file['tmp'], $target);
            if (!$ok) {
                throw new RuntimeException('Lampiran gagal disimpan.');
            }
            @chmod($target, 0640);
            $this->moved[] = $target;

            $id = $this->attachments->create([
                'pr_id' => $prId,
                'original_name' => $file['original'],
                'stored_name' => $storedName,
                'mime_type' => $file['mime'],
                'size_bytes' => $file['size'],
                'path' => $relativeDir . '/' . $storedName,
                'uploaded_by' => $userId,
            ]);
            $this->audit->log($userId, 'pr.attachment_upload', 'purchase_requisition', $prId, null, [
                'attachment_id' => $id,
                'original_name' => $file['original'],
                'mime_type' => $file['mime'],
                'size_bytes' => $file['size'],
            ]);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Menghapus file fisik yang sudah terlanjur dipindahkan bila transaksi dibatalkan.
     */
    public function discardMoved(): void
    {
        foreach ($this->moved as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->moved = [];
    }

    /**
     * @param array<string, mixed> $attachment
     */
    public function delete(int $userId, array $attachment): void
    {
        $this->attachments->delete((int) $attachment['id']);
        $this->audit->log($userId, 'pr.attachment_delete', 'purchase_requisition', (int) $attachment['pr_id'], [
            'attachment_id' => (int) $attachment['id'],
            'original_name' => $attachment['original_name'],
        ], null);

        $path = $this->absolutePath($attachment);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Path absolut file, dipastikan berada di dalam folder penyimpanan.
     *
     * @param array<string, mixed> $attachment
     */
    public function absolutePath(array $attachment): string
    {
        $root = realpath(self::storageRoot());
        $path = realpath(self::storageRoot() . '/' . $attachment['path']);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'File lampiran tidak ditemukan.');
        }

        return $path;
    }

    private static function isUploadedFile(string $tmp): bool
    {
        if ($tmp === '') {
            return false;
        }

        return PHP_SAPI === 'cli' ? is_file($tmp) : is_uploaded_file($tmp);
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(mb_substr($name, 0, 200));

        return $name !== '' ? $name : 'lampiran';
    }

    /**
     * Pemeriksaan tambahan berdasarkan isi file.
     */
    private static function contentMatches(string $path, string $extension, string $mime): bool
    {
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return @getimagesize($path) !== false;
        }
        if (in_array($extension, ['docx', 'xlsx'], true) && $mime === 'application/zip') {
            // Dokumen Office modern adalah arsip ZIP berisi [Content_Types].xml
            if (!class_exists(ZipArchive::class)) {
                return false;
            }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return false;
            }
            $folder = $extension === 'docx' ? 'word/' : 'xl/';
            $ok = $zip->locateName('[Content_Types].xml') !== false && self::zipHasPrefix($zip, $folder);
            $zip->close();

            return $ok;
        }

        return true;
    }

    private static function zipHasPrefix(ZipArchive $zip, string $prefix): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
