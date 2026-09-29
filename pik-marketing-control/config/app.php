<?php

declare(strict_types=1);

use App\Helpers\Env;

/*
 * Konfigurasi aplikasi. Nilai diambil dari file .env (lihat .env.example).
 * Jangan menulis password atau secret langsung di file ini.
 */
return [
    'name'        => Env::get('APP_NAME', 'PIK Marketing Control'),
    'company'     => 'PT Permata Indo Kemas',
    // production | development | testing
    'env'         => Env::get('APP_ENV', 'production'),
    'debug'       => Env::bool('APP_DEBUG', false),
    'timezone'    => Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
    // Kosongkan agar base path terdeteksi otomatis (mis. /pik bila di subfolder)
    'base_path'   => Env::get('APP_BASE_PATH', ''),
    // false = URL memakai /index.php/... (untuk server tanpa mod_rewrite)
    'pretty_urls' => Env::bool('APP_PRETTY_URLS', true),
    'force_https' => Env::bool('FORCE_HTTPS', false),

    'session' => [
        'name'           => 'PIKSESSID',
        'idle_minutes'   => (int) Env::get('SESSION_IDLE_MINUTES', '120'),
        'absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', '12'),
    ],

    'security' => [
        'login_max_attempts'    => 5,
        'login_lockout_minutes' => 15,
        'password_min_length'   => 8,
    ],

    'pagination' => [
        'per_page' => 25,
    ],

    'upload' => [
        // batas upload workbook untuk import data (MB)
        'max_import_mb' => 20,
    ],
];
