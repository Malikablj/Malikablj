<?php
declare(strict_types=1);

/**
 * Data demo untuk lingkungan development / UAT (BUKAN produksi):
 * satu user per role + beberapa customer. Ditolak bila APP_ENV=production.
 *
 *   NPD_DEMO_PASSWORD='Rahasia123' php bin/demo-seed.php
 * Password demo diambil dari env NPD_DEMO_PASSWORD (wajib), tidak di-hardcode.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "Ditolak: demo-seed tidak boleh dijalankan pada APP_ENV=production\n");
    exit(1);
}
$password = (string) getenv('NPD_DEMO_PASSWORD');
if (strlen($password) < 8) {
    fwrite(STDERR, "Set NPD_DEMO_PASSWORD (min. 8 karakter, huruf + angka)\n");
    exit(1);
}

$users = [
    ['admin', 'Andi Pratama', 'andi@pik.local', 'Admin Sistem'],
    ['admin_sales', 'Sari Wulandari', 'sari@pik.local', 'Admin Sales'],
    ['admin_sales', 'Budi Santoso', 'budi@pik.local', 'Admin Sales'],
    ['npd_staff', 'Rizki Ramadhan', 'rizki@pik.local', 'Admin NPD'],
    ['npd_staff', 'Maya Lestari', 'maya@pik.local', 'Trial Analyst'],
    ['drafter', 'Dimas Saputra', 'dimas@pik.local', 'Drafter'],
    ['purchasing', 'Hendra Gunawan', 'hendra@pik.local', 'Purchasing'],
    ['production', 'Agus Salim', 'agus@pik.local', 'Supervisor Produksi'],
    ['quality', 'Lestari Dewi', 'lestari@pik.local', 'QC Engineer'],
    ['management', 'Farhan Zulfikar', 'farhan@pik.local', 'NPD Manager'],
];
$hash = password_hash($password, PASSWORD_DEFAULT);
$created = 0;
foreach ($users as [$role, $name, $email, $title]) {
    if (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
        continue;
    }
    Db::insert('users', [
        'role_id' => (int) Db::value('SELECT id FROM roles WHERE code = ?', [$role]),
        'name' => $name, 'email' => $email, 'password_hash' => $hash, 'job_title' => $title,
        'is_active' => 1, 'language' => 'id', 'password_changed_at' => date('Y-m-d H:i:s'),
    ]);
    $created++;
}
$customers = [
    ['SNT', 'PT Sanitya Utama', 'Jl. Industri Raya No. 8, Tangerang', 'Gudang Sanitya, Kawasan MM2100 Blok C-5, Bekasi', '021-5551234'],
    ['KSM', 'PT Kosmetika Nusantara', 'Jl. Gatot Subroto Kav. 21, Jakarta Selatan', 'Jl. Raya Cikarang KM 3, Bekasi', '021-5208899'],
    ['HMC', 'CV Home Care Indonesia', 'Jl. Ahmad Yani 45, Surabaya', 'Jl. Rungkut Industri II/7, Surabaya', '031-8471122'],
];
foreach ($customers as [$code, $name, $inv, $ship, $phone]) {
    if (!Db::value('SELECT id FROM customers WHERE code = ?', [$code])) {
        Db::insert('customers', ['code' => $code, 'name' => $name, 'invoice_address' => $inv, 'shipping_address' => $ship, 'phone' => $phone, 'is_active' => 1]);
    }
}
echo "Demo: {$created} user baru, customer siap.\n";
