<?php
declare(strict_types=1);

namespace App\Import;

/**
 * Format file impor data project lama (satu sumber untuk template, pembaca, dan dokumentasi).
 * Kolom dikenali dari JUDUL di baris 1 (urutan kolom boleh berubah; tanda * dan huruf besar/kecil diabaikan).
 * Nilai pilihan diterima dalam bahasa Indonesia atau Inggris (lihat VOCAB).
 */
final class LegacyFormat
{
    public const SHEET_PROJECT = 'Project';
    public const SHEET_PART = 'Part';
    public const SHEET_PROCESS = 'Proses';
    public const SHEET_LISTS = 'Daftar';
    public const SHEET_GUIDE = 'Petunjuk';

    /** Batas ukuran file dan jumlah baris per sheet (di luar batas → ditolak sebelum diproses). */
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_ROWS = 5000;

    /**
     * sheet => [kunci => [judul, wajib (true|'cond'|false), tipe (text|date|int|email|choice), panjang maks, keterangan]]
     * 'cond' = wajib pada kondisi tertentu (dijelaskan di keterangan).
     */
    public const COLUMNS = [
        self::SHEET_PROJECT => [
            'ref' => ['Ref Project', true, 'text', 60, 'Kunci unik project di file ini (disarankan: no. project lama). Dipakai juga di sheet Part & Proses. Ref yang sudah pernah diimpor ditolak.'],
            'name' => ['Nama Produk / Project', true, 'text', 190, 'Nama produk sesuai NPR.'],
            'customer' => ['Kode Customer', true, 'text', 30, 'Kode customer yang SUDAH ADA di Pengaturan › Customer.'],
            'sales' => ['Email Sales PIC', true, 'email', 190, 'Email user aktif ber-role Admin Sales.'],
            'npd' => ['Email NPD PIC', true, 'email', 190, 'Email user aktif ber-role NPD Staff atau Admin.'],
            'priority' => ['Prioritas', false, 'choice', 20, 'Rendah / Normal / Tinggi / Mendesak. Kosong = Normal.'],
            'npr_date' => ['Tanggal NPR', true, 'date', 0, 'Tanggal NPR dikirim / project dimulai.'],
            'feedback_date' => ['Tanggal Feedback NPD', false, 'date', 0, 'Tanggal feedback NPR selesai (part mulai). Kosong = sama dengan Tanggal NPR.'],
            'target' => ['Target Finish', false, 'date', 0, 'Target selesai project.'],
            'npr_number' => ['No. NPR', false, 'text', 40, 'No. NPR lama. Kosong = dibuat sistem (format 001/PIK/NPR/X/2026).'],
            'code' => ['Kode Project', false, 'text', 20, 'Kode project lama. Kosong = dibuat sistem (format NPD-2026-001).'],
            'status' => ['Status Project', true, 'choice', 20, 'Berjalan / Hold / Selesai / Batal.'],
            'finish_date' => ['Tanggal Selesai Project', 'cond', 'date', 0, 'Wajib bila Status Project = Selesai.'],
            'reason' => ['Alasan Hold / Batal', 'cond', 'text', 500, 'Wajib bila Status Project = Hold atau Batal.'],
            'qty_month' => ['Qty per Bulan', false, 'int', 0, 'Kebutuhan per bulan (angka).'],
            'qty_year' => ['Qty per Tahun', false, 'int', 0, 'Kebutuhan per tahun (angka).'],
            'note' => ['Catatan', false, 'text', 2000, 'Catatan NPR.'],
        ],
        self::SHEET_PART => [
            'ref' => ['Ref Project', true, 'text', 60, 'Ref Project dari sheet Project.'],
            'part' => ['Nama Part', true, 'text', 120, 'Nama part/komponen, unik dalam satu project (mis. Body, Cap).'],
            'type' => ['Jenis Part', true, 'choice', 20, 'New Mold / Subcont — menentukan template workflow.'],
            'supplier' => ['Supplier Mold', false, 'text', 190, 'Supplier/pembuat mold.'],
            'drafter' => ['Email PIC Drafter', false, 'email', 190, 'User ber-role Drafter.'],
            'purchasing' => ['Email PIC Purchasing', false, 'email', 190, 'User ber-role Purchasing.'],
            'production' => ['Email PIC Production', false, 'email', 190, 'User ber-role Production.'],
            'quality' => ['Email PIC Quality', false, 'email', 190, 'User ber-role Quality.'],
            'status' => ['Status Part', false, 'choice', 20, 'Aktif / Batal. Kosong = Aktif.'],
            'reason' => ['Alasan Batal Part', 'cond', 'text', 500, 'Wajib bila Status Part = Batal.'],
            'note' => ['Keterangan', false, 'text', 2000, 'Disimpan sebagai feedback NPD part.'],
        ],
        self::SHEET_PROCESS => [
            'ref' => ['Ref Project', true, 'text', 60, 'Ref Project dari sheet Project.'],
            'part' => ['Nama Part', 'cond', 'text', 120, 'Nama Part dari sheet Part. Kosongkan hanya untuk G1 (Assembly / Fit Test, level project).'],
            'process' => ['Proses', true, 'text', 200, 'Pilih dari daftar, mis. "N3 — 3D Prototype Development". Cukup kodenya (N3) juga boleh.'],
            'status' => ['Status', true, 'choice', 20, 'Selesai / Berjalan / Dilewati. Proses yang tidak ditulis = belum mulai.'],
            'start' => ['Tanggal Mulai', 'cond', 'date', 0, 'Wajib untuk Selesai dan Berjalan.'],
            'finish' => ['Tanggal Selesai', 'cond', 'date', 0, 'Wajib untuk Selesai; kosong untuk Berjalan.'],
            'plan_finish' => ['Rencana Selesai', false, 'date', 0, 'Hanya untuk Berjalan. Kosong = Tanggal Mulai + durasi bawaan template.'],
            'pic' => ['Email PIC', false, 'email', 190, 'PIC proses bila berbeda dari PIC bawaan (role harus sesuai proses).'],
            'note' => ['Keterangan', false, 'text', 1000, 'Untuk Dilewati: alasan dilewati.'],
        ],
    ];

    /** Nilai pilihan: kunci kolom => [nilai internal => [ejaan yang diterima…]] (label pertama = yang ditampilkan di template). */
    public const VOCAB = [
        'priority' => ['low' => ['Rendah', 'Low'], 'normal' => ['Normal'], 'high' => ['Tinggi', 'High'], 'urgent' => ['Mendesak', 'Urgent']],
        'project_status' => ['running' => ['Berjalan', 'Running', 'On Progress'], 'hold' => ['Hold', 'On Hold'], 'completed' => ['Selesai', 'Completed', 'Finish'], 'cancelled' => ['Batal', 'Cancelled', 'Cancel']],
        'part_type' => ['new_mold' => ['New Mold', 'New Mould', 'new_mold'], 'subcont' => ['Subcont', 'Sub Cont', 'Subcon']],
        'part_status' => ['active' => ['Aktif', 'Active'], 'cancelled' => ['Batal', 'Cancelled', 'Cancel']],
        'process_status' => ['completed' => ['Selesai', 'Completed', 'Done'], 'current' => ['Berjalan', 'Running', 'On Progress', 'Current'], 'skipped' => ['Dilewati', 'Skipped', 'Skip']],
    ];

    /** Normalisasi judul/nilai untuk pencocokan: huruf kecil, tanpa *, spasi tunggal. */
    public static function norm(string $s): string
    {
        $s = str_replace(['*', "\u{00A0}"], ['', ' '], $s);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }

    /** Nilai internal dari teks pilihan, atau null bila tidak dikenal. */
    public static function choice(string $vocab, string $value): ?string
    {
        $n = self::norm($value);
        foreach (self::VOCAB[$vocab] as $key => $labels) {
            foreach ($labels as $l) {
                if (self::norm($l) === $n) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** @return list<string> label tampilan untuk dropdown */
    public static function labels(string $vocab): array
    {
        return array_values(array_map(static fn (array $l) => $l[0], self::VOCAB[$vocab]));
    }

    /** Label tampilan nilai internal. */
    public static function label(string $vocab, string $key): string
    {
        return self::VOCAB[$vocab][$key][0] ?? $key;
    }

    /** Teks pilihan proses di dropdown, mis. "N3 — 3D Prototype Development". */
    public static function processOption(string $code, string $name): string
    {
        return $code . ' — ' . $name;
    }
}
