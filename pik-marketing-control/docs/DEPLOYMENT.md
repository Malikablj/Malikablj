# DEPLOYMENT — PIK Marketing Control

Phase 02 memasang **database**, Phase 03 menyiapkan **migrasi data** (§7). Web app belum untuk dipakai pengguna (modul menyusul
di Phase 04–05).

## Prasyarat

- Node.js 20 atau lebih baru.
- Akun Google Workspace PIK yang boleh membuat Apps Script project dan spreadsheet.
- Google Apps Script API aktif untuk akun tersebut: <https://script.google.com/home/usersettings>.

## 1. Hubungkan kode ke project Apps Script

```bash
npm install                     # clasp 3.4.1, typescript, typings Apps Script
npx clasp login
```

1. Buat project baru di <https://script.google.com> (standalone), lalu salin **Script ID** dari *Project Settings*.
2. Salin `.clasp.json.example` menjadi `.clasp.json` dan isi `scriptId`. `rootDir` tetap `src`.
   `.clasp.json` dan `.clasprc.json` tidak boleh di-commit (sudah di `.gitignore`).
3. Kirim kode:

   ```bash
   npm test && npm run typecheck   # pastikan hijau sebelum push
   npm run push                    # clasp push; setujui penimpaan manifest bila ditanya
   npm run open                    # membuka editor Apps Script
   ```

## 2. Script Properties (editor → Project Settings → Script Properties)

| Properti | Wajib | Isi |
|---|---|---|
| `DATABASE_SPREADSHEET_ID` | tidak | Kosongkan agar `setupDatabase()` membuat spreadsheet baru dan mengisinya otomatis. Bila diisi, gunakan spreadsheet kosong khusus database. |
| `DRIVE_ROOT_FOLDER_ID` | tidak | Folder Drive tujuan file database (dan lampiran nanti). |
| `TIMEZONE` | tidak | Default `Asia/Jakarta`. |
| `APP_NAME` | tidak | Default `PIK Marketing Control`. |
| `ADMIN_EMAILS` | tidak | Email lain (dipisah koma) yang boleh menjalankan fungsi pemeliharaan selain pemilik skrip. |
| `MIGRATION_PACKAGE_FILE_ID` | untuk migrasi | ID file `migration-package.json` di Drive (§7). |
| `MIGRATION_DRY_RUN` | — | Diisi otomatis oleh `dryRunMigration()` (hash paket + hasil). Jangan diubah manual. |

Jangan menyimpan rahasia di kode atau di spreadsheet.

## 3. Buat dan uji database

Jalankan dari editor Apps Script (pilih fungsi → **Run**). Pada run pertama, Google meminta izin akses Spreadsheet, Drive, dan email
pengguna.

1. `setupDatabase`: membuat spreadsheet database (atau memakai `DATABASE_SPREADSHEET_ID`), 21 sheet, seed ENUMS/SETTINGS, README,
   format, dropdown, proteksi, dan satu entri `DB_INIT` di AUDIT_LOG. Aman dijalankan ulang.
2. `runDatabaseSelfTest`: menjalankan 35 kasus uji pada spreadsheet sementara (dibuang ke trash setelahnya). Hasil yang diharapkan
   di log: `Self-test: 35/35 lulus`.
3. `verifyDatabase`: pemeriksaan read-only. Hasil yang diharapkan: `ok: true`.

Kirim ringkasan log langkah 1–3 ke tim pengembang. Hasil ini menjadi verifikasi pertama di Google Sheets sungguhan (lihat
`docs/TESTING.md`).

## 4. Akses spreadsheet database

- Bagikan spreadsheet database hanya kepada Admin (Editor). Pengguna aplikasi tidak perlu akses langsung, karena web app nanti
  berjalan sebagai deployer.
- Jangan mengubah header, urutan kolom, atau nama sheet secara manual. Perubahan struktur hanya lewat kode + `initializeDatabase()`.
- Header, README, dan AUDIT_LOG diberi proteksi "hanya peringatan". Proteksi yang lebih ketat boleh ditambahkan Admin.
- Setelah edit manual darurat, jalankan `verifyDatabase`.

## 5. Perubahan skema berikutnya

1. Tambahkan kolom **di akhir** tabel di `src/db/Schema.gs` (jangan menyisipkan di tengah), naikkan `SCHEMA_VERSION`.
2. `npm run docs:schema && npm test && npm run typecheck`.
3. `npm run push`, lalu jalankan `initializeDatabase`. Kolom baru ditambahkan di akhir, data yang ada tidak disentuh.
   Bila struktur tidak cocok, initializer berhenti tanpa perubahan dan menjelaskan konfliknya.

## 6. Web app (setelah Phase 04)

Deploy → New deployment → Web app: *Execute as* = saya (deployer), *Who has access* = domain PIK (`appsscript.json`:
`USER_DEPLOYING`, `DOMAIN`). Otorisasi per role ditegakkan di server pada Phase 04.

## 7. Migrasi data legacy (Phase 03)

Migrasi memuat workbook `PIK_Master_Database_AppSheet.xlsx` ke database. Workbook tidak pernah dibuka oleh Apps Script. Yang dimuat
adalah **paket migrasi** yang dibuat dari workbook di komputer lokal, dan seluruh langkahnya diikat oleh hash SHA-256 paket.

**Sebelum mulai:** konfirmasi keputusan D3 dan D4 (`docs/DECISIONS.md`). Paket memakai default yang direkomendasikan. Bila memilih
alternatif, pemetaan diubah dan paket dibuat ulang lebih dulu.

### 7.1 Buat dan tinjau paket (lokal)

```bash
cp /path/ke/PIK_Master_Database_AppSheet.xlsx migration/source/   # folder ini di-ignore git
npm test
npm run migrate -- migrate      # PROFILE → MAP → VALIDATE → DRY RUN → MIGRATE → VERIFY (gladi di emulator)
```

Tinjau `docs/MIGRATION_REPORT.md`. Semua langkah harus berstatus OK/LULUS dan bagian **DECISION REQUIRED** harus sudah dijawab.
Berkas yang diunggah adalah `migration/reports/migration-package.json`. Berkas ini berisi seluruh data bisnis, jadi jangan dibagikan.

### 7.2 Jalankan di Apps Script

1. `npm run push`. Di editor jalankan `setupDatabase` (bila belum), lalu `verifyDatabase` → `ok: true`.
2. Unggah `migration-package.json` ke folder Drive yang hanya dapat diakses Admin. Isi Script Property `MIGRATION_PACKAGE_FILE_ID`
   dengan ID file tersebut (bagian URL setelah `/d/`).
3. Jalankan berurutan dari editor (pilih fungsi → **Run**) dan baca log tiap langkah:

   | Fungsi | Langkah | Hasil yang diharapkan di log |
   |---|---|---|
   | `profileSourceWorkbook` | PROFILE | Nama file, SHA-256, jumlah baris per sheet, isu per tingkat |
   | `validateMigrationMapping` | MAP + VALIDATE | `Paket VALID (0 error)` |
   | `dryRunMigration` | DRY RUN | `DRY RUN OK — runMigration() boleh dijalankan`, `Error validasi: 0`. Tidak menulis ke database. |
   | `runMigration` | MIGRATE + VERIFY | `MIGRASI SELESAI` dan `VERIFIKASI LULUS`. Bila log menyebut `MIGRASI BERHENTI … (batas waktu)`, jalankan `runMigration` lagi; proses melanjutkan dari batch terakhir. |
   | `verifyMigration` | VERIFY | `VERIFIKASI LULUS`; dapat dijalankan kapan saja (read-only) |

4. Setiap langkah menyimpan laporan JSON lengkap (`pik-migration-*.json`) di `DRIVE_ROOT_FOLDER_ID` atau My Drive. Bandingkan jumlah
   per tabel, total, dan hasil pemeriksaan dengan `docs/MIGRATION_REPORT.md`. Angkanya harus sama.

`runMigration` menolak berjalan bila belum ada dry run yang lolos untuk paket yang sama (`FORBIDDEN`), dan mengulang dry run sebelum
menulis. Selama migrasi berjalan, penulisan lain menunggu script lock.

### 7.3 Setelah migrasi

- Semua record legacy bertanda `is_legacy = TRUE`, dengan asal di `import_ref`, `source_file`, `source_sheet`, dan `legacy_row`.
- `MIGRATION_ISSUES` berisi semua isu, masing-masing dengan catatan penanganan migrasi. Admin menyelesaikannya, misalnya dengan
  menautkan delivery legacy ke PO. Suntingan Admin tidak dianggap kerusakan oleh `verifyMigration` dan tidak ditimpa migrasi ulang.
- **Migrasi ulang** (sumber atau pemetaan berubah): buat paket baru (§7.1), unggah, ganti `MIGRATION_PACKAGE_FILE_ID`, lalu
  `dryRunMigration` → `runMigration`. Record yang tidak berubah dilewati; record milik migrasi diperbarui; record yang sudah diedit
  pengguna tidak ditimpa dan dicatat sebagai isu `MIGRATION_CONFLICT`.
- Simpan paket dan laporan di folder terbatas sebagai jejak audit.
