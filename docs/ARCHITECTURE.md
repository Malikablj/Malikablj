# Arsitektur — NPD Project Control v3.0

## 1. Ringkasan

Aplikasi web **PHP 8.2+ native (tanpa framework)** dengan **MySQL 8 (InnoDB, utf8mb4)**,
dirender di server (server-side rendering), dengan **Vanilla JavaScript** untuk interaksi
(autosave NPR, pratinjau jadwal, Gantt, notifikasi). Dapat dijalankan di hosting PHP/MySQL
standar: tidak membutuhkan Node.js, framework, atau proses daemon (tugas terjadwal memakai cron).

```
Browser (HTML5 + CSS3 + Vanilla JS, fetch API)
   │  HTTPS, cookie sesi HttpOnly/Secure/SameSite, token CSRF
   ▼
public/  (document root — satu-satunya folder yang diekspos web server)
   ├── *.php            halaman (SSR) — PRG pattern untuk form POST
   ├── api/*.php        endpoint JSON (autosave, pratinjau jadwal, data Gantt, notifikasi)
   ├── download.php     unduh dokumen (cek sesi + hak akses, file di luar webroot)
   ├── export.php       PDF (mPDF) / Excel (PhpSpreadsheet)
   └── assets/          css, js, font Inter (self-hosted), logo
   │
   ▼
includes/bootstrap.php  → konfigurasi, autoload, sesi, header keamanan, i18n
   │
   ▼
modules/  (logika bisnis, namespace App\…) — SATU-SATUNYA tempat aturan bisnis
   ├── Core/        Db (PDO), Session, Auth, Gate, Csrf, Request, Response, Settings, AuditLogger, NumberSequence, I18n
   ├── User/        UserService
   ├── Master/      MasterService, CustomerService
   ├── Npr/         NprService, NprFeedbackService, NprFields
   ├── Project/     ProjectService, StatusService, HoldService
   ├── Workflow/    WorkflowTemplateService, WorkflowInstantiator, WorkflowEngine, DependencyService, GateService
   ├── Scheduling/  WorkingCalendar, Scheduler, ScheduleRepository, BaselineService
   ├── Document/    DocumentService, UploadValidator
   ├── Approval/    ApprovalService
   ├── Record/      RecordService (trial, material, validation)
   ├── Notification/ Notifier, MailQueue, Mailer
   ├── Report/      DashboardService, ReportService, NprPdf, TimelinePdf, ExcelWriter, …
   └── Kpi/         KpiService
   │
   ▼
MySQL 8 (database/schema.sql)            storage/ (di luar webroot)
                                            ├── documents/  file unggahan (nama acak)
                                            ├── exports/    file export sementara
                                            ├── logs/       app.log, cron.log
                                            └── sessions/   (opsional) file sesi PHP
cron/ (php-cli)  overdue.php · notifications.php · daily-report.php
```

## 2. Keputusan teknologi

| Area | Keputusan | Alasan |
| --- | --- | --- |
| Backend | PHP 8.2+ native, OOP sederhana, `declare(strict_types=1)` | Sesuai instruksi (tanpa Laravel/Symfony/CI); mudah dipelihara |
| Database | MySQL 8, PDO, **prepared statements saja** | Anti SQL injection; `Db::insert/update` hanya menerima nama kolom dari kode (divalidasi regex) |
| Autoload | Autoloader PSR-4 sendiri untuk `App\` → `modules/`; `vendor/autoload.php` dimuat bila ada | Inti aplikasi tetap berjalan tanpa Composer; fitur PDF/Excel/SMTP butuh `vendor/` |
| PDF | **mPDF** | Header/footer per halaman (nomor halaman, nomor NPR, PIK-FORM-NPD-07), watermark, tabel multi-halaman, SVG (Gantt) |
| Excel | **PhpSpreadsheet** | Tanggal sebagai tanggal sungguhan, freeze pane, autofilter, border, format angka |
| Email | **PHPMailer** (SMTP) via antrean DB + cron | Kegagalan tidak menggagalkan transaksi; retry; status terlihat Admin |
| Frontend | HTML5, CSS3 (custom properties), Vanilla JS, fetch API | Sesuai instruksi; tanpa build step |
| Grafik | SVG digambar Vanilla JS (`assets/js/charts.js`), palet light/dark dari CSS variable | Tanpa library besar; dark mode konsisten |
| Gantt | HTML/CSS + Vanilla JS (`assets/js/gantt.js`), data dari `api/gantt.php` | Zoom hari/minggu/bulan, scroll horizontal, mode daftar di HP |
| Tes | PHPUnit (unit + integrasi DB MySQL nyata) + tes HTTP (server bawaan PHP + curl) + tes browser (Chromium headless) | Logika bisnis diuji otomatis; bukan screenshot manual |

## 3. Struktur folder

```
/                         (root repository = root aplikasi)
├── config/               config.php (membaca .env), database.php, mail.php
├── public/               DOCUMENT ROOT web server
│   ├── index.php, login.php, logout.php, dashboard.php, profile.php
│   ├── projects.php, project.php, process.php, npr.php, npr-edit.php
│   ├── tracker.php, gantt.php, calendar.php, documents.php, approvals.php
│   ├── reports.php, notifications.php, download.php, export.php
│   ├── settings/         users.php, customers.php, masters.php, workflow.php,
│   │                     holidays.php, notifications.php, email-queue.php, audit.php, system.php
│   ├── api/              *.php (JSON)
│   └── assets/           css/ js/ images/ fonts/
├── includes/             bootstrap.php, functions.php (helper global: e(), t(), url()…),
│                         permissions.php (require_permission()), layout/ (header, sidebar, footer)
├── modules/              logika bisnis (App\…), lihat §1
├── lang/                 id.php, en.php
├── storage/              documents/, exports/, logs/, cache/, sessions/   (TIDAK diekspos)
├── database/             schema.sql, seed.sql, hardening.sql, seeds/*.php, migrations/
├── cron/                 overdue.php, notifications.php, daily-report.php
├── bin/                  install.php, create-admin.php, build-seed.php, demo-seed.php, backup.sh
├── tests/                Unit/, Integration/, Http/, browser/
├── docs/                 dokumentasi
├── legacy/python-flask/  implementasi lama PRD v2.1 (Python) — disimpan sebagai referensi, tidak dipakai
├── vendor/               dependency Composer (tidak di-commit)
├── .env.example, composer.json, phpunit.xml, README.md
```

**Catatan audit repository (Phase 0):** repository sebelumnya berisi aplikasi **Python/Flask**
untuk PRD v2.1 (satu proses aktif, AI Assistant, SQLite). Karena v3.0 menetapkan PHP + MySQL dan
model data yang berbeda (multi-part, dependency), kode tersebut **tidak dihapus** tetapi dipindah
ke `legacy/python-flask/` (riwayat git tetap utuh) agar root dapat dipakai aplikasi PHP. Logika
domain lama (daftar proses, field record trial/material/validasi) dipakai sebagai referensi.

## 4. Alur request

1. Web server meneruskan request ke `public/<halaman>.php`.
2. Halaman memanggil `require __DIR__.'/../includes/bootstrap.php'` →
   memuat `.env`, zona waktu `Asia/Jakarta`, autoloader, koneksi PDO (lazy), sesi aman,
   header keamanan (CSP dengan nonce, X-Frame-Options, nosniff, Referrer-Policy, HSTS saat HTTPS),
   bahasa & tema pengguna.
3. `require_login()` → redirect ke login (halaman) atau 401 JSON (API).
4. POST: `Csrf::verify()` (form field `_csrf` atau header `X-CSRF-Token`) → 419 bila gagal.
5. Halaman memanggil **service**. Service memanggil `Gate::authorize($user, 'ability', $context)`
   → `AuthorizationException` → **HTTP 403** (halaman error aman / JSON `{"error":…}`).
   Otorisasi selalu dicek di service, sehingga entry point mana pun (halaman, API, cron) aman.
6. Perubahan data dalam `Db::transaction()`; audit log ditulis dalam transaksi yang sama.
   Email hanya **dimasukkan antrean** dalam transaksi; pengiriman SMTP terjadi di cron.
7. Halaman merender view dengan `e()` (htmlspecialchars, ENT_QUOTES, UTF-8) untuk setiap output.

## 5. Keamanan

| Ancaman | Kontrol |
| --- | --- |
| SQL injection | PDO `ATTR_EMULATE_PREPARES=false`, prepared statements, identifier whitelist |
| XSS | `e()` di semua output HTML, `json_encode` dengan `JSON_HEX_*` untuk data di atribut/script, CSP `script-src 'self' 'nonce-…'` |
| CSRF | Token per sesi (`random_bytes(32)`), dicek dengan `hash_equals` pada setiap POST/PUT/PATCH/DELETE |
| Session fixation/hijack | `session.use_strict_mode`, `session_regenerate_id(true)` saat login & berkala, cookie HttpOnly + Secure (HTTPS) + SameSite=Lax, timeout idle (default 8 jam, `security.session_timeout_minutes`) |
| Brute force | `login_attempts`: kunci 15 menit setelah 5 gagal per email+IP (dapat diatur); batas per IP |
| Password | `password_hash(PASSWORD_DEFAULT)` (bcrypt/argon2), `password_needs_rehash`, panjang minimal 8 |
| Otorisasi | `Gate` di server (role × permission × scope `own`), bukan hanya menyembunyikan tombol |
| Upload | Maks. 25 MB, whitelist ekstensi (Admin), MIME via `finfo`, nama file disanitasi, disimpan dengan nama acak di `storage/documents` (di luar webroot), unduh lewat `download.php` dengan cek hak akses + `Content-Disposition` + `nosniff` |
| Audit | `audit_logs` append-only (tanpa UI edit/hapus; trigger opsional `database/hardening.sql`) |
| Rahasia | `.env` di luar webroot; password SMTP di DB dienkripsi (sodium secretbox, kunci `APP_KEY`) |

## 6. Mesin penjadwalan (ringkas — detail di DATABASE_DESIGN.md §6)

* `WorkingCalendar` — `isWorkingDay()`, `addWorkingDays()`, `countWorkingDays()`, `nextWorkingDay()`;
  hari kerja dari tabel `working_calendar` (bawaan Senin–Jumat) dan `holidays` (termasuk berulang tahunan).
* `Scheduler` — fungsi murni (tanpa DB) yang menerima graf proses + dependency + kalender + "hari ini"
  dan menghasilkan planned/forecast untuk setiap proses, perkiraan selesai part/project, peringatan,
  dan daftar perubahan. Dipakai identik untuk **pratinjau** (tanpa simpan) dan **simpan**.
* `ScheduleRepository::recalculateProject()` — memuat project, menjalankan `Scheduler`,
  menyimpan hasil + `schedule_changes` + audit + notifikasi dalam **satu transaksi** dengan
  `SELECT … FOR UPDATE` pada baris project (mencegah dua perhitungan bersamaan).
* JavaScript **tidak pernah** menghitung jadwal; ia hanya menampilkan hasil dari server.

## 7. Notifikasi & cron

| Cron | Jadwal disarankan | Tugas |
| --- | --- | --- |
| `cron/overdue.php` | tiap jam (hari kerja 06:00–20:00) | aktivasi proses yang tanggal mulainya tiba, hitung ulang forecast, deteksi overdue hari pertama, due soon, target berisiko, next action, no update, pengingat Hold |
| `cron/notifications.php` | tiap 5 menit | kirim antrean email (PHPMailer SMTP), retry backoff 5m/15m/1j/4j, maks. 5 percobaan |
| `cron/daily-report.php` | hari kerja 07:00 | ringkasan overdue harian satu email per PIC / NPD PIC |

Setiap cron memakai lock file (`flock`) agar tidak berjalan ganda dan mencatat `job_runs`.

## 8. Internasionalisasi & tema

* `lang/id.php`, `lang/en.php` — array kunci → teks; `t('key', ['param' => …])`. Bawaan `id`.
  Bahasa disimpan di `users.language`; tanggal diformat per bahasa (`05 Okt 2026` / `05 Oct 2026`).
* Tema: `users.theme` (`system`/`light`/`dark`) → atribut `data-theme` pada `<html>` dirender
  server; untuk `system` skrip kecil ber-nonce di `<head>` menerapkan tema sebelum paint
  (tanpa kilatan terang). Semua warna via CSS custom properties dengan set lengkap untuk dark mode.

## 9. Konkurensi

* Nomor NPR / kode project / kode approval: `number_sequences` dengan
  `INSERT … ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)` (atomik di InnoDB).
* Penguncian optimistik: kolom `lock_version` pada `npr`, `projects`, `project_parts`, `processes`;
  form mengirim versi yang dibaca; `UPDATE … WHERE id=? AND lock_version=?` → 0 baris = konflik
  ("Data sudah diubah pengguna lain, muat ulang").
* Perhitungan ulang jadwal: `SELECT … FOR UPDATE` pada project.

## 10. Lingkungan

| Variabel `.env` | Contoh | Fungsi |
| --- | --- | --- |
| `APP_ENV` | `production` / `uat` / `development` | mode (detail error hanya di development) |
| `APP_URL` | `https://npd.permataindokemas.co.id` | URL absolut untuk email |
| `APP_KEY` | 32 byte base64 | enkripsi rahasia (SMTP) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | | koneksi MySQL |
| `SESSION_SECURE` | `auto` / `1` / `0` | flag Secure cookie (auto = ikut HTTPS) |
| `MAIL_*` | | default SMTP bila belum diatur Admin |
| `STORAGE_PATH` | `/var/npd/storage` | lokasi storage di luar webroot |
