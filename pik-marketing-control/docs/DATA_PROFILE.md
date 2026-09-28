# Data Profile: Source Workbook

> **Status: NOT PROFILED. The source workbook has not been provided.**
>
> This file is regenerated automatically by `npm run migrate:profile` once the workbook
> is placed in `migration/source/`. Until then it records where the workbook was looked for
> and what the profile will contain.

## Expected source

| Item | Value |
|---|---|
| File name | `PIK_Master_Database_AppSheet.xlsx` |
| Expected location | `migration/source/PIK_Master_Database_AppSheet.xlsx` (git-ignored: it contains business data) |
| Origin (per spec) | Excel export of the AppSheet master database |

## Search performed (2026-09-28)

| Location | Result |
|---|---|
| Git repository (all branches: `claude/adoring-wright-7byumb`, `claude/serene-ritchie-h31imh`) | No `.xlsx` / `.xls` / `.csv` files. The repository holds an unrelated Python app (NPD Project Control). |
| Files uploaded to the session | Only the Technical Specification / Master Prompt (Markdown). |
| Container filesystem (`*.xlsx`, `*.xls`, `*.xlsm`, `*.csv`, `*PIK*`, `*AppSheet*`) | Nothing relevant. |
| Connected Google Drive: titles `PIK_Master_Database`, `PIK Master`, `AppSheet`, `Master Database`, `Marketing Control` | No match. |
| Connected Google Drive: full-text `Inbound Maklon`, `PO Financials`, `PO_Lines` | No match. |
| Connected Google Drive: spreadsheets with PIK/PO/customer names | Related operational files exist (e.g. `Delivery Plan_PIK`, `PO JUNI 2026`, `Form Review Inquiry Customer`, per-product inspection workbooks). None is the master database, so none was used. |

Consequence: **no migration data was imported and none was fabricated.** The database schema
follows the Technical Specification (§6). The migration engine is complete and tested
against a small synthetic fixture workbook (`tests/migration/`), and its mapping is marked
`PROVISIONAL` until verified against the real workbook.

## What `npm run migrate:profile` produces

For every sheet in the workbook:

- sheet name, dimension, header row, data row count, empty-row count
- merged cell ranges (merged cells inside data are flagged, since they break row-wise import)
- per column: header, detected types (text / number / date / boolean / formula / error /
  rich text / hyperlink), fill rate, distinct count, up to 3 sample values, min/max for
  numbers and dates, maximum text length
- formula columns (the cached result is imported; the formula is reported)
- date columns stored as **text**, with the patterns seen (e.g. `DD/MM/YYYY`) and whether
  any value disambiguates day/month order (day > 12)
- numbers stored as **text**, with the separators seen (`1.000,50` vs `1,000.50`)
- duplicate full rows and duplicate values in candidate identifier columns
  (`ID`, `*Code`, `*No*`, `*Number*`, AppSheet `Row ID`/`_RowNumber`)
- candidate relationships: columns whose values mostly appear in another sheet's
  identifier column (e.g. `PO Lines.PO ID` → `Purchase Orders.ID`)
- comparison against the provisional mapping: mapped sheets/columns found, missing, and
  unmapped source columns

Sample values can be suppressed with `npm run migrate:profile -- --no-samples` if this file
must not contain business data.

## Next steps once the workbook is available

1. Copy the workbook to `migration/source/PIK_Master_Database_AppSheet.xlsx`.
2. `npm run migrate:profile`: regenerates this file.
3. Update `migration/mapping/workbook.mapping.js` to the real sheet/column names, value maps
   and date/number formats; set `status: 'VERIFIED'`. Update `docs/MIGRATION_MAPPING.md`.
4. `npm run migrate:dry`: review `migration/reports/` (summary + issues CSV).
5. `npm run migrate`, then `npm run migrate:verify`.
