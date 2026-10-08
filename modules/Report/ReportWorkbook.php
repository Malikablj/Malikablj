<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\BusinessRuleException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Workbook laporan (PhpSpreadsheet): kop berlogo, judul, keterangan periode/filter/pencetak di tiap sheet,
 * header tebal dibekukan + filter aktif, tanggal sebagai tanggal Excel sungguhan, angka sebagai angka,
 * baris bertanda (mis. overdue) diwarnai merah muda.
 */
final class ReportWorkbook
{
    private Spreadsheet $book;
    /** @var array<string,bool> */
    private array $used = [];

    /** @param list<array{0:string,1:string}> $info pasangan label → nilai di bawah judul */
    public function __construct(private string $title, private array $info = [])
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new BusinessRuleException('Library Excel (PhpSpreadsheet) belum terpasang. Jalankan: composer install');
        }
        $this->book = new Spreadsheet();
        $this->book->getProperties()->setCreator('NPD Project Control')->setCompany('PT. Permata Indo Kemas')->setTitle($title);
        $this->book->removeSheetByIndex(0);
        $this->book->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * Sheet tabel. $types per kolom: text|date|int|dec|pct (pct = angka 0–100 ditampilkan "0.0%").
     * $flag(row) true → baris diwarnai merah muda (mis. overdue/terlambat).
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     * @param list<string> $types
     * @param list<int> $widths
     */
    public function table(string $name, array $headers, array $rows, array $types = [], array $widths = [], ?callable $flag = null, string $empty = '–'): self
    {
        $sh = $this->newSheet($name);
        $cols = count($headers);
        $hr = $this->head($sh, $cols);
        foreach ($headers as $i => $h) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $hr, $h);
        }
        $lastCol = Coordinate::stringFromColumnIndex($cols);
        $st = $sh->getStyle("A$hr:$lastCol$hr");
        $st->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1D1D1F');
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sh->getRowDimension($hr)->setRowHeight(26);
        $row = $hr + 1;
        if (!$rows) {
            $sh->setCellValue('A' . $row, $empty);
            $sh->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setARGB('FF6E6E73');
            $row++;
        }
        $first = $row;
        $flagged = [];
        foreach ($rows as $r) {
            foreach (array_values($r) as $i => $v) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
                $type = $types[$i] ?? 'text';
                if ($v === null || $v === '') {
                    $sh->setCellValue($cell, $type === 'text' ? (string) ($v ?? '') : null);
                    continue;
                }
                switch ($type) {
                    case 'date':
                        $sh->setCellValue($cell, XlsDate::PHPToExcel(new \DateTimeImmutable((string) $v)));
                        break;
                    case 'int':
                    case 'dec':
                        $sh->setCellValueExplicit($cell, $type === 'int' ? (int) $v : (float) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                        break;
                    case 'pct':
                        $sh->setCellValueExplicit($cell, (float) $v / 100, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                        break;
                    default:
                        // teks: cegah formula injection (nilai diawali = + - @ dianggap teks)
                        $sh->setCellValueExplicit($cell, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            if ($flag !== null && $flag($r)) {
                $flagged[] = $row;
            }
            $row++;
        }
        // Format angka/tanggal & warna baris diterapkan per rentang (bukan per sel): ribuan baris tetap cepat
        if ($rows) {
            $formats = ['date' => 'dd-mmm-yyyy', 'dec' => '0.0', 'pct' => '0.0%'];
            foreach ($types as $i => $type) {
                if (isset($formats[$type])) {
                    $col = Coordinate::stringFromColumnIndex($i + 1);
                    $sh->getStyle($col . $first . ':' . $col . ($row - 1))->getNumberFormat()->setFormatCode($formats[$type]);
                }
            }
            foreach (self::blocks($flagged) as [$a, $b]) {
                $sh->getStyle("A$a:$lastCol$b")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDE2E1');
            }
        }
        // rata atas = gaya bawaan workbook; bungkus teks hanya pada kolom teks (gaya per sel mahal untuk ribuan baris)
        foreach (range(1, $cols) as $i) {
            if (($types[$i - 1] ?? 'text') === 'text') {
                $col = Coordinate::stringFromColumnIndex($i);
                $sh->getStyle($col . ($hr + 1) . ':' . $col . max($hr + 1, $row - 1))->getAlignment()->setWrapText(true);
            }
        }
        if ($rows) {
            $sh->setAutoFilter("A$hr:$lastCol" . ($row - 1));
        }
        $sh->freezePane('A' . ($hr + 1));
        foreach (range(1, $cols) as $i) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth($widths[$i - 1] ?? 16);
        }
        $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sh->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($hr, $hr);
        return $this;
    }

    /**
     * Kelompokkan nomor baris berurutan menjadi rentang [awal, akhir].
     * @param list<int> $rows
     * @return list<array{0:int,1:int}>
     */
    private static function blocks(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $r - 1) {
                $out[$last][1] = $r;
            } else {
                $out[] = [$r, $r];
            }
        }
        return $out;
    }

    /** @return array{filename:string,content:string} */
    public function output(string $filenameBase): array
    {
        $this->book->setActiveSheetIndex(0);
        $tmp = fopen('php://temp', 'w+b');
        (new Xlsx($this->book))->save($tmp);
        rewind($tmp);
        $content = (string) stream_get_contents($tmp);
        fclose($tmp);
        $this->book->disconnectWorksheets();
        return ['filename' => PdfFactory::safeFilename($filenameBase) . '.xlsx', 'content' => $content];
    }

    private function newSheet(string $name): Worksheet
    {
        $base = mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $name)) ?: 'Sheet', 0, 28);
        $title = $base;
        for ($i = 2; isset($this->used[mb_strtolower($title)]); $i++) {
            $title = mb_substr($base, 0, 26) . ' ' . $i;
        }
        $this->used[mb_strtolower($title)] = true;
        $sh = new Worksheet($this->book, $title);
        $this->book->addSheet($sh);
        return $sh;
    }

    /** Kop sheet; mengembalikan nomor baris header tabel. */
    private function head(Worksheet $sh, int $cols): int
    {
        $logo = PdfFactory::logoPath();
        if (is_file($logo)) {
            $d = new Drawing();
            $d->setPath($logo);
            $d->setHeight(36);
            $d->setCoordinates('A1');
            $d->setWorksheet($sh);
        }
        $sh->setCellValue('C1', mb_strtoupper($this->title));
        $sh->getStyle('C1')->getFont()->setBold(true)->setSize(14);
        $sh->setCellValue('C2', 'PT. Permata Indo Kemas');
        $sh->getStyle('C2')->getFont()->getColor()->setARGB('FF6E6E73');
        $sh->getRowDimension(1)->setRowHeight(30);
        $row = 3;
        foreach ($this->info as [$label, $value]) {
            $sh->setCellValue('A' . $row, $label);
            $sh->setCellValueExplicit('C' . $row, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sh->getStyle('A' . $row)->getFont()->getColor()->setARGB('FF6E6E73');
            $sh->getStyle('C' . $row)->getFont()->setBold(true);
            $row++;
        }
        return $row + 1;
    }
}
