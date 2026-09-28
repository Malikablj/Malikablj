# Architecture

PIK Marketing Control is a **modular monolith**: one Node.js process serves the REST API and the
built React app, backed by one PostgreSQL database. A separate CLI migrates the AppSheet
workbook into the same database. Decisions and their reasons are in [DECISIONS.md](DECISIONS.md).

```text
 Browser (React SPA)                         Operator
   │  same-origin HTTPS, session cookie        │  npm run migrate:*
   ▼                                           ▼
 Express (apps/api) ─────────────────┐    Migration CLI (migration/scripts)
   /api/*   REST JSON                │      reads workbook (exceljs), never writes it
   /*       built web app (dist)     │      │
   │                                 │      │
   └──────────────► PostgreSQL ◄─────┘──────┘
                    schema.sql · views.sql (business calculations)

 packages/shared: enums · permission matrix · workflow rules · zod schemas
                  (imported by the API, the web app and the tests)
```

## Repository layout

| Path | Responsibility |
|---|---|
| `apps/api/src/routes` | URL → middleware chain (permission, validation) → controller |
| `apps/api/src/controllers` | HTTP only: read request, call a service, send the envelope |
| `apps/api/src/services` | business rules, cross-entity checks, transactions |
| `apps/api/src/repositories` | SQL only (parameterized); one module per aggregate |
| `apps/api/src/middleware` | session, authorization, CSRF, validation, errors, request log |
| `apps/api/src/validators` | list query schemas (body schemas live in `packages/shared`) |
| `apps/api/src/utils` | errors, SQL builders, dates, CSV, password hashing, rate limiter |
| `apps/api/migrations` | numbered schema changes after the baseline |
| `apps/web/src` | React app (pages, components, layouts, hooks, context, services, utils, styles) |
| `packages/shared/src` | contracts shared by API and web |
| `database` | `schema.sql` (baseline), `views.sql`, setup script, admin seed |
| `migration` | workbook migration: mapping, engine, reports, backups |
| `tests` | unit, API, migration (Vitest) and E2E (Playwright) |

Dependencies point one way: routes → controllers → services → repositories → `db/pool`.
Services never contain SQL; repositories never contain business rules.

## Backend

### Request pipeline

```text
helmet (CSP, headers) → cors → express.json (1 MB) → cookie-parser → request logger
→ /api: /health → CSRF check → load session → /auth → requireAuth → module routers
         (requirePermission → validateIdParams/validateQuery/validateBody → controller)
→ /api 404 → static web app + SPA fallback → error handler
```

- **Validation**: zod schemas parse bodies and queries. Blank strings become `null`; numbers
  accept numeric strings (form inputs). Validated queries are stored on `req.validatedQuery`.
- **Errors**: `AppError(status, code, message, details)`; the error handler converts PostgreSQL
  errors by constraint name into user-facing messages (unique → 409 `CONFLICT`, foreign key →
  409 `REFERENCE_ERROR`, check/not-null/format → 400 `VALIDATION_ERROR`, connection loss → 503)
  and never leaks SQL or stack traces.
- **SQL safety**: every value is a bind parameter. Dynamic parts (sort columns, enum lists)
  come only from whitelists or validated constants (`orderBy`, `codeList`, `sqlDate`).
- **Transactions**: `withTransaction()` wraps multi-row operations (PO + lines, primary
  contact switch, migrations).
- **Pagination**: `findPage()` runs the page query and a count with the same `WHERE`.

### Security

- Passwords: scrypt (N=2^14, r=8, p=5) with per-hash salt and parameters; unknown emails are
  checked against a dummy hash so timing does not reveal accounts.
- Sessions: 256-bit random token in an `HttpOnly`, `SameSite=Lax` cookie (`Secure` in
  production); only an HMAC-SHA256 of the token is stored. Logout, password change,
  deactivation and role change revoke sessions immediately.
- CSRF: state-changing requests need `X-Requested-With: XMLHttpRequest`.
- Login rate limiting per account+IP and per IP (in memory; see DECISIONS.md).
- Authorization: `requirePermission(module, action)` on every route, record-level checks for
  owner-only access (Marketing POs) in services. The role is read from the session row in the
  database, never from the request.
- Headers: helmet with a Content Security Policy (scripts only from the app's own origin, no
  inline scripts, no plugins, no framing by other sites). HSTS and `upgrade-insecure-requests`
  are sent only when the app is served over HTTPS (`COOKIE_SECURE`), so a plain-HTTP intranet
  deployment still loads.

### Business calculations in the database

`database/views.sql` defines the calculations once; the API, dashboard, reports and migration
verification all read them:

| Object | Calculates |
|---|---|
| `follow_up_state(date, status, today)` | `OVERDUE` / `TODAY` / `UPCOMING` / `DONE` / `CANCELLED` |
| `outstanding_quantity(ordered, delivered, returned)` | `MAX(0, ordered − delivered + returned)` |
| `v_po_line_fulfillment` | delivered / in progress / returned / outstanding per PO line |
| `v_purchase_order_summary` | PO totals (sum of lines), value, unpriced lines, unallocated legacy quantities |
| `v_stock_current` | latest stock count per product, type and warehouse |

"Today" is always the date in `APP_TIMEZONE`, computed in Node and passed to SQL.

## Data model

```text
users ── user_sessions
customers ─┬─ contacts
           ├─ leads ───────────── activities ── follow_ups
           ├─ purchase_orders ─┬─ po_lines ─┬─ deliveries
           │                   │            └─ returns
           │                   ├─ invoices_payments
           │                   └─ po_financials
           └─ (products for customer-specific items)
products ── stock (dated counts) · leadtime · inbound_maklon
migration_runs ── migration_run_records · migration_issues
```

- UUID primary keys; `created_at`/`updated_at` (trigger), `created_by`/`updated_by`.
- **Composite foreign keys** keep relationships consistent across entities: a lead's contact
  belongs to the lead's customer, a delivery's line belongs to its PO, a follow-up's lead
  belongs to its customer, and so on. The database rejects inconsistent data even if the API
  had a bug.
- Every foreign key is indexed (checked by a test).
- **Lineage** on migrated tables: `source_file`, `source_sheet`, `legacy_row`, `legacy_key`
  (unique), `source_data` (the complete source row as JSONB), `migrated_at`.
- Archive instead of delete (`is_active`), cancel via status; see DECISIONS.md.
- Schema versioning: `schema.sql` is an idempotent baseline recorded as `0000_baseline`; later
  changes are numbered SQL files in `apps/api/migrations/`; views are recreated on every setup.

## Frontend

- **Routing**: React Router with lazy-loaded pages; `RequireAuth` redirects to the login page
  and back; `RequirePermission` shows a "no access" state for modules the role cannot read.
- **Session**: `AuthProvider` checks `/api/auth/session` on start and every 15 minutes, and
  returns to the login page when any request answers 401. It exposes `can()`, `access()` and
  `canEdit()` from the shared permission matrix, and the server's business date (`today`).
- **Data fetching**: `useApi(path, params, { refreshKey })` keeps previous data visible while
  reloading, derives `loading` from the request key (no state updates inside effects) and
  aborts stale requests. `useListParams` keeps filters, sorting and paging in the URL.
- **Forms**: `useForm` validates with the same zod schema as the API, then shows server field
  errors on the same fields; user input is preserved on errors.
- **Components**: a small in-house kit (buttons, fields, data table with phone cards, tabs,
  native `<dialog>` modals, searchable combobox, toasts, confirm dialog, states) on CSS design
  tokens from the Technical Specification §12.
- **Responsive**: sidebar on desktop; top bar, drawer and bottom navigation on phones; tables
  turn into cards below 760 px; no horizontal page scrolling (checked by E2E tests).
- **Accessibility**: visible labels linked to inputs and to error/hint text, ARIA tabs and
  combobox with keyboard support, focus-trapped native dialogs, skip link, `aria-sort`,
  status never shown by colour alone (badges carry text).
- **Formatting**: Indonesian conventions (`1.250.000`, `28 Sep 2026`, `Rp`); instants shown in
  the business timezone.

## Migration engine

```text
workbook ─► reader (exceljs, raw values) ─► readiness gate (mapping vs. real sheets/columns)
        ─► per entity, in dependency order:
             parse fields (dates/numbers/enums, ambiguity → issue) ─► legacy key
             ─► duplicate check ─► resolve references (code → normalized name → PO + customer
                → PO + product; several candidates → issue, never a guess)
             ─► entity rules ─► upsert on legacy_key (SAVEPOINT per record)
        ─► run records + issues (fingerprinted, auto-resolved when they disappear)
        ─► summary JSON + issues CSV; verify compares database vs. run records
```

- Dry run and apply execute the same code; the dry run rolls back.
- Apply: `pg_dump` backup first, one transaction, blocked while the mapping is `PROVISIONAL`.
- Re-runs are idempotent and never overwrite rows edited in the app after migration
  (`updated_at > migrated_at`).
- Full rules: [MIGRATION_MAPPING.md](MIGRATION_MAPPING.md).

## Testing

| Level | Tool | Runs against |
|---|---|---|
| Unit | Vitest | shared rules, value parsers |
| API | Vitest + Supertest | the Express app with a real PostgreSQL `_test` database |
| Migration | Vitest | a generated, deliberately messy fixture workbook; also the verify CLI in a child process |
| E2E | Playwright (Chromium) | the production build and API in production mode on a fresh `_e2e` database |

## Deployment

One process: `npm run build`, then `NODE_ENV=production npm start` behind an HTTPS reverse
proxy (`TRUST_PROXY=1`). Built assets are fingerprinted and cached for a year; `index.html` is
revalidated on every load, so a new deployment is picked up immediately. Health: `GET /api/health`. Configuration: environment variables only (README).
