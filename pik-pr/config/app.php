<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'name' => (string) Env::get('APP_NAME', 'PR PIK'),
    'env' => (string) Env::get('APP_ENV', 'production'),
    'debug' => (bool) Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Jakarta'),

    'session' => [
        'name' => (string) Env::get('SESSION_NAME', 'pik_pr_session'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 120),
        'secure' => (bool) Env::get('SESSION_SECURE_COOKIE', false),
    ],

    'login' => [
        'max_attempts' => 5,
        'decay_minutes' => 15,
    ],

    'upload' => [
        'path' => (string) Env::get('UPLOAD_PATH', 'storage/uploads'),
        'max_mb' => max(1, (int) Env::get('UPLOAD_MAX_MB', 5)),
        'max_files_per_pr' => 10,
        // ekstensi => MIME yang diterima (divalidasi dengan finfo, bukan dari browser)
        'allowed' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        ],
    ],

    'pr' => [
        // Singkatan bulan pada nomor PR, contoh: PR/PIK/SEPT/2026-PDPR077
        'months' => ['JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGS', 'SEPT', 'OKT', 'NOV', 'DES'],
        'max_items' => 100,
    ],
];
