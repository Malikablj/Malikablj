# Implementation Plan — NPD Project Control v3.0

Prinsip: **satu fase selesai & stabil (test hijau + regression) sebelum fase berikutnya**.
Setiap fase diakhiri dengan: daftar file, perubahan database, fitur yang berfungsi, hasil test,
perbaikan, regression test, update dokumentasi, dan commit.

Status fase dicatat di bagian akhir dokumen ini (**Phase log**).

| Fase | Cakupan | Deliverable utama | Kriteria selesai |
| --- | --- | --- | --- |
| 0 | Audit PRD + repository | `docs/*.md`, `database/schema.sql`, struktur folder | Schema dapat dibuat di MySQL 8 dan dijalankan ulang tanpa error |
| 1 | Database + Autentikasi + Role | bootstrap, `Db`, `Session`, `Auth`, `LoginThrottle`, `Csrf`, `Gate`, `AuditLogger`, `I18n`, layout + tema + bahasa, manajemen user, `seed.sql`, `bin/install.php`, `bin/create-admin.php` | Login/logout/timeout/rate limit/CSRF/403 lulus test unit + HTTP |
| 2 | NPR | master & customer (Admin), NPR draft/autosave/submit/feedback/return/complete, multi-part, penomoran aman, revision history, lampiran, PDF NPR (mPDF) | UAT-01..05 otomatis lulus |
| 3 | Project + Part + Process | pembuatan project dari NPR, part, instansiasi proses dari template berversi, status turunan, daftar & detail project | Struktur Project→Part→Process & status §3.3 teruji |
| 4 | Workflow + Dependency | aktivasi otomatis, complete/outcome/loop, skip & pasangan, gate, koreksi manual, editor dependency + validasi siklus/cross-part + pratinjau | UAT-06, 11–15 lulus |
| 5 | Scheduling engine | `WorkingCalendar`, `Scheduler` (planned+forecast), planning manual, auto-shift, baseline, target finish, hari libur | Contoh §6.4, tabel §6.1, UAT-07/08/10, performa ≤ 1 dtk |
| 6 | Timeline + Gantt | timeline Level 1/2, Gantt (hari/minggu/bulan, today line, target, dependency, baseline), tracker, kalender, export timeline PDF/Excel (PIK-FORM-NPD-07) | UAT-18/19 lulus |
| 7 | Document + Approval | upload berversi, validasi, unduh aman, dokumen wajib, approval customer/internal terhubung workflow, record trial/material/validasi | Test dokumen & approval lulus |
| 8 | Overdue + Notification | overdue hari kerja, panel overdue, notifikasi web, antrean email + retry, 3 cron, ringkasan harian, next action | UAT-09 lulus; email gagal tidak menggagalkan transaksi |
| 9 | Hold + Resume + Archive | hold project/part, resume dengan pratinjau & baseline baru, pengingat, cancel, arsip/pulihkan | UAT-16/17/24 lulus |
| 10 | Dashboard + KPI + Reports | 8 kartu, panel, attention, my tasks, 6 grafik, weekly report, analytics, KPI PIC + drill-down + export | UAT-20 lulus; angka dari MySQL |
| 11 | UI/UX refinement | konsistensi token, dark mode semua komponen, responsif, animasi, aksesibilitas | UAT-21/22 (browser test) |
| 12 | Testing + Security + Performance | test lengkap, security test (SQLi, XSS, CSRF, authz, upload), uji beban dasar | Seluruh suite hijau |
| 13 | Deployment preparation | `docs/DEPLOYMENT.md`, `docs/BACKUP_AND_RESTORE.md`, contoh konfigurasi Apache/Nginx, crontab, skrip backup | Instalasi bersih dari dokumen berhasil |

## Strategi test

| Lapisan | Alat | Lokasi |
| --- | --- | --- |
| Unit (tanpa DB) | PHPUnit | `tests/Unit` — kalender, scheduler, validator, i18n, gate |
| Integrasi (MySQL nyata `npd_test`) | PHPUnit, transaksi di-rollback per test | `tests/Integration` — service: auth, NPR, workflow, jadwal, dokumen, notifikasi, KPI |
| HTTP | PHPUnit + server bawaan PHP + curl | `tests/Http` — login, sesi, CSRF, 403, header keamanan, unduhan |
| Browser | Chromium headless (Playwright, hanya untuk test) | `tests/browser` — dark mode, responsif, bahasa |

Perintah: `composer test` (atau `vendor/bin/phpunit`). Database test dibuat otomatis dari
`database/schema.sql` + `database/seed.sql` oleh `tests/bootstrap.php`.

## Phase log

| Fase | Status | Commit | Catatan |
| --- | --- | --- | --- |
| 0 | Selesai | (lihat git log) | Audit, dokumen, schema 45 tabel teruji di MySQL 8.0.46 |
| 1 | Berjalan | | |
