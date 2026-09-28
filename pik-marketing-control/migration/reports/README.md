# migration/reports

Output yang dihasilkan skrip (di-ignore oleh git karena berisi data bisnis):

| File | Dihasilkan oleh | Isi |
|---|---|---|
| `data-profile.json` | `migration/scripts/profile-workbook.js` | Profil setiap sheet/kolom dan statistik domain |
| `phase01-migration-issues.csv` | `migration/scripts/profile-workbook.js` | Semua isu Phase 01, satu baris per record atau per sheet/kolom |

Buat ulang dengan:

```bash
node migration/scripts/profile-workbook.js [path/ke/PIK_Master_Database_AppSheet.xlsx]
```
