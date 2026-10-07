<?php
declare(strict_types=1);

use App\Core\Env;

/** SMTP bawaan dari .env. Pengaturan Admin (application_settings mail.*) lebih diutamakan bila diisi. */
return [
    'host'       => Env::get('MAIL_HOST', ''),
    'port'       => (int) Env::get('MAIL_PORT', '587'),
    'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
    'username'   => Env::get('MAIL_USERNAME', ''),
    'password'   => Env::get('MAIL_PASSWORD', ''),
    'from_address' => Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name'  => Env::get('MAIL_FROM_NAME', 'NPD Project Control'),
    // percobaan ulang: menit tunggu sebelum percobaan ke-2, 3, 4, 5
    'retry_backoff_minutes' => [5, 15, 60, 240],
    'max_attempts' => 5,
];
