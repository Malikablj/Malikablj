# NPD Project Control v3.0 — PT. Permata Indo Kemas

Aplikasi web pengendali **New Product Development**: New Project Request (NPR) digital sesuai
form **PIK-FORM-NPD-01 rev 00**, project multi-part, workflow berdependensi (FS/SS/FF/paralel + lag),
penjadwalan hari kerja, timeline dua level, overdue & notifikasi, Hold/Resume, dokumen berversi,
approval, audit log, dashboard, dan KPI per PIC.

Sumber requirement: `PRD_NPD_Project_Control_v3.0.docx` — dipetakan di
[`docs/PRD_REQUIREMENTS_MATRIX.md`](docs/PRD_REQUIREMENTS_MATRIX.md).

| Teknologi | Versi |
| --- | --- |
| PHP (native, tanpa framework) | 8.2 atau lebih baru (diuji 8.3) |
| MySQL | 8.0+ (InnoDB, utf8mb4_unicode_ci) — diuji 8.0.46 |
| Frontend | HTML5, CSS3, Vanilla JavaScript (tanpa Node.js / build step) |
| PDF / Excel / Email | mPDF, PhpSpreadsheet, PHPMailer (via Composer) |

## Status fase

Lihat [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) bagian *Phase log*.

## Instalasi cepat (development)

```bash
# 1. Dependency PHP
composer install

# 2. Konfigurasi
cp .env.example .env
php bin/generate-key.php        # salin hasilnya ke APP_KEY di .env
# isi DB_HOST, DB_NAME, DB_USER, DB_PASS; untuk lokal set APP_ENV=development

# 3. Database (membuat database, tabel, data master, template workflow)
php bin/install.php
# alternatif tanpa CLI: impor database/schema.sql lalu database/seed.sql lewat phpMyAdmin

# 4. Admin pertama
php bin/create-admin.php

# 5. Jalankan (development)
php -S 127.0.0.1:8080 -t public
```

Buka `http://127.0.0.1:8080`. Di produksi, **document root web server harus menunjuk ke folder
`public/`** — folder lain (config, storage, modules, .env) tidak boleh dapat diakses dari web.
Panduan produksi lengkap (akun MySQL berhak minimal, Apache/Nginx, HTTPS, cron, upgrade, rollback) ada di
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md), backup & pemulihan di [`docs/BACKUP_AND_RESTORE.md`](docs/BACKUP_AND_RESTORE.md);
contoh konfigurasi siap pakai di [`deploy/`](deploy).

## Struktur

```
config/      konfigurasi (membaca .env)
public/      document root: halaman, api/, assets/ (css, js, font Inter, logo)
includes/    bootstrap, helper view (e(), t(), url()), guard otorisasi, layout
modules/     logika bisnis (namespace App\…): Core, User, Npr, Project, Workflow, Scheduling, …
lang/        id.php (bawaan), en.php
database/    schema.sql, seed.sql (generated), seeds/*.php, hardening.sql, migrations/
cron/        overdue.php, notifications.php, daily-report.php
bin/         install.php, migrate.php, create-admin.php, backup.php, restore.php, db-grants.php,
             check-deployment.php, build-seed.php, generate-key.php
deploy/      contoh nginx, Apache, php.ini, cron.d, logrotate
storage/     documents/, exports/, logs/, sessions/, backups/   (di luar webroot)
tests/       Unit/, Integration/ (MySQL nyata), Http/ (server PHP + curl), Ops/ (skrip operasional),
             browser/ (Chromium), perf/ (uji kinerja)
docs/        PRD matrix, arsitektur, desain database, rencana, open questions
legacy/      implementasi Python/Flask lama (PRD v2.1) — referensi, tidak dipakai
```

## Menjalankan test

```bash
vendor/bin/phpunit                      # semua suite
vendor/bin/phpunit --testsuite unit     # tanpa database
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit --testsuite http     # menjalankan php -S terhadap database npd_test_http
vendor/bin/phpunit --testsuite ops      # migrasi, backup/restore, pemeriksaan deploy (database npd_test_*)
```

Test membuat ulang database berawalan `npd_test` (user MySQL di `.env` butuh hak CREATE/DROP untuk
database tersebut). Database lain tidak pernah disentuh. Uji kinerja: `docs/PERFORMANCE.md`.

## Keamanan (ringkas)

PDO prepared statements · token CSRF · escaping output `e()` · CSP dengan nonce · sesi
HttpOnly/Secure/SameSite + timeout idle + regenerasi ID · `password_hash()` · rate limiting login ·
otorisasi role × permission di server (`App\Core\Gate`) · audit log append-only.
Detail & test yang membuktikannya: [`docs/SECURITY.md`](docs/SECURITY.md).

## Mengubah data awal

Data master & template workflow didefinisikan di `database/seeds/*.php`.
Setelah mengubahnya jalankan `php bin/build-seed.php` untuk membuat ulang `database/seed.sql`.
