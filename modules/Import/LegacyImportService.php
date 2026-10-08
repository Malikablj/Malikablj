<?php
declare(strict_types=1);

namespace App\Import;

use App\Approval\ApprovalService;
use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\NumberSequence;
use App\Core\User;
use App\Core\ValidationException;
use App\Master\MasterService;
use App\Notification\Notifier;
use App\Npr\NprFields;
use App\Project\HoldService;
use App\Project\LifecycleService;
use App\Project\RevisionHistory;
use App\Project\StatusService;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\WorkflowEngine;
use App\Workflow\WorkflowInstantiator;

/**
 * Impor data project lama dari Excel (format: LegacyFormat, template: LegacyTemplate).
 *
 * analyze(): membaca & memvalidasi seluruh file TANPA menyimpan — kesalahan per sheet/baris/kolom + peringatan.
 * commit():  menjalankan analyze() ulang lalu menyimpan SEMUA project dalam satu transaksi (gagal satu → batal semua).
 *
 * Aturan (OQ-36..OQ-38, docs/IMPORT_DATA_LAMA.md):
 *  - Satu baris Project = satu NPR (status Selesai Feedback, semua part diterima) + satu project.
 *  - Proses dibuat dari template workflow AKTIF; status dari sheet Proses (tidak tertulis = belum mulai).
 *    Proses Selesai memakai tanggal aktual file; Planned-nya kosong (rencana lama tidak dikarang).
 *  - Proses yang belum mulai dijadwalkan mulai tanggal impor (schedule_floor) lalu diaktifkan sesuai aturan biasa.
 *  - Run data lama (Selesai & Berjalan saat impor) ditandai is_imported → tidak dihitung KPI/Analytics/Weekly.
 *  - Hold/Batal dijalankan lewat layanan biasa (riwayat & aturan sama), tanggalnya = tanggal impor.
 *  - Notifikasi/email tidak dikirim selama impor.
 */
final class LegacyImportService
{
    private const NPR_PATTERN = '#^(\d{3,})/PIK/NPR/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/(\d{4})$#';
    private const CODE_PATTERN = '/^NPD-(\d{4})-(\d{3,})$/';
    private const DONE = ['completed', 'skipped'];

    private ?WorkingCalendar $cal = null;
    /** @var array<string,array<string,mixed>> */
    private array $templates = [];

    public function __construct(private ScheduleService $schedule = new ScheduleService())
    {
    }

    // ================================================================ analisis

    /**
     * @return array{errors:list<array{sheet:string,row:int,column:string,message:string}>,warnings:list<array{sheet:string,row:int,column:string,message:string}>,plan:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function analyze(string $path): array
    {
        $read = LegacyWorkbook::read($path);
        $errors = $read['errors'];
        $warnings = [];
        if (!isset($read['rows'][LegacyFormat::SHEET_PROJECT])) {
            return ['errors' => $errors, 'warnings' => [], 'plan' => [], 'summary' => self::emptySummary()];
        }
        // satu pesan per sel: sel yang formatnya sudah salah tidak dilaporkan lagi sebagai "wajib diisi"
        $seen = [];
        foreach ($errors as $e) {
            $seen[$e['sheet'] . '|' . $e['row'] . '|' . $e['column']] = true;
        }
        $err = static function (string $sheet, int $row, string $col, string $msg) use (&$errors, &$seen): void {
            $k = $sheet . '|' . $row . '|' . $col;
            if ($row > 0 && $col !== '' && isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $errors[] = ['sheet' => $sheet, 'row' => $row, 'column' => $col, 'message' => $msg];
        };
        $warn = static function (string $sheet, int $row, string $col, string $msg) use (&$warnings): void {
            $warnings[] = ['sheet' => $sheet, 'row' => $row, 'column' => $col, 'message' => $msg];
        };
        $this->loadTemplates();
        $today = Clock::todayString();
        $cols = LegacyFormat::COLUMNS;
        $title = static fn (string $sheet, string $key): string => $cols[$sheet][$key][0];

        // ---- data acuan
        $rows = $read['rows'];
        $refs = array_values(array_unique(array_filter(array_map(static fn ($r) => (string) ($r['values']['ref'] ?? ''), $rows[LegacyFormat::SHEET_PROJECT]))));
        $emails = [];
        $custCodes = [];
        foreach ($rows as $list) {
            foreach ($list as $r) {
                foreach (['sales', 'npd', 'drafter', 'purchasing', 'production', 'quality', 'pic'] as $k) {
                    if (!empty($r['values'][$k])) {
                        $emails[(string) $r['values'][$k]] = true;
                    }
                }
                if (!empty($r['values']['customer'])) {
                    $custCodes[(string) $r['values']['customer']] = true;
                }
            }
        }
        $users = [];
        if ($emails) {
            $e = array_keys($emails);
            foreach (Db::fetchAll('SELECT u.id, u.name, u.email, u.job_title, u.is_active, r.code AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE LOWER(u.email) IN ' . Db::in($e), $e) as $u) {
                $users[mb_strtolower((string) $u['email'])] = $u;
            }
        }
        $customers = [];
        if ($custCodes) {
            $c = array_keys($custCodes);
            foreach (Db::fetchAll('SELECT * FROM customers WHERE code IN ' . Db::in($c), $c) as $cu) {
                $customers[mb_strtolower((string) $cu['code'])] = $cu;
            }
        }
        $takenRefs = $refs ? array_flip(array_map('mb_strtolower', Db::column('SELECT legacy_ref FROM projects WHERE legacy_ref IN ' . Db::in($refs), $refs))) : [];

        $userFor = function (string $sheet, int $row, string $key, ?string $email, array $roles, string $roleLabel) use (&$users, $err, $title): ?array {
            if ($email === null || $email === '') {
                return null;
            }
            $u = $users[mb_strtolower($email)] ?? null;
            if ($u === null) {
                $err($sheet, $row, $title($sheet, $key), 'User dengan email ' . $email . ' tidak ditemukan. Buat dulu di Pengaturan › User.');
                return null;
            }
            if ((int) $u['is_active'] !== 1) {
                $err($sheet, $row, $title($sheet, $key), 'User ' . $email . ' nonaktif.');
                return null;
            }
            if (!in_array($u['role'], $roles, true)) {
                $err($sheet, $row, $title($sheet, $key), 'User ' . $email . ' bukan ' . $roleLabel . '.');
                return null;
            }
            return $u;
        };

        // ---- sheet Project
        $projects = [];
        $nprNumbers = [];
        $codes = [];
        $S = LegacyFormat::SHEET_PROJECT;
        foreach ($rows[$S] as $r) {
            $v = $r['values'];
            $row = $r['row'];
            $ref = (string) ($v['ref'] ?? '');
            if ($ref === '') {
                $err($S, $row, $title($S, 'ref'), 'Wajib diisi.');
                continue;
            }
            $key = mb_strtolower($ref);
            if (isset($projects[$key])) {
                $err($S, $row, $title($S, 'ref'), 'Ref Project "' . $ref . '" dipakai lebih dari sekali (juga di baris ' . $projects[$key]['row'] . ').');
                continue;
            }
            if (isset($takenRefs[$key])) {
                $err($S, $row, $title($S, 'ref'), 'Ref Project "' . $ref . '" sudah pernah diimpor.');
            }
            $p = ['row' => $row, 'ref' => $ref, 'parts' => [], 'project_processes' => []];
            $p['name'] = (string) ($v['name'] ?? '');
            if ($p['name'] === '') {
                $err($S, $row, $title($S, 'name'), 'Wajib diisi.');
            }
            $p['customer'] = null;
            if (empty($v['customer'])) {
                $err($S, $row, $title($S, 'customer'), 'Wajib diisi.');
            } else {
                $cu = $customers[mb_strtolower((string) $v['customer'])] ?? null;
                if ($cu === null) {
                    $err($S, $row, $title($S, 'customer'), 'Kode customer "' . $v['customer'] . '" tidak ditemukan. Buat dulu di Pengaturan › Customer.');
                } elseif ((int) $cu['is_active'] !== 1) {
                    $err($S, $row, $title($S, 'customer'), 'Customer "' . $v['customer'] . '" nonaktif.');
                } else {
                    $p['customer'] = $cu;
                }
            }
            if (empty($v['sales'])) {
                $err($S, $row, $title($S, 'sales'), 'Wajib diisi.');
            }
            if (empty($v['npd'])) {
                $err($S, $row, $title($S, 'npd'), 'Wajib diisi.');
            }
            $p['sales'] = $userFor($S, $row, 'sales', $v['sales'] ?? null, ['admin_sales'], 'Admin Sales');
            $p['npd'] = $userFor($S, $row, 'npd', $v['npd'] ?? null, ['npd_staff', 'admin'], 'NPD Staff/Admin');
            $p['priority'] = 'normal';
            if (!empty($v['priority'])) {
                $p['priority'] = LegacyFormat::choice('priority', (string) $v['priority']) ?? 'normal';
                if (LegacyFormat::choice('priority', (string) $v['priority']) === null) {
                    $err($S, $row, $title($S, 'priority'), 'Pilih: ' . implode(' / ', LegacyFormat::labels('priority')) . '.');
                }
            }
            $p['npr_date'] = $v['npr_date'] ?? null;
            if ($p['npr_date'] === null) {
                $err($S, $row, $title($S, 'npr_date'), 'Wajib diisi.');
            } elseif ($p['npr_date'] > $today) {
                $err($S, $row, $title($S, 'npr_date'), 'Tidak boleh di masa depan.');
            }
            $p['feedback_date'] = $v['feedback_date'] ?? $p['npr_date'];
            if ($p['feedback_date'] !== null && $p['npr_date'] !== null) {
                if ($p['feedback_date'] < $p['npr_date']) {
                    $err($S, $row, $title($S, 'feedback_date'), 'Tidak boleh sebelum Tanggal NPR.');
                } elseif ($p['feedback_date'] > $today) {
                    $err($S, $row, $title($S, 'feedback_date'), 'Tidak boleh di masa depan.');
                }
            }
            $p['target'] = $v['target'] ?? null;
            if ($p['target'] !== null && $p['npr_date'] !== null && $p['target'] < $p['npr_date']) {
                $err($S, $row, $title($S, 'target'), 'Tidak boleh sebelum Tanggal NPR.');
            }
            $p['npr_number'] = ($v['npr_number'] ?? '') !== '' ? (string) $v['npr_number'] : null;
            $p['npr_seq'] = null;
            if ($p['npr_number'] !== null) {
                $nk = mb_strtolower($p['npr_number']);
                if (isset($nprNumbers[$nk])) {
                    $err($S, $row, $title($S, 'npr_number'), 'No. NPR "' . $p['npr_number'] . '" dipakai lebih dari sekali di file ini.');
                }
                $nprNumbers[$nk] = $row;
                if (preg_match(self::NPR_PATTERN, $p['npr_number'], $m)) {
                    $p['npr_seq'] = ['year' => (int) $m[3], 'seq' => (int) $m[1]];
                }
            }
            $p['code'] = ($v['code'] ?? '') !== '' ? (string) $v['code'] : null;
            $p['code_seq'] = null;
            if ($p['code'] !== null) {
                $ck = mb_strtolower($p['code']);
                if (isset($codes[$ck])) {
                    $err($S, $row, $title($S, 'code'), 'Kode Project "' . $p['code'] . '" dipakai lebih dari sekali di file ini.');
                }
                $codes[$ck] = $row;
                if (preg_match(self::CODE_PATTERN, $p['code'], $m)) {
                    $p['code_seq'] = ['year' => (int) $m[1], 'seq' => (int) $m[2]];
                }
            }
            $p['status'] = null;
            if (empty($v['status'])) {
                $err($S, $row, $title($S, 'status'), 'Wajib diisi: ' . implode(' / ', LegacyFormat::labels('project_status')) . '.');
            } else {
                $p['status'] = LegacyFormat::choice('project_status', (string) $v['status']);
                if ($p['status'] === null) {
                    $err($S, $row, $title($S, 'status'), 'Pilih: ' . implode(' / ', LegacyFormat::labels('project_status')) . '.');
                }
            }
            $p['finish_date'] = $v['finish_date'] ?? null;
            if ($p['status'] === 'completed') {
                if ($p['finish_date'] === null) {
                    $err($S, $row, $title($S, 'finish_date'), 'Wajib diisi bila Status Project = Selesai.');
                } elseif ($p['finish_date'] > $today) {
                    $err($S, $row, $title($S, 'finish_date'), 'Tidak boleh di masa depan.');
                } elseif ($p['feedback_date'] !== null && $p['finish_date'] < $p['feedback_date']) {
                    $err($S, $row, $title($S, 'finish_date'), 'Tidak boleh sebelum Tanggal Feedback NPD.');
                }
            } elseif ($p['finish_date'] !== null) {
                $err($S, $row, $title($S, 'finish_date'), 'Hanya diisi bila Status Project = Selesai.');
            }
            $p['reason'] = (string) ($v['reason'] ?? '');
            if (in_array($p['status'], ['hold', 'cancelled'], true) && $p['reason'] === '') {
                $err($S, $row, $title($S, 'reason'), 'Wajib diisi bila Status Project = Hold atau Batal.');
            }
            $p['qty_month'] = $v['qty_month'] ?? null;
            $p['qty_year'] = $v['qty_year'] ?? null;
            $p['note'] = ($v['note'] ?? '') !== '' ? (string) $v['note'] : null;
            $projects[$key] = $p;
        }
        if ($nprNumbers) {
            $list = array_keys($nprNumbers);
            foreach (Db::column('SELECT LOWER(npr_number) FROM npr WHERE LOWER(npr_number) IN ' . Db::in($list), $list) as $taken) {
                $err($S, (int) $nprNumbers[$taken], $title($S, 'npr_number'), 'No. NPR "' . $taken . '" sudah ada di aplikasi.');
            }
            foreach ($projects as $p) {
                if ($p['npr_seq'] !== null && Db::value('SELECT id FROM npr WHERE seq_year = ? AND seq_no = ?', [$p['npr_seq']['year'], $p['npr_seq']['seq']])) {
                    $err($S, $p['row'], $title($S, 'npr_number'), 'Nomor urut ' . $p['npr_seq']['seq'] . ' tahun ' . $p['npr_seq']['year'] . ' sudah dipakai NPR lain di aplikasi.');
                }
            }
        }
        if ($codes) {
            $list = array_keys($codes);
            foreach (Db::column('SELECT LOWER(code) FROM projects WHERE LOWER(code) IN ' . Db::in($list), $list) as $taken) {
                $err($S, (int) $codes[$taken], $title($S, 'code'), 'Kode Project "' . $taken . '" sudah ada di aplikasi.');
            }
        }
        $seqDup = [];
        foreach ($projects as $p) {
            if ($p['npr_seq'] !== null) {
                $k = $p['npr_seq']['year'] . ':' . $p['npr_seq']['seq'];
                if (isset($seqDup[$k])) {
                    $err($S, $p['row'], $title($S, 'npr_number'), 'Nomor urut ' . $p['npr_seq']['seq'] . ' tahun ' . $p['npr_seq']['year'] . ' sama dengan baris ' . $seqDup[$k] . '.');
                }
                $seqDup[$k] = $p['row'];
            }
        }

        // ---- sheet Part
        $S = LegacyFormat::SHEET_PART;
        foreach ($rows[$S] ?? [] as $r) {
            $v = $r['values'];
            $row = $r['row'];
            $pk = mb_strtolower((string) ($v['ref'] ?? ''));
            if ($pk === '') {
                $err($S, $row, $title($S, 'ref'), 'Wajib diisi.');
                continue;
            }
            if (!isset($projects[$pk])) {
                $err($S, $row, $title($S, 'ref'), 'Ref Project "' . $v['ref'] . '" tidak ada di sheet Project.');
                continue;
            }
            $name = (string) ($v['part'] ?? '');
            if ($name === '') {
                $err($S, $row, $title($S, 'part'), 'Wajib diisi.');
                continue;
            }
            $nk = LegacyFormat::norm($name);
            if (isset($projects[$pk]['parts'][$nk])) {
                $err($S, $row, $title($S, 'part'), 'Part "' . $name . '" sudah ada untuk project ini (baris ' . $projects[$pk]['parts'][$nk]['row'] . ').');
                continue;
            }
            $part = ['row' => $row, 'name' => $name, 'processes' => []];
            $part['type'] = !empty($v['type']) ? LegacyFormat::choice('part_type', (string) $v['type']) : null;
            if ($part['type'] === null) {
                $err($S, $row, $title($S, 'type'), 'Wajib diisi: ' . implode(' / ', LegacyFormat::labels('part_type')) . '.');
            } elseif (!isset($this->templates[$part['type']])) {
                $err($S, $row, $title($S, 'type'), 'Template workflow ' . LegacyFormat::label('part_type', $part['type']) . ' belum tersedia.');
                $part['type'] = null;
            }
            $part['supplier'] = ($v['supplier'] ?? '') !== '' ? (string) $v['supplier'] : null;
            $part['pics'] = [];
            foreach (['drafter' => 'Drafter', 'purchasing' => 'Purchasing', 'production' => 'Production', 'quality' => 'Quality'] as $role => $label) {
                $u = $userFor($S, $row, $role, $v[$role] ?? null, [$role], $label);
                $part['pics'][$role] = $u !== null ? (int) $u['id'] : null;
            }
            $part['status'] = !empty($v['status']) ? LegacyFormat::choice('part_status', (string) $v['status']) : 'active';
            if ($part['status'] === null) {
                $err($S, $row, $title($S, 'status'), 'Pilih: ' . implode(' / ', LegacyFormat::labels('part_status')) . '.');
                $part['status'] = 'active';
            }
            $part['reason'] = (string) ($v['reason'] ?? '');
            if ($part['status'] === 'cancelled' && $part['reason'] === '') {
                $err($S, $row, $title($S, 'reason'), 'Wajib diisi bila Status Part = Batal.');
            }
            $part['note'] = ($v['note'] ?? '') !== '' ? (string) $v['note'] : null;
            $projects[$pk]['parts'][$nk] = $part;
        }

        // ---- sheet Proses
        $S = LegacyFormat::SHEET_PROCESS;
        $gateOn = (bool) ($this->templates['project']['gate_enabled'] ?? false);
        foreach ($rows[$S] ?? [] as $r) {
            $v = $r['values'];
            $row = $r['row'];
            $pk = mb_strtolower((string) ($v['ref'] ?? ''));
            if ($pk === '') {
                $err($S, $row, $title($S, 'ref'), 'Wajib diisi.');
                continue;
            }
            if (!isset($projects[$pk])) {
                $err($S, $row, $title($S, 'ref'), 'Ref Project "' . $v['ref'] . '" tidak ada di sheet Project.');
                continue;
            }
            $partName = (string) ($v['part'] ?? '');
            $part = null;
            if ($partName !== '') {
                $part = $projects[$pk]['parts'][LegacyFormat::norm($partName)] ?? null;
                if ($part === null) {
                    $names = array_map(static fn ($x) => $x['name'], $projects[$pk]['parts']);
                    $err($S, $row, $title($S, 'part'), 'Part "' . $partName . '" tidak ada di sheet Part untuk Ref ' . $v['ref'] . ($names ? ' (tersedia: ' . implode(', ', $names) . ')' : '') . '.');
                    continue;
                }
                if ($part['type'] === null) {
                    continue; // jenis part salah — sudah dilaporkan
                }
            }
            $tplKey = $part !== null ? (string) $part['type'] : 'project';
            $raw = (string) ($v['process'] ?? '');
            if ($raw === '') {
                $err($S, $row, $title($S, 'process'), 'Wajib diisi.');
                continue;
            }
            $step = $this->findStep($tplKey, $raw);
            if ($step === null) {
                $scope = $part !== null ? 'template ' . LegacyFormat::label('part_type', $tplKey) : 'proses level project';
                $hint = $part === null ? ' Hanya G1 (Assembly / Fit Test) yang diisi tanpa Nama Part; proses lain wajib mengisi Nama Part.' : '';
                $err($S, $row, $title($S, 'process'), 'Proses "' . $raw . '" tidak ada di ' . $scope . '.' . $hint);
                continue;
            }
            $code = (string) $step['code'];
            if ($part === null) {
                if ($step['step_type'] !== 'gate') {
                    $err($S, $row, $title($S, 'process'), $code . ' ' . $step['name'] . ' diisi otomatis dari sheet Project (Tanggal NPR, Tanggal Feedback, Status/Tanggal Selesai Project) — hapus baris ini.');
                    continue;
                }
                if (!$gateOn) {
                    $err($S, $row, $title($S, 'process'), 'Gate Assembly / Fit Test tidak aktif di template workflow — hapus baris ini.');
                    continue;
                }
            }
            if ($step['activation'] === 'loop_only') {
                $err($S, $row, $title($S, 'process'), $code . ' ' . $step['name'] . ' hanya terjadi lewat keputusan di sistem dan tidak dapat diimpor. Isi proses pemicunya sesuai kondisi terakhir dan catat di Keterangan.');
                continue;
            }
            $bucket = $part !== null ? 'parts' : 'project_processes';
            $existing = $part !== null ? ($projects[$pk]['parts'][LegacyFormat::norm($partName)]['processes'][$code] ?? null) : ($projects[$pk]['project_processes'][$code] ?? null);
            if ($existing !== null) {
                $err($S, $row, $title($S, 'process'), 'Proses ' . $code . ' sudah ditulis untuk ' . ($part !== null ? 'part ' . $part['name'] : 'project ini') . ' (baris ' . $existing['row'] . ').');
                continue;
            }
            $pr = ['row' => $row, 'code' => $code, 'step' => $step];
            $pr['status'] = !empty($v['status']) ? LegacyFormat::choice('process_status', (string) $v['status']) : null;
            if ($pr['status'] === null) {
                $err($S, $row, $title($S, 'status'), 'Wajib diisi: ' . implode(' / ', LegacyFormat::labels('process_status')) . '.');
                continue;
            }
            $pr['start'] = $v['start'] ?? null;
            $pr['finish'] = $v['finish'] ?? null;
            $pr['plan_finish'] = $v['plan_finish'] ?? null;
            $pr['note'] = ($v['note'] ?? '') !== '' ? (string) $v['note'] : null;
            switch ($pr['status']) {
                case 'completed':
                    if ($pr['start'] === null) {
                        $err($S, $row, $title($S, 'start'), 'Wajib diisi untuk Selesai.');
                    }
                    if ($pr['finish'] === null) {
                        $err($S, $row, $title($S, 'finish'), 'Wajib diisi untuk Selesai.');
                    } elseif ($pr['finish'] > $today) {
                        $err($S, $row, $title($S, 'finish'), 'Tidak boleh di masa depan.');
                    } elseif ($pr['start'] !== null && $pr['finish'] < $pr['start']) {
                        $err($S, $row, $title($S, 'finish'), 'Tidak boleh sebelum Tanggal Mulai.');
                    }
                    if ($pr['plan_finish'] !== null) {
                        $err($S, $row, $title($S, 'plan_finish'), 'Hanya untuk proses Berjalan.');
                    }
                    break;
                case 'current':
                    if ($pr['start'] === null) {
                        $err($S, $row, $title($S, 'start'), 'Wajib diisi untuk Berjalan.');
                    } elseif ($pr['start'] > $today) {
                        $err($S, $row, $title($S, 'start'), 'Tidak boleh di masa depan.');
                    }
                    if ($pr['finish'] !== null) {
                        $err($S, $row, $title($S, 'finish'), 'Proses Berjalan tidak punya Tanggal Selesai. Bila sudah selesai, ubah Status menjadi Selesai.');
                    }
                    if ($pr['plan_finish'] !== null && $pr['start'] !== null && $pr['plan_finish'] < $pr['start']) {
                        $err($S, $row, $title($S, 'plan_finish'), 'Tidak boleh sebelum Tanggal Mulai.');
                    }
                    break;
                case 'skipped':
                    if ((int) $step['is_skippable'] !== 1) {
                        $err($S, $row, $title($S, 'status'), $code . ' ' . $step['name'] . ' tidak boleh dilewati menurut template workflow.');
                    }
                    if ($pr['start'] !== null || $pr['finish'] !== null || $pr['plan_finish'] !== null) {
                        $err($S, $row, $title($S, 'start'), 'Proses Dilewati tidak diisi tanggal.');
                    }
                    break;
            }
            if ($pr['start'] !== null && $projects[$pk]['npr_date'] !== null && $pr['start'] < $projects[$pk]['npr_date']) {
                $warn($S, $row, $title($S, 'start'), 'Tanggal Mulai lebih awal dari Tanggal NPR project.');
            }
            $pr['pic'] = null;
            if (!empty($v['pic'])) {
                // PIC harus ber-role sesuai proses (proses NPD boleh Admin, sama seperti NPD PIC project)
                $roleCode = (string) $step['pic_role_code'];
                $roleName = (string) Db::value('SELECT name_id FROM roles WHERE code = ?', [$roleCode]);
                $u = $userFor($S, $row, 'pic', (string) $v['pic'], $roleCode === 'npd_staff' ? ['npd_staff', 'admin'] : [$roleCode], $roleName !== '' ? $roleName : $roleCode);
                $pr['pic'] = $u !== null ? (int) $u['id'] : null;
            }
            if ($part !== null) {
                $projects[$pk]['parts'][LegacyFormat::norm($partName)]['processes'][$code] = $pr;
            } else {
                $projects[$pk][$bucket][$code] = $pr;
            }
        }

        // ---- konsistensi per project
        $calendar = $this->calendar();
        foreach ($projects as $pk => &$p) {
            $SP = LegacyFormat::SHEET_PROJECT;
            if (!$p['parts']) {
                $err($SP, $p['row'], $title($SP, 'ref'), 'Project belum punya part. Tambahkan minimal satu baris di sheet Part dengan Ref ' . $p['ref'] . '.');
                continue;
            }
            $g1 = $p['project_processes']['G1'] ?? null;
            $g1Status = $g1['status'] ?? 'not_started';
            $openParts = 0;
            $doneParts = 0;
            foreach ($p['parts'] as &$part) {
                if ($part['type'] === null) {
                    continue;
                }
                $tpl = $this->templates[$part['type']];
                $status = [];
                foreach ($tpl['steps'] as $code => $_) {
                    $status[$code] = $part['processes'][$code]['status'] ?? 'not_started';
                }
                $statusOf = static fn (string $code): ?string => $status[$code] ?? match ($code) {
                    'P1', 'P2' => 'completed',
                    'G1' => $gateOn ? $g1Status : null,
                    default => null,
                };
                // grup "dilewati bersama"
                $groups = [];
                foreach ($tpl['steps'] as $code => $s) {
                    if (($s['skip_group'] ?? '') !== '' && $s['skip_group'] !== null) {
                        $groups[(string) $s['skip_group']][] = $code;
                    }
                }
                foreach ($groups as $g => $members) {
                    $skipped = array_values(array_filter($members, static fn ($c) => $status[$c] === 'skipped'));
                    if ($skipped && count($skipped) !== count($members)) {
                        $other = array_values(array_diff($members, $skipped));
                        $err(LegacyFormat::SHEET_PROCESS, (int) $part['processes'][$skipped[0]]['row'], $title(LegacyFormat::SHEET_PROCESS, 'status'),
                            'Part ' . $part['name'] . ': proses grup ' . $g . ' (' . implode(', ', $members) . ') harus dilewati bersama; ' . implode(', ', $other) . ' belum ditulis sebagai Dilewati.');
                    }
                }
                // dependency
                foreach ($tpl['steps'] as $code => $s) {
                    $st = $status[$code];
                    if (!in_array($st, ['completed', 'current'], true)) {
                        continue;
                    }
                    $pr = $part['processes'][$code];
                    foreach ($tpl['deps'][$code] ?? [] as $d) {
                        if ((int) $d['only_when_gate'] === 1 && !$gateOn) {
                            continue;
                        }
                        $pred = (string) $d['code'];
                        if (isset($tpl['steps'][$pred]) && $tpl['steps'][$pred]['activation'] === 'loop_only') {
                            continue;
                        }
                        $ps = $statusOf($pred);
                        if ($ps === null) {
                            continue; // predecessor tidak dibuat (mis. gate nonaktif)
                        }
                        $need = match (strtoupper((string) $d['dep_type'])) {
                            'FS' => self::DONE,
                            'SS' => ['completed', 'current', 'skipped'],
                            'FF' => $st === 'completed' ? self::DONE : null,
                            default => null,
                        };
                        if ($need === null || in_array($ps, $need, true)) {
                            if (strtoupper((string) $d['dep_type']) === 'FS' && $ps === 'completed' && isset($part['processes'][$pred]) && $pr['start'] !== null
                                && $part['processes'][$pred]['finish'] !== null && $pr['start'] < $part['processes'][$pred]['finish']) {
                                $warn(LegacyFormat::SHEET_PROCESS, (int) $pr['row'], $title(LegacyFormat::SHEET_PROCESS, 'start'),
                                    'Part ' . $part['name'] . ': ' . $code . ' mulai sebelum pendahulunya ' . $pred . ' selesai (' . $part['processes'][$pred]['finish'] . ').');
                            }
                            continue;
                        }
                        $predName = $tpl['steps'][$pred]['name'] ?? ($pred === 'G1' ? 'Assembly / Fit Test' : $pred);
                        $what = $ps === 'not_started' ? 'belum ditulis di sheet Proses' : 'masih Berjalan';
                        $fix = $pred === 'G1' ? ' Isi baris G1 tanpa Nama Part.' : ' Isi ' . $pred . ' sebagai Selesai' . ((int) ($tpl['steps'][$pred]['is_skippable'] ?? 0) === 1 ? ' atau Dilewati' : '') . '.';
                        $err(LegacyFormat::SHEET_PROCESS, (int) $pr['row'], $title(LegacyFormat::SHEET_PROCESS, 'status'),
                            'Part ' . $part['name'] . ': ' . $code . ' ' . $s['name'] . ' ' . LegacyFormat::label('process_status', $st) . ', tetapi pendahulunya ' . $pred . ' ' . $predName . ' ' . $what . '.' . $fix);
                    }
                }
                // part selesai
                $finishCode = $tpl['finish_code'];
                $part['completed'] = $finishCode !== null && $status[$finishCode] === 'completed';
                if ($part['completed']) {
                    $missing = [];
                    foreach ($tpl['steps'] as $code => $s) {
                        if ($code !== $finishCode && $s['activation'] !== 'loop_only' && !in_array($status[$code], self::DONE, true)) {
                            $missing[] = $code;
                        }
                    }
                    if ($missing) {
                        $err(LegacyFormat::SHEET_PROCESS, (int) $part['processes'][$finishCode]['row'], $title(LegacyFormat::SHEET_PROCESS, 'status'),
                            'Part ' . $part['name'] . ' ditandai selesai, tetapi proses ' . implode(', ', $missing) . ' belum Selesai/Dilewati.');
                    }
                    if ($part['status'] === 'cancelled') {
                        $err(LegacyFormat::SHEET_PART, $part['row'], $title(LegacyFormat::SHEET_PART, 'status'), 'Part yang sudah selesai (' . $finishCode . ' Selesai) tidak dapat dibatalkan.');
                    }
                }
                $part['needs_masterbatch'] = null;
                if ($part['type'] === 'new_mold' && isset($groups['MB'])) {
                    $mb = array_map(static fn ($c) => $status[$c], $groups['MB']);
                    if (count(array_filter($mb, static fn ($x) => $x === 'skipped')) === count($mb)) {
                        $part['needs_masterbatch'] = 0;
                    } elseif (array_intersect($mb, ['completed', 'current'])) {
                        $part['needs_masterbatch'] = 1;
                    }
                }
                if ($part['status'] === 'active' && !$part['completed']) {
                    $openParts++;
                    $hasCurrent = in_array('current', $status, true);
                    if (!$hasCurrent && $p['status'] === 'running') {
                        $warn(LegacyFormat::SHEET_PART, $part['row'], $title(LegacyFormat::SHEET_PART, 'part'),
                            'Part ' . $part['name'] . ' tidak punya proses Berjalan: proses berikutnya dijadwalkan & dimulai mulai tanggal impor.');
                    }
                    foreach ($part['processes'] as $code => $pr) {
                        if ($pr['status'] === 'current' && $pr['start'] !== null) {
                            $pf = $pr['plan_finish'] ?? $calendar->finishFromStart($calendar->nextWorkingDay($pr['start']), max(1, (int) $pr['step']['default_duration']));
                            if ($pf < $today && $p['status'] === 'running') {
                                $warn(LegacyFormat::SHEET_PROCESS, (int) $pr['row'], $title(LegacyFormat::SHEET_PROCESS, 'plan_finish'),
                                    'Part ' . $part['name'] . ': ' . $code . ' direncanakan selesai ' . $pf . ' sehingga langsung terhitung overdue setelah impor. Isi Rencana Selesai bila ada rencana baru.');
                            }
                        }
                    }
                } elseif ($part['completed']) {
                    $doneParts++;
                }
            }
            unset($part);
            // gate level project
            if ($g1 !== null && in_array($g1Status, ['completed', 'current'], true)) {
                foreach ($p['parts'] as $part) {
                    if ($part['type'] === null || $part['status'] === 'cancelled') {
                        continue;
                    }
                    $ms = $this->templates[$part['type']]['milestone_code'];
                    if ($ms !== null && ($part['processes'][$ms]['status'] ?? 'not_started') !== 'completed') {
                        $err(LegacyFormat::SHEET_PROCESS, (int) $g1['row'], $title(LegacyFormat::SHEET_PROCESS, 'status'),
                            'G1 Assembly / Fit Test ' . LegacyFormat::label('process_status', $g1Status) . ', tetapi milestone ' . $ms . ' part ' . $part['name'] . ' belum Selesai.');
                    }
                }
            }
            // status project
            $activeParts = array_filter($p['parts'], static fn ($x) => $x['status'] === 'active' && $x['type'] !== null);
            if ($p['status'] === 'completed') {
                if (!$activeParts) {
                    $err($SP, $p['row'], $title($SP, 'status'), 'Project Selesai harus punya minimal satu part aktif yang selesai.');
                }
                foreach ($activeParts as $part) {
                    if (empty($part['completed'])) {
                        $fc = $this->templates[$part['type']]['finish_code'];
                        $err($SP, $p['row'], $title($SP, 'status'), 'Project Selesai, tetapi part ' . $part['name'] . ' belum selesai (isi ' . $fc . ' sebagai Selesai, atau Status Part = Batal).');
                    }
                }
                if ($gateOn && !in_array($g1Status, self::DONE, true)) {
                    $err($SP, $p['row'], $title($SP, 'status'), 'Project Selesai, tetapi gate G1 Assembly / Fit Test belum Selesai/Dilewati. Isi baris G1 tanpa Nama Part.');
                }
            } elseif ($p['status'] === 'cancelled' && $doneParts > 0) {
                $err($SP, $p['row'], $title($SP, 'status'), 'Project dengan part yang sudah selesai tidak dapat dibatalkan (FR-HLD-05). Batalkan part yang belum selesai lewat Status Part, lalu Status Project = Selesai.');
            } elseif ($p['status'] === 'hold' && $openParts === 0) {
                $err($SP, $p['row'], $title($SP, 'status'), 'Project Hold harus punya part aktif yang belum selesai.');
            } elseif ($p['status'] === 'running') {
                if (!$activeParts) {
                    $warn($SP, $p['row'], $title($SP, 'status'), 'Semua part Batal: project akan berstatus Batal.');
                } elseif ($openParts === 0) {
                    $warn($SP, $p['row'], $title($SP, 'status'), 'Semua part aktif sudah selesai: project akan berstatus Siap Selesai. Gunakan Status Project = Selesai bila sudah selesai.');
                }
            }
        }
        unset($p);

        $plan = array_values($projects);
        $summary = self::emptySummary();
        foreach ($plan as $p) {
            $summary['projects']++;
            $summary['status_' . ($p['status'] ?? 'running')] = ($summary['status_' . ($p['status'] ?? 'running')] ?? 0) + 1;
            foreach ($p['parts'] as $part) {
                $summary['parts']++;
                foreach ($part['processes'] as $pr) {
                    $summary['processes_' . $pr['status']]++;
                }
            }
            foreach ($p['project_processes'] as $pr) {
                $summary['processes_' . $pr['status']]++;
            }
        }
        usort($errors, static fn ($a, $b) => [$a['sheet'] !== '', self::sheetOrder($a['sheet']), $a['row']] <=> [$b['sheet'] !== '', self::sheetOrder($b['sheet']), $b['row']]);
        usort($warnings, static fn ($a, $b) => [self::sheetOrder($a['sheet']), $a['row']] <=> [self::sheetOrder($b['sheet']), $b['row']]);
        return ['errors' => $errors, 'warnings' => $warnings, 'plan' => $plan, 'summary' => $summary];
    }

    /** @return array<string,int> */
    private static function emptySummary(): array
    {
        return ['projects' => 0, 'parts' => 0, 'status_running' => 0, 'status_hold' => 0, 'status_completed' => 0, 'status_cancelled' => 0,
            'processes_completed' => 0, 'processes_current' => 0, 'processes_skipped' => 0];
    }

    private static function sheetOrder(string $sheet): int
    {
        return match ($sheet) {
            '' => 0, LegacyFormat::SHEET_PROJECT => 1, LegacyFormat::SHEET_PART => 2, LegacyFormat::SHEET_PROCESS => 3, default => 4,
        };
    }

    // ================================================================ impor

    /**
     * Impor seluruh file (satu transaksi). Ditolak bila analisis masih menemukan kesalahan.
     * @return array{projects:list<array{ref:string,id:int,code:string,npr_number:string,status:string}>,summary:array<string,int>,warnings:int}
     */
    public function commit(User $actor, string $path, string $fileName): array
    {
        Gate::authorize($actor, 'project.import');
        $a = $this->analyze($path);
        if ($a['errors']) {
            throw new ValidationException(['file' => 'File masih berisi ' . count($a['errors']) . ' kesalahan. Perbaiki lalu periksa ulang.']);
        }
        if (!$a['plan']) {
            throw new ValidationException(['file' => 'Tidak ada baris project untuk diimpor.']);
        }
        $sha = (string) hash_file('sha256', $path);
        try {
            $created = Notifier::muted(fn (): array => Db::transaction(function () use ($actor, $a, $fileName, $sha): array {
                $this->bumpSequences($a['plan']);
                $out = [];
                foreach ($a['plan'] as $p) {
                    $out[] = $this->importProject($actor, $p, $fileName);
                }
                AuditLogger::log('import.legacy', 'import', null, null, [
                    'file' => $fileName, 'sha256' => $sha, 'projects' => count($out),
                    'codes' => array_slice(array_column($out, 'code'), 0, 200), 'summary' => $a['summary'],
                ], null, null, $actor);
                return $out;
            }));
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new BusinessRuleException('Sebagian data sudah ada (Ref Project, No. NPR, atau Kode Project kembar — mungkin file sedang/telah diimpor oleh pengguna lain). Tidak ada data yang disimpan; periksa ulang file.');
            }
            throw $e;
        }
        return ['projects' => $created, 'summary' => $a['summary'], 'warnings' => count($a['warnings'])];
    }

    /** Penomoran berikutnya melanjutkan dari nomor format sistem tertinggi pada file. @param list<array<string,mixed>> $plan */
    private function bumpSequences(array $plan): void
    {
        $max = [];
        foreach ($plan as $p) {
            if ($p['npr_seq'] !== null) {
                $k = 'NPR-' . $p['npr_seq']['year'];
                $max[$k] = max($max[$k] ?? 0, $p['npr_seq']['seq']);
            }
            if ($p['code_seq'] !== null) {
                $k = 'NPD-' . $p['code_seq']['year'];
                $max[$k] = max($max[$k] ?? 0, $p['code_seq']['seq']);
            }
        }
        foreach ($max as $key => $value) {
            Db::execute(
                'INSERT INTO number_sequences (seq_key, current_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, ?)',
                [$key, $value, $value]
            );
        }
    }

    /** @param array<string,mixed> $p @return array{ref:string,id:int,code:string,npr_number:string,status:string} */
    private function importProject(User $actor, array $p, string $fileName): array
    {
        $now = Clock::nowString();
        $today = Clock::todayString();
        $cal = $this->calendar();
        $nprDate = (string) $p['npr_date'];
        $fbDate = (string) $p['feedback_date'];
        $at = static fn (string $d): string => $d . ' 00:00:00';
        $cust = $p['customer'];
        $sales = $p['sales'];
        $npd = $p['npd'];

        // ---- NPR (Selesai Feedback)
        if ($p['npr_number'] !== null) {
            $number = (string) $p['npr_number'];
            $seqYear = $p['npr_seq']['year'] ?? null;
            $seqNo = $p['npr_seq']['seq'] ?? null;
        } else {
            do {
                $n = NumberSequence::nextNprNumber(new \DateTimeImmutable($nprDate));
            } while (Db::value('SELECT id FROM npr WHERE npr_number = ? OR (seq_year = ? AND seq_no = ?)', [$n['number'], $n['year'], $n['seq']]));
            [$number, $seqYear, $seqNo] = [$n['number'], $n['year'], $n['seq']];
        }
        $nprId = Db::insert('npr', [
            'npr_number' => $number, 'seq_year' => $seqYear, 'seq_no' => $seqNo, 'status' => 'feedback_completed',
            'product_name' => (string) $p['name'], 'customer_id' => (int) $cust['id'],
            'invoice_address' => $cust['invoice_address'], 'shipping_address' => $cust['shipping_address'], 'phone' => $cust['phone'],
            'qty_per_month' => $p['qty_month'], 'qty_per_year' => $p['qty_year'], 'note' => $p['note'], 'launching_target' => $p['target'],
            'created_by' => $actor->id, 'sales_pic_id' => (int) $sales['id'],
            'requested_by_id' => (int) $sales['id'], 'requested_by_name' => (string) $sales['name'], 'requested_by_title' => $sales['job_title'],
            'requested_at' => $at($nprDate), 'first_submitted_at' => $at($nprDate), 'submit_count' => 1,
            'received_by_id' => (int) $npd['id'], 'received_by_name' => (string) $npd['name'], 'received_by_title' => $npd['job_title'],
            'received_at' => $at($fbDate), 'created_at' => $at($nprDate),
        ]);

        // ---- project
        if ($p['code'] !== null) {
            $projectCode = (string) $p['code'];
        } else {
            do {
                $projectCode = NumberSequence::nextProjectCode(new \DateTimeImmutable($nprDate));
            } while (Db::value('SELECT id FROM projects WHERE code = ?', [$projectCode]));
        }
        $closed = $p['status'] === 'completed';
        $projectId = Db::insert('projects', [
            'code' => $projectCode, 'npr_id' => $nprId, 'customer_id' => (int) $cust['id'], 'name' => (string) $p['name'],
            'priority' => $p['priority'], 'sales_pic_id' => (int) $sales['id'], 'npd_pic_id' => (int) $npd['id'],
            'start_date' => $nprDate, 'target_finish' => $p['target'], 'status' => 'on_progress',
            // proses yang belum mulai dijadwalkan mulai tanggal impor (rencana lama tidak diketahui)
            'schedule_floor' => $closed ? null : $today,
            'last_activity_at' => $now, 'legacy_ref' => (string) $p['ref'], 'imported_at' => $now, 'created_at' => $at($nprDate),
        ]);

        // ---- part (NPR + project)
        $partIds = [];
        $sort = 0;
        foreach ($p['parts'] as $nk => $part) {
            $sort += 10;
            $master = $this->partNameCode((string) $part['name']);
            $nprPart = [
                'npr_id' => $nprId, 'sort_order' => $sort, 'part_name_code' => $master ?? NprFields::OTHER_PART,
                'part_name_custom' => $master === null ? (string) $part['name'] : null, 'part_type' => (string) $part['type'],
                'mold_supplier' => $part['supplier'], 'status' => 'active',
            ];
            $nprPart['part_name'] = NprFields::partDisplayName($nprPart);
            $nprPartId = Db::insert('npr_parts', $nprPart);
            Db::insert('npr_feedback', [
                'npr_part_id' => $nprPartId, 'feedback_text' => $part['note'], 'decision' => 'feasible',
                'needs_new_masterbatch' => $part['needs_masterbatch'], 'published_at' => $at($fbDate), 'published_by' => (int) $npd['id'], 'updated_by' => $actor->id,
            ]);
            $partIds[$nk] = Db::insert('project_parts', [
                'project_id' => $projectId, 'npr_part_id' => $nprPartId, 'name' => $nprPart['part_name'], 'sort_order' => $sort,
                'part_type' => (string) $part['type'], 'status' => 'not_started', 'needs_new_masterbatch' => $part['needs_masterbatch'],
                'mold_supplier' => $part['supplier'], 'drafter_pic_id' => $part['pics']['drafter'], 'purchasing_pic_id' => $part['pics']['purchasing'],
                'production_pic_id' => $part['pics']['production'], 'quality_pic_id' => $part['pics']['quality'], 'accepted_at' => $at($fbDate),
            ]);
        }

        // ---- proses
        $inst = new WorkflowInstantiator($this->schedule);
        $projData = [
            'P1' => ['status' => 'completed', 'actual_start' => $nprDate, 'actual_finish' => $nprDate, 'activated_at' => $at($nprDate),
                     'completed_at' => $now, 'completed_by' => $actor->id, 'outcome' => 'submitted', 'pic_user_id' => (int) $sales['id']],
            'P2' => ['status' => 'completed', 'actual_start' => $nprDate, 'actual_finish' => $fbDate, 'activated_at' => $at($nprDate),
                     'completed_at' => $now, 'completed_by' => $actor->id, 'outcome' => 'completed', 'pic_user_id' => (int) $npd['id']],
        ];
        foreach ($p['project_processes'] as $code => $pr) {
            $projData[$code] = $this->processData($pr, $actor, $now);
        }
        if ($closed) {
            $projData['PF'] = ['status' => 'completed', 'actual_start' => (string) $p['finish_date'], 'actual_finish' => (string) $p['finish_date'],
                'activated_at' => $at((string) $p['finish_date']), 'completed_at' => $now, 'completed_by' => $actor->id];
        }
        $levelIds = $inst->insertProjectProcesses($projectId, $projData);
        $imported = [];
        foreach ($levelIds as $code => $id) {
            if (isset($projData[$code]) && in_array($projData[$code]['status'], ['completed', 'current'], true)) {
                $imported[$id] = $p['project_processes'][$code] ?? ['note' => null];
            }
        }
        foreach ($p['parts'] as $nk => $part) {
            $data = [];
            foreach ($part['processes'] as $code => $pr) {
                $data[$code] = $this->processData($pr, $actor, $now);
            }
            $ids = $inst->insertPartProcesses($partIds[$nk], $fbDate, $data);
            foreach ($part['processes'] as $code => $pr) {
                if (isset($ids[$code])) {
                    $imported[$ids[$code]] = $pr;
                }
            }
            if (!empty($part['completed'])) {
                $finishCode = $this->templates[$part['type']]['finish_code'];
                Db::update('project_parts', ['completed_at' => $at((string) $part['processes'][$finishCode]['finish']), 'status' => 'completed'], ['id' => $partIds[$nk]]);
            }
        }
        // run data lama (tidak dihitung KPI), approval Pending untuk proses approval yang sedang berjalan, catatan → komentar
        $engine = new WorkflowEngine($this->schedule);
        $counts = [];
        foreach ($imported as $id => $pr) {
            $row = Db::fetch('SELECT * FROM processes WHERE id = ?', [$id]);
            $counts[(string) $row['status']] = ($counts[(string) $row['status']] ?? 0) + 1;
            if (in_array($row['status'], ['completed', 'current'], true)) {
                Db::insert('process_runs', [
                    'process_id' => $id, 'iteration' => 1, 'activated_at' => (string) $row['activated_at'],
                    'planned_start_at_activation' => $row['planned_start'], 'planned_finish_at_activation' => $row['planned_finish'],
                    'planned_duration_at_activation' => (int) $row['duration'], 'actual_start' => $row['actual_start'],
                    'actual_finish' => $row['status'] === 'completed' ? $row['actual_finish'] : null, 'outcome' => $row['status'] === 'completed' ? $row['outcome'] : null,
                    'pic_user_id' => $row['pic_user_id'], 'completed_by' => $row['status'] === 'completed' ? $actor->id : null,
                    'status' => $row['status'] === 'completed' ? 'completed' : 'open', 'is_imported' => 1,
                ]);
            }
            if ($row['status'] === 'current') {
                ApprovalService::requestFor($engine->load($id), $actor);
            }
            if (!empty($pr['note']) && $row['status'] !== 'skipped') {
                Db::insert('comments', ['project_id' => $projectId, 'part_id' => $row['part_id'], 'process_id' => $id, 'user_id' => $actor->id,
                    'body' => '[Data lama] ' . $pr['note'], 'created_at' => $now]);
            }
        }
        if ($closed) {
            Db::update('projects', ['finished_at' => $at((string) $p['finish_date']), 'finished_by' => $actor->id, 'status' => 'completed'], ['id' => $projectId]);
        }

        RevisionHistory::record('import', 'Diimpor dari data lama (Ref ' . $p['ref'] . ')', ['file' => $fileName, 'ref' => $p['ref'], 'status' => $p['status']], $nprId, $projectId, null, null, $actor->id);
        AuditLogger::log('project.import', 'project', $projectId, null, [
            'code' => $projectCode, 'npr_number' => $number, 'legacy_ref' => $p['ref'], 'status' => $p['status'], 'parts' => count($p['parts']),
            'processes' => $counts,
        ], 'Impor data lama: ' . $fileName, $projectId, $actor);

        // ---- jadwal, baseline, lalu status akhir lewat layanan biasa
        $this->schedule->recalculate($projectId, 'initial', null, null, $actor, 'plan');
        foreach ($partIds as $ppId) {
            $this->schedule->createBaseline($projectId, $ppId, 'Baseline v1 — impor data lama', $actor);
        }
        $lifecycle = new LifecycleService($this->schedule);
        if ($p['status'] === 'hold') {
            (new HoldService($this->schedule))->hold($actor, $projectId, null, (string) $p['reason']);
        }
        if ($p['status'] === 'cancelled') {
            $lifecycle->cancelProject($actor, $projectId, (string) $p['reason']);
        } else {
            foreach ($p['parts'] as $nk => $part) {
                if ($part['status'] === 'cancelled') {
                    $lifecycle->cancelPart($actor, $partIds[$nk], (string) $part['reason']);
                }
            }
            if ($p['status'] === 'running') {
                $engine->activateReady($projectId, $actor);
            }
        }
        $status = (new StatusService())->refresh($projectId)['project'];
        return ['ref' => (string) $p['ref'], 'id' => $projectId, 'code' => $projectCode, 'npr_number' => $number, 'status' => $status];
    }

    /** Kolom proses dari baris sheet Proses. @param array<string,mixed> $pr @return array<string,mixed> */
    private function processData(array $pr, User $actor, string $now): array
    {
        $cal = $this->calendar();
        $data = match ($pr['status']) {
            'completed' => ['status' => 'completed', 'actual_start' => $pr['start'], 'actual_finish' => $pr['finish'], 'activated_at' => $pr['start'] . ' 00:00:00',
                            'completed_at' => $now, 'completed_by' => $actor->id],
            'current' => (function () use ($pr, $cal): array {
                $ps = $cal->nextWorkingDay((string) $pr['start']);
                $pf = $pr['plan_finish'] ?? $cal->finishFromStart($ps, max(1, (int) $pr['step']['default_duration']));
                if ($ps > $pf) {
                    $ps = (string) $pr['start'];
                }
                return ['status' => 'current', 'actual_start' => $pr['start'], 'activated_at' => $pr['start'] . ' 00:00:00', 'planned_start' => $ps, 'planned_finish' => $pf];
            })(),
            default => ['status' => 'skipped', 'skip_reason' => $pr['note'] ?? 'Dilewati (data lama)', 'skipped_at' => $now, 'skipped_by' => $actor->id],
        };
        if ($pr['pic'] !== null) {
            $data['pic_user_id'] = (int) $pr['pic'];
        }
        return $data;
    }

    // ================================================================ bantuan

    private function calendar(): WorkingCalendar
    {
        return $this->cal ??= $this->schedule->calendar();
    }

    /** Template aktif: step aktif berkunci kode, dependency efektif, kode finish & milestone. */
    private function loadTemplates(): void
    {
        if ($this->templates) {
            return;
        }
        $inst = new WorkflowInstantiator($this->schedule);
        foreach (['project', 'new_mold', 'subcont'] as $code) {
            try {
                $v = $inst->currentVersion($code);
            } catch (\Throwable) {
                continue;
            }
            $steps = [];
            $finish = null;
            $milestone = null;
            foreach ($inst->steps((int) $v['version_id']) as $s) {
                $steps[(string) $s['code']] = $s;
                if ($s['step_type'] === 'finish') {
                    $finish = (string) $s['code'];
                }
                if ((int) $s['is_gate_milestone'] === 1) {
                    $milestone ??= (string) $s['code'];
                }
            }
            $this->templates[$code] = [
                'version_id' => (int) $v['version_id'], 'gate_enabled' => (int) $v['gate_enabled'] === 1, 'steps' => $steps,
                'deps' => $inst->effectiveDeps((int) $v['version_id']), 'finish_code' => $finish, 'milestone_code' => $milestone,
            ];
        }
    }

    /** Step dari teks "N3 — 3D Prototype Development", "N3", atau nama prosesnya. @return array<string,mixed>|null */
    private function findStep(string $tpl, string $raw): ?array
    {
        $steps = $this->templates[$tpl]['steps'] ?? [];
        $s = trim($raw);
        if (preg_match('/^([A-Za-z]{1,3}\d{1,3})(?:\s*(?:—|–|-|:|\.)\s*|\s+|$)/u', $s, $m)) {
            $code = strtoupper($m[1]);
            if (isset($steps[$code])) {
                return $steps[$code];
            }
        }
        $n = LegacyFormat::norm($s);
        foreach ($steps as $step) {
            if (LegacyFormat::norm((string) $step['name']) === $n || LegacyFormat::norm((string) ($step['name_en'] ?? '')) === $n) {
                return $step;
            }
        }
        return null;
    }

    /** Kode master nama part bila nama cocok dengan daftar master (mis. "Body", "Cap"); null = nama bebas. */
    private function partNameCode(string $name): ?string
    {
        $n = LegacyFormat::norm($name);
        foreach (MasterService::options('part_name', true) as $o) {
            if ((int) ($o['is_active'] ?? 1) === 1 && (LegacyFormat::norm((string) $o['label_id']) === $n || LegacyFormat::norm((string) $o['label_en']) === $n)) {
                return (string) $o['code'];
            }
        }
        return null;
    }
}
