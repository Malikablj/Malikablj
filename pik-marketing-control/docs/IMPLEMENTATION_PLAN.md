# Implementation Plan

Loop for every phase: inspect → plan → implement → run → test → fix → verify → document.
A phase is complete only when its checks pass (`npm run lint`, `npm test`, `npm run test:e2e`
plus the phase checks).

| Phase | Scope | Verification | Status |
|---|---|---|---|
| 0 Foundation | repo audit, workbook search, workspace (`apps/api`, `apps/web`, `packages/shared`), env, health endpoint, error envelope, lint/test tooling, docs | `GET /api/health` 200; lint + tests pass | Done |
| 1 Database | `schema.sql` (all spec tables + lineage, FKs incl. composite, checks, indexes, triggers), `views.sql` (business calculations), `db:setup`, `db:seed` | schema tests: tables, lineage columns, every FK indexed, constraints, triggers; calculation tests | Done |
| 2 Migration engine | profile / dry-run / apply / verify, readiness gate, matching levels 1–4, issues, lineage, re-runs, backups | fixture workbook tests: counts, duplicates, ambiguity, unresolved refs, lineage, calculations, dry-run isolation, idempotency, app-edit protection, verify CLI in a fresh process | Done (awaiting real workbook) |
| 3 Authentication | login/logout/session/me/change password, sessions, scrypt, rate limit, CSRF header, permission middleware, user admin | API tests: valid/invalid login, inactive user, unauthorized/forbidden, role restrictions, security headers | Done |
| 4 App shell | sidebar, phone bottom navigation and drawer, header, global search, notifications, routing, toasts, confirm dialog, loading/empty/error states | build; E2E: every page, phone layout | Done |
| 5 Customers | list/search/filters, workspace with tabs, create/edit/archive/restore, contacts | API tests (CRUD, relationships); E2E | Done |
| 6 CRM | leads (list + Kanban with drag-and-drop), lead detail with pipeline, transitions | API tests (CRUD, transitions); E2E smoke | Done |
| 7 Activities | log activities per customer/contact/lead, timeline | API tests; E2E | Done |
| 8 Follow-ups | today/overdue/upcoming/done, complete, reschedule, notifications | API tests (states, summary); E2E smoke | Done |
| 9 Purchase orders | PO + lines (atomic), status rules, fulfillment, line edits | API tests (creation, outstanding, rules); E2E (figures) | Done |
| 10 Delivery & return | CRUD, PO/line/product consistency, over-delivery warning | API tests (fulfillment effects) | Done |
| 11 Products & stock | product master/detail, stock counts and history, lead time, inbound maklon | API tests; E2E | Done |
| 12 Dashboard | KPIs from SQL, pipeline, follow-ups, activities, open POs, delivery status | API tests; E2E | Done |
| 13 Reporting | report catalog, 7 reports with filters and totals, CSV | API tests (filters, timezone, CSV, labels) | Done |
| 14 Polish | responsive, accessibility, keyboard, states, no console errors | Playwright: all pages as Admin, read-only roles, phone layouts without horizontal scroll, zero console errors | Done |

## Blocked on external input

* **Real migration**: needs `PIK_Master_Database_AppSheet.xlsx` → then `migrate:profile`, update
  the mapping, `migrate:dry`, `migrate`, `migrate:verify` (see DATA_PROFILE.md "Next steps").
  `inbound_maklon` and `po_financials` keep a provisional structure until their sheets are
  profiled.
* **Business confirmations**: DECISIONS.md "Open business decisions" B1–B9.
