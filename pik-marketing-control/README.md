# PIK Marketing Control

Internal web app for **PT Permata Indo Kemas** that replaces the AppSheet master database for
marketing and sales: customers, contacts, the lead pipeline, activities, follow-ups, purchase
orders with delivery/return tracking, products and stock, invoices, a management dashboard and
reports. The interface is in Bahasa Indonesia and works on desktop and phone.

> **Status.** The application is complete and tested. The source workbook
> `PIK_Master_Database_AppSheet.xlsx` was **not available**, so legacy data has not been migrated
> yet: the migration engine is built and tested on a synthetic workbook, and its sheet/column
> mapping stays `PROVISIONAL` (the real import is blocked) until it is checked against the real
> file. See [Migrating the AppSheet data](#migrating-the-appsheet-data).

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [Scripts](#scripts)
- [Migrating the AppSheet data](#migrating-the-appsheet-data)
- [Testing](#testing)
- [Production](#production)
- [Roles and permissions](#roles-and-permissions)
- [Project structure](#project-structure)
- [Documentation](#documentation)

## Features

| Area | What users can do |
|---|---|
| Dashboard | Total customers, active leads (with pipeline value), follow-ups today/overdue, open POs, outstanding quantity per unit; today's follow-ups with one-tap actions, recent activities, pipeline by stage, running POs, delivery status. "My data" / "All" scope. |
| Customers | Search, filter, create, edit, archive/restore. The customer page is the workspace: overview, contacts (WhatsApp/phone/email links), activities, leads, follow-ups, purchase orders, deliveries, returns. |
| Leads | Kanban with drag-and-drop between stages and a list view; lost reason required; Won can only be reopened by an Admin. |
| Activities | WhatsApp, call, email, meeting, visit, quotation, sample, … linked to customer, contact and lead; timeline per day. |
| Follow-ups | Today / Overdue / Upcoming / Done tabs, complete with outcome, reschedule with reason, notification badge. |
| Purchase orders | PO with lines (atomic create), status changes with rules (cancel needs a reason, deliveries in transit block cancelling), per-line delivered / in progress / returned / outstanding. |
| Deliveries and returns | Recorded against a PO line (product always follows the line); over-delivery warning; returns may reference a PO and line. |
| Products, stock, lead time | Product master with categories and units; stock as dated counts per type (FG, WIP, Ready, Reserved) and warehouse with full history; lead time per product/customer. |
| Finance | Invoices with payment status derived from amounts, overdue tracking and totals; PO financial summary. |
| Reports | Customers, leads, activities, follow-ups, POs, deliveries, stock, with date, customer, PIC and status filters, totals and **CSV export**. |
| Administration | Users and roles, password reset, deactivation (ends sessions), migration issue review. |

Every figure (outstanding quantity, follow-up state, KPIs, report totals) is computed in
PostgreSQL from the recorded transactions, so the screens, dashboard, reports and migration
verification always agree.

## Tech stack

- **Frontend**: React 19 + Vite, JavaScript, React Router; plain CSS with the design tokens from
  the Technical Specification; no UI framework.
- **Backend**: Node.js + Express 5 REST API (JSON), modular monolith
  (routes → controllers → services → repositories), parameterized SQL with `pg` (no ORM).
- **Database**: PostgreSQL 16 (schema, constraints and business views in `database/`).
- **Shared**: `packages/shared` holds enums, the permission matrix, workflow rules and zod
  validation schemas used by both the API (authoritative) and the forms (early feedback).
- **Migration**: Node scripts reading the workbook with `exceljs`.
- **Tests**: Vitest + Supertest (unit, API, migration) and Playwright (browser E2E).

## Getting started

Requirements: **Node.js ≥ 22.22**, **PostgreSQL 16** (tested with 16; the schema needs at least 13) and npm.

```bash
cd pik-marketing-control
npm install

# 1. Configuration
cp .env.example .env            # then edit .env: DATABASE_URL, SESSION_SECRET, ADMIN_EMAIL, ...

# 2. PostgreSQL (skip if you already have a server)
docker compose up -d            # local PostgreSQL 16 on 127.0.0.1:5432, uses POSTGRES_PASSWORD

# 3. Database schema and the first administrator
npm run db:setup                # creates the database if needed, applies schema + views
npm run db:seed                 # creates the Admin from ADMIN_EMAIL / ADMIN_PASSWORD
                                # (empty ADMIN_PASSWORD: a random one is printed once)

# 4. Development servers (API on :4000, web on :5173 with /api proxied)
npm run dev
```

Open http://localhost:5173 and sign in with the admin account. The database starts empty:
no demo or sample business data is created. Add users under *Pengaturan → Pengguna*, then
customers, products and so on, or migrate the AppSheet data.

`npm run db:setup` is safe to run repeatedly: it applies pending numbered migrations from
`apps/api/migrations/` and refreshes the views; it never drops data.

## Configuration

All settings are environment variables (a `.env` file in this folder is loaded automatically;
real environment variables take precedence). Never commit `.env`.

| Variable | Required | Description |
|---|---|---|
| `DATABASE_URL` | yes | PostgreSQL connection string for the application |
| `SESSION_SECRET` | production | ≥ 32 random characters; keys the session token hashes |
| `NODE_ENV` | | `development` (default) or `production` |
| `PORT` | | API port (default `4000`) |
| `SESSION_TTL_HOURS` | | session lifetime (default `168` = 7 days) |
| `APP_TIMEZONE` | | business timezone for "today" (default `Asia/Jakarta`) |
| `CORS_ORIGIN` | | allowed browser origin(s) when the web app is hosted separately |
| `TRUST_PROXY` | | `1` behind a reverse proxy / load balancer |
| `COOKIE_SECURE` | | session cookie over HTTPS only; defaults to on in production |
| `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | seed | first administrator for `npm run db:seed` |
| `DATABASE_URL_TEST` | tests | separate database for `npm test`; name must end in `_test` |
| `DATABASE_URL_E2E` | | database for `npm run test:e2e`; name must end in `_e2e` (defaults to the test URL with `_e2e`) |
| `POSTGRES_PASSWORD` | docker | password for the `docker-compose.yml` database |

## Scripts

| Command | Purpose |
|---|---|
| `npm run dev` | API (auto-restart) and Vite dev server together |
| `npm run build` | production build of the web app + API load check |
| `npm start` | production server: API and the built web app on one port |
| `npm run lint` | ESLint (including React hooks rules) |
| `npm test` | unit, API and migration tests (Vitest) |
| `npm run test:e2e` | browser tests (Playwright) |
| `npm run db:setup` | create/upgrade the schema and views |
| `npm run db:seed` | create the first Admin user |
| `npm run migrate:profile` | profile the AppSheet workbook → `docs/DATA_PROFILE.md` |
| `npm run migrate:dry` | full migration rehearsal, rolled back |
| `npm run migrate` | backup, then migrate in one transaction |
| `npm run migrate:verify` | verify the last applied migration |

## Migrating the AppSheet data

The workbook is only ever read. Nothing is guessed: ambiguous names, dates or numbers become
issues for a person to decide, and no row disappears silently.

1. Copy `PIK_Master_Database_AppSheet.xlsx` to `migration/source/` (git-ignored).
2. `npm run migrate:profile`: writes `docs/DATA_PROFILE.md` (sheets, columns, types, blanks,
   duplicates, relationship candidates) and checks the mapping against the real file.
3. Review and correct `migration/mapping/workbook.mapping.js` against the profile (sheet and
   column names, value maps, keys), then set `status: 'VERIFIED'`.
4. `npm run migrate:dry`: the complete import inside a transaction that is rolled back. Review
   `migration/reports/migration_summary.json` and `migration/reports/migration_issues.csv`.
5. `npm run migrate`: takes a `pg_dump` backup to `migration/backups/`, then imports everything
   in one transaction.
6. `npm run migrate:verify`: row counts, lineage, dropped rows, stale records, relationship
   quality and reconciliation with legacy calculated columns.
7. Resolve the remaining issues in the app under *Pengaturan → Migration Issues*.

Every migrated row keeps its source file, sheet, row number, key and the complete original row
(`source_data`). Re-running is safe: records are matched on their legacy key, unchanged rows are
left alone, and records edited in the app after migration are never overwritten. Details:
[migration/README.md](migration/README.md) and [docs/MIGRATION_MAPPING.md](docs/MIGRATION_MAPPING.md).

## Testing

```bash
npm run lint
npm test            # needs DATABASE_URL_TEST; its schema is recreated on every run
npm run test:e2e    # builds the web app, recreates the *_e2e database, starts the server
```

- **Unit**: business rules (lead and PO transitions, payment status, value parsing).
- **API** (Supertest against a real PostgreSQL): authentication, sessions, rate limiting, CSRF,
  permissions per role, CRUD and business rules for every module, calculations, reports and CSV.
- **Migration**: a synthetic, deliberately messy workbook: counts, duplicates, ambiguity,
  unresolved references, lineage, calculations, dry-run isolation, idempotent re-runs,
  protection of app edits, and the verification CLI in a separate process.
- **E2E** (Playwright, production build): the login → customer → lead → follow-up → dashboard
  flow; every page opened as Admin without console errors; read-only roles; own-PO editing;
  phone layouts without horizontal scrolling. `E2E_SCREENSHOTS=<dir> npm run test:e2e` saves
  a screenshot of every page.

Test data is only ever written to the `_test` / `_e2e` databases, which are recreated on every run
(the setup refuses any database whose name does not end in `_test` or `_e2e`).

## Production

```bash
npm ci
npm run build
NODE_ENV=production npm start      # serves /api and the web app on PORT
```

- Run behind HTTPS (reverse proxy such as nginx or Caddy) and set `TRUST_PROXY=1`. In
  production the session cookie is `Secure` and the browser is told to use HTTPS only (HSTS).
  Set `COOKIE_SECURE=0` only for a trusted intranet served over plain HTTP; this also turns
  those HTTPS-only headers off.
- Set a strong `SESSION_SECRET` (≥ 32 characters); the server refuses to start without one.
- Run `npm run db:setup` after each deployment (applies pending migrations, refreshes views).
- `GET /api/health` reports API and database status for monitoring.
- Back up PostgreSQL regularly (`pg_dump`); the migration's own backups are not a backup strategy.

**CSV and Excel.** Exports are UTF-8 with a BOM, comma separated, `.` as decimal separator. Excel
with Indonesian regional settings expects `;`: if columns do not split, open the file via
*Data → From Text/CSV* and choose comma.

## Roles and permissions

| Module | Admin | Marketing | Sales | Management | Viewer |
|---|---|---|---|---|---|
| Customers, contacts, leads, activities, follow-ups | RW | RW | RW | R | R |
| Purchase orders | RW | RW own, R others | R | R | R |
| Deliveries, returns, products, stock, lead time, maklon, dashboard, reports | RW | R | R | R | R |
| Invoices and PO finance | RW | R | R | R | — |
| Users | RW | — | — | — | — |
| Migration issues | RW | — | — | R | — |

The API enforces this matrix on every request (the role always comes from the server-side
session); the UI only hides what a role cannot do. Choices the specification left open are
listed in [docs/DECISIONS.md](docs/DECISIONS.md).

## Project structure

```text
pik-marketing-control/
├── apps/
│   ├── api/            Express REST API (src/routes, controllers, services, repositories, …)
│   │   └── migrations/ numbered schema changes after the baseline
│   └── web/            React + Vite app (src/pages, components, layouts, hooks, context, …)
├── packages/shared/    enums, permission matrix, workflow rules, zod schemas
├── database/           schema.sql, views.sql, setup script, seeds/
├── migration/          workbook migration: source/, mapping/, scripts/, reports/, backups/
├── tests/              unit/, api/, migration/, e2e/
└── docs/               specification, decisions, architecture, API, migration mapping
```

## Documentation

| Document | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | product requirements (derived; the original PRD was not provided) |
| [docs/TECHNICAL_SPEC.md](docs/TECHNICAL_SPEC.md) | the Technical Specification v1.0 |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | how the system is built and why |
| [docs/API.md](docs/API.md) | REST API reference |
| [docs/DECISIONS.md](docs/DECISIONS.md) | decision log and open business decisions |
| [docs/DATA_PROFILE.md](docs/DATA_PROFILE.md) | workbook profile (to be generated from the real file) |
| [docs/MIGRATION_MAPPING.md](docs/MIGRATION_MAPPING.md) | migration rules, matching, defaults, issue types |
| [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) | phases and their status |
