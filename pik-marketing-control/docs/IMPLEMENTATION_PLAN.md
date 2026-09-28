# Implementation Plan

Loop for every phase: inspect → plan → implement → run → test → fix → verify → document.
A phase is complete only when its checks pass (`npm run lint`, `npm test`, plus the phase checks).

| Phase | Scope | Verification | Status |
|---|---|---|---|
| 0 Foundation | repo audit, workbook search, workspace (`apps/api`, `apps/web`, `packages/shared`), env, health endpoint, error envelope, lint/test tooling, docs | `GET /api/health` 200; lint + tests pass | Done |
| 1 Database | `schema.sql` (all spec tables + lineage, FKs incl. composite, checks, indexes, triggers), `views.sql` (business calculations), `db:setup`, `db:seed` | schema tests: tables, lineage columns, every FK indexed, constraints, triggers; calculation tests | Done |
| 2 Migration engine | profile / dry-run / apply / verify, readiness gate, matching levels 1–4, issues, lineage, re-runs, backups | fixture workbook tests: counts, duplicates, ambiguity, unresolved refs, lineage, calculations, dry-run isolation, idempotency, app-edit protection | Done (awaiting real workbook) |
| 3 Authentication | login/logout/me/change password, sessions, scrypt, rate limit, CSRF header, permission middleware, user admin | API tests: valid/invalid login, inactive user, unauthorized/forbidden, role restrictions | Planned |
| 4 App shell | sidebar, mobile bottom navigation, header, global search, notifications, routing, toasts, loading/empty/error states | build + E2E smoke | Planned |
| 5 Customers | list/search/filters, detail workspace with tabs, create/edit/archive, contacts | API tests (CRUD, relationships) | Planned |
| 6 CRM | leads (list + Kanban), lead detail, status transitions | API tests (CRUD, transitions) | Planned |
| 7 Activities | log activities per customer/contact/lead | API tests | Planned |
| 8 Follow-ups | today/upcoming/overdue/done, complete, reschedule | API tests (states) | Planned |
| 9 Purchase orders | PO + lines (atomic), status, fulfillment | API tests (creation, outstanding) | Planned |
| 10 Delivery & return | CRUD, PO/line/product consistency | API tests (fulfillment effects) | Planned |
| 11 Products & stock | product master/detail, stock snapshots, lead time | API tests | Planned |
| 12 Dashboard | KPIs from SQL, pipeline, follow-ups, activities, open POs, delivery status | API tests | Planned |
| 13 Reporting | customer/lead/activity/follow-up/PO/delivery/stock reports + CSV | API tests | Planned |
| 14 Polish | responsive, accessibility, keyboard, states, console errors | Playwright smoke + screenshots at phone and desktop sizes | Planned |

## Blocked on external input

* **Real migration**: needs `PIK_Master_Database_AppSheet.xlsx` → then `migrate:profile`, update
  the mapping, `migrate:dry`, `migrate`, `migrate:verify` (see DATA_PROFILE.md "Next steps").
* **Business confirmations**: DECISIONS.md "Open business decisions" B1–B9.
