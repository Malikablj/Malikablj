# migration/reports

Output yang dihasilkan skrip. Semuanya di-ignore git karena berisi data bisnis.

| File | Dihasilkan oleh | Isi |
|---|---|---|
| `data-profile.json` | `profile-workbook.js` | Profil setiap sheet/kolom dan statistik domain (Phase 01) |
| `phase01-migration-issues.csv` | `profile-workbook.js` | Semua isu Phase 01, satu baris per record atau per sheet/kolom |
| `migration-package.json` | `migrate.js` (semua perintah) | Paket migrasi yang diunggah ke Drive untuk migrasi produksi di Apps Script |
| `migration-dry-run.json` | `migrate.js dry-run` / `migrate` | Hasil `dryRunMigration()`: integritas, rencana per tabel, error validasi |
| `migration-run.json` | `migrate.js migrate` | Hasil `runMigration()` dan rerun idempotensi |
| `migration-verify.json` | `migrate.js migrate` | Hasil `verifyMigration()` lengkap, termasuk rekonsiliasi |
| `migration-summary.json` | `migrate.js` | Ringkasan angka semua langkah |
| `migration-issues.csv` | `migrate.js migrate` | `MIGRATION_ISSUES` seperti tersimpan di database |
| `migration-transformations.csv` | `migrate.js` | Setiap nilai yang berbeda dari sel sumbernya: aturan, nilai asal, nilai hasil |
| `migration-accounting.csv` | `migrate.js` | Disposisi setiap baris sumber (MIGRATED, EXCLUDED, ISSUE_ONLY, REPRESENTED, NOT_MIGRATED) |
| `outstanding-reconciliation.csv` | `migrate.js migrate` | Outstanding hitung vs legacy per PO |
| `database/*.csv` | `migrate.js migrate` | Isi setiap sheet database hasil gladi migrasi |

Laporan yang dapat dibaca manusia ditulis ke `docs/MIGRATION_REPORT.md` (juga di-ignore git).

Buat ulang dengan:

```bash
node migration/scripts/profile-workbook.js [path/ke/PIK_Master_Database_AppSheet.xlsx]
npm run migrate -- migrate [path/ke/PIK_Master_Database_AppSheet.xlsx]
```
