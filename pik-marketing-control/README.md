# PIK Marketing Control

Aplikasi internal Marketing PT Permata Indo Kemas: customer → contact → lead → activity → follow-up → PO → delivery → outstanding.
Stack: **Google Apps Script + Google Sheets + HTML/CSS/Vanilla JS** (keputusan D1).

> ⚠️ **Data rahasia.** Workbook sumber, laporan profiling/migrasi, dan dokumen Phase 01 memuat data bisnis PIK.
> Semuanya di-ignore git (`.gitignore`) dan **tidak dipublikasikan di repositori ini karena repositori ini publik**.
> Dokumen tersebut hanya akan di-commit setelah proyek berada di repositori privat (keputusan D2).
> Test hanya memakai data sintetis.

## Status

| Fase | Status |
|---|---|
| 01 — Inspect & Profile | ✅ profil data, pemetaan migrasi, daftar isu, rencana implementasi |
| 02 — Database | ✅ skema 21 sheet, initializer idempoten, ENUMS, SETTINGS, AUDIT_LOG, validasi, repository |
| 03 — Migration | ✅ pipeline PROFILE → MAP → VALIDATE → DRY RUN → MIGRATE → VERIFY → REPORT; gladi penuh dengan workbook asli di emulator Apps Script lolos semua pemeriksaan |
| 04 — Backend | ✅ satu API `api(action, payload)` untuk 19 modul: CRUD, validasi, otorisasi per role di server, LockService, batch read/write, audit log, perhitungan turunan (outstanding, follow-up, status bayar), dashboard, laporan + CSV |
| 05 — Frontend | ✅ 18 layar (Login … Pengaturan), design system minimalis, sidebar desktop / rel ikon tablet / navigasi bawah ponsel; state loading, kosong, error, sukses, konfirmasi, validasi form |
| 06 — Integration test | ✅ test browser end-to-end (alur Login → … → Dashboard, role, daftar, state UI, responsif) terhadap backend asli di emulator |
| Login untuk akun Gmail (D15) | ✅ login email + password (hash PBKDF2, sesi bertanda tangan, password sementara wajib diganti, batas percobaan) di samping login akun Google Workspace |

**Belum dijalankan di Google sungguhan.** Semua kode diuji di emulator Apps Script (kredensial Google tidak tersedia di environment
pengembangan). Deployment web app, migrasi produksi, dan daftar periksa pertama di Apps Script dijalankan pemilik: lihat
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) §6–§7 (§6.1 untuk Google Workspace, §6.2 untuk akun Gmail biasa). **Keputusan yang masih dibutuhkan:** D3 dan D4 (sebelum migrasi produksi), D5 (hak
akses, sebelum dipakai pengguna). Default yang direkomendasikan sudah terpasang; lihat [docs/DECISIONS.md](docs/DECISIONS.md).

## Dokumen

| Dokumen | Isi |
|---|---|
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Skema final: sheet, kolom, tipe, relasi, keunikan, aturan, ENUMS, SETTINGS (dibuat dari kode) |
| [docs/API.md](docs/API.md) | Kontrak frontend ↔ backend: amplop respons, kode error, matriks akses, parameter daftar, semua aksi |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Register keputusan bisnis (D1–D15, termasuk D5 hak akses dan D15 login Gmail) dan teknis Phase 02–06 + login password |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Lapisan, berkas, aturan penulisan, API, dan frontend |
| [docs/TESTING.md](docs/TESTING.md) | Cara menguji (Node, emulator, browser), cakupan, hasil per fase, batasan emulator |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | clasp, Script Properties, `setupDatabase`, self-test, deploy web app & user, daftar periksa, backup, migrasi produksi |
| `docs/DATA_PROFILE.md`, `docs/MIGRATION_MAPPING.md`, `docs/MIGRATION_ISSUES.md`, `docs/IMPLEMENTATION_PLAN.md` | Phase 01. Rahasia, tidak di-commit (D2) |
| `docs/MIGRATION_REPORT.md` | Laporan migrasi Phase 03 (dibuat `npm run migrate -- migrate`). Rahasia, tidak di-commit (D2) |
| `prompts/` | Paket prompt fase dari starter |

## Perintah

Butuh Node.js 20+. `npm test` tidak butuh dependency.

```bash
npm test                  # 128 test: API per role, login password, database, migrasi (workbook sintetis), setup/akses/lock/batch, aturan proyek
npm run dev               # aplikasi lengkap di http://127.0.0.1:8080 (emulator, data sintetis, akun uji per role)
npm install && npx playwright install chromium && npm run test:e2e   # 49 test browser end-to-end
npm run emulate:init      # jalankan setupDatabase → verify → init ulang → self-test di emulator
npm run migrate -- migrate   # pipeline migrasi lengkap untuk workbook di migration/source/ (lihat di bawah)
npm run docs:schema       # buat ulang docs/DATABASE_SCHEMA.md dari src/db/Schema.gs
npm install && npm run typecheck   # cek pemakaian API Apps Script terhadap typings resmi
npm run push              # clasp push ke project Apps Script (lihat docs/DEPLOYMENT.md)
```

Fungsi Apps Script untuk pemeliharaan (jalankan dari editor; hanya pemilik skrip atau `ADMIN_EMAILS`):
`setupDatabase`, `initializeDatabase`, `verifyDatabase`, `runDatabaseSelfTest`, `setupAdminAccount` (Admin pertama / pemulihan
dengan password sementara di log), dan migrasi: `profileSourceWorkbook`,
`validateMigrationMapping`, `dryRunMigration`, `runMigration`, `verifyMigration`. Web app: `doGet` (HtmlService) dan satu fungsi data
`api(action, payload)` yang dipanggil UI lewat `google.script.run` (lihat [docs/API.md](docs/API.md)).

## Profiler workbook (read-only, Phase 01)

```bash
node migration/scripts/profile-workbook.js                       # workbook di migration/source/
node migration/scripts/profile-workbook.js /path/ke/workbook.xlsx --as-of 2026-09-23
```

Output di `migration/reports/`: `data-profile.json` dan `phase01-migration-issues.csv`. Profiler tidak menulis ke workbook dan
memeriksa hash SHA-256-nya sebelum dan sesudah membaca.

## Migrasi data (Phase 03)

```bash
npm run migrate -- package    # PROFILE + MAP + VALIDATE → migration/reports/migration-package.json
npm run migrate -- dry-run    # + DRY RUN di emulator Apps Script (tidak menulis apa pun)
npm run migrate -- migrate    # + MIGRATE, rerun (idempotensi), VERIFY, laporan docs/MIGRATION_REPORT.md
# opsi: [path/ke/workbook.xlsx] --out <folder> --report <file.md> --as-of YYYY-MM-DD
```

- Workbook sumber hanya dibaca; hash SHA-256-nya dibandingkan sebelum dan sesudah. Semua keluaran berisi data bisnis dan di-ignore git.
- ID record = ID workbook. Relasi hanya dari ID workbook atau kecocokan persis & unik; relasi yang tidak pasti dibiarkan kosong dan
  dicatat di `MIGRATION_ISSUES`, tidak pernah ditebak.
- Setiap baris sumber tercatat: dimigrasikan, dikecualikan (baris asli lengkap disimpan di isunya), hanya isu, diwakili, atau dokumentasi.
- Migrasi nyata hanya berjalan setelah dry run paket yang sama lolos tanpa error. Rerun aman: record yang tidak berubah dilewati,
  record yang sudah diedit pengguna tidak ditimpa.
- Paket yang sama dijalankan di Apps Script oleh pemilik untuk migrasi produksi (docs/DEPLOYMENT.md §7).
