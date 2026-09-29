# ARCHITECTURE — PIK Marketing Control

```
Browser: SPA HtmlService (src/web, Vanilla JS)
   │  google.script.run.api(action, payload)        ← satu-satunya fungsi server yang dipanggil UI (docs/API.md)
   ▼
api/Api.gs   reset cache → route [modul, R|RW] → requireUser_ (USERS) → requirePermission_ (MODULE_PERMISSIONS) → handler
   │                                                                   → { success, data } | { success: false, error }
   ▼
services/*.gs (per modul) ── ServiceKit (daftar: filter → cari → urut → halaman; whitelist input)
   │                 │
   │                 └──► domain/Calculations.gs   nilai turunan (outstanding, fulfillment, due state, status bayar)
   ▼
db/UnitOfWork ── db/Repository ── db/Validation ── db/Audit        (satu script lock per penulisan)
   ▼
Spreadsheet database (satu sheet per tabel) + ENUMS, SETTINGS, AUDIT_LOG, MIGRATION_ISSUES

Migrasi:  workbook legacy (.xlsx, read-only) → Node PROFILE → MAP → VALIDATE → migration-package.json (SHA-256)
          → Drive → services/MigrationService.gs → migration/MigrationRunner.gs: DRY RUN → MIGRATE → VERIFY
Lokal:    tools/dev-server (kode .gs asli di emulator + shim google.script.run) ← Playwright E2E (e2e/)
```

## Lapisan dan berkas

| Lapisan | Berkas | Tanggung jawab |
|---|---|---|
| Entry point | `src/Code.gs` | `doGet` (web app), `getAppHealth`, `include_`. |
| API | `src/api/Api.gs` | `api(action, payload)`: tabel route tertutup (aksi tak dikenal = `NOT_FOUND`), identitas, izin per route, amplop respons. Kontrak lengkap: `docs/API.md`. |
| Auth | `src/auth/Auth.gs` | Akun Google → `USERS` → role. `MODULE_PERMISSIONS` (matriks akses + default D5), `can_`, `requirePermission_`. Admin pertama = pemilik skrip selama belum ada Admin aktif. |
| Service | `src/services/ServiceKit.gs` | Mesin daftar di server (filter → cari → urut → halaman; filter/urutan tak dikenal ditolak), whitelist kolom input, nama tampilan, opsi picker, cek `expectedUpdatedAt`. |
| | `SessionService.gs` | Sesi (user, izin, enum, setting, metadata form dari skema), pilihan user, pencarian global. |
| | `CustomerService.gs` | Customer (workspace detail) dan contact (satu contact utama). |
| | `CrmService.gs` | Lead dan pipeline, aktivitas, follow-up (bucket hari ini/terlambat/mendatang, selesai + berikutnya). |
| | `OrderService.gs` | PO + item, delivery, retur, inbound; konfirmasi kelebihan qty. |
| | `ProductService.gs` | Produk, stok (stok terbaru per produk/jenis), lead time. |
| | `FinanceService.gs` | Invoice dan pembayaran (status bayar dihitung dari nilai). |
| | `DashboardService.gs`, `ReportService.gs` | KPI dan daftar kerja; laporan (ringkasan, grup, baris) dan ekspor CSV. |
| | `AuditService.gs`, `AdminService.gs` | Audit log dan riwayat per record; user, setting, nilai enum, isu migrasi. |
| Domain | `src/domain/Calculations.gs` | Nilai turunan yang dihitung setiap dibaca dan tidak pernah disimpan: terkirim/retur/outstanding/terjadwal per item dan PO, fulfillment, due state follow-up (D14), status bayar invoice. |
| Konfigurasi | `src/Config.gs` | Script Properties: `DATABASE_SPREADSHEET_ID`, `DRIVE_ROOT_FOLDER_ID`, `APP_NAME`, `TIMEZONE`, `ADMIN_EMAILS`. |
| Inti | `src/core/Errors.gs` | `AppError` (kode + pesan aman) dan amplop respons; error internal disamarkan. |
| | `src/core/Ids.gs` | ID `PREFIX-XXXXXXXXXX`, cek bentrok terhadap ID yang ada. |
| | `src/core/Lock.gs` | Script lock re-entrant untuk setiap penulisan; cache dibaca ulang saat lock didapat; `flush` sebelum lock dilepas. |
| | `src/core/Access.gs` | Siapa yang boleh menjalankan fungsi pemeliharaan; email pelaku. |
| | `src/core/Text.gs`, `Time.gs` | Normalisasi teks/kunci dokumen, tanggal & waktu, tanggal kalender zona aplikasi. |
| Database | `src/db/Schema.gs` | Definisi 21 sheet: kolom, tipe, relasi, keunikan, aturan, konsistensi. Sumber `docs/DATABASE_SCHEMA.md`. |
| | `src/db/Enums.gs`, `Settings.gs` | Seed dan pembacaan ENUMS/SETTINGS. |
| | `src/db/SheetFormat.gs` | Format angka/teks, dropdown & checkbox, catatan header, proteksi, kapasitas baris. |
| | `src/db/Database.gs` | `setupDatabase`, `initializeDatabase` (idempoten, dua tahap), `verifyDatabase` (read-only). |
| | `src/db/Validation.gs` | Validasi record (tipe, wajib, enum, relasi, konsistensi, aturan, keunikan). |
| | `src/db/Repository.gs` | Baca per tabel (cache per eksekusi), insert batch, validasi tanpa menulis (`dbValidateInsert_`), update (satu record dengan optimistic concurrency, atau batch `dbUpdateMany_`), arsip/pulihkan. |
| | `src/db/UnitOfWork.gs` | Insert multi-tabel sebagai satu unit (PO + item, aktivitas + follow-up berikutnya): semua divalidasi dulu, lalu satu `setValues` per tabel. |
| | `src/db/Audit.gs` | Penulisan `AUDIT_LOG` (append-only) dalam lock yang sama. |
| Migrasi (Apps Script) | `src/services/MigrationService.gs` | Fungsi editor `profileSourceWorkbook`, `validateMigrationMapping`, `dryRunMigration`, `runMigration`, `verifyMigration`: membaca paket dari Drive (`MIGRATION_PACKAGE_FILE_ID`), menyimpan penanda dry run, laporan JSON ke Drive, ringkasan di log. |
| | `src/migration/MigrationRunner.gs` | Integritas paket, rencana insert/update/lewati/konflik, validasi penuh tanpa menulis (dry run), pemuatan batch yang dapat dilanjutkan, konflik, verifikasi, dan rekonsiliasi. |
| Migrasi (Node) | `migration/scripts/lib/analyze.js`, `lib/checks/`, `lib/xlsx-reader.js` | PROFILE: pembaca `.xlsx` read-only (SHA-256 sebelum/sesudah) dan pemeriksaan domain Phase 01. |
| | `migration/scripts/lib/migration/` | MAP: pemetaan per sheet (`mapping.js`, `fields.js`), paket + accounting + ekspektasi (`build-package.js`), gladi di emulator (`rehearsal.js`), laporan (`report.js`). |
| | `migration/scripts/migrate.js` | CLI `package` / `dry-run` / `migrate`. |
| Web | `src/web/Index.html` | Kerangka halaman: memuat berkas di bawah lewat `include_`. |
| | `Styles.html` | Design system: token warna/spasi/radius/bayangan, komponen, breakpoint desktop/tablet/ponsel. |
| | `JsCore.html` | `PIK.h` (DOM tanpa `innerHTML`), ikon SVG, format angka/tanggal Indonesia, `PIK.api` (Promise), router, toast, dialog konfirmasi, drawer, popover. |
| | `JsComponents.html` | `PIK.ui`: badge, tabel yang menjadi kartu di ponsel, halaman daftar (cari/filter/urut/halaman di URL), form + validasi, picker, arsip dengan urungkan, riwayat, grafik batang, state loading/kosong/error. |
| | `JsForms.html` | Form per modul (customer … invoice, user), termasuk konfirmasi kelebihan qty dan error server per kolom. |
| | `JsViewsCrm.html`, `JsViewsOps.html`, `JsViewsAdmin.html` | Layar: dashboard, customer + detail, contact, lead + pipeline, aktivitas, follow-up; PO + detail, delivery, retur, produk, stok, lead time; invoice, laporan, pengaturan. |
| | `JsApp.html` | Login, shell (sidebar, top bar, navigasi bawah ponsel), izin per layar, pencarian global (Ctrl/⌘ K), notifikasi follow-up, daftar route. |
| Uji | `src/tests/*.gs` | Kasus uji database yang dijalankan di Node (emulator) dan di Apps Script (`runDatabaseSelfTest`). |
| | `tests/*.test.js`, `tests/support/api-harness.js` | Test Node; `api-*.test.js` memanggil `api()` sebagai user dengan role berbeda. |
| | `e2e/` | Test browser (Playwright) terhadap dev server. |
| Alat | `tools/gas-emulator/` | Emulator Apps Script untuk test, gladi migrasi, dev server, dan `npm run emulate:init`. |
| | `tools/dev-server/` | `npm run dev`: menjalankan kode `.gs` dan HTML asli secara lokal; `google.script.run` diteruskan ke `api()` lewat HTTP. |

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

## Aturan API dan service (Phase 04)

- UI hanya memanggil `api(action, payload)`. Fungsi publik lain (`doGet`, `getAppHealth`, fungsi pemeliharaan & migrasi) dikunci oleh
  daftar tertutup yang diuji otomatis; fungsi pemeliharaan hanya untuk pemilik skrip atau `ADMIN_EMAILS`.
- Setiap route punya izin `[modul, R|RW]` yang dicek di server sebelum handler berjalan. Role selalu dibaca dari `USERS`, tidak pernah
  dari payload. UI menyembunyikan tombol sesuai izin, tetapi penegaknya tetap server.
- Data keuangan di layar bersama (detail customer/PO, dashboard) hanya dikirim ke role yang boleh membaca modul `finance`.
- Input form di-whitelist per modul; kolom sistem, migrasi, dan kolom turunan tidak dapat diisi klien.
- Penulisan multi-record memvalidasi semuanya sebelum penulisan pertama (`dbValidateInsert_`, `dbUpdateMany_`, `dbInsertUnit_`):
  PO + item, aktivitas + follow-up berikutnya, pertukaran contact utama, follow-up selesai + berikutnya. Gagal = tidak ada yang tertulis.
- Nilai turunan (terkirim, retur, outstanding, fulfillment, status follow-up, status bayar) dihitung dari transaksi setiap dibaca dan
  tidak pernah disimpan atau diterima dari klien.
- Qty yang melebihi batas item PO tidak diterima diam-diam: server menjawab `OVER_QUANTITY` (`confirmable`) dan pengguna harus
  mengonfirmasi secara eksplisit.
- Daftar dikerjakan di server (satu `getValues` per tabel per eksekusi); klien hanya menerima satu halaman.

## Aturan frontend (Phase 05)

- Satu halaman (SPA) tanpa library. DOM dibuat dengan `PIK.h()`; teks selalu lewat `textContent`. `innerHTML`,
  `insertAdjacentHTML`, dan `document.write` tidak dipakai (diuji otomatis).
- Semua data berasal dari `PIK.api`; tidak ada data contoh di kode UI. Setiap layar punya state loading (skeleton + bar muat),
  kosong (dengan langkah berikutnya), error (dengan Coba lagi), dan umpan balik sukses (toast).
- Route di hash URL (`#customers?status=ACTIVE&page=2`) lewat `google.script.history`: filter, urutan, halaman, dan tab bertahan saat
  muat ulang dan dapat dibagikan.
- Form menampilkan error per kolom dari validasi browser dan dari server (`details.errors`); isian tidak hilang saat simpan gagal.
  Perubahan berisiko (arsip, tutup PO yang masih outstanding, kelebihan qty) selalu lewat dialog konfirmasi.
- Overlay (drawer, dialog, menu, palet pencarian) ditutup lewat fungsi close-nya, termasuk saat pindah halaman, sehingga listener
  keyboard/klik ikut dilepas. Fokus awal overlay tidak merebut kolom yang sudah dipilih pengguna.
- Responsif: sidebar penuh (≥ 1024 px), rel ikon (768–1023 px), ponsel (≤ 767 px) dengan navigasi bawah, tabel menjadi kartu, filter
  di balik tombol, dan form sebagai bottom sheet.

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
