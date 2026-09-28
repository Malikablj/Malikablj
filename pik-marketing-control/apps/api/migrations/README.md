# Incremental schema migrations

`database/schema.sql` is the baseline and always describes the **complete current schema**
(used for fresh installs). Once a database is in use, every structural change must also be
added here as a numbered SQL file so existing databases can be upgraded:

```
apps/api/migrations/0001_add_lead_source_detail.sql
apps/api/migrations/0002_...
```

Rules:

1. File names: `NNNN_short_description.sql` (4 digits, lowercase, underscores).
2. Make the same change in `database/schema.sql`, so fresh installs match upgraded ones.
3. Never drop or truncate tables with data. Prefer additive changes (new nullable columns,
   new tables, new indexes). If a destructive change is unavoidable, get explicit approval
   and document a backup/restore plan first.
4. Views and business functions live in `database/views.sql`, which is re-applied on every
   `npm run db:setup`; they do not need migration files.

`npm run db:setup` applies pending files in order, inside one transaction, and records them in
`schema_migrations`. A fresh database records all existing files as applied, because
`schema.sql` already contains their changes.

No incremental migrations exist yet: the current schema is the baseline (`0000_baseline`).
