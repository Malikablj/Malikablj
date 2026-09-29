<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Pembaca file .xlsx tanpa library eksternal (ZipArchive + XMLReader).
 *
 * Nilai sel dikembalikan sebagai:
 *   string | int | float | bool | null | DateTimeImmutable (sel berformat tanggal)
 * Rumus dibaca dari nilai terakhir yang tersimpan (cached value).
 */
final class XlsxReader
{
    private ZipArchive $zip;
    /** @var array<string,string> nama sheet => path xml di dalam zip */
    private array $sheets = [];
    /** @var list<string> */
    private array $sharedStrings = [];
    /** @var array<int,bool> index style => apakah format tanggal */
    private array $dateStyles = [];
    private bool $date1904 = false;

    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    public function __construct(string $path)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP "zip" belum aktif (dibutuhkan untuk membaca .xlsx).');
        }
        if (!is_file($path)) {
            throw new RuntimeException('File tidak ditemukan: ' . $path);
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('File bukan .xlsx yang valid: ' . basename($path));
        }
        $this->loadWorkbook();
        $this->loadSharedStrings();
        $this->loadStyles();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * Semua baris sheet sebagai array 0-based per kolom. Baris kosong tetap
     * dipertahankan (sebagai []) agar nomor baris sesuai dengan Excel:
     * index 0 = baris 1.
     * @return list<array<int,mixed>>
     */
    public function rows(string $sheet): array
    {
        if (!isset($this->sheets[$sheet])) {
            throw new RuntimeException('Sheet tidak ditemukan: ' . $sheet);
        }
        $xml = $this->zip->getFromName($this->sheets[$sheet]);
        if ($xml === false) {
            throw new RuntimeException('Isi sheet tidak dapat dibaca: ' . $sheet);
        }
        $reader = new XMLReader();
        $reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $rows = [];
        $currentRow = null;
        $rowIndex = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $r = (int) $reader->getAttribute('r');
                $rowIndex = $r > 0 ? $r : $rowIndex + 1;
                $currentRow = [];
                if ($reader->isEmptyElement) {
                    $rows[$rowIndex] = [];
                    $currentRow = null;
                }
                continue;
            }
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                $rows[$rowIndex] = $currentRow ?? [];
                $currentRow = null;
                continue;
            }
            if ($currentRow !== null && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $ref = (string) $reader->getAttribute('r');
                $type = (string) ($reader->getAttribute('t') ?? '');
                $style = (int) ($reader->getAttribute('s') ?? 0);
                $col = $ref !== '' ? self::columnIndex($ref) : count($currentRow);
                $raw = null;
                $inline = null;
                if (!$reader->isEmptyElement) {
                    $depth = $reader->depth;
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $reader->depth === $depth) {
                            break;
                        }
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
                            $raw = $reader->readString();
                        } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'is') {
                            $inline = $this->readInlineString($reader);
                        }
                    }
                }
                $currentRow[$col] = $this->convert($type, $raw, $inline, $style);
            }
        }
        $reader->close();
        if ($rows === []) {
            return [];
        }
        $max = max(array_keys($rows));
        $out = [];
        for ($i = 1; $i <= $max; $i++) {
            $row = $rows[$i] ?? [];
            if ($row !== []) {
                $width = max(array_keys($row)) + 1;
                $filled = array_fill(0, $width, null);
                foreach ($row as $c => $v) {
                    $filled[$c] = $v;
                }
                $row = $filled;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Baris sebagai array asosiatif berdasarkan header di baris pertama.
     * @return array{headers:list<string>,rows:list<array{row:int,data:array<string,mixed>}>}
     */
    public function table(string $sheet): array
    {
        $rows = $this->rows($sheet);
        if ($rows === []) {
            return ['headers' => [], 'rows' => []];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), $rows[0]);
        $out = [];
        foreach (array_slice($rows, 1, null, true) as $i => $row) {
            $data = [];
            $empty = true;
            foreach ($headers as $c => $h) {
                if ($h === '') {
                    continue;
                }
                $v = $row[$c] ?? null;
                if (is_string($v) && trim($v) === '') {
                    $v = null;
                }
                if ($v !== null) {
                    $empty = false;
                }
                $data[$h] = $v;
            }
            if (!$empty) {
                $out[] = ['row' => $i + 1, 'data' => $data];
            }
        }
        return ['headers' => array_values(array_filter($headers, static fn ($h) => $h !== '')), 'rows' => $out];
    }

    /** "AB12" => 27 (0-based) */
    public static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref)) ?? '';
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /** Serial date Excel => DateTimeImmutable (UTC, tanpa zona waktu). */
    public function serialToDate(float $serial): DateTimeImmutable
    {
        if ($this->date1904) {
            $serial += 1462;
        }
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        // 25569 = selisih hari 1899-12-30 s/d 1970-01-01
        $ts = ($days - 25569) * 86400 + $seconds;
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }

    private function convert(string $type, ?string $raw, ?string $inline, int $style): mixed
    {
        switch ($type) {
            case 's':
                return $raw === null ? null : ($this->sharedStrings[(int) $raw] ?? null);
            case 'inlineStr':
                return $inline;
            case 'str':
                return $raw;
            case 'b':
                return $raw === null ? null : $raw === '1';
            case 'e':
                return null; // #N/A, #REF!, dst.
            case 'd':
                return $raw === null || $raw === '' ? null : new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            return $raw;
        }
        if (($this->dateStyles[$style] ?? false) === true) {
            return $this->serialToDate((float) $raw);
        }
        if (preg_match('/^-?\d+$/', $raw) && strlen(ltrim($raw, '-')) < 16) {
            return (int) $raw;
        }
        $float = (float) $raw;
        // bilangan bulat yang disimpan sebagai 18810.0 / 1.2E+5
        if (abs($float) < 1e15 && floor($float) === $float) {
            return (int) $float;
        }
        return $float;
    }

    private function readInlineString(XMLReader $reader): string
    {
        $depth = $reader->depth;
        $text = '';
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'is' && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $text .= $reader->readString();
            }
        }
        return $text;
    }

    private function loadWorkbook(): void
    {
        $workbook = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('Struktur .xlsx tidak lengkap (workbook.xml).');
        }
        $targets = [];
        $relReader = new XMLReader();
        $relReader->XML($rels, 'UTF-8', LIBXML_NONET);
        while ($relReader->read()) {
            if ($relReader->nodeType === XMLReader::ELEMENT && $relReader->localName === 'Relationship') {
                $target = (string) $relReader->getAttribute('Target');
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $targets[(string) $relReader->getAttribute('Id')] = preg_replace('#/[^/]+/\.\./#', '/', $target) ?? $target;
            }
        }
        $relReader->close();
        $wb = new XMLReader();
        $wb->XML($workbook, 'UTF-8', LIBXML_NONET);
        while ($wb->read()) {
            if ($wb->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($wb->localName === 'workbookPr') {
                $this->date1904 = in_array(strtolower((string) $wb->getAttribute('date1904')), ['1', 'true'], true);
            }
            if ($wb->localName === 'sheet') {
                $rid = $wb->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
                    ?? $wb->getAttribute('r:id');
                $name = (string) $wb->getAttribute('name');
                if ($rid !== null && isset($targets[$rid])) {
                    $this->sheets[$name] = $targets[$rid];
                }
            }
        }
        $wb->close();
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;
        }
        $reader = new XMLReader();
        $reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_PARSEHUGE);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $depth = $reader->depth;
                $text = '';
                if (!$reader->isEmptyElement) {
                    while ($reader->read()) {
                        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si' && $reader->depth === $depth) {
                            break;
                        }
                        // abaikan teks fonetik (rPh)
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'rPh') {
                            $reader->next();
                            continue;
                        }
                        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                            $text .= $reader->readString();
                        }
                    }
                }
                $this->sharedStrings[] = $text;
            }
        }
        $reader->close();
    }

    private function loadStyles(): void
    {
        $xml = $this->zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return;
        }
        $customFormats = [];
        $reader = new XMLReader();
        $reader->XML($xml, 'UTF-8', LIBXML_NONET);
        $inCellXfs = false;
        $index = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'numFmt') {
                $customFormats[(int) $reader->getAttribute('numFmtId')] = (string) $reader->getAttribute('formatCode');
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'cellXfs') {
                $inCellXfs = true;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
                $inCellXfs = false;
            } elseif ($inCellXfs && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'xf') {
                $fmt = (int) $reader->getAttribute('numFmtId');
                $this->dateStyles[$index] = in_array($fmt, self::BUILTIN_DATE_FORMATS, true)
                    || (isset($customFormats[$fmt]) && self::isDateFormat($customFormats[$fmt]));
                $index++;
            }
        }
        $reader->close();
    }

    public static function isDateFormat(string $format): bool
    {
        // buang teks dalam tanda kutip dan blok [..] (warna/locale), kecuali durasi [h]
        $clean = preg_replace('/"[^"]*"|\\\\.|\[(?!h\]|hh\]|m\]|mm\]|s\]|ss\])[^\]]*\]/i', '', $format) ?? $format;
        if (preg_match('/^(General|@|0|#)/i', trim($clean)) && !preg_match('/[dy]/i', $clean)) {
            return false;
        }
        return (bool) preg_match('/[dmyhs]/i', $clean) && !preg_match('/^[#0.,%E+\-\s]*$/i', $clean);
    }
}
