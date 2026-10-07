<?php

declare(strict_types=1);

/*
 * KHUSUS DEVELOPMENT / TESTING — membuat 1 user untuk setiap role:
 *
 *   admin@pik.test, marketing@pik.test, sales@pik.test,
 *   management@pik.test, viewer@pik.test
 *
 * Password semua user = nilai variabel DEV_PASSWORD, atau default
 * "PikDev2026!" bila tidak diset. Script hanya berjalan bila APP_ENV = development,
 * local, atau testing (ditolak di production maupun bila APP_ENV tidak diset).
 *
 *   php database/seed_dev_users.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Database;
use App\Models\User;

if (!in_array(config('app.env'), ['development', 'local', 'testing'], true)) {
    fwrite(STDERR, "Ditolak: APP_ENV harus development/local/testing. User development tidak boleh dibuat di server produksi.\n");
    exit(1);
}

$password = getenv('DEV_PASSWORD') ?: 'PikDev2026!';
$users = [
    ['Admin Dev', 'admin@pik.test', 'Admin'],
    ['Marketing Dev', 'marketing@pik.test', 'Marketing'],
    ['Sales Dev', 'sales@pik.test', 'Sales'],
    ['Management Dev', 'management@pik.test', 'Management'],
    ['PPIC Dev', 'ppic@pik.test', 'PPIC'],
    ['Viewer Dev', 'viewer@pik.test', 'Viewer'],
];

foreach ($users as [$name, $email, $role]) {
    if (Database::fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
        echo "- {$email} sudah ada, dilewati\n";
        continue;
    }
    User::createWithPassword(['name' => $name, 'email' => $email, 'role' => $role, 'password' => $password]);
    echo "+ {$email} ({$role})\n";
}
echo "\nSelesai. Password development: " . (getenv('DEV_PASSWORD') ? '(dari DEV_PASSWORD)' : $password) . "\n";
