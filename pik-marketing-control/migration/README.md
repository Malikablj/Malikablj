# Migration: AppSheet workbook → PostgreSQL

Full rules: [docs/MIGRATION_MAPPING.md](../docs/MIGRATION_MAPPING.md).

```
migration/
├── source/     put PIK_Master_Database_AppSheet.xlsx here (git-ignored: business data)
├── mapping/    workbook.mapping.js: sheet/column mapping (PROVISIONAL until verified)
├── scripts/    CLI entry points + lib/ (reader, profiler, gate, resolver, engine, verify)
├── reports/    generated summary JSON + issues CSV (git-ignored)
└── backups/    pg_dump taken before every applied migration (git-ignored)
```

| Command | What it does | Writes to the database? |
|---|---|---|
| `npm run migrate:profile` | profiles the workbook → `docs/DATA_PROFILE.md` | no |
| `npm run migrate:dry` | full import in a transaction that is rolled back → reports | no |
| `npm run migrate` | backup, then import as one transaction → reports | yes |
| `npm run migrate:verify` | row counts, lineage, stale records, reconciliation | no |

Options (after `--`): `--file <xlsx>`, `--mapping <js>`, `--out <md>` and `--no-samples`
(profile), `--no-backup` and `--allow-provisional` (apply; never for production data).

Rules of the road:

* The workbook is only ever **read**.
* Nothing is guessed: ambiguous names, dates or numbers become issues, not choices.
* No row disappears silently: every source row is imported, merged as an identical duplicate,
  or reported as an `ERROR` issue with the full source row.
* Records edited in the app after migration are never overwritten by a re-run.
