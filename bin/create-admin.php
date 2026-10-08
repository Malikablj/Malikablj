<?php
declare(strict_types=1);

/**
 * Membuat akun Admin pertama (interaktif). Password tidak pernah ditampilkan atau disimpan plaintext.
 *   php bin/create-admin.php
 *   php bin/create-admin.php --name="Admin NPD" --email=admin@pik.co.id   (password ditanya)
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\ValidationException;
use App\User\UserService;

$opts = getopt('', ['name:', 'email:', 'title:']);

$ask = static function (string $label, bool $hidden = false): string {
    fwrite(STDOUT, $label . ': ');
    // stty butuh posix & shell_exec (sering dimatikan hosting) — tanpa itu input tetap terbaca, hanya terlihat
    if ($hidden && DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && function_exists('shell_exec') && posix_isatty(STDIN)) {
        shell_exec('stty -echo');
        $v = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $v;
    }
    return trim((string) fgets(STDIN));
};

$name = $opts['name'] ?? $ask('Nama');
$email = $opts['email'] ?? $ask('Email');
$title = $opts['title'] ?? 'Admin';
$password = getenv('NPD_ADMIN_PASSWORD') ?: $ask('Password (min. 8, huruf + angka)', true);
if (!getenv('NPD_ADMIN_PASSWORD')) {
    $confirm = $ask('Ulangi password', true);
    if ($confirm !== $password) {
        fwrite(STDERR, "Password tidak sama.\n");
        exit(1);
    }
}

try {
    $id = (new UserService())->createInitialAdmin($name, $email, $password, $title);
    echo "Admin dibuat (id {$id}). Silakan login dengan email {$email}.\n";
} catch (ValidationException $e) {
    foreach ($e->errors() as $field => $msg) {
        fwrite(STDERR, "{$field}: {$msg}\n");
    }
    exit(1);
}
