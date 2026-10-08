<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Import\LegacyFormat;
use App\Import\LegacyTemplate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Membuat file impor dari template aplikasi lalu mengisinya seperti pengguna mengisi di Excel. */
trait LegacyImportFixtures
{
    /** @var list<string> */
    private array $legacyFiles = [];

    /**
     * Baris berisi [kunci kolom => nilai]. Nilai tanggal 'Y-m-d' ditulis sebagai tanggal Excel (serial);
     * awali dengan '=' untuk rumus, atau bungkus ['text' => '...'] untuk menulis teks apa adanya.
     * @param list<array<string,mixed>> $projects
     * @param list<array<string,mixed>> $parts
     * @param list<array<string,mixed>> $processes
     */
    protected function legacyFile(array $projects, array $parts = [], array $processes = [], ?string $template = null, bool $precalculate = true): string
    {
        $tpl = $this->scratch('tpl');
        file_put_contents($tpl, $template ?? LegacyTemplate::build());
        $book = IOFactory::load($tpl);
        foreach ([LegacyFormat::SHEET_PROJECT => $projects, LegacyFormat::SHEET_PART => $parts, LegacyFormat::SHEET_PROCESS => $processes] as $sheet => $rows) {
            $sh = $book->getSheetByName($sheet);
            $cols = [];
            $highest = Coordinate::columnIndexFromString($sh->getHighestColumn());
            for ($c = 1; $c <= $highest; $c++) {
                $title = LegacyFormat::norm((string) $sh->getCell([$c, 1])->getValue());
                foreach (LegacyFormat::COLUMNS[$sheet] as $key => $def) {
                    if (LegacyFormat::norm($def[0]) === $title) {
                        $cols[$key] = [$c, $def[2]];
                    }
                }
            }
            foreach ($rows as $i => $row) {
                foreach ($row as $key => $value) {
                    [$c, $type] = $cols[$key];
                    $coord = [$c, $i + 2];
                    if ($value === null) {
                        continue;
                    }
                    if (is_array($value)) {
                        $sh->setCellValueExplicit($coord, (string) $value['text'], DataType::TYPE_STRING);
                    } elseif (is_string($value) && str_starts_with($value, '=')) {
                        $sh->setCellValue($coord, $value);
                    } elseif ($type === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $sh->setCellValue($coord, Date::PHPToExcel(new \DateTimeImmutable($value)));
                    } elseif (is_int($value) || is_float($value)) {
                        $sh->setCellValueExplicit($coord, $value, DataType::TYPE_NUMERIC);
                    } else {
                        $sh->setCellValueExplicit($coord, (string) $value, DataType::TYPE_STRING);
                    }
                }
            }
        }
        $path = $this->scratch('xlsx');
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas($precalculate);
        $writer->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    protected function scratch(string $ext): string
    {
        $path = sys_get_temp_dir() . '/npd-legacy-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $this->legacyFiles[] = $path;
        return $path;
    }

    protected function cleanLegacyFiles(): void
    {
        foreach ($this->legacyFiles as $f) {
            @unlink($f);
        }
        $this->legacyFiles = [];
    }
}
