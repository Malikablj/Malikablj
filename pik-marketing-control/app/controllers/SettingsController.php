<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Validator;
use App\Models\Setting;
use App\Services\Automation;

final class SettingsController extends Controller
{
    private const FIELDS = ['company_name', 'invoice_default_due_days', 'ppn_rate', 'delivery_reminder_days', 'automation_interval_minutes'];

    public function show(): void
    {
        $this->view('settings/index', $this->data() + ['errors' => []]);
    }

    public function update(): void
    {
        $v = Validator::make($_POST, [
            'company_name'                => 'required|string|max:120',
            'invoice_default_due_days'    => 'required|integer|min:0|max:365',
            'ppn_rate'                    => 'required|numeric|min:0|max:100',
            'delivery_reminder_days'      => 'required|integer|min:0|max:30',
            'automation_interval_minutes' => 'required|integer|min:5|max:1440',
        ], [
            'company_name' => 'Nama perusahaan', 'invoice_default_due_days' => 'Jatuh tempo default', 'ppn_rate' => 'Tarif PPN',
            'delivery_reminder_days' => 'Pengingat delivery', 'automation_interval_minutes' => 'Interval otomasi',
        ]);
        if ($v->fails()) {
            $old = [];
            foreach (self::FIELDS as $f) {
                $old[$f] = $_POST[$f] ?? '';
            }
            $this->invalid('settings/index', $this->data(), $v->errors(), $old);
            return;
        }
        foreach ($v->validated() as $key => $value) {
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
        $this->success('Otomasi selesai: ' . $total . ' notifikasi baru, ' . $result['followups_overdue'] . ' follow up ditandai Overdue, '
            . $result['invoices_updated'] . ' status invoice diperbarui.', '/settings');
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
