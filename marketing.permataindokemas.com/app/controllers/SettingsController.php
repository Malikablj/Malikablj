<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Mailer;
use App\Helpers\Validator;
use App\Models\Setting;
use App\Services\Automation;
use Throwable;

final class SettingsController extends Controller
{
    private const FIELDS = [
        'company_name', 'delivery_reminder_days', 'automation_interval_minutes',
        'qc_default_email', 'mail_transport', 'mail_from_address', 'mail_from_name',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
    ];

    public function show(): void
    {
        $this->view('settings/index', $this->data() + ['errors' => []]);
    }

    public function update(): void
    {
        $v = Validator::make($_POST, [
            'company_name'                => 'required|string|max:120',
            'delivery_reminder_days'      => 'required|integer|min:0|max:30',
            'automation_interval_minutes' => 'required|integer|min:5|max:1440',
            'qc_default_email'            => 'nullable|string|max:500',
            'mail_transport'              => ['required', ['in', array_keys(Mailer::TRANSPORTS)]],
            'mail_from_address'           => 'nullable|email|max:190',
            'mail_from_name'              => 'nullable|string|max:120',
            'smtp_host'                   => 'nullable|string|max:190',
            'smtp_port'                   => 'nullable|integer|min:1|max:65535',
            'smtp_encryption'             => ['nullable', ['in', array_keys(Mailer::ENCRYPTIONS)]],
            'smtp_username'               => 'nullable|string|max:190',
        ], [
            'company_name' => 'Nama perusahaan',
            'delivery_reminder_days' => 'Pengingat delivery', 'automation_interval_minutes' => 'Interval otomasi',
            'qc_default_email' => 'Email QC default', 'mail_transport' => 'Metode kirim', 'mail_from_address' => 'Email pengirim',
            'mail_from_name' => 'Nama pengirim', 'smtp_host' => 'SMTP host', 'smtp_port' => 'SMTP port', 'smtp_encryption' => 'Enkripsi', 'smtp_username' => 'SMTP username',
        ]);
        $data = $v->validated();
        if (!$v->fails() && !empty($data['qc_default_email'])) {
            $list = Mailer::parseList((string) $data['qc_default_email']);
            if ($list['invalid'] !== []) {
                $v->addError('qc_default_email', 'Email tidak valid: ' . implode(', ', $list['invalid']));
            } else {
                $data['qc_default_email'] = implode(', ', $list['valid']);
            }
        }
        if (!$v->fails() && ($data['mail_transport'] ?? '') === 'smtp' && empty($data['smtp_host'])) {
            $v->addError('smtp_host', 'SMTP host wajib diisi bila metode kirim SMTP.');
        }
        if ($v->fails()) {
            $old = [];
            foreach (self::FIELDS as $f) {
                $old[$f] = $_POST[$f] ?? '';
            }
            $this->invalid('settings/index', $this->data(), $v->errors(), $old);
            return;
        }
        foreach ($data as $key => $value) {
            Setting::set($key, $value === null ? '' : (string) $value);
        }
        // Password SMTP hanya diganti bila diisi (kosong = tetap); centang "hapus" untuk mengosongkan.
        $password = is_string($_POST['smtp_password'] ?? null) ? (string) $_POST['smtp_password'] : '';
        if ($password !== '') {
            Setting::set('smtp_password', mb_substr($password, 0, 255));
        } elseif ((string) ($_POST['smtp_password_clear'] ?? '') === '1') {
            Setting::set('smtp_password', '');
        }
        $this->success('Pengaturan disimpan.', '/settings');
    }

    /** Kirim email percobaan ke email user yang login (atau alamat yang diisi). */
    public function testMail(): void
    {
        $target = trim((string) ($_POST['test_email'] ?? ''));
        if ($target === '') {
            $target = (string) (Auth::user()['email'] ?? '');
        }
        $list = Mailer::parseList($target);
        if ($list['valid'] === [] || $list['invalid'] !== []) {
            $this->failure('Alamat email percobaan tidak valid.', '/settings#email');
        }
        try {
            Mailer::send($list['valid'], 'Tes email — ' . (Setting::get('app_name') ?? 'PIK Marketing Control'),
                '<p>Email percobaan dari <strong>' . e(Setting::get('app_name') ?? 'PIK Marketing Control') . '</strong>.</p><p>Bila email ini diterima, pengaturan email sudah benar dan notifikasi complaint ke QC dapat terkirim.</p>'
                . '<p style="color:#6e6e73;font-size:12px">Dikirim ' . e(date('d/m/Y H:i')) . ' oleh ' . e(Auth::user()['name'] ?? '') . ' · metode ' . e(Mailer::transport()) . '</p>');
        } catch (Throwable $e) {
            \App\Helpers\Logger::error('Tes email gagal: ' . $e->getMessage());
            $this->failure('Tes email gagal: ' . $e->getMessage(), '/settings#email');
        }
        $this->success('Email percobaan terkirim ke ' . implode(', ', $list['valid']) . '. Periksa juga folder spam.', '/settings#email');
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
            'hasSmtpPassword' => (string) Setting::get('smtp_password', '') !== '',
        ];
    }
}
