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
| 0 | Selesai | `670687e` | Audit, dokumen, schema 45 tabel teruji di MySQL 8.0.46 (idempoten) |
| 1 | Selesai | `04f8681` | Auth, sesi aman, rate limit, CSRF, Gate (matriks §2.3), manajemen user, audit log, i18n ID/EN, tema terang/gelap, layout responsif. 119 test hijau (unit 22 · integrasi 73 · HTTP 24). Bug ditemukan & diperbaiki: ID sesi berkoma (cookie tidak valid), POST form ke /api/ membalas JSON, spesifisitas CSS tombol drawer. |
| 2 | Selesai | `2a4d8db` | NPR digital lengkap: master NPR & customer (Admin), draft + autosave, multi-part (master / "Lainnya" / promosi ke master), kirim (nomor NPR & kode project aman konkuren), kolom biru/pink ditegakkan server, feedback per part (draft → publikasi), Kembalikan, Selesaikan Feedback (Tidak Feasible membatalkan part; Perlu Revisi mengembalikan NPR), Revision History dengan diff, lampiran privat + unduhan aman, PDF NPR mPDF (kop, pita hitam, kotak centang, biru/pink, no. halaman & No. NPR per halaman, pita pratinjau + watermark), notifikasi web + antrean email, stepper HP. 167 test hijau + alur browser end-to-end. Bug ditemukan & diperbaiki: payload parsial menghapus field multi/checkbox; legenda PDF menempel; border radio tertimpa warna teks; bilah aksi kosong. |
| 5 (inti) | Selesai | `487d1e5` | `WorkingCalendar` + `Scheduler` murni (FS/SS/FF/Paralel, lag, tanggal manual, Skipped, loop_only, tarik maju, jalur kritis, deteksi siklus). 36 unit test termasuk contoh PRD §6.4 & tabel §6.1; 201 node < 1 dtk. |
| 3–5 | Selesai | `19cae6f` | Project → Part → Proses dari template berversi (snapshot), mesin workflow (aktivasi otomatis pada Planned Start, Mulai lebih awal, penyelesaian + keputusan repeat/loop/activate/gate_fail, approval tercatat, dokumen wajib, FF memblokir, Tidak dijalankan berpasangan + Jalankan kembali, koreksi manual Admin, Finish part/project), status turunan §3.3, run KPI per iterasi, `ScheduleService` (hitung ulang dalam transaksi + `FOR UPDATE`, `schedule_changes`, audit, notifikasi geser & target berisiko, baseline berversi, Target Finish baru), `DependencyService` (lintas part ditolak, siklus ditolak, pratinjau, audit sebelum/sesudah), unggah dokumen proses berversi, halaman Project (ringkasan, proses, riwayat), halaman Proses (selesaikan, planning + pratinjau, editor dependency + pratinjau, dokumen, iterasi, aktivitas), API pratinjau jadwal, `cron/overdue.php` (aktivasi harian + job_runs + GET_LOCK). 247 test hijau (unit 56 · integrasi 153 · HTTP 38) + alur browser `project_flow.py` & regresi `npr_flow.py`. Keputusan baru: OQ-25 (FS P2 = mulai hari kerja berikutnya, sesuai contoh §6.4), OQ-26 (definisi "belum dimulai" untuk Tidak dijalankan). Bug ditemukan & diperbaiki: G1/PF tanpa predecessor akan aktif sebelum ada part (diberi dependency P2); siklus jadwal pada loop T0 Not OK (edge pemicu ke proses loop_only diabaikan Scheduler); hasil gate unik per proses menolak iterasi kedua (kunci jadi proses+iterasi); `Db::in()` dibungkus kurung ganda; pratinjau membandingkan dengan data tersimpan basi (kini dengan hasil hitung "sebelum"); aktivasi tidak menghitung ulang successor (kini dihitung ulang). |
| 6 | Selesai | `3118555` | Timeline dua level (`timeline.php`: Level 1 proses level project + ringkasan part; Level 2 seluruh proses part dengan tabel 12 kolom PRD §6.6, filter status, edit planning langsung untuk NPD/Admin), Gantt HTML/CSS (posisi via variabel CSS) + JS vanilla (zoom hari/minggu/bulan, gulir ke hari ini, panah dependency SVG FS/SS/FF, toggle baseline/jalur kritis/dependency, garis hari ini & Target Finish, arsiran akhir pekan & libur, batang tumbuh dengan reduce-motion), mode tabel bawaan di HP, Gantt lintas project (Project/Part, filter customer/PIC/status/jenis, paginasi), Process Tracker (kartu per part-proses aktif, overdue merah), Kalender (kategori proses, agenda manual Admin/NPD/Sales, libur, tampilan daftar di HP), export Timeline PDF (A4 landscape, PIK-FORM-NPD-07 tiap halaman, baris overdue merah, halaman Gantt vektor) & Excel (sheet per level/part, tanggal asli, freeze, filter, keterlambatan ditandai). 260 test hijau + `timeline_flow.py`. Bug ditemukan & diperbaiki: token CSRF di test HTTP dari respons redirect; ikon SVG `display:block` memecah baris pada kartu tracker; label kolom PDF memakai teks form planning ("Planned Finish baru"). |
| 7 | Selesai | (commit Phase 7) | Approval otomatis Pending saat proses approval aktif (per iterasi), keputusan dari halaman proses dengan bukti lampiran & nama pemberi keputusan, status versi dokumen dinilai (Approved/Rejected), penarikan Pending saat proses direset/dilewati, halaman Approval (antrean & riwayat, filter, hanya yang berhak dapat memutuskan); pusat Dokumen (pencarian & filter project/part/tipe/status/pengunggah/tanggal, riwayat versi, pratinjau, lepas oleh Admin dengan alasan); catatan Trial/T0/Commissioning & Validation per iterasi, Material (Purchasing memperbarui), hasil validasi mengikuti keputusan; Next Action & Waiting For per part/project; komentar proses dengan notifikasi; tab project Approval, Dokumen, Trial & Material, Activity. 271 test hijau + 3 alur browser. Bug ditemukan & diperbaiki: ikon SVG `display:block` memecah teks (kini inline secara bawaan); `.inline-edit` belum flex; lampiran NPR draft tidak terkait project setelah dikirim. |
| 8 | Berikutnya | | Overdue (hari pertama, harian), due soon, target berisiko, no update, next action jatuh tempo, pengingat Hold; pusat notifikasi; antrean email PHPMailer + retry; 3 skrip cron. |
