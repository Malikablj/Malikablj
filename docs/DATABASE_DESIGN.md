# Desain Database — NPD Project Control v3.0

* DBMS: **MySQL 8.0+**, engine **InnoDB**, charset **utf8mb4**, collation **utf8mb4_unicode_ci**.
* Nama database bawaan: `npd_project_control`.
* File: `database/schema.sql` (struktur, idempoten), `database/seed.sql` (data master & template
  workflow, digenerate dari `database/seeds/*.php` oleh `bin/build-seed.php`),
  `database/hardening.sql` (opsional: trigger append-only audit log + contoh GRANT).
* Semua tabel memiliki primary key, foreign key, index untuk pola query utama, `created_at`
  dan (bila dapat berubah) `updated_at`.
* Tanggal planned/actual bertipe `DATE`; waktu kejadian `DATETIME` dalam WIB (`time_zone = '+07:00'`).

## 1. Diagram relasi (ringkas)

```
roles ─┬─< role_permissions >── permissions
       └─< users ─┬─< login_attempts (via email)
                  ├─< notifications ─< notification_deliveries
                  └─< audit_logs (snapshot nama, tanpa FK)

customers ─< customer_contacts
customers ─< npr ─< npr_parts ─1:1─ npr_feedback
                 │         └─1:1─ project_parts
                 └─1:1─ projects ─< project_parts ─< processes ─< process_dependencies (predecessor → processes)
                              │                        │       ├─< process_runs   (KPI per aktivasi)
                              │                        │       └─< documents / approvals / records
                              ├─< processes (level project: P1, P2, G1, PF; part_id NULL)
                              ├─< project_gates (1 per gate, menunjuk proses G1)
                              ├─< schedule_baselines ─< schedule_baseline_items
                              ├─< schedule_changes
                              ├─< hold_history, next_actions, comments, calendar_events
                              └─< revision_history

workflow_templates ─< workflow_template_versions ─< workflow_steps ─< workflow_step_dependencies
project_parts.workflow_template_version_id → workflow_template_versions   (versi yang dipakai part)
processes.workflow_step_id → workflow_steps                               (atribut step disalin ke proses)

documents ─< document_versions   (documents.current_version_id → versi terakhir)
approvals ─< approval_history    (approvals.document_version_id = revisi yang dinilai)
working_calendar (7 baris), holidays, master_options, application_settings, number_sequences, job_runs
```

## 2. Daftar tabel

| Kelompok | Tabel | Isi |
| --- | --- | --- |
| Akses | `roles` | 8 role tetap (kode: admin, admin_sales, npd_staff, drafter, purchasing, production, quality, management) |
| | `permissions`, `role_permissions` | Kemampuan (mis. `npr.edit_sales_fields`) & pemetaan role → kemampuan dengan `scope` `all`/`own` (matriks PRD §2.3) |
| | `users` | Akun; `password_hash` (bcrypt/argon2), `is_active`, `language`, `theme`, `must_change_password` |
| | `login_attempts` | Riwayat percobaan login untuk rate limiting |
| Master | `application_settings` | Pengaturan Admin (ambang Due Soon, No Update, Hold reminder, SMTP, ekstensi file, timeout sesi, tarik maju jadwal) |
| | `master_options` | Semua daftar pilihan NPR (nama part, jenis permintaan, resin, warna, surface, neck/preform, decoration, metode mould, kemasan, metode test) + 18 tipe dokumen; nonaktif ≠ hapus |
| | `customers`, `customer_contacts` | Customer (kode, nama, alamat invoice & kirim, telepon) dan kontak |
| | `number_sequences` | Penomoran atomik (NPR, project, approval) |
| Kalender | `working_calendar` | Hari kerja per hari ISO (1=Senin … 7=Minggu); bawaan Senin–Jumat kerja |
| | `holidays` | Hari libur (tanggal, nama, berulang tahunan) |
| Workflow | `workflow_templates` | `project`, `new_mold`, `subcont`; menunjuk versi aktif |
| | `workflow_template_versions` | Versi template (draft/published/retired) |
| | `workflow_steps` | Atribut proses §5.8 (PIC role, durasi, mandatory, boleh dilewati + grup, eksternal, approval, keputusan & tujuan loop, dokumen wajib/disarankan, record, kategori kalender, milestone gate) |
| | `workflow_step_dependencies` | Dependency bawaan template (kode predecessor, tipe, lag, hanya bila gate aktif) |
| NPR | `npr` | Seluruh isian biru form + status + Requested/Received by |
| | `npr_parts` | Baris Tabel Komponen (nama part, jenis part, development, supplier mold, komponen Ext, resin, warna, pantone, surface, neck/preform) |
| | `npr_feedback` | Kolom pink per part (berat, metode mould, cavity, harga mould %, lead time, feedback, keputusan, perlu masterbatch baru, status publikasi) |
| Project | `projects` | Kode NPD-YYYY-XXX, NPR, customer, prioritas, Sales/NPD PIC, Target Finish, forecast, status turunan, hold, arsip |
| | `project_parts` | Part runtime: jenis, versi template, status, tanggal mulai, forecast, PIC per peran, hold, cancel |
| | `processes` | Instance proses per part/project: PIC, status, durasi, manual start/finish, planned, forecast, actual, iterasi, loop, skip |
| | `process_dependencies` | Dependency per project (FS/SS/FF/PARALLEL, lag, asal template/override) |
| | `process_runs` | Satu baris per aktivasi proses: Planned Finish saat aktivasi, PIC saat selesai, hari Hold, tanggal mulai overdue → dasar KPI |
| | `project_gates` | Hasil gate Assembly/Fit Test (Pass/Fail, part yang diulang) |
| Jadwal | `schedule_baselines`, `schedule_baseline_items` | Baseline berversi per part/project |
| | `schedule_changes` | Log setiap penggeseran (penyebab, selisih hari kerja, tanggal lama/baru) |
| Dokumen | `documents`, `document_versions` | Dokumen berversi (tidak menimpa) milik NPR atau project › part › proses |
| Approval | `approvals`, `approval_history` | 10 tipe approval, pemberi customer/internal, iterasi, revisi dokumen yang dinilai |
| Record | `trial_records`, `material_requests`, `validation_records` | Catatan Trial/T0/Commissioning, permintaan & persiapan material, validasi |
| Kendali | `next_actions` | Next Action + Waiting For per part |
| | `hold_history` | Masa Hold (mulai, selesai, alasan, siapa, target baru, baseline baru, pengingat) |
| | `revision_history` | Loop, revisi NPR/dokumen, Hold, baseline, skip, koreksi |
| | `comments`, `calendar_events` | Komentar; agenda meeting/follow-up |
| Notifikasi | `notifications` | Notifikasi web (dedupe per user) |
| | `notification_deliveries` | Antrean email (status, percobaan, error terakhir, jadwal retry) |
| Audit | `audit_logs` | Append-only: user, waktu, IP, aksi, entitas, nilai lama/baru (JSON), alasan |
| | `job_runs` | Riwayat eksekusi cron |

## 3. Aturan integritas penting

| Aturan | Implementasi |
| --- | --- |
| Nomor NPR & kode project unik meski dikirim bersamaan | `number_sequences` + upsert `LAST_INSERT_ID()`; UNIQUE `npr.npr_number`, `npr(seq_year, seq_no)`, `projects.code` |
| Satu NPR = satu project | UNIQUE `projects.npr_id` |
| Satu feedback per part | UNIQUE `npr_feedback.npr_part_id` |
| Harga mould 0–100% | CHECK `chk_nprf_pct` (+ validasi jumlah = 100% di server) |
| Dependency tidak ke diri sendiri, tidak ganda | CHECK `chk_pd_not_self`, UNIQUE `(process_id, predecessor_id)`; siklus & cross-part divalidasi di server |
| Dokumen harus punya pemilik | CHECK `chk_doc_owner` (npr_id atau project_id) |
| Versi dokumen tidak menimpa | UNIQUE `(document_id, version_no)` |
| Notifikasi tidak dobel | UNIQUE `notifications(user_id, dedupe_key)`, `notification_deliveries(dedupe_key)` |
| Audit tidak bisa diubah | Tidak ada UPDATE/DELETE di kode; trigger opsional; GRANT INSERT, SELECT saja |
| Data tidak dihapus permanen | Project diarsipkan; master/user dinonaktifkan; FK tanpa CASCADE pada data bisnis |

## 4. Status

**Proses** (`processes.status`): `not_started`, `current`, `completed`, `revision`, `problem`, `skipped`.
`revision` = aktif kembali karena loop approval; `problem` = aktif mengulang karena NG/FAIL.
`activation = 'loop_only'` (mis. Mold Correction) tidak dijadwalkan sampai dipicu loop; dependency yang masuk ke
proses loop_only hanya mencatat pemicu (bukan syarat jadwal). `processes.loop_after_process_id` = proses yang dibuka
kembali menunggu proses loop selesai (Mold Machining menunggu Mold Correction) — diperlakukan sebagai FS oleh Scheduler.
Riwayat per aktivasi disimpan di `process_runs` (Planned Finish saat aktivasi, PIC saat selesai, status
`open/completed/reset/skipped`) sebagai dasar KPI per iterasi (OQ-15). Hasil gate disimpan per iterasi
(`project_gates`, unik `process_id + iteration`).

**Part** (`project_parts.status`, turunan): `not_started`, `on_progress`, `waiting_approval`,
`waiting_external`, `hold`, `completed`, `cancelled`. Aturan (PRD §3.3): proses aktif approval
customer → Waiting Approval; proses aktif eksternal → Waiting External; lainnya On Progress; bila
paralel, ambil yang paling "menunggu" (Waiting Approval > Waiting External > On Progress).

**Project** (`projects.status`, turunan): `not_started`, `on_progress`, `waiting`, `hold`,
`ready_to_finish`, `completed`, `cancelled`. Overdue/Berisiko **tidak disimpan** sebagai status;
dihitung dari proses (hari kerja) dan Target Finish.

**NPR**: `draft`, `submitted` (Dikirim), `returned` (Dikembalikan), `feedback_completed`
(Selesai Feedback), `discarded` (draft dibatalkan pembuatnya — tidak pernah dikirim).

## 5. Penomoran

| Nomor | Format | Kunci sequence | Kapan |
| --- | --- | --- | --- |
| NPR | `001/PIK/NPR/X/2026` (urut 3 digit, bulan Romawi, tahun) | `NPR-<tahun>` | pertama kali NPR dikirim |
| Project | `NPD-2026-001` | `NPD-<tahun>` | bersamaan dengan nomor NPR |
| Approval | `APR-2026-0001` | `APR-<tahun>` | approval dibuat |

## 6. Mesin penjadwalan

### 6.1 Kalender kerja

* Hari kerja: `working_calendar.is_working = 1` (bawaan Senin–Jumat) dan bukan `holidays`
  (libur berulang dicocokkan bulan-tanggal setiap tahun).
* `addWorkingDays(d, n)`: maju (n>0) / mundur (n<0) tepat n hari kerja dari d (d tidak dihitung);
  n = 0 mengembalikan d.
* `countWorkingDays(a, b)`: jumlah hari kerja dalam [a, b] inklusif (0 bila b < a).
* Planned Start/Finish selalu hari kerja; tanggal aktual boleh hari apa pun.

### 6.2 Perhitungan per proses (urutan topologis)

Untuk proses yang **belum dimulai**:

1. `allowed` = tanggal mulai part (proses level project: tanggal mulai project).
2. Untuk setiap predecessor:
   * **FS**: `addWorkingDays(predFinish, 1 + lag)`; tidak boleh < `predStart`.
   * **SS**: `addWorkingDays(predStart, lag)`; tidak boleh < `predStart`.
   * **FF**: batas selesai `addWorkingDays(predFinish, lag)` (tidak membatasi mulai).
   * **PARALLEL**: tidak membatasi (proses aktif sejak part dimulai).
   * `allowed = max(allowed, kandidat)`; predecessor yang menentukan dicatat sebagai "penghalang".
3. Tanggal manual (tabel PRD §6.1):
   * hanya `manual_start`: `start = max(allowed, manual_start)`; peringatan bila `manual_start < allowed`.
   * hanya `manual_finish`: `start = max(allowed, manual_finish − (durasi − 1))`.
   * keduanya: `durasi = countWorkingDays(manual_start, manual_finish)`; `start = max(allowed, manual_start)`.
4. `finish = addWorkingDays(start, durasi − 1)`; bila ada FF dan `finish < batasFF` → `finish = batasFF`
   (durasi efektif diperpanjang).
5. Setting "Tarik maju jadwal bila selesai lebih awal" = mati: pada perhitungan karena kejadian aktual,
   `start` tidak lebih awal dari planned start sebelumnya.

Proses lain:

* **Completed**: memakai `actual_start`/`actual_finish`.
* **Current/Revision/Problem**: Planned Start & Finish **tidak diubah**;
  `forecast_finish = max(planned_finish, hari kerja ≥ hari ini)`.
* **Skipped** / loop_only belum aktif: durasi nol, meneruskan tanggal predecessor
  (`start = allowed`, `finish = addWorkingDays(allowed, −1)`), tidak tampil sebagai batang.

Dua lintasan dijalankan: **planned** (predecessor berjalan memakai planned finish → jadwal tidak
bergeser selama proses masih berjalan, PRD §6.4) dan **forecast** (predecessor berjalan memakai
forecast finish → perkiraan selesai part/project "langsung", FR-SCH-05).

Perkiraan selesai part = forecast finish terakhir prosesnya; project = terakhir di antara part
aktif dan proses level project. Bila > Target Finish → label **Berisiko / Perkiraan melewati target**.

### 6.3 Contoh PRD §6.4 (dijadikan unit test)

NPD Feedback selesai Rabu 30-09-2026. Develop MB (3 hk) & 3D Prototype (3 hk) FS NPD Feedback →
keduanya 01–05 Okt. 2D Drawing (3 hk) FS keduanya → 06–08 Okt. Develop MB selesai aktual 07 Okt →
2D Drawing **08–12 Okt** (+2 hk), 3D Prototype tidak berubah.

## 7. Overdue & KPI

* **Proses overdue**: status aktif, bukan Hold, dan `countWorkingDays(planned_finish + 1, hari ini) ≥ 1`
  (keterlambatan dihitung dalam hari kerja; akhir pekan setelah Planned Finish Jumat belum terlambat).
* `process_runs.overdue_since` diisi cron pada hari pertama overdue (dasar "jumlah overdue" KPI).
* **On-time rate** = run selesai dengan `actual_finish ≤ planned_finish_at_activation` / run selesai
  dalam periode (Skipped dikecualikan).
* **Durasi aktual** = `countWorkingDays(actual_start, actual_finish) − hold_working_days`.
* PIC yang dinilai = `process_runs.pic_user_id` (PIC saat proses selesai).

## 8. Migrasi

Perubahan skema setelah go-live ditulis sebagai file berurutan `database/migrations/NNNN_nama.sql`
yang idempoten (cek `information_schema` sebelum `ALTER`). `bin/migrate.php` mencatat migrasi yang
sudah dijalankan pada tabel `schema_migrations` (dibuat otomatis oleh skrip).
