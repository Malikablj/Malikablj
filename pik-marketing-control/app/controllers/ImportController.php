<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Logger;
use App\Models\Setting;
use App\Services\Migration\WorkbookImporter;
use DomainException;
use RuntimeException;

/**
 * Import data awal dari PIK_Master_Database_AppSheet.xlsx lewat browser (Admin).
 * - "Cek dulu" membaca & memetakan workbook tanpa menyimpan apa pun.
 * - "Import" hanya untuk database kosong (satu transaksi; gagal = tidak ada yang tersimpan).
 *   Mengganti data yang sudah ada sengaja hanya lewat CLI (--fresh) agar tidak terjadi tanpa sengaja.
 */
final class ImportController extends Controller
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function show(): void
    {
        $this->view('import/index', $this->baseData());
    }

    public function run(): void
    {
        @set_time_limit(300);
        $mode = ($_POST['mode'] ?? '') === 'import' ? 'import' : 'dry';
        $applyCorrections = ($_POST['apply_corrections'] ?? '0') === '1';
        $tmp = [];
        try {
            $master = $this->storeUpload($_FILES['master'] ?? null, $tmp);
            if ($master === null) {
                throw new DomainException('Pilih file master workbook (.xlsx).');
            }
            $legacy = [];
            foreach ($this->normalizeMultiple($_FILES['legacy'] ?? null) as $file) {
                if (($stored = $this->storeUpload($file, $tmp)) !== null) {
                    $legacy[] = $stored;
                }
            }
            if ($mode === 'import' && ($existing = WorkbookImporter::nonEmptyTables()) !== []) {
                throw new DomainException('Database sudah berisi data (' . implode(', ', array_keys($existing)) . '). Import lewat web hanya untuk database kosong.');
            }
            $importer = (new WorkbookImporter($master['path'], array_column($legacy, 'path'), $applyCorrections))->build();
            $report = $importer->report;
            $counts = null;
            if ($mode === 'import') {
                $counts = $importer->execute(Auth::id());
                Audit::log('import', 'import', null, $master['name'], array_map(static fn ($c) => ['old' => null, 'new' => $c], $counts));
                WorkbookImporter::recordLastImport($master['name'], array_column($legacy, 'name'), $counts, (int) ($report['auto_corrected'] ?? 0));
            }
            $this->view('import/index', array_merge($this->baseData(), [
                'report' => $report, 'mode' => $mode, 'counts' => $counts,
                'files'  => array_merge([$master['name']], array_column($legacy, 'name')),
            ]));
        } catch (DomainException | RuntimeException $e) {
            if (!$e instanceof DomainException) {
                Logger::error('Import web gagal: ' . $e->getMessage());
            }
            $this->view('import/index', array_merge($this->baseData(), ['uploadError' => $e->getMessage()]), 422);
        } finally {
            foreach ($tmp as $path) {
                @unlink($path);
            }
        }
    }

    /** @return array<string,mixed> */
    private function baseData(): array
    {
        $last = Setting::get('last_import');
        return [
            'title'       => 'Import Data',
            'existing'    => WorkbookImporter::nonEmptyTables(),
            'lastImport'  => $last !== null ? (json_decode($last, true) ?: null) : null,
            'report'      => null,
            'mode'        => null,
            'counts'      => null,
            'files'       => [],
            'uploadError' => null,
            'maxUpload'   => ini_get('upload_max_filesize') ?: '2M',
        ];
    }

    /**
     * Validasi & simpan upload ke storage/imports (dihapus lagi setelah diproses).
     * @param array<string,mixed>|null $file
     * @param list<string> $tmp
     * @return array{path:string,name:string}|null
     */
    private function storeUpload(?array $file, array &$tmp): ?array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $name = mb_substr(basename((string) ($file['name'] ?? 'file.xlsx')), 0, 120);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? "File {$name} terlalu besar (batas upload server " . (ini_get('upload_max_filesize') ?: '?') . ').'
                : "Upload {$name} gagal (kode {$error}).");
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('Upload tidak valid.');
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new DomainException("File {$name} lebih dari 20 MB.");
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new DomainException("File {$name} bukan .xlsx. Simpan ulang dari Excel sebagai Excel Workbook (.xlsx).");
        }
        if (file_get_contents((string) $file['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") {
            throw new DomainException("File {$name} bukan file .xlsx yang valid.");
        }
        $dir = APP_ROOT . '/storage/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            throw new RuntimeException('Folder storage/imports tidak dapat dibuat.');
        }
        $dest = $dir . '/upload-' . bin2hex(random_bytes(8)) . '.xlsx';
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            throw new RuntimeException('File upload tidak dapat disimpan ke storage/imports (periksa izin folder).');
        }
        $tmp[] = $dest;
        return ['path' => $dest, 'name' => $name];
    }

    /**
     * $_FILES untuk input multiple → daftar file.
     * @return list<array<string,mixed>>
     */
    private function normalizeMultiple(mixed $files): array
    {
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            return [];
        }
        $out = [];
        foreach (array_keys($files['name']) as $i) {
            $out[] = [
                'name' => $files['name'][$i] ?? '', 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0,
            ];
        }
        return array_slice($out, 0, 5);
    }
}
