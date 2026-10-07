<?php

declare(strict_types=1);

/*
 * Membuat akun Admin dari command line (alternatif halaman /setup).
 *
 *   php database/create_admin.php
 *   php database/create_admin.php --name="Nama Admin" --email=admin@perusahaan.co.id
 *
 * Password diminta secara interaktif (tidak tampil di layar & tidak tersimpan
 * di riwayat shell). Untuk otomasi bisa memakai variabel ADMIN_PASSWORD.
 */

if (PHP_SAPI !== 'cli') {
    exit("Jalankan dari command line.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Auth;
use App\Models\User;

$opts = getopt('', ['name:', 'email:']);

function ask(string $question): string
{
    echo $question;
    return trim((string) fgets(STDIN));
}

function askHidden(string $question): string
{
    echo $question;
    $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($isTty && stripos(PHP_OS, 'WIN') !== 0) {
        shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";
        return $value;
    }
    return trim((string) fgets(STDIN));
}

$name = (string) ($opts['name'] ?? '') ?: ask('Nama admin      : ');
$email = (string) ($opts['email'] ?? '') ?: ask('Email admin     : ');
$password = (string) (getenv('ADMIN_PASSWORD') ?: askHidden('Password        : '));
if (!getenv('ADMIN_PASSWORD')) {
    $confirm = askHidden('Ulangi password : ');
    if ($confirm !== $password) {
        fwrite(STDERR, "Password tidak sama.\n");
        exit(1);
    }
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Nama wajib diisi dan email harus valid.\n");
    exit(1);
}
$policy = Auth::passwordPolicyError($password);
if ($policy !== null) {
    fwrite(STDERR, $policy . "\n");
    exit(1);
}

try {
    $id = User::createWithPassword(['name' => $name, 'email' => $email, 'role' => 'Admin', 'password' => $password]);
    echo "Admin berhasil dibuat (id {$id}). Silakan login dengan email {$email}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
