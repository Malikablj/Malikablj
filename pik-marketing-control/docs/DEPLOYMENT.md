# DEPLOYMENT — PIK Marketing Control

Phase 02 hanya memasang **database**. Web app belum untuk dipakai pengguna (modul menyusul di Phase 04–05).

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

Jangan menyimpan rahasia di kode atau di spreadsheet.

## 3. Buat dan uji database

Jalankan dari editor Apps Script (pilih fungsi → **Run**). Pada run pertama, Google meminta izin akses Spreadsheet, Drive, dan email
pengguna.

1. `setupDatabase`: membuat spreadsheet database (atau memakai `DATABASE_SPREADSHEET_ID`), 21 sheet, seed ENUMS/SETTINGS, README,
   format, dropdown, proteksi, dan satu entri `DB_INIT` di AUDIT_LOG. Aman dijalankan ulang.
2. `runDatabaseSelfTest`: menjalankan 30 kasus uji pada spreadsheet sementara (dibuang ke trash setelahnya). Hasil yang diharapkan
   di log: `Self-test: 30/30 lulus`.
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
