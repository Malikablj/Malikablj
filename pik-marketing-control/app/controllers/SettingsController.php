<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Audit;
use App\Helpers\DbBackup;
use App\Helpers\Logger;
use App\Helpers\Mailer;
use App\Helpers\Migrator;
use App\Helpers\Validator;
use App\Models\Setting;
use App\Services\Automation;
use Throwable;

final class SettingsController extends Controller
{
    private const FIELDS = ['company_name', 'qc_email', 'ppn_rate', 'delivery_reminder_days', 'automation_interval_minutes'];

    public function show(): void
    {
        $this->view('settings/index', $this->data() + ['errors' => []]);
    }

    public function update(): void
    {
        $v = Validator::make($_POST, [
            'company_name'                => 'required|string|max:120',
            'qc_email'                    => 'nullable|string|max:500',
            'ppn_rate'                    => 'required|numeric|min:0|max:100',
            'delivery_reminder_days'      => 'required|integer|min:0|max:30',
            'automation_interval_minutes' => 'required|integer|min:5|max:1440',
        ], [
            'company_name' => 'Nama perusahaan', 'qc_email' => 'Email QC', 'ppn_rate' => 'Tarif PPN',
            'delivery_reminder_days' => 'Pengingat delivery', 'automation_interval_minutes' => 'Interval otomasi',
        ]);
        $qc = $v->validated()['qc_email'] ?? null;
        if ($qc !== null && Mailer::parseList((string) $qc) === null) {
            $v->addError('qc_email', 'Email QC tidak valid. Pisahkan beberapa email dengan koma (maks. 5).');
        }
        if ($v->fails()) {
            $old = [];
            foreach (self::FIELDS as $f) {
                $old[$f] = $_POST[$f] ?? '';
            }
            $this->invalid('settings/index', $this->data(), $v->errors(), $old);
            return;
        }
        foreach ($v->validated() as $key => $value) {
            if ($key === 'qc_email') {
                $value = implode(', ', Mailer::parseList((string) $value) ?? []);
            }
            Setting::set($key, (string) $value);
        }
        $this->success('Pengaturan disimpan.', '/settings');
    }

    /** Jalankan otomasi sekarang (tanpa menunggu interval). */
    public function runAutomation(): void
    {
        $result = Automation::run();
        if ($result === null) {
            $this->failure('Otomasi sedang berjalan di proses lain. Coba lagi sebentar.', '/settings');
        }
        $total = array_sum($result['notifications']);
        $this->success('Otomasi selesai: ' . $total . ' notifikasi baru, ' . $result['followups_overdue'] . ' follow up ditandai Overdue.', '/settings');
    }

    /** Status pembaruan struktur database (migrasi). */
    public function database(): void
    {
        $this->view('settings/database', [
            'title'   => 'Pembaruan database',
            'all'     => Migrator::all(),
            'pending' => Migrator::pending(),
        ]);
    }

    /** Backup tabel terkait, lalu jalankan migrasi yang belum jalan. */
    public function migrate(): void
    {
        if (Migrator::pending() === []) {
            $this->success('Struktur database sudah versi terbaru.', '/settings/database');
        }
        @set_time_limit(300);
        try {
            $backup = DbBackup::tables(['users', 'purchase_orders', 'po_lines', 'deliveries', 'returns', 'customers', 'migration_issues'], 'before-migrate');
            $ran = Migrator::run();
        } catch (Throwable $e) {
            Logger::error('Migrasi gagal: ' . $e->getMessage());
            $this->failure('Pembaruan database gagal: ' . $e->getMessage() . ' Pembaruan aman dijalankan ulang setelah penyebabnya diperbaiki.', '/settings/database');
        }
        Audit::log('migrate', 'database', null, implode(', ', $ran), ['backup' => ['old' => null, 'new' => basename($backup)]]);
        $this->success(count($ran) . ' pembaruan database dijalankan. Backup tabel sebelum pembaruan: storage/backups/' . basename($backup) . '.', '/settings/database');
    }

    /** @return array<string,mixed> */
    private function data(): array
    {
        $values = [];
        foreach (self::FIELDS as $f) {
            $values[$f] = Setting::get($f);
        }
        $last = Setting::get('automation_last_result');
        $lastImport = Setting::get('last_import');
        return [
            'title'      => 'Settings',
            'values'     => $values,
            'lastRun'    => Setting::get('automation_last_run'),
            'lastResult' => $last !== null ? (json_decode($last, true) ?: null) : null,
            'lastImport' => $lastImport !== null ? (json_decode($lastImport, true) ?: null) : null,
        ];
    }
}
