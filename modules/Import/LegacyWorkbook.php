<?php
declare(strict_types=1);

namespace App\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Pembaca file impor (.xlsx): memetakan kolom dari judul baris 1, mengubah nilai sel ke tipe kolom
 * (tanggal → Y-m-d, angka, teks), dan melaporkan kesalahan format per sheet/baris/kolom.
 * Rumus TIDAK dihitung (mesin rumus dapat memanggil fungsi jaringan seperti WEBSERVICE); yang dipakai
 * hanya nilai tersimpan dari Excel.
 */
final class LegacyWorkbook
{
    /** Batas ukuran isi arsip setelah diekstrak (cegah zip bomb). */
    private const MAX_UNCOMPRESSED = 120 * 1024 * 1024;

    /**
     * @return array{rows:array<string,list<array{row:int,values:array<string,string|int|null>}>>,errors:list<array{sheet:string,row:int,column:string,message:string}>}
     */
    public static function read(string $path): array
    {
        $errors = [];
        $fail = static fn (string $msg): array => ['rows' => [], 'errors' => [['sheet' => '', 'row' => 0, 'column' => '', 'message' => $msg]]];
        if (!is_file($path) || filesize($path) === 0) {
            return $fail('File kosong atau tidak dapat dibaca.');
        }
        if (filesize($path) > LegacyFormat::MAX_BYTES) {
            return $fail('Ukuran file melebihi ' . (LegacyFormat::MAX_BYTES / 1048576) . ' MB. Bagi data menjadi beberapa file.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return $fail('File bukan Excel .xlsx yang valid. Simpan ulang dari Excel sebagai "Excel Workbook (.xlsx)".');
        }
        $total = 0;
        $hasWorkbook = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $total += (int) ($st['size'] ?? 0);
            $hasWorkbook = $hasWorkbook || ($st['name'] ?? '') === 'xl/workbook.xml';
        }
        $zip->close();
        if (!$hasWorkbook) {
            return $fail('File bukan Excel .xlsx yang valid. Simpan ulang dari Excel sebagai "Excel Workbook (.xlsx)".');
        }
        if ($total > self::MAX_UNCOMPRESSED) {
            return $fail('Isi file terlalu besar untuk diproses. Bagi data menjadi beberapa file.');
        }
        $wanted = [LegacyFormat::SHEET_PROJECT, LegacyFormat::SHEET_PART, LegacyFormat::SHEET_PROCESS];
        try {
            $reader = new Xlsx();
            $names = $reader->listWorksheetNames($path);
            $present = [];
            foreach ($names as $n) {
                foreach ($wanted as $w) {
                    if (LegacyFormat::norm($n) === LegacyFormat::norm($w)) {
                        $present[$w] = $n;
                    }
                }
            }
            foreach ($wanted as $w) {
                if (!isset($present[$w])) {
                    $errors[] = ['sheet' => $w, 'row' => 0, 'column' => '', 'message' => 'Sheet "' . $w . '" tidak ditemukan. Gunakan template dari aplikasi (Pengaturan › Impor Data Lama).'];
                }
            }
            if ($errors) {
                return ['rows' => [], 'errors' => $errors];
            }
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly(array_values($present));
            $book = $reader->load($path);
        } catch (\Throwable) {
            return $fail('File Excel tidak dapat dibaca (rusak atau terkunci kata sandi). Simpan ulang dari Excel sebagai .xlsx tanpa kata sandi.');
        }
        $rows = [];
        foreach ($wanted as $w) {
            $sh = $book->getSheetByName($present[$w]);
            if ($sh === null) {
                $errors[] = ['sheet' => $w, 'row' => 0, 'column' => '', 'message' => 'Sheet "' . $w . '" tidak dapat dibaca.'];
                continue;
            }
            $rows[$w] = self::readSheet($sh, $w, $errors);
        }
        $book->disconnectWorksheets();
        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param list<array{sheet:string,row:int,column:string,message:string}> $errors
     * @return list<array{row:int,values:array<string,string|int|null>}>
     */
    private static function readSheet(Worksheet $sh, string $sheet, array &$errors): array
    {
        $cols = LegacyFormat::COLUMNS[$sheet];
        $byTitle = [];
        foreach ($cols as $key => $def) {
            $byTitle[LegacyFormat::norm($def[0])] = $key;
        }
        $highestCol = min(Coordinate::columnIndexFromString($sh->getHighestDataColumn()), 60);
        $map = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $title = LegacyFormat::norm(self::text($sh, Coordinate::stringFromColumnIndex($c) . '1'));
            if ($title === '' || !isset($byTitle[$title])) {
                continue;
            }
            $key = $byTitle[$title];
            if (isset($map[$key])) {
                $errors[] = ['sheet' => $sheet, 'row' => 1, 'column' => $cols[$key][0], 'message' => 'Judul kolom "' . $cols[$key][0] . '" muncul lebih dari sekali.'];
                continue;
            }
            $map[$key] = Coordinate::stringFromColumnIndex($c);
        }
        $missing = [];
        foreach ($cols as $key => $def) {
            if ($def[1] !== false && !isset($map[$key])) {
                $missing[] = $def[0];
            }
        }
        if ($missing) {
            $errors[] = ['sheet' => $sheet, 'row' => 1, 'column' => '', 'message' => 'Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '. Jangan mengubah judul kolom template.'];
            return [];
        }
        $last = $sh->getHighestDataRow();
        if ($last - 1 > LegacyFormat::MAX_ROWS) {
            $errors[] = ['sheet' => $sheet, 'row' => 0, 'column' => '', 'message' => 'Maksimal ' . LegacyFormat::MAX_ROWS . ' baris data per sheet. Bagi data menjadi beberapa file.'];
            return [];
        }
        $out = [];
        for ($r = 2; $r <= $last; $r++) {
            $values = [];
            $empty = true;
            $rowErrors = [];
            foreach ($cols as $key => [$title, , $type, $max]) {
                if (!isset($map[$key])) {
                    $values[$key] = null;
                    continue;
                }
                $coord = $map[$key] . $r;
                [$v, $err] = self::value($sh, $coord, $type, (int) $max);
                if ($v !== null && $v !== '') {
                    $empty = false;
                }
                if ($err !== null) {
                    $empty = false;
                    $rowErrors[] = ['sheet' => $sheet, 'row' => $r, 'column' => $title, 'message' => $err];
                }
                $values[$key] = $v;
            }
            if ($empty) {
                continue;
            }
            array_push($errors, ...$rowErrors);
            $out[] = ['row' => $r, 'values' => $values];
        }
        return $out;
    }

    /** @return array{0:string|int|null,1:?string} [nilai, pesan kesalahan] */
    private static function value(Worksheet $sh, string $coord, string $type, int $max): array
    {
        if (!$sh->cellExists($coord)) {
            return [null, null];
        }
        $cell = $sh->getCell($coord);
        $raw = $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA || (is_string($raw) && str_starts_with($raw, '='))) {
            $raw = $cell->getOldCalculatedValue();
            if ($raw === null || $raw === '') {
                return [null, 'Sel berisi rumus tanpa nilai tersimpan. Salin kolom lalu Paste Values.'];
            }
        }
        if ($raw instanceof RichText) {
            $raw = $raw->getPlainText();
        }
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, null];
        }
        if ($type === 'date') {
            return self::date($raw);
        }
        if ($type === 'int') {
            if (is_int($raw) || (is_float($raw) && floor($raw) === $raw)) {
                return $raw >= 0 && $raw <= 4294967295 ? [(int) $raw, null] : [null, 'Angka harus 0 atau lebih.'];
            }
            $s = str_replace(['.', ',', ' '], '', trim((string) $raw));
            return ctype_digit($s) && strlen($s) <= 10 ? [(int) $s, null] : [null, 'Harus berupa angka bulat (mis. 50000).'];
        }
        if (is_float($raw)) {
            $s = floor($raw) === $raw && abs($raw) < 1e15 ? sprintf('%.0f', $raw) : rtrim(rtrim(sprintf('%.6F', $raw), '0'), '.');
        } elseif (is_bool($raw)) {
            $s = $raw ? 'TRUE' : 'FALSE';
        } else {
            $s = (string) $raw;
        }
        $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s));
        if ($type !== 'text' || $max <= 255) {
            $s = trim((string) preg_replace('/\s+/u', ' ', $s)); // nilai satu baris
        }
        if ($max > 0 && mb_strlen($s) > $max) {
            return [mb_substr($s, 0, $max), 'Maksimal ' . $max . ' karakter.'];
        }
        if ($type === 'email' && $s !== '') {
            $s = mb_strtolower($s);
            if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
                return [$s, 'Format email tidak valid.'];
            }
        }
        return [$s, null];
    }

    /** @return array{0:?string,1:?string} */
    public static function date(mixed $raw): array
    {
        $bad = 'Tanggal tidak valid. Gunakan format 25/03/2026 atau 2026-03-25.';
        if ($raw instanceof \DateTimeInterface) {
            return [$raw->format('Y-m-d'), null];
        }
        if (is_int($raw) || is_float($raw)) {
            if ($raw < 1 || $raw > 2958465) {
                return [null, $bad];
            }
            return [Date::excelToDateTimeObject((float) floor((float) $raw))->format('Y-m-d'), null];
        }
        $s = trim((string) $raw);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]]; // hari dulu (format Indonesia)
        } elseif (preg_match('/^\d+(\.\d+)?$/', $s)) {
            return self::date((float) $s);
        } else {
            return [null, $bad];
        }
        if (!checkdate($mo, $d, $y) || $y < 1900) {
            return [null, $bad];
        }
        return [sprintf('%04d-%02d-%02d', $y, $mo, $d), null];
    }

    private static function text(Worksheet $sh, string $coord): string
    {
        if (!$sh->cellExists($coord)) {
            return '';
        }
        $v = $sh->getCell($coord)->getValue();
        if ($v instanceof RichText) {
            $v = $v->getPlainText();
        }
        return is_scalar($v) ? (string) $v : '';
    }
}
