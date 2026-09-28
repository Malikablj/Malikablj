# PIK Marketing Control

Aplikasi internal Marketing PT Permata Indo Kemas: customer → contact → lead → activity → follow-up → PO → delivery → outstanding.

> ⚠️ **Data rahasia.** Workbook sumber, laporan profiling/migrasi, dan dokumen Phase 01 di `docs/` memuat data bisnis PIK.
> Semuanya di-ignore git (`.gitignore`) dan **tidak dipublikasikan di repositori ini karena repositori ini publik**.
> Dokumen hanya akan di-commit setelah proyek berada di repositori privat (keputusan D2).

## Status

| Fase | Status |
|---|---|
| 01 — Inspect & Profile | ✅ selesai: profil data, pemetaan migrasi, daftar isu, rencana implementasi |
| 02 — Database | menunggu keputusan D1–D5 ([docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md)) |

Belum ada migrasi yang dijalankan. Stack final (Apps Script + Google Sheets vs React + Express + PostgreSQL) menunggu keputusan D1.

## Dokumen

- [docs/DATA_PROFILE.md](docs/DATA_PROFILE.md) — profil lengkap `PIK_Master_Database_AppSheet.xlsx`
- [docs/MIGRATION_MAPPING.md](docs/MIGRATION_MAPPING.md) — pemetaan sheet/kolom ke skema target, aturan transformasi & relasi
- [docs/MIGRATION_ISSUES.md](docs/MIGRATION_ISSUES.md) — katalog isu migrasi (daftar per record ada di CSV)
- [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) — register keputusan, arsitektur, fase kerja, risiko

## Menjalankan profiler (read-only)

Butuh Node.js 18+ (tanpa dependency npm).

```bash
# workbook di migration/source/ (default) …
node migration/scripts/profile-workbook.js
# … atau path lain, dengan tanggal acuan "masa depan" tertentu
node migration/scripts/profile-workbook.js /path/ke/PIK_Master_Database_AppSheet.xlsx --as-of 2026-09-23
```

Output di `migration/reports/`: `data-profile.json` dan `phase01-migration-issues.csv`. Profiler tidak menulis ke workbook dan
memeriksa hash SHA-256-nya sebelum dan sesudah membaca.
