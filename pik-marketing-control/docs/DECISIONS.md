# Decision Log

Format per entry: **Decision** · Reason · Impact · Alternative considered.
Trivial coding choices are not recorded.

## Open business decisions (implemented with a safe, reversible default)

These were not defined by the Technical Specification, and the PRD was not provided. Each is
implemented in exactly one place, so changing it is a small, contained edit.

| # | Question | Current default | Where to change |
|---|---|---|---|
| B1 | Which deliveries count as "Delivered Quantity"? | Only status `DELIVERED`. `SCHEDULED/ON_DELIVERY/DELAYED` are shown separately as "in progress"; `CANCELLED` never counts. | `database/views.sql` (`v_po_line_fulfillment`) |
| B2 | Return statuses and which count as "Returned Quantity" | Statuses `REPORTED`, `RECEIVED`, `RESOLVED`, `CANCELLED`; only `RECEIVED` + `RESOLVED` (goods physically back) count | `views.sql`, `packages/shared/src/constants.js`, `schema.sql` check |
| B3 | Marketing access to POs: spec says "RW/R" | Marketing may create POs and edit/cancel **their own** POs (owner = PIC); read-only on others | `packages/shared/src/permissions.js` |
| B4 | Access to modules missing from the spec matrix | Finance (invoices, PO financials): Admin RW; Marketing/Sales/Management R; **Viewer none**. Lead time = Products. Inbound maklon = Delivery. Migration issues: Admin RW, Management R | `permissions.js` |
| B5 | Lead pipeline rules | Any stage may move to any stage; moving to `LOST` requires a reason; a `WON` lead can only be reopened by an Admin | `packages/shared/src/workflow.js` |
| B6 | PO status rules | Status is set manually (no automatic close on full delivery); cancelling requires a reason; only an Admin can reopen a cancelled PO | `workflow.js` |
| B7 | Legacy rows without a status | Deliveries → `DELIVERED`, returns → `RECEIVED`, POs → `OPEN` (see MIGRATION_MAPPING.md §5) | `migration/mapping/workbook.mapping.js` |
| B8 | Priority levels (leads, follow-ups) | `LOW`, `MEDIUM`, `HIGH` | `constants.js`, `schema.sql` checks |
| B9 | "Outstanding Quantity" KPI across products with different units | KPI shows outstanding per unit (e.g. "12.000 pcs · 300 kg"); POs with status `OPEN`, `ON_PROCESS`, `PARTIAL` only | dashboard service |

## Decisions

### 2026-09-28: Build in `pik-marketing-control/`, leave the existing NPD app untouched
The repository already contains a working, unrelated Python/Flask app ("NPD Project
Control"). · Replacing it would destroy working software nobody asked to remove. · The new
app is self-contained in its own folder with its own package.json; the root README points to it.
· Alternative: replace the repository contents (rejected: destructive).

### 2026-09-28: Source workbook and PRD not available: build without fabricated data
`PIK_Master_Database_AppSheet.xlsx` was not found in the repository, the uploads or the
connected Google Drive; the PRD was not supplied. · The master prompt says to continue the
architecture but never fabricate migration data. · The schema follows the Technical
Specification; the mapping is `PROVISIONAL` and `npm run migrate` is blocked until it is
verified against the real workbook; the engine is proven on a synthetic fixture.
`docs/PRD.md` states the PRD is missing and lists requirements derived from the spec.
· Alternative: use related Drive sheets (e.g. "Delivery Plan_PIK"). Rejected: not the named
source; mixing sources would be guessing.

### 2026-09-28: Current dependency versions (Node ≥ 22.22)
React 19.3, React Router 8, Vite 8, Express 5, zod 4, pg 8, Vitest 5, ESLint 10. · Maintained
versions with security fixes. · Node 22 LTS or newer is required (React Router 8 and Vitest 5
need it). · Alternative: older majors (rejected: shorter support window).

### 2026-09-28: `exceljs` instead of `xlsx` (SheetJS)
The npm `xlsx@0.18.5` has known vulnerabilities (prototype pollution, ReDoS); fixed versions are
only published on the SheetJS CDN, which the network policy blocks. · `exceljs` is widely used,
exposes cell types, formulas and merged cells for profiling. Its transitive `uuid` is pinned
to a patched version via `overrides` (`npm audit`: 0 vulnerabilities). · Alternative: vendoring
SheetJS 0.20.x (not reachable).

### 2026-09-28: Password hashing with Node's built-in scrypt
`node:crypto` scrypt, N=2^14, r=8, p=5 (an OWASP-listed configuration, ~16 MiB, ~0.2 s).
· No native add-on to compile; parameters are stored in each hash so they can be raised later.
· Alternative: bcrypt/argon2 packages (native builds; not needed).

### 2026-09-28: Server-side sessions in PostgreSQL
Random 256-bit token in an `httpOnly`, `SameSite=Lax` cookie (`Secure` in production); only an
HMAC-SHA256 (keyed with `SESSION_SECRET`) of the token is stored in `user_sessions`. · Logout
and user deactivation take effect immediately (impossible with stateless JWTs). · CSRF:
state-changing requests must send `X-Requested-With: XMLHttpRequest` (custom headers cannot be
sent cross-site without a CORS preflight). · Alternative: JWT (rejected: no revocation).

### 2026-09-28: `TIMESTAMPTZ` and a business timezone
All instants are `TIMESTAMPTZ` (spec says TIMESTAMP). "Today" for follow-ups and KPIs is the
date in `APP_TIMEZONE` (default Asia/Jakarta), passed to SQL as a parameter. · A server in UTC
would otherwise treat 00:00 to 07:00 WIB as "yesterday". · Excel wall-clock times are
interpreted in the same timezone.

### 2026-09-28: Business calculations live in the database
`outstanding_quantity()`, `follow_up_state()`, `v_po_line_fulfillment`,
`v_purchase_order_summary`, `v_stock_current` in `database/views.sql`. · One implementation
used by the API, dashboard, reports and migration verification; the UI never recomputes.

### 2026-09-28: PO outstanding = sum of line outstanding
Each line: `MAX(0, ordered − delivered + returned)`. PO total = sum of lines, so over-delivery
of one item never hides a shortage of another. Deliveries/returns recorded against a PO without
a line (legacy data only) are shown as "unallocated" and not netted. · Alternative: PO-level
`MAX(0, Σordered − Σdelivered + Σreturned)` (rejected: hides shortages).

### 2026-09-28: Follow-up "Overdue" is derived, not stored
`follow_up_state(date, status, today)` returns `OVERDUE/TODAY/UPCOMING/DONE/CANCELLED`. The stored
status `OVERDUE` is accepted (legacy data) but never set automatically; users set `PLANNED`,
`DONE`, `RESCHEDULE`, `CANCELLED`. Rescheduling sets a new date and status `RESCHEDULE`.
· A stored overdue flag goes stale every midnight.

### 2026-09-28: Schema additions beyond the spec
* `lost_reason`, `status_changed_at`, `closed_at` on leads (lost-deal analysis, pipeline timing).
* `completed_at` on follow-ups; `cancel_reason`, `cancelled_at` on purchase orders.
* `customer_id` on returns (required): returns without a PO must still belong to a customer.
* `delivery_number` (surat jalan) on deliveries, `return_number` on returns, `payment_date` on invoices.
* `line_no` and `item_name` on PO lines; `item_name` on deliveries, returns, stock, lead time,
  inbound maklon, with `product_id` nullable **only** so unmatched legacy products stay usable
  (the API still requires a product for new records).
* `is_active` on contacts; `name_key` (normalized name) on customers/products for matching.
* Composite foreign keys (e.g. `(lead_id, customer_id)`) so a record can never point to a
  lead, contact, PO or PO line of a different customer/PO.
* Lineage (`legacy_key`, `source_data`, `migrated_at`) on every migrated table, plus
  `migration_runs` / `migration_run_records` for verification.

### 2026-09-28: Provisional structure for `inbound_maklon` and `po_financials`
The spec says to derive them from the workbook, which is unavailable. · Minimal relational core
(customer/PO/product, date, quantity, document number; PO value, tax, totals) plus
`source_data` keeping every source column. · Additive changes after profiling.

### 2026-09-28: Stock rows are dated snapshots
Current stock = latest `stock_date` per product/stock type/warehouse (`v_stock_current`);
history is kept. · Matches how stock is counted and recorded in spreadsheets. · Alternative:
movement ledger (needs transaction data the spec does not describe).

### 2026-09-28: API conventions
snake_case JSON (mirrors the schema), standard `{ success, data, meta }` envelope, `PUT`
applies partial updates (omitted fields unchanged, `null` clears), pagination
`page`/`page_size` (max 100), sorting `sort=field` / `sort=-field` from a whitelist. NUMERIC
values are JSON numbers (all arithmetic is exact in SQL).

### 2026-09-28: Records are archived or cancelled, never deleted
Customers, contacts, products and users are archived (`is_active`); POs, deliveries, returns,
follow-ups and invoices are cancelled via status. The only deletion is a PO line without any
delivery/return (a correction while entering a PO), enforced by foreign keys.

### 2026-09-28: Migration engine design
Dry run = the real import inside a rolled-back transaction; apply = one committed transaction
with a savepoint per record and a `pg_dump` backup first; re-runs upsert on `legacy_key` and
never overwrite records edited in the app; issues are fingerprinted and auto-resolved when they
disappear. Full rules in MIGRATION_MAPPING.md.

### 2026-09-28: Schema versioning
`database/schema.sql` is the complete baseline (idempotent); later changes are numbered files in
`apps/api/migrations/` applied by `npm run db:setup`. Views are recreated on every setup.

### 2026-09-28: One deployable unit
In production the Express API also serves the built React app (`apps/web/dist`), so the app runs
as a single Node process behind any reverse proxy. CORS is only needed for split hosting.

### 2026-09-28: CSV export
RFC 4180 (comma, `.` decimals, UTF-8 BOM so Excel shows Indonesian text correctly); text cells
starting with `= + - @` are prefixed with `'` to prevent formula injection. In Excel with an
Indonesian locale, open via *Data → From Text/CSV* if columns do not split.

### 2026-09-28: Business data never enters git
`migration/source/*`, `migration/reports/*`, `migration/backups/*` and `.env` are git-ignored.
