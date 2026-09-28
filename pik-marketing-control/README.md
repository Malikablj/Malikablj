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
| 02 — Database | ✅ skema 21 sheet, initializer idempoten, ENUMS, SETTINGS, AUDIT_LOG, validasi, repository. Teruji di emulator (59 test); satu run di Apps Script sungguhan masih menunggu (lihat [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)) |
| 03 — Migration | berikutnya |

Belum ada migrasi data bisnis yang dijalankan.

## Dokumen

| Dokumen | Isi |
|---|---|
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Skema final: sheet, kolom, tipe, relasi, keunikan, aturan, ENUMS, SETTINGS (dibuat dari kode) |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Register keputusan bisnis (D1–D14) dan teknis Phase 02 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Lapisan, berkas, dan aturan penulisan |
| [docs/TESTING.md](docs/TESTING.md) | Cara menguji, cakupan, hasil, batasan emulator |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | clasp, Script Properties, `setupDatabase`, self-test di Apps Script |
| `docs/DATA_PROFILE.md`, `docs/MIGRATION_MAPPING.md`, `docs/MIGRATION_ISSUES.md`, `docs/IMPLEMENTATION_PLAN.md` | Phase 01. Rahasia, tidak di-commit (D2) |
| `prompts/` | Paket prompt fase dari starter |

## Perintah

Butuh Node.js 20+. `npm test` tidak butuh dependency.

```bash
npm test                  # 59 test: database (emulator Apps Script), setup/akses/lock/batch, aturan proyek, dokumen
npm run emulate:init      # jalankan setupDatabase → verify → init ulang → self-test di emulator
npm run docs:schema       # buat ulang docs/DATABASE_SCHEMA.md dari src/db/Schema.gs
npm install && npm run typecheck   # cek pemakaian API Apps Script terhadap typings resmi
npm run push              # clasp push ke project Apps Script (lihat docs/DEPLOYMENT.md)
```

Fungsi Apps Script untuk pemeliharaan (jalankan dari editor; hanya pemilik skrip atau `ADMIN_EMAILS`):
`setupDatabase`, `initializeDatabase`, `verifyDatabase`, `runDatabaseSelfTest`.

## Profiler workbook (read-only, Phase 01)

```bash
node migration/scripts/profile-workbook.js                       # workbook di migration/source/
node migration/scripts/profile-workbook.js /path/ke/workbook.xlsx --as-of 2026-09-23
```

Output di `migration/reports/`: `data-profile.json` dan `phase01-migration-issues.csv`. Profiler tidak menulis ke workbook dan
memeriksa hash SHA-256-nya sebelum dan sesudah membaca.
