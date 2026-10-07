<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\BusinessRuleException;
use App\Core\I18n;
use App\Core\User;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel Timeline (PRD §6.7): satu sheet per level/part, No. Dokumen PIK-FORM-NPD-07 di sel kanan atas
 * setiap sheet, tanggal sebagai tanggal Excel sungguhan, baris judul dibekukan, filter aktif,
 * keterlambatan ditandai (kolom angka + warna merah).
 */
final class TimelineExcel
{
    private const HEADER_ROW = 7;
    private const DATE_FMT = 'dd-mmm-yyyy';

    public function __construct(private TimelineExport $export = new TimelineExport())
    {
    }

    /** @return array{filename:string,content:string} */
    public function build(User $user, int $projectId, string $scope, ?int $partId = null): array
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new BusinessRuleException('Library Excel (PhpSpreadsheet) belum terpasang. Jalankan: composer install');
        }
        $x = $this->export->build($user, $projectId, $scope, $partId);
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('NPD Project Control')->setCompany('PT. Permata Indo Kemas')
            ->setTitle('Project Timeline ' . $x['project']['code']);
        $book->removeSheetByIndex(0);
        $used = [];
        foreach ($x['sections'] as $s) {
            $title = $this->sheetTitle($s['level'] === 1 ? 'Level 1 - Project' : $s['title'], $used);
            $sheet = new Worksheet($book, $title);
            $book->addSheet($sheet);
            $s['level'] === 1 ? $this->level1($sheet, $x, $s) : $this->level2($sheet, $x, $s);
        }
        $book->setActiveSheetIndex(0);
        $tmp = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($tmp);
        rewind($tmp);
        $content = (string) stream_get_contents($tmp);
        fclose($tmp);
        $book->disconnectWorksheets();
        return ['filename' => PdfFactory::safeFilename($x['filename_base']) . '.xlsx', 'content' => $content];
    }

    /** Judul sheet: ≤31 karakter, tanpa : \ / ? * [ ], unik. @param array<string,bool> $used */
    private function sheetTitle(string $name, array &$used): string
    {
        $base = mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $name)) ?: 'Sheet', 0, 28);
        $title = $base;
        for ($i = 2; isset($used[mb_strtolower($title)]); $i++) {
            $title = mb_substr($base, 0, 26) . ' ' . $i;
        }
        $used[mb_strtolower($title)] = true;
        return $title;
    }

    /** Kop sheet: logo, judul, No. Dokumen di sel kanan atas, identitas project. @param array<string,mixed> $x */
    private function head(Worksheet $sh, array $x, string $subtitle, int $lastCol): void
    {
        $p = $x['project'];
        $last = Coordinate::stringFromColumnIndex($lastCol);
        $logo = PdfFactory::logoPath();
        if (is_file($logo)) {
            $d = new Drawing();
            $d->setPath($logo);
            $d->setHeight(38);
            $d->setCoordinates('A1');
            $d->setWorksheet($sh);
        }
        $sh->setCellValue('C1', 'PROJECT TIMELINE');
        $sh->getStyle('C1')->getFont()->setBold(true)->setSize(14);
        $sh->setCellValue('C2', $subtitle);
        $sh->getStyle('C2')->getFont()->setSize(10)->getColor()->setARGB('FF555555');
        // No. Dokumen di sel kanan atas setiap sheet (PRD §6.7) — tanpa nomor revisi
        $sh->setCellValue($last . '1', 'No. Dokumen: ' . TimelineExport::DOC_NO);
        $sh->getStyle($last . '1')->getFont()->setBold(true);
        $sh->getStyle($last . '1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sh->getStyle($last . '1')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        $info = [
            [I18n::t('project.project'), $p['code'] . ' — ' . $p['name'], I18n::t('npr.customer'), (string) $p['customer_name']],
            ['No. NPR', (string) $p['npr_number'], I18n::t('project.target_finish'), $p['target_finish']],
            [I18n::t('common.status'), TimelineExport::status((string) $p['status']), I18n::t('project.forecast_finish'), $p['forecast_finish']],
            [I18n::t('export.printed_by'), $x['printed_by'] . ' · ' . I18n::dateTime($x['printed_at']), '', null],
        ];
        $row = 3;
        foreach ($info as [$l1, $v1, $l2, $v2]) {
            $sh->setCellValue('A' . $row, $l1);
            $sh->setCellValue('C' . $row, $v1);
            if ($l2 !== '') {
                $sh->setCellValue('F' . $row, $l2);
                if (is_string($v2) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v2)) {
                    $this->date($sh, 'G' . $row, $v2);
                } else {
                    $sh->setCellValue('G' . $row, (string) ($v2 ?? '–'));
                }
            }
            $sh->getStyle('A' . $row)->getFont()->getColor()->setARGB('FF6E6E73');
            $sh->getStyle('F' . $row)->getFont()->getColor()->setARGB('FF6E6E73');
            $sh->getStyle('C' . $row . ':G' . $row)->getFont()->setBold(true);
            $row++;
        }
        $sh->getRowDimension(1)->setRowHeight(30);
    }

    /** @param list<string> $headers */
    private function tableHeader(Worksheet $sh, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sh->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . self::HEADER_ROW, $h);
        }
        $range = 'A' . self::HEADER_ROW . ':' . Coordinate::stringFromColumnIndex(count($headers)) . self::HEADER_ROW;
        $st = $sh->getStyle($range);
        $st->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1D1D1F');
        $st->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sh->getRowDimension(self::HEADER_ROW)->setRowHeight(28);
        $sh->freezePane('C' . (self::HEADER_ROW + 1)); // baris judul & kolom No/nama dibekukan
    }

    private function finish(Worksheet $sh, int $cols, int $lastRow, array $widths): void
    {
        $lastCol = Coordinate::stringFromColumnIndex($cols);
        if ($lastRow > self::HEADER_ROW) {
            $sh->setAutoFilter('A' . self::HEADER_ROW . ':' . $lastCol . $lastRow);
            $sh->getStyle('A' . self::HEADER_ROW . ':' . $lastCol . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCCCCCC');
            $sh->getStyle('A' . (self::HEADER_ROW + 1) . ':' . $lastCol . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        }
        foreach ($widths as $i => $w) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }
        $ps = $sh->getPageSetup();
        $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $ps->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $sh->getHeaderFooter()->setOddHeader('&R' . TimelineExport::DOC_NO)->setOddFooter('&L&F&RHal. &P / &N');
    }

    private function date(Worksheet $sh, string $cell, mixed $value): void
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $sh->setCellValue($cell, XlsDate::PHPToExcel(new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone('UTC'))));
            $sh->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        } else {
            $sh->setCellValue($cell, null);
        }
    }

    private function markLate(Worksheet $sh, string $range): void
    {
        $st = $sh->getStyle($range);
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFDE7E9');
        $st->getFont()->getColor()->setARGB('FF9B1020');
    }

    /** @param array<string,mixed> $x @param array<string,mixed> $s */
    private function level1(Worksheet $sh, array $x, array $s): void
    {
        $headers = ['No', I18n::t('timeline.part_or_process'), I18n::t('timeline.type'), I18n::t('common.status'), I18n::t('project.progress') . ' (%)', 'PIC',
            I18n::t('timeline.start'), I18n::t('timeline.planned_finish'), I18n::t('project.forecast_finish'), I18n::t('export.late_days'), I18n::t('export.late_count'), I18n::t('timeline.remark')];
        $this->head($sh, $x, $s['title'], count($headers));
        $this->tableHeader($sh, $headers);
        $row = self::HEADER_ROW;
        foreach ($s['data']['rows'] as $r) {
            $row++;
            $sh->setCellValue('A' . $row, (int) $r['no']);
            $sh->setCellValue('B' . $row, trim($r['code'] . ' ' . $r['name']));
            $sh->setCellValue('C' . $row, $r['kind'] === 'part' ? I18n::t('part_type.' . $r['part_type']) : I18n::t('timeline.project_level'));
            $sh->setCellValue('D' . $row, TimelineExport::status((string) $r['status']));
            $sh->setCellValue('E' . $row, $r['kind'] === 'part' ? (int) $r['progress'] : null);
            $sh->setCellValue('F' . $row, $r['pic'] ?? '');
            $this->date($sh, 'G' . $row, $r['kind'] === 'part' ? $r['actual_start'] : ($r['actual_start'] ?? $r['planned_start']));
            $this->date($sh, 'H' . $row, $r['planned_finish']);
            $this->date($sh, 'I' . $row, $r['kind'] === 'part' && $r['actual_finish'] ? $r['actual_finish'] : $r['forecast_finish']);
            $sh->setCellValue('J' . $row, $r['overdue_days'] > 0 ? (int) $r['overdue_days'] : 0);
            $sh->setCellValue('K' . $row, (int) ($r['overdue_count'] ?? ($r['overdue_days'] > 0 ? 1 : 0)));
            $sh->setCellValue('L' . $row, $r['remark']);
            if ($r['kind'] === 'part') {
                $sh->getStyle('A' . $row . ':L' . $row)->getFont()->setBold(true);
            }
            if ($r['overdue_days'] > 0) {
                $this->markLate($sh, 'A' . $row . ':L' . $row);
            }
        }
        $this->finish($sh, count($headers), $row, [6, 34, 14, 16, 11, 22, 14, 14, 14, 11, 11, 46]);
    }

    /** @param array<string,mixed> $x @param array<string,mixed> $s */
    private function level2(Worksheet $sh, array $x, array $s): void
    {
        $part = $s['data']['part'];
        $headers = ['No', I18n::t('process.process'), 'PIC', I18n::t('process.dependencies'), I18n::t('process.duration') . ' (' . I18n::t('process.working_days') . ')',
            I18n::t('timeline.planned_start'), I18n::t('timeline.planned_finish'), I18n::t('timeline.actual_start'), I18n::t('timeline.actual_finish'),
            I18n::t('process.deviation'), I18n::t('export.late_days'), I18n::t('common.status'), I18n::t('timeline.remark')];
        $this->head($sh, $x, $s['title'] . ' · ' . I18n::t('part_type.' . $part['part_type']), count($headers));
        $this->tableHeader($sh, $headers);
        $row = self::HEADER_ROW;
        foreach ($s['data']['rows'] as $r) {
            $row++;
            $sh->setCellValue('A' . $row, (int) $r['no']);
            $sh->setCellValue('B' . $row, $r['code'] . ' ' . $r['name'] . ($r['manual'] ? ' [M]' : ''));
            $sh->setCellValue('C' . $row, $r['pic'] ?? '');
            $sh->setCellValue('D' . $row, TimelineExport::deps($r['deps']));
            $sh->setCellValue('E' . $row, (int) $r['duration']);
            $this->date($sh, 'F' . $row, $r['planned_start']);
            $this->date($sh, 'G' . $row, $r['planned_finish']);
            $this->date($sh, 'H' . $row, $r['actual_start']);
            $this->date($sh, 'I' . $row, $r['actual_finish']);
            $sh->setCellValue('J' . $row, $r['deviation']);
            $sh->setCellValue('K' . $row, (int) $r['overdue_days']);
            $sh->setCellValue('L' . $row, TimelineExport::status((string) $r['status']));
            $sh->setCellValue('M' . $row, $r['remark']);
            if ($r['overdue_days'] > 0) {
                $this->markLate($sh, 'A' . $row . ':M' . $row);
            } elseif ($r['skipped']) {
                $sh->getStyle('A' . $row . ':M' . $row)->getFont()->setStrikethrough(true)->getColor()->setARGB('FF888888');
            }
        }
        $sh->getStyle('J' . (self::HEADER_ROW + 1) . ':J' . max($row, self::HEADER_ROW + 1))->getNumberFormat()->setFormatCode('+0;-0;0');
        $this->finish($sh, count($headers), $row, [6, 34, 20, 22, 12, 14, 14, 14, 14, 11, 11, 18, 44]);
    }
}
