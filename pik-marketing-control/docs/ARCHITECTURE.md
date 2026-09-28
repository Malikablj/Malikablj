# ARCHITECTURE — PIK Marketing Control

```
Workbook legacy (.xlsx, read-only)
   │  Node: migration/scripts/migrate.js — PROFILE → MAP → VALIDATE paket
   ▼
migration-package.json (SHA-256) ──Drive──► services/MigrationService.gs ──► migration/MigrationRunner.gs
                                           DRY RUN → MIGRATE → VERIFY (Apps Script; digladikan di emulator)
                                                                 │
Browser (HtmlService, Vanilla JS) ──google.script.run──► entry point (Code.gs, services/)
                                                                 │  handleRequest_ → { success, data | error }
                                                                 ▼
                                   db/Repository ── db/Validation ── db/Audit      (satu lock per penulisan)
                                                                 ▼
                                   Spreadsheet database (satu sheet per tabel) + ENUMS, SETTINGS, AUDIT_LOG
```

## Lapisan dan berkas

| Lapisan | Berkas | Tanggung jawab |
|---|---|---|
| Entry point | `src/Code.gs`, `src/services/*.gs` | Fungsi publik (tanpa `_`) yang dipanggil klien atau editor. Membungkus hasil dengan `handleRequest_`. |
| Konfigurasi | `src/Config.gs` | Script Properties: `DATABASE_SPREADSHEET_ID`, `DRIVE_ROOT_FOLDER_ID`, `APP_NAME`, `TIMEZONE`, `ADMIN_EMAILS`. |
| Inti | `src/core/Errors.gs` | `AppError` (kode + pesan aman) dan amplop respons; error internal disamarkan. |
| | `src/core/Ids.gs` | ID `PREFIX-XXXXXXXXXX`, cek bentrok terhadap ID yang ada. |
| | `src/core/Lock.gs` | Script lock re-entrant untuk setiap penulisan; `flush` sebelum lock dilepas. |
| | `src/core/Access.gs` | Siapa yang boleh menjalankan fungsi pemeliharaan; email pelaku. |
| | `src/core/Text.gs`, `Time.gs` | Normalisasi teks/kunci dokumen, tanggal & waktu. |
| Database | `src/db/Schema.gs` | Definisi 21 sheet: kolom, tipe, relasi, keunikan, aturan, konsistensi. Sumber `docs/DATABASE_SCHEMA.md`. |
| | `src/db/Enums.gs`, `Settings.gs` | Seed dan pembacaan ENUMS/SETTINGS. |
| | `src/db/SheetFormat.gs` | Format angka/teks, dropdown & checkbox, catatan header, proteksi, kapasitas baris. |
| | `src/db/Database.gs` | `setupDatabase`, `initializeDatabase` (idempoten, dua tahap), `verifyDatabase` (read-only). |
| | `src/db/Validation.gs` | Validasi record (tipe, wajib, enum, relasi, konsistensi, aturan, keunikan). |
| | `src/db/Repository.gs` | Baca per tabel (cache per eksekusi), insert batch, update (satu record dengan optimistic concurrency, atau batch `dbUpdateMany_`), arsip/pulihkan. |
| | `src/db/Audit.gs` | Penulisan `AUDIT_LOG` (append-only) dalam lock yang sama. |
| Migrasi (Apps Script) | `src/services/MigrationService.gs` | Fungsi editor `profileSourceWorkbook`, `validateMigrationMapping`, `dryRunMigration`, `runMigration`, `verifyMigration`: membaca paket dari Drive (`MIGRATION_PACKAGE_FILE_ID`), menyimpan penanda dry run, laporan JSON ke Drive, ringkasan di log. |
| | `src/migration/MigrationRunner.gs` | Integritas paket, rencana insert/update/lewati/konflik, validasi penuh tanpa menulis (dry run), pemuatan batch yang dapat dilanjutkan, konflik, verifikasi, dan rekonsiliasi. |
| Migrasi (Node) | `migration/scripts/lib/analyze.js`, `lib/checks/`, `lib/xlsx-reader.js` | PROFILE: pembaca `.xlsx` read-only (SHA-256 sebelum/sesudah) dan pemeriksaan domain Phase 01. |
| | `migration/scripts/lib/migration/` | MAP: pemetaan per sheet (`mapping.js`, `fields.js`), paket + accounting + ekspektasi (`build-package.js`), gladi di emulator (`rehearsal.js`), laporan (`report.js`). |
| | `migration/scripts/migrate.js` | CLI `package` / `dry-run` / `migrate`. |
| Web | `src/web/*.html` | Shell HTML; teks server selalu lewat `textContent`. |
| Uji | `src/tests/*.gs` | Kasus uji database yang dijalankan di Node (emulator) dan di Apps Script (`runDatabaseSelfTest`). |
| | `tools/gas-emulator/` | Emulator Apps Script untuk test, gladi migrasi, dan `npm run emulate:init`. |

## Aturan

- Frontend tidak pernah menulis ke Sheets secara langsung; semua lewat service → repository.
- Setiap penulisan: lock → baca ulang data segar → validasi seluruh batch → satu `setValues` data + satu `setValues` audit → flush.
  Batch bersifat semua-atau-tidak-sama-sekali.
- Pembacaan: satu `getValues` per tabel per eksekusi (cache dibuang setelah penulisan).
- Header dicek sebelum tabel dipakai. Bila berbeda dari skema, operasi berhenti dengan `SCHEMA_MISMATCH` alih-alih menulis ke kolom
  yang salah.
- Konteks penulisan (`migration`, `internal`) hanya diberikan oleh kode server, tidak pernah dari input klien.
- Semua berkas `.gs` hanya berisi deklarasi di tingkat atas (tanpa pemanggilan lintas berkas saat dimuat), sehingga urutan berkas di
  Apps Script tidak berpengaruh. Hal ini diuji otomatis.

## Aturan migrasi

- Paket migrasi adalah satu-satunya masukan pemuatan dan diikat oleh SHA-256: dry run, migrasi, dan verifikasi selalu memakai paket
  yang sama. `runMigration()` menolak paket yang belum lolos dry run dan mengulang dry run di bawah lock sebelum menulis.
- Seluruh pemuatan berjalan di dalam satu script lock (penulisan lain menunggu), per tabel sesuai urutan relasi, dalam batch 500 record.
  Setiap batch insert = satu `setValues` + satu entri audit `MIGRATION_RUN`; update = satu `setValues` per blok baris + audit
  `UPDATE` per record. Bila batas waktu (±4,5 menit) tercapai, run berhenti di antara batch dan run berikutnya melanjutkan.
- Rerun idempoten lewat `migration_hash` (SHA-256 baris sumber + record hasil pemetaan): sama → dilewati; berubah dan record masih
  milik migrasi → diperbarui; record yang sudah diedit pengguna tidak pernah ditimpa (isu `MIGRATION_CONFLICT`). Isu migrasi yang sudah
  ditinjau Admin dipertahankan.
- Verifikasi hanya membaca. Record yang sudah diedit pengguna setelah migrasi diperiksa terhadap nilai yang ditulis migrasi dan
  dihitung terpisah; riwayatnya ada di AUDIT_LOG.
