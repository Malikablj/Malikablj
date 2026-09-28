# API Reference

REST API of PIK Marketing Control. Base path: `/api`. All request and response bodies are JSON
(`snake_case`), except CSV report exports. The authoritative request schemas are the zod schemas
in `packages/shared/src/validation/` (shared with the web forms); list query schemas are in
`apps/api/src/validators/`.

## Conventions

### Authentication

`POST /api/auth/login` sets the `pik_session` cookie (`HttpOnly`, `SameSite=Lax`, `Secure` in
production). Browsers send it automatically; API clients must keep the cookie. Sessions live
`SESSION_TTL_HOURS` (default 7 days) and end immediately on logout, password change (other
sessions), deactivation or role change.

### CSRF

Every request other than `GET`/`HEAD`/`OPTIONS` must send

```
X-Requested-With: XMLHttpRequest
```

otherwise the API answers `403 CSRF_REJECTED`.

### Response envelope

```json
{ "success": true, "data": { … }, "meta": { … } }
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "details": { "fields": { "name": "Nama customer wajib diisi." } } } }
```

Messages are user-facing Bahasa Indonesia. `details.fields` maps field names (nested paths joined
with dots, e.g. `lines.0.order_quantity`) to messages.

| HTTP | `error.code` | Meaning |
|---|---|---|
| 400 | `VALIDATION_ERROR`, `BAD_REQUEST` | invalid body or query |
| 401 | `UNAUTHORIZED` | not signed in / session expired |
| 403 | `FORBIDDEN`, `CSRF_REJECTED`, `ACCOUNT_INACTIVE` | role may not do this / missing CSRF header / deactivated account |
| 404 | `NOT_FOUND` | unknown id or route |
| 409 | `CONFLICT` | unique value already used (e.g. email, customer code, PO number per customer) |
| 409 | `REFERENCE_ERROR` | referenced record missing, or still referenced by other data |
| 422 | `BUSINESS_RULE` | valid input that a business rule refuses (e.g. cancelling a PO with deliveries in transit) |
| 429 | `TOO_MANY_REQUESTS` | login rate limit |
| 503 | `DATABASE_UNAVAILABLE` | database unreachable |

### Lists

| Parameter | Description |
|---|---|
| `page`, `page_size` | 1-based page; size default 25, maximum 100 |
| `sort` | `field` ascending or `-field` descending, from the whitelist of each list |
| `q` | case-insensitive text search over the list's main columns |
| filters | per endpoint; lists of values are comma-separated (`?status=OPEN,PARTIAL`) |

List responses: `data` is an array, `meta` is `{ page, page_size, total, total_pages }` (plus
extras noted below). Unknown query parameters are ignored.

### Values

- Business dates are `YYYY-MM-DD`; times `HH:MM`; instants ISO 8601 with offset
  (`2026-09-28T09:00:00+07:00`), returned in UTC.
- "Today" (follow-up state, overdue, dashboards) is the date in `APP_TIMEZONE` (Asia/Jakarta).
- Money and quantities are JSON numbers; all arithmetic happens in SQL with exact `NUMERIC`.
- `PUT` is a partial update: omitted fields stay unchanged, `null` clears a field.
- Records are never physically deleted: `DELETE` archives (customers, contacts, products) and
  statuses cancel (POs, deliveries, returns, follow-ups, invoices). The only real delete is an
  unused PO line.
- Migrated records carry lineage: `source_file`, `source_sheet`, `legacy_row`, `legacy_key`,
  `source_data`, `migrated_at`.

### Permissions

Each endpoint lists the module it checks (`read` or `write`). The matrix is in
`packages/shared/src/permissions.js`; see the README for a summary. `OWN` access (Marketing on
purchase orders) allows writing only records whose `owner_user_id` is the caller.

## System and authentication

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/health` | public | `{ status, database, schema_version, timezone, today }`; 503 when the database is unreachable |
| POST | `/auth/login` | public | body `{ email, password }` → `{ user }`, meta `{ timezone, today }`. Rate limited: 10 failures per account+IP and 100 per IP per 15 minutes |
| POST | `/auth/logout` | any | ends the session |
| GET | `/auth/session` | public | `{ user }` or `{ user: null }` (always 200; used by the web app on start) |
| GET | `/auth/me` | signed in | `{ user }`, meta `{ timezone, today }`; 401 without a session |
| POST | `/auth/change-password` | signed in | `{ current_password, new_password }` (min. 10 characters); signs out other sessions |

## Users

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/users/options` | signed in | active users `{ id, name, role }` for owner/PIC pickers |
| GET | `/users` | USERS read | filters `role`, `is_active`; sort `name`, `email`, `role`, `last_login_at` |
| GET | `/users/:id` | USERS read | one user (never includes the password hash) |
| POST | `/users` | USERS write | `{ name*, email*, role*, password*, is_active }` |
| PUT | `/users/:id` | USERS write | `{ name, email, role, is_active }`; an Admin cannot deactivate or demote themselves or the last active Admin |
| POST | `/users/:id/reset-password` | USERS write | `{ password* }`; ends that user's sessions |

`*` = required.

## Customers and contacts

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/customers` | CUSTOMERS read | filters `status`, `industry`, `is_active` (default `true`); rows include primary contact, active leads, open POs and last activity |
| GET | `/customers/facets` | CUSTOMERS read | `{ industries }`: distinct industries of active customers, for filters |
| GET | `/customers/:id` | CUSTOMERS read | customer + `summary { contacts, active_leads, pipeline_value, open_purchase_orders, outstanding_quantity, overdue_follow_ups, last_activity_at, next_follow_up_date }` |
| POST | `/customers` | CUSTOMERS write | `{ name*, customer_code, industry, status, address, phone, email, website, notes }` |
| PUT | `/customers/:id` | CUSTOMERS write | same fields, partial |
| DELETE | `/customers/:id` | CUSTOMERS write | archive (`is_active = false`) |
| POST | `/customers/:id/restore` | CUSTOMERS write | un-archive |
| GET | `/customers/:customerId/contacts` | CONTACTS read | active contacts, primary first |
| POST | `/customers/:customerId/contacts` | CONTACTS write | `{ name*, position, phone, whatsapp, email, is_primary, notes }`; a new primary contact replaces the previous one |
| PUT | `/contacts/:id` | CONTACTS write | partial update |
| DELETE | `/contacts/:id` | CONTACTS write | archive |

Customer statuses: `ACTIVE`, `POTENTIAL`, `DORMANT`, `INACTIVE`.

## Leads

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/leads` | LEADS read | filters `status`, `priority`, `customer_id`, `owner_user_id`, `closing_from`, `closing_to`, `source`; sort `name`, `customer_name`, `status`, `estimated_value`, `expected_closing_date`, `created_at`, `updated_at` (default `-updated_at`) |
| GET | `/leads/board` | LEADS read | same filters; per status `{ status, count, total_value, leads[] }` (≤ 50 cards per column) |
| GET | `/leads/:id` | LEADS read | lead with customer, contact, product, owner names and next follow-up |
| POST | `/leads` | LEADS write | `{ customer_id*, name*, contact_id, product_id, source, estimated_value, status, priority, owner_user_id, expected_closing_date, notes, lost_reason }` |
| PUT | `/leads/:id` | LEADS write | partial; status changes follow the same rules as below |
| PATCH | `/leads/:id/status` | LEADS write | `{ status*, lost_reason }` |

Statuses `NEW → CONTACTED → QUALIFIED → QUOTATION → NEGOTIATION → WON | LOST | DORMANT`. Any
stage may move to any other, except: `LOST` needs `lost_reason`; a `WON` lead can only be
reopened by an Admin (422 otherwise). The contact must belong to the lead's customer.

## Activities

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/activities` | ACTIVITIES read | filters `type`, `customer_id`, `contact_id`, `lead_id`, `owner_user_id`, `from`, `to` (business dates); newest first |
| GET | `/activities/:id` | ACTIVITIES read | |
| POST | `/activities` | ACTIVITIES write | `{ customer_id*, type*, subject*, activity_at*, contact_id, lead_id, description, owner_user_id }` |
| PUT | `/activities/:id` | ACTIVITIES write | partial |

Types: `WHATSAPP`, `CALL`, `EMAIL`, `MEETING`, `VISIT`, `QUOTATION`, `SAMPLE`, `PRESENTATION`,
`FOLLOW_UP`, `COMPLAINT`, `NOTE`, `OTHER`.

## Follow-ups

Every follow-up row includes a derived `state`: `OVERDUE`, `TODAY`, `UPCOMING`, `DONE`,
`CANCELLED` (computed by `follow_up_state()` with the business date).

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/follow-ups` | FOLLOW_UPS read | filters `state`, `priority`, `customer_id`, `lead_id`, `owner_user_id`, `from`, `to`; rows include customer, lead, owner and contact phone |
| GET | `/follow-ups/summary` | FOLLOW_UPS read | `{ today, overdue, upcoming, upcoming_7_days, done_last_7_days }`; `?owner=me` or `?owner_user_id=` |
| GET | `/follow-ups/:id` | FOLLOW_UPS read | |
| POST | `/follow-ups` | FOLLOW_UPS write | `{ customer_id*, follow_up_date*, follow_up_time, lead_id, activity_id, owner_user_id, priority, status, notes }` |
| PUT | `/follow-ups/:id` | FOLLOW_UPS write | partial |
| POST | `/follow-ups/:id/complete` | FOLLOW_UPS write | `{ outcome }` → status `DONE`, outcome appended to notes |
| POST | `/follow-ups/:id/reschedule` | FOLLOW_UPS write | `{ follow_up_date*, follow_up_time, notes }`; date must be today or later; the old date is kept in the notes |

## Products

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/products` | PRODUCTS read | filters `category`, `customer_id`, `status`, `is_active` (default `true`); rows include current stock per type |
| GET | `/products/facets` | PRODUCTS read | `{ categories, units }` |
| GET | `/products/:id` | PRODUCTS read | product + `stock[]` (current per type/warehouse), `lead_times[]`, `open_order_lines[]` |
| POST | `/products` | PRODUCTS write | `{ name*, product_code, category, customer_id, description, unit, lead_time_days, status }` |
| PUT | `/products/:id` | PRODUCTS write | partial |
| DELETE | `/products/:id` | PRODUCTS write | archive |
| POST | `/products/:id/restore` | PRODUCTS write | un-archive |

## Purchase orders

Quantities come from database views: per line `delivered_quantity` (deliveries `DELIVERED`),
`in_progress_quantity` (`SCHEDULED`, `ON_DELIVERY`, `DELAYED`), `returned_quantity` (returns
`RECEIVED`, `RESOLVED`) and `outstanding_quantity = MAX(0, ordered − delivered + returned)`; the
PO totals are sums of its lines. `is_late` = open PO with outstanding quantity past its
`expected_delivery_date`.

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/purchase-orders` | PURCHASE_ORDERS read | filters `status`, `customer_id`, `owner_user_id`, `po_date_from/to`, `expected_from/to`, `has_outstanding`, `late`; sort `po_date`, `po_number`, `customer_name`, `expected_delivery_date`, `outstanding_quantity`, `total_value`, `status` |
| GET | `/purchase-orders/:id` | PURCHASE_ORDERS read | header + totals, `lines[]`, `deliveries[]`, `returns[]`, `can_edit`; plus `invoices[]` and `financials[]` when the role may read finance |
| POST | `/purchase-orders` | PURCHASE_ORDERS write | `{ po_number*, customer_id*, po_date, expected_delivery_date, owner_user_id, notes, lines*: [{ product_id*, order_quantity*, unit, unit_price, notes }] }`, created atomically |
| PUT | `/purchase-orders/:id` | owner or RW | header fields, partial; the customer cannot change once deliveries, returns or invoices exist |
| PATCH | `/purchase-orders/:id/status` | owner or RW | `{ status*, cancel_reason }` |
| POST | `/purchase-orders/:id/lines` | owner or RW | add a line → full PO detail |
| PUT | `/po-lines/:id` | owner or RW | partial; the product cannot change once the line has deliveries or returns → full PO detail |
| DELETE | `/po-lines/:id` | owner or RW | only lines without deliveries/returns, never the last line → full PO detail |

Statuses `OPEN`, `ON_PROCESS`, `PARTIAL`, `CLOSED`, `CANCELLED`. Cancelling needs
`cancel_reason` and is refused while deliveries are `SCHEDULED` or `ON_DELIVERY`; only an Admin
can reopen a cancelled PO (to `OPEN`). A line's unit defaults to the product unit.

## Deliveries and returns

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/deliveries` | DELIVERIES read | filters `status`, `customer_id`, `purchase_order_id`, `product_id`, `from`, `to` |
| GET | `/deliveries/:id` | DELIVERIES read | |
| POST | `/deliveries` | DELIVERIES write | `{ purchase_order_id*, po_line_id*, delivery_date*, quantity*, status*, delivery_number, notes }` |
| PUT | `/deliveries/:id` | DELIVERIES write | partial |
| GET | `/returns` | RETURNS read | filters `status`, `customer_id`, `purchase_order_id`, `product_id`, `from`, `to` |
| GET | `/returns/:id` | RETURNS read | |
| POST | `/returns` | RETURNS write | `{ customer_id*, product_id*, return_date*, quantity*, reason*, status*, purchase_order_id, po_line_id, return_number, notes }` |
| PUT | `/returns/:id` | RETURNS write | partial |

A delivery's product always comes from its PO line (the line must belong to the PO; cancelled
POs are refused). Delivering more than the line's outstanding quantity is allowed but returned
as `meta.warnings[]`. A return's PO must belong to its customer and its line to that PO and
product. Delivery statuses: `SCHEDULED`, `ON_DELIVERY`, `DELIVERED`, `DELAYED`, `CANCELLED`.
Return statuses: `REPORTED`, `RECEIVED`, `RESOLVED`, `CANCELLED`.

## Stock, lead time, inbound maklon

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/stock` | STOCK read | current stock: latest count per product, type and warehouse; filters `stock_type`, `warehouse`, `product_id` |
| GET | `/stock/history` | STOCK read | every count, newest first; same filters |
| GET | `/stock/overview` | STOCK read | `{ totals: [{ stock_type, unit, quantity, entries }], warehouses[] }` |
| GET | `/stock/:id` | STOCK read | |
| POST | `/stock` | STOCK write | `{ product_id*, stock_type*, quantity*, stock_date*, warehouse, notes }` (a new count) |
| PUT | `/stock/:id` | STOCK write | correct a count |
| GET/POST | `/lead-times` | LEAD_TIME read/write | `{ lead_time_days*, product_id, customer_id, notes }` (product or customer required) |
| GET/PUT | `/lead-times/:id` | LEAD_TIME read/write | |
| GET/POST | `/inbound-maklon` | INBOUND_MAKLON read/write | `{ inbound_date*, quantity*, customer_id, purchase_order_id, product_id, item_name, unit, document_number, notes }` (product or item name required); filters `customer_id`, `purchase_order_id`, `from`, `to` |
| GET/PUT | `/inbound-maklon/:id` | INBOUND_MAKLON read/write | |

Stock types: `FG`, `WIP`, `READY`, `RESERVED`. The inbound maklon structure is provisional
until the source sheet is profiled.

## Finance

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/invoices` | FINANCE read | filters `payment_status`, `customer_id`, `purchase_order_id`, `overdue`, `from`, `to`; `meta.totals { amount, paid_amount, open_balance, overdue_balance }` for the whole filter |
| GET | `/invoices/:id` | FINANCE read | includes `balance`, `is_overdue` |
| POST | `/invoices` | FINANCE write | `{ purchase_order_id*, invoice_number*, invoice_date*, amount*, due_date, paid_amount, payment_date, is_cancelled, notes }` |
| PUT | `/invoices/:id` | FINANCE write | partial |
| GET/POST | `/po-financials` | FINANCE read/write | PO financial summary `{ purchase_order_id*, currency, po_value, tax_amount, total_amount, invoiced_amount, paid_amount, outstanding_amount, notes }` (provisional structure) |
| GET/PUT | `/po-financials/:id` | FINANCE read/write | |

`payment_status` is derived: `UNPAID` (nothing paid), `PARTIAL`, `PAID` (paid ≥ amount), or
`CANCELLED` when `is_cancelled` is true.

## Dashboard, search, notifications

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/dashboard/summary` | DASHBOARD read | `?scope=me\|all`. `{ today, kpis { total_customers, active_leads, pipeline_value, follow_up_today, follow_up_overdue, follow_up_upcoming, open_purchase_orders, late_purchase_orders, outstanding_quantity: [{ unit, quantity }] }, pipeline[], follow_ups { today[], overdue[], upcoming[], totals }, recent_activities[], open_purchase_orders[], deliveries { by_status[], upcoming[] } }` |
| GET | `/search?q=` | signed in | at least 2 characters; up to 5 results per group (customers, contacts, leads, purchase orders, products) the role may read: `[{ group, items: [{ id, title, subtitle, url }] }]` |
| GET | `/notifications` | signed in | derived live for the caller: own follow-ups today/overdue, own late POs, open migration errors (Admin): `{ count, items: [{ type, severity, title, subtitle, date, url }] }` |

Outstanding quantity is reported per unit because quantities in different units are never added.

## Reports

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/reports` | REPORTS read | catalog of reports the role may open: `[{ type, title, filters { date, customer, owner, status { label, options } }, columns[], total_columns[] }]` |
| GET | `/reports/:type` | REPORTS read + the report's module | filters `q`, `from`, `to`, `customer_id`, `owner_user_id`, `status` (per report) and paging → rows, `meta { columns, total_columns, totals, title, … }` |
| GET | `/reports/:type?format=csv` | same | CSV download of every matching row (max. 20,000; header `X-Report-Truncated: true` when cut) |

Types: `customers`, `leads`, `activities`, `follow-ups`, `purchase-orders`, `deliveries`,
`stock`. Columns declare a `type` (`money`, `number`, `date`, `time`, `timestamp`) and `labels`
for coded values; CSV uses the labels and shows timestamps in the business timezone. CSV is
UTF-8 with BOM, comma separated; cells starting with `= + - @` are prefixed with `'`.

## Migration issues

| Method | Path | Access | Description |
|---|---|---|---|
| GET | `/migration-issues` | MIGRATION read | filters `resolution_status`, `severity`, `issue_type`, `entity_type`; errors first |
| GET | `/migration-issues/summary` | MIGRATION read | `{ counts: [{ resolution_status, severity, count }], open_by_type[], last_run }` |
| PUT | `/migration-issues/:id` | MIGRATION write | `{ resolution_status*: OPEN\|RESOLVED\|IGNORED, resolution_notes }`; records who resolved it and when |
