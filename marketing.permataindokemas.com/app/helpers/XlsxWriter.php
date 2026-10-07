<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Penulis file .xlsx minimalis (tanpa library): beberapa sheet, header tebal,
 * baris header dibekukan, lebar kolom otomatis, tanggal & angka bertipe asli
 * (bukan teks) sehingga bisa dijumlah/difilter di Excel.
 */
final class XlsxWriter
{
    /** @var list<array{name:string,headers:list<string>,rows:list<list<mixed>>}> */
    private array $sheets = [];

    /**
     * @param list<string> $headers
     * @param iterable<list<mixed>> $rows nilai: string|int|float|bool|null|DateTimeInterface
     */
    public function addSheet(string $name, array $headers, iterable $rows): self
    {
        $clean = preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name) ?? 'Sheet';
        $clean = mb_substr(trim($clean) !== '' ? trim($clean) : 'Sheet' . (count($this->sheets) + 1), 0, 31);
        $list = [];
        foreach ($rows as $row) {
            $list[] = array_values($row);
        }
        $this->sheets[] = ['name' => $clean, 'headers' => array_values($headers), 'rows' => $list];
        return $this;
    }

    public function save(string $path): void
    {
        if ($this->sheets === []) {
            $this->addSheet('Sheet1', [], []);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat file: ' . $path);
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($sheet['headers'], $sheet['rows']));
        }
        $zip->close();
    }

    /** Kirim sebagai download lalu hapus file sementara. */
    public function download(string $filename): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pikxlsx');
        if ($tmp === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara.');
        }
        $this->save($tmp);
        $safe = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $filename) ?? 'export.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: private, no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $_) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function styles(): string
    {
        // 0 = normal · 1 = tanggal (dd/mm/yyyy) · 2 = header tebal · 3 = angka ribuan · 4 = desimal
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F4"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function sheetXml(array $headers, array $rows): string
    {
        $widths = [];
        foreach ($headers as $c => $h) {
            $widths[$c] = max(8, mb_strlen($h) + 2);
        }
        $data = '';
        $r = 1;
        if ($headers !== []) {
            $data .= '<row r="1">';
            foreach ($headers as $c => $h) {
                $data .= '<c r="' . self::col($c) . '1" t="inlineStr" s="2"><is><t>' . self::esc($h) . '</t></is></c>';
            }
            $data .= '</row>';
            $r = 2;
        }
        foreach ($rows as $row) {
            $data .= '<row r="' . $r . '">';
            foreach ($row as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::col($c) . $r;
                if ($value instanceof DateTimeInterface) {
                    // pakai tanggal & jam "dinding" (bukan UTC) agar tanggal tidak bergeser
                    $wall = gmmktime((int) $value->format('H'), (int) $value->format('i'), (int) $value->format('s'),
                        (int) $value->format('n'), (int) $value->format('j'), (int) $value->format('Y'));
                    $serial = $wall / 86400 + 25569;
                    $data .= '<c r="' . $ref . '" s="1"><v>' . round($serial, 6) . '</v></c>';
                    $len = 10;
                } elseif (is_bool($value)) {
                    $data .= '<c r="' . $ref . '" t="b"><v>' . ($value ? 1 : 0) . '</v></c>';
                    $len = 5;
                } elseif (is_int($value)) {
                    $data .= '<c r="' . $ref . '" s="3"><v>' . $value . '</v></c>';
                    $len = strlen((string) $value) + 2;
                } elseif (is_float($value)) {
                    $data .= '<c r="' . $ref . '" s="4"><v>' . $value . '</v></c>';
                    $len = strlen(number_format($value, 2)) + 1;
                } else {
                    // sel inlineStr selalu teks (tidak pernah dieksekusi sebagai rumus)
                    $text = (string) $value;
                    $data .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc($text) . '</t></is></c>';
                    $len = min(60, mb_strlen($text) + 2);
                }
                $widths[$c] = max($widths[$c] ?? 8, $len);
            }
            $data .= '</row>';
            $r++;
        }
        $cols = '';
        foreach ($widths as $c => $w) {
            $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min(60, $w) . '" customWidth="1"/>';
        }
        $pane = $headers !== [] ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $pane . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $data . '</sheetData></worksheet>';
    }

    public static function col(int $index): string
    {
        $name = '';
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $n = intdiv($n - 1, 26);
        }
        return $name;
    }

    private static function esc(string $s): string
    {
        // hapus karakter kontrol yang tidak valid di XML
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
