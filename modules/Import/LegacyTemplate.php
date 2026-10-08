<?php
declare(strict_types=1);

namespace App\Import;

use App\Core\Clock;
use App\Core\Db;
use App\Workflow\WorkflowInstantiator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Template Excel impor data project lama: sheet Petunjuk, Project, Part, Proses, Daftar.
 * Daftar berisi customer, user per role, dan proses dari template workflow AKTIF saat template diunduh,
 * dipakai sebagai dropdown (data validation) agar isian cocok dengan sistem.
 */
final class LegacyTemplate
{
    /** Baris data yang diberi format & dropdown (data di luar baris ini tetap dibaca, hanya tanpa dropdown). */
    private const ROWS = 2000;

    /**
     * Daftar pilihan dari database.
     * @return array{customers:list<array{0:string,1:string}>,users:array<string,list<array{0:string,1:string}>>,all_users:list<string>,processes:list<array<string,mixed>>,gate:bool}
     */
    public static function listsFromDb(): array
    {
        $customers = array_map(static fn ($r) => [(string) $r['code'], (string) $r['name']],
            Db::fetchAll('SELECT code, name FROM customers WHERE is_active = 1 ORDER BY code'));
        $users = ['sales' => [], 'npd' => [], 'drafter' => [], 'purchasing' => [], 'production' => [], 'quality' => []];
        $all = [];
        foreach (Db::fetchAll('SELECT u.email, u.name, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.name') as $u) {
            $row = [(string) $u['email'], (string) $u['name']];
            $all[] = (string) $u['email'];
            match ((string) $u['role']) {
                'admin_sales' => $users['sales'][] = $row,
                'npd_staff', 'admin' => $users['npd'][] = $row,
                'drafter', 'purchasing', 'production', 'quality' => $users[(string) $u['role']][] = $row,
                default => null,
            };
        }
        $roleNames = [];
        foreach (Db::fetchAll('SELECT code, name_id AS name FROM roles') as $ro) {
            $roleNames[(string) $ro['code']] = (string) $ro['name'];
        }
        $inst = new WorkflowInstantiator();
        $processes = [];
        $gate = false;
        foreach (['project' => 'Level project', 'new_mold' => 'New Mold', 'subcont' => 'Subcont'] as $tpl => $scope) {
            try {
                $v = $inst->currentVersion($tpl);
            } catch (\Throwable) {
                continue;
            }
            if ($tpl === 'project') {
                $gate = (int) $v['gate_enabled'] === 1;
            }
            foreach ($inst->steps((int) $v['version_id']) as $s) {
                if ($tpl === 'project' && ($s['step_type'] !== 'gate' || !$gate)) {
                    continue; // P1/P2/PF diisi dari sheet Project
                }
                if ($s['activation'] === 'loop_only') {
                    continue; // hanya terjadi lewat keputusan di sistem
                }
                $processes[] = [
                    'option' => LegacyFormat::processOption((string) $s['code'], (string) $s['name']),
                    'scope' => $scope, 'role' => $roleNames[(string) $s['pic_role_code']] ?? (string) $s['pic_role_code'], 'duration' => (int) $s['default_duration'],
                    'skippable' => (int) $s['is_skippable'] === 1, 'skip_group' => (string) ($s['skip_group'] ?? ''),
                    'finish' => $s['step_type'] === 'finish',
                ];
            }
        }
        return ['customers' => $customers, 'users' => $users, 'all_users' => $all, 'processes' => $processes, 'gate' => $gate];
    }

    /** @param array<string,mixed>|null $lists hasil listsFromDb() @return string isi file .xlsx */
    public static function build(?array $lists = null): string
    {
        $lists ??= self::listsFromDb();
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('NPD Project Control')->setTitle('Template Impor Data Project Lama');
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $guide = $book->getActiveSheet()->setTitle(LegacyFormat::SHEET_GUIDE);
        $ranges = self::listsSheet($book->createSheet()->setTitle(LegacyFormat::SHEET_LISTS), $lists);
        $sheets = [];
        foreach ([LegacyFormat::SHEET_PROJECT, LegacyFormat::SHEET_PART, LegacyFormat::SHEET_PROCESS] as $i => $name) {
            $sheets[$name] = $book->createSheet($i + 1)->setTitle($name);
        }
        $lists2 = [
            LegacyFormat::SHEET_PROJECT => ['customer' => $ranges['customers'], 'sales' => $ranges['sales'], 'npd' => $ranges['npd'],
                'priority' => $ranges['priority'], 'status' => $ranges['project_status']],
            LegacyFormat::SHEET_PART => ['type' => $ranges['part_type'], 'drafter' => $ranges['drafter'], 'purchasing' => $ranges['purchasing'],
                'production' => $ranges['production'], 'quality' => $ranges['quality'], 'status' => $ranges['part_status']],
            LegacyFormat::SHEET_PROCESS => ['process' => $ranges['processes'], 'status' => $ranges['process_status'], 'pic' => $ranges['all_users']],
        ];
        foreach ($sheets as $name => $sh) {
            self::dataSheet($sh, LegacyFormat::COLUMNS[$name], $lists2[$name]);
        }
        self::guideSheet($guide, $lists);
        $book->setActiveSheetIndex(0);
        $tmp = fopen('php://temp', 'w+b');
        (new Xlsx($book))->save($tmp);
        rewind($tmp);
        $content = (string) stream_get_contents($tmp);
        fclose($tmp);
        $book->disconnectWorksheets();
        return $content;
    }

    public static function filename(): string
    {
        return 'Template-Impor-Data-Lama-NPD-' . Clock::todayString() . '.xlsx';
    }

    /**
     * @param array<string,array{0:string,1:mixed,2:string,3:int,4:string}> $cols
     * @param array<string,?string> $lists kunci kolom → rumus rentang daftar
     */
    private static function dataSheet(Worksheet $sh, array $cols, array $lists): void
    {
        $i = 0;
        foreach ($cols as $key => [$title, $required, $type, $max, $note]) {
            $i++;
            $col = Coordinate::stringFromColumnIndex($i);
            $cell = $col . '1';
            $sh->setCellValueExplicit($cell, $title . ($required === true ? ' *' : ''), DataType::TYPE_STRING);
            $st = $sh->getStyle($cell);
            $st->getFont()->setBold(true)->getColor()->setARGB($required === true ? 'FFFFFFFF' : 'FF1D1D1F');
            $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($required === true ? 'FF1D1D1F' : ($required === 'cond' ? 'FFFFE08A' : 'FFE5E5EA'));
            $st->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
            $sh->getComment($cell)->setWidth('320pt')->setHeight('90pt')->getText()->createTextRun($note . ($required === true ? ' (WAJIB)' : ''));
            $sh->getColumnDimension($col)->setWidth(match ($type) {
                'date' => 14, 'int' => 13, 'email' => 30, 'choice' => 16,
                default => $key === 'name' || $key === 'note' || $key === 'reason' || $key === 'process' ? 38 : 20,
            });
            $range = $col . '2:' . $col . self::ROWS;
            // format kolom (tanpa membuat sel): tanggal tampil dd/mm/yyyy, teks tetap teks (nol di depan tidak hilang)
            $sh->getStyle($col . ':' . $col)->getNumberFormat()->setFormatCode(match ($type) {
                'date' => 'dd/mm/yyyy', 'int' => '0', default => '@',
            });
            $sh->getStyle($cell)->getNumberFormat()->setFormatCode('@');
            if ($type === 'date') {
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_DATE)->setOperator(DataValidation::OPERATOR_BETWEEN)
                    ->setFormula1('DATE(2000,1,1)')->setFormula2('DATE(2099,12,31)')
                    ->setAllowBlank(true)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setErrorTitle('Tanggal tidak valid')->setError('Isi tanggal, mis. 25/03/2026.');
                $sh->setDataValidation($range, $dv);
            } elseif (($lists[$key] ?? null) !== null) {
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST)->setFormula1((string) $lists[$key])->setAllowBlank(true)->setShowDropDown(true)
                    ->setShowErrorMessage(true)->setErrorStyle($type === 'email' ? DataValidation::STYLE_WARNING : DataValidation::STYLE_STOP)
                    ->setErrorTitle('Pilih dari daftar')->setError('Nilai harus dipilih dari daftar (sheet Daftar).');
                $sh->setDataValidation($range, $dv);
            }
        }
        $last = Coordinate::stringFromColumnIndex($i);
        $sh->getRowDimension(1)->setRowHeight(32);
        $sh->freezePane('A2');
        $sh->setAutoFilter('A1:' . $last . '1');
        $sh->setSelectedCells('A2');
    }

    /**
     * Sheet Daftar: satu daftar per kolom. @param array<string,mixed> $lists
     * @return array<string,?string> nama daftar → rumus rentang (null bila daftar kosong)
     */
    private static function listsSheet(Worksheet $sh, array $lists): array
    {
        $ranges = [];
        $col = 0;
        $put = static function (string $name, string $title, array $values, ?string $title2 = null, array $values2 = []) use ($sh, &$col, &$ranges): void {
            $col++;
            $c = Coordinate::stringFromColumnIndex($col);
            $sh->setCellValueExplicit($c . '1', $title, DataType::TYPE_STRING);
            $sh->getStyle($c . '1')->getFont()->setBold(true);
            $sh->getColumnDimension($c)->setWidth(max(14, min(48, max(array_map('mb_strlen', array_merge([$title], $values))) + 2)));
            foreach ($values as $i => $v) {
                $sh->setCellValueExplicit($c . ($i + 2), (string) $v, DataType::TYPE_STRING);
            }
            $ranges[$name] = $values ? "'" . LegacyFormat::SHEET_LISTS . "'!\$$c\$2:\$$c\$" . (count($values) + 1) : null;
            if ($title2 !== null) {
                $col++;
                $c2 = Coordinate::stringFromColumnIndex($col);
                $sh->setCellValueExplicit($c2 . '1', $title2, DataType::TYPE_STRING);
                $sh->getStyle($c2 . '1')->getFont()->setBold(true);
                $sh->getColumnDimension($c2)->setWidth(max(14, min(48, max(array_map('mb_strlen', array_merge([$title2], $values2))) + 2)));
                foreach ($values2 as $i => $v) {
                    $sh->setCellValueExplicit($c2 . ($i + 2), (string) $v, DataType::TYPE_STRING);
                }
            }
            $col++; // kolom kosong pemisah
        };
        $put('customers', 'Kode Customer', array_column($lists['customers'], 0), 'Nama Customer', array_column($lists['customers'], 1));
        $put('sales', 'Email Sales', array_column($lists['users']['sales'], 0), 'Nama', array_column($lists['users']['sales'], 1));
        $put('npd', 'Email NPD Staff / Admin', array_column($lists['users']['npd'], 0), 'Nama', array_column($lists['users']['npd'], 1));
        foreach (['drafter' => 'Drafter', 'purchasing' => 'Purchasing', 'production' => 'Production', 'quality' => 'Quality'] as $k => $label) {
            $put($k, 'Email ' . $label, array_column($lists['users'][$k], 0), 'Nama', array_column($lists['users'][$k], 1));
        }
        $put('all_users', 'Email semua user aktif', $lists['all_users']);
        $p = $lists['processes'];
        $put('processes', 'Proses', array_column($p, 'option'), 'Berlaku untuk', array_column($p, 'scope'));
        $put('project_status', 'Status Project', LegacyFormat::labels('project_status'));
        $put('part_status', 'Status Part', LegacyFormat::labels('part_status'));
        $put('part_type', 'Jenis Part', LegacyFormat::labels('part_type'));
        $put('priority', 'Prioritas', LegacyFormat::labels('priority'));
        $put('process_status', 'Status Proses', LegacyFormat::labels('process_status'));
        $sh->freezePane('A2');
        return $ranges;
    }

    /** @param array<string,mixed> $lists */
    private static function guideSheet(Worksheet $sh, array $lists): void
    {
        $sh->getColumnDimension('A')->setWidth(24);
        $sh->getColumnDimension('B')->setWidth(30);
        $sh->getColumnDimension('C')->setWidth(14);
        $sh->getColumnDimension('D')->setWidth(110);
        $r = 1;
        $line = static function (string $a, string $b = '', string $c = '', string $d = '', bool $bold = false) use ($sh, &$r): void {
            foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $col => $v) {
                if ($v !== '') {
                    $sh->setCellValueExplicit($col . $r, $v, DataType::TYPE_STRING);
                }
            }
            if ($bold) {
                $sh->getStyle("A$r:D$r")->getFont()->setBold(true);
            }
            $sh->getStyle("A$r:D$r")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $r++;
        };
        $sh->setCellValueExplicit('A1', 'Template Impor Data Project Lama — NPD Project Control v3.0 (PT. Permata Indo Kemas)', DataType::TYPE_STRING);
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $r = 2;
        $line('Diunduh: ' . Clock::todayString() . ' — daftar customer, user, dan proses di sheet Daftar sesuai data aplikasi saat itu.');
        $r++;
        $line('LANGKAH', '', '', '', true);
        foreach ([
            '1. Buat dulu semua Customer (Pengaturan › Customer) dan User (Pengaturan › User), lalu unduh ulang template ini agar dropdown berisi data terbaru.',
            '2. Sheet Project: satu baris per project/NPR. Sheet Part: satu baris per part. Sheet Proses: satu baris per proses yang SUDAH Selesai, sedang Berjalan, atau Dilewati.',
            '3. Proses yang tidak ditulis di sheet Proses dianggap belum mulai dan dijadwalkan sistem mulai tanggal impor.',
            '4. Unggah di aplikasi: Pengaturan › Impor Data Lama › Periksa file. Sistem menampilkan semua kesalahan per sheet & baris tanpa menyimpan apa pun.',
            '5. Bila tidak ada kesalahan, klik Impor. Semua project disimpan sekaligus; bila satu gagal, tidak ada yang tersimpan.',
        ] as $t) {
            $line($t);
            $sh->mergeCells('A' . ($r - 1) . ':D' . ($r - 1));
        }
        $r++;
        $line('ATURAN PENTING', '', '', '', true);
        foreach ([
            'Tanggal: isi sebagai tanggal Excel (mis. 25/03/2026) atau teks 2026-03-25 / 25/03/2026. Tidak boleh di masa depan (kecuali Target Finish dan Rencana Selesai).',
            'Kolom bertanda * wajib. Kolom kuning wajib pada kondisi tertentu (lihat keterangan). Arahkan kursor ke judul kolom untuk melihat keterangan.',
            'Jangan mengubah judul kolom dan nama sheet. Urutan kolom boleh diubah; kolom tambahan diabaikan.',
            'Sel berisi rumus: salin lalu Paste Values dulu (rumus tidak dihitung saat impor demi keamanan).',
            'Urutan proses harus masuk akal: proses Selesai/Berjalan hanya boleh bila semua pendahulunya Selesai atau Dilewati (sesuai dependency template workflow).',
            'Proses yang boleh Dilewati hanya yang ditandai "Ya" di kolom Boleh dilewati di bawah; proses satu grup (mis. Masterbatch N1 & N2) dilewati bersama.',
            'Part selesai: isi proses Finish (part) sebagai Selesai. Project Selesai: semua part aktif selesai dan isi Tanggal Selesai Project.',
            'Mold Correction (N9) tidak diimpor: hanya terjadi lewat keputusan T0 Not OK di sistem. Bila koreksi mold sedang berjalan, isi T0 Trial (N8) sebagai Berjalan dan catat di Keterangan.',
            'Dokumen dan approval lama tidak ikut diimpor; unggah dokumen yang diperlukan di halaman proses setelah impor.',
            'Proses dari data lama (Selesai & yang sedang Berjalan saat impor) TIDAK dihitung di KPI per PIC, Analytics, maupun Weekly Report. Proses yang dimulai setelah impor dihitung normal.',
            'Impor tidak mengirim notifikasi/email. Tugas langsung terlihat di dasbor PIC; pengingat overdue berjalan normal lewat cron.',
            'Hold/Batal tercatat pada tanggal impor; tulis tanggal aslinya di kolom Alasan bila perlu.',
            'No. NPR / Kode Project lama dipakai apa adanya (harus unik). Nomor berformat sistem (001/PIK/NPR/X/2026, NPD-2026-001) membuat penomoran berikutnya melanjutkan dari nomor tertinggi.',
        ] as $t) {
            $line('• ' . $t);
            $sh->mergeCells('A' . ($r - 1) . ':D' . ($r - 1));
        }
        $r++;
        $line('KOLOM', '', '', '', true);
        $line('Sheet', 'Kolom', 'Wajib', 'Keterangan', true);
        foreach (LegacyFormat::COLUMNS as $sheet => $cols) {
            foreach ($cols as [$title, $required, , , $note]) {
                $line($sheet, $title, $required === true ? 'Wajib' : ($required === 'cond' ? 'Bersyarat' : '-'), $note);
            }
        }
        $r++;
        $line('PROSES (template workflow aktif)', '', '', '', true);
        $line('Proses', 'Berlaku untuk', 'Durasi bawaan', 'PIC (role) / Boleh dilewati', true);
        foreach ($lists['processes'] as $p) {
            $line($p['option'], $p['scope'], (string) $p['duration'] . ' hari kerja',
                'PIC: ' . $p['role'] . ' · Boleh dilewati: ' . ($p['skippable'] ? 'Ya' . ($p['skip_group'] !== '' ? ' (grup ' . $p['skip_group'] . ')' : '') : 'Tidak') . ($p['finish'] ? ' · Selesai = part selesai' : ''));
        }
        if (!$lists['gate']) {
            $line('Gate Assembly / Fit Test (G1) tidak aktif di template project, sehingga tidak perlu diisi.');
        }
        $r++;
        $line('CONTOH PENGISIAN (contoh saja — jangan disalin; sheet ini tidak ikut diimpor)', '', '', '', true);
        foreach ([
            ['Project', 'Ref Project = PRJ-2025-014 · Nama = Botol Serum 30ml · Kode Customer = (dari daftar) · Status Project = Berjalan · Tanggal NPR = 03/02/2025'],
            ['Part', 'PRJ-2025-014 · Body · New Mold      |      PRJ-2025-014 · Cap · Subcont'],
            ['Proses', 'PRJ-2025-014 · Body · N1 — Masterbatch Development · Dilewati · (Keterangan: pakai masterbatch lama)'],
            ['Proses', 'PRJ-2025-014 · Body · N2 — Customer Masterbatch Approval · Dilewati'],
            ['Proses', 'PRJ-2025-014 · Body · N3 — 3D Prototype Development · Selesai · 10/02/2025 · 20/02/2025'],
            ['Proses', 'PRJ-2025-014 · Body · N4 — Customer 3D Approval · Berjalan · 21/02/2025 · (Rencana Selesai boleh diisi)'],
            ['Proses', 'PRJ-2025-014 · Cap · S1 — Artwork Development · Berjalan · 12/02/2025'],
        ] as [$a, $d]) {
            $line($a, $d);
            $sh->mergeCells('B' . ($r - 1) . ':D' . ($r - 1));
        }
    }
}
