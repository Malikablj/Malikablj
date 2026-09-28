# Migration Mapping: Workbook → PostgreSQL

> **Mapping status: PROVISIONAL.** The source workbook `PIK_Master_Database_AppSheet.xlsx`
> has not been provided (see [DATA_PROFILE.md](DATA_PROFILE.md)). Sheet names follow the entity
> list of the Technical Specification; **column names are placeholders**. `npm run migrate`
> refuses to run until the mapping is verified against the real workbook and marked `VERIFIED`.
> The engine itself is complete and tested against a synthetic fixture workbook
> (`tests/migration/`).

The authoritative mapping is [`migration/mapping/workbook.mapping.js`](../migration/mapping/workbook.mapping.js).
Target-side rules (types, required fields, relationships) live in
[`migration/scripts/lib/entities.js`](../migration/scripts/lib/entities.js).

## 1. Pipeline

```text
workbook (read-only)
  → npm run migrate:profile   profile sheets/columns → docs/DATA_PROFILE.md
  → edit mapping              real sheet/column names, value maps, formats → status VERIFIED
  → npm run migrate:dry       full import inside a transaction that is ROLLED BACK
  → review migration/reports/ migration-summary.json + migration-issues.csv
  → npm run migrate           pg_dump backup, then the same import COMMITTED as one transaction
  → npm run migrate:verify    independent checks against the database
  → resolve issues            Pengaturan → Migration Issues (in the app)
```

## 2. Readiness gate (runs before any data is touched)

| Check | Result when it fails |
|---|---|
| Every mapped sheet exists | error, entity not run |
| Every mapped column (fields, keys, references) exists in its sheet | error, entity not run |
| Every workbook sheet is mapped **or** listed in `ignoredSheets` with a reason | error |
| Value-map targets and defaults are valid codes | error |
| Required target fields and required relationships are mapped | error |
| Mapping status is `VERIFIED` | `migrate` blocked (dry run allowed) |
| Entity without a key | warning: row+content fallback key |
| Duplicate headers, merged cells in data, values in unlabelled columns | warning |

`migrate` (apply) is blocked on any error. `migrate:dry` skips entities with errors and reports them.

## 3. Import order and relationships

```text
customers ─┬─ contacts
           ├─ products (optional customer)
           ├─ leads ────────── (contact, product, owner)
           ├─ activities ───── (contact, lead, owner)
           ├─ follow_ups ───── (lead, activity, owner)
           └─ purchase_orders ─ po_lines ─┬─ deliveries
                        │                 └─ returns (customer; PO and line optional)
                        ├─ invoices_payments
                        ├─ po_financials
                        └─ inbound_maklon (customer / PO / product, all optional)
products ─┬─ stock
          └─ leadtime (product and/or customer)
users ─── owner (PIC) of leads, activities, follow-ups, purchase orders
```

Owners are matched to **existing application users** by email or normalized name. Create the
users (Pengaturan → Users) before running the migration; unmatched PIC values leave the owner
empty and raise an `INFO` issue.

## 4. Matching rules (references)

Strategies are listed per reference in the mapping and tried in order:

| Level | Strategy | Matches | Example |
|---|---|---|---|
| 1 | `key` | legacy key of an imported row (e.g. AppSheet ID column) | `PO Lines.PO ID` → `Purchase Orders.ID` |
| 1 | `code` | `customer_code` / `product_code`, trimmed, case-insensitive | `Product Code` |
| 2 | `name` | `normalize_key()` of the name (case, punctuation, whitespace); contacts/leads are searched within the record's customer | `"PT. Maju  Jaya"` = `"pt maju jaya"` |
| 2 | `user` | user email or normalized user name | `PIC` |
| 3 | `po_number` | PO number, restricted to the record's customer (or `customerColumn`) | `PO Number` + `Customer Name` |
| 3 | (automatic) | delivery/return → PO line by (PO, product) when exactly one line matches | |
| 4 | (never) | several candidates are **never** chosen automatically | issue `AMBIGUOUS_REFERENCE` with all candidates |

* An empty source cell skips the strategy. A value that finds nothing falls through to the next
  strategy (reported as `REFERENCE_FALLBACK` if a later strategy matches).
* Several candidates stop resolution: a weaker level cannot safely break the tie.
* Unresolved **required** reference (e.g. contact → customer): row not imported, `ERROR` issue
  with the full source row.
* Unresolved **optional** reference: field left empty, `WARNING` (owner: `INFO`).
* Unresolved **product** on PO lines, deliveries, returns, stock, lead time, inbound maklon:
  the record is still imported with `product_id` empty and the source text in `item_name`, so
  quantities keep counting; `WARNING UNRESOLVED_PRODUCT`. An admin links the product later.

## 5. Transformations

| Target type | Rule |
|---|---|
| text | trimmed; blank → NULL; non-breaking spaces removed; emails lower-cased; longer than the column → truncated with `WARNING VALUE_TRUNCATED` (original kept in `source_data`); phone numbers stored as Excel numbers → `WARNING NUMBER_AS_TEXT` (leading zero lost) |
| number | real Excel numbers as-is; text numbers only when unambiguous (`1500.5`) or when `formats.number` is `'id'` (`1.250.000,50`) or `'en'` (`1,250,000.50`); `"1.500"` without a format → `AMBIGUOUS_NUMBER` |
| date | real Excel dates; ISO text; month names (Indonesian/English); `DD/MM/YYYY` vs `MM/DD/YYYY` only when unambiguous or declared via `formats.date`; otherwise `AMBIGUOUS_DATE` |
| datetime | Excel stores wall-clock time without a zone: interpreted in `APP_TIMEZONE` (Asia/Jakarta). Activities may combine a date column and a time column |
| boolean | `Ya/Yes/Y/1/TRUE/x` and `Tidak/No/N/0/FALSE` |
| enum | `valueMap` (labels compared after normalization), or the code itself; unknown label → mapping default + `WARNING UNMAPPED_VALUE` |
| quantity < 0 | row not imported (`ERROR INVALID_VALUE`); optional amounts < 0 are cleared with a warning |

Formula cells import their cached result. Error cells (`#N/A`, ...) import as empty and are
counted in the profile.

### Documented defaults (applied only when the source cell is empty or the column is absent)

| Field | Default | Reason |
|---|---|---|
| customers.status | `ACTIVE` | spec default |
| products.status | `ACTIVE` | master data in use |
| leads.status | `NEW` | pipeline start |
| activities.type | `OTHER` | neutral category |
| follow_ups.status | `PLANNED` | not yet done |
| purchase_orders.status | `OPEN` | status is managed in the app afterwards |
| deliveries.status | `DELIVERED` | legacy delivery rows record deliveries that happened; **confirm with the business** |
| returns.status | `RECEIVED` | legacy return rows record goods that came back; **confirm with the business** |
| contacts.is_primary | `false` | |
| invoices amount / paid | `0` | |
| activities.subject | first line of the description, else the type label | `INFO DERIVED_VALUE` |
| invoices_payments.payment_status | derived from amount vs. paid (`CANCELLED` kept) | contradicting source status → `WARNING INCONSISTENT_VALUE` |

## 6. Duplicate strategy

| Situation | Handling |
|---|---|
| Rows with the same declared key and identical mapped values | imported once; `INFO DUPLICATE_ROW` (suppressed with `allowRepeatedKeys`, e.g. PO headers repeated on line rows) |
| Same declared key, different values | first row imported; others **not imported**, `ERROR DUPLICATE_KEY_CONFLICT` with the conflicting row: a person decides |
| Identical rows in a sheet without a key (e.g. two deliveries on the same day) | **both imported** (could be legitimate), `INFO POSSIBLE_DUPLICATE` |
| Two master records with the same normalized name | both imported; any reference by that name becomes `AMBIGUOUS_REFERENCE` |

## 7. Lineage strategy

Every migrated row stores `source_file`, `source_sheet`, `legacy_row` (Excel row number),
`legacy_key`, `source_data` (the complete source row, **including unmapped columns**) and
`migrated_at`. Every issue stores the sheet, row and the full source row. Each applied run is
recorded in `migration_runs` and every processed record with its outcome in
`migration_run_records`.

## 8. Re-run strategy

* Records are upserted on `legacy_key`: `mapping.key` columns (preferably a stable ID), or a
  fallback of row number + content hash. Re-running an unchanged workbook changes nothing.
* A record edited in the application after its last migration (`updated_at > migrated_at`) is
  **never overwritten**; `INFO MODIFIED_IN_APP`.
* Changed source rows update their record (including lineage).
* Issues are fingerprinted: re-runs update them instead of duplicating them. Issues that no
  longer occur are auto-resolved; decisions made by an admin (resolved/ignored) are kept.
* Records from earlier runs that are no longer in the workbook are **not deleted**;
  `migrate:verify` reports them as "stale" for review.

## 9. Rollback strategy

1. `migrate` runs as **one transaction**: any unexpected failure rolls back everything.
   Individual bad rows are isolated with savepoints and reported, not fatal.
2. Before applying, `migrate` writes `pg_dump` to `migration/backups/pre-migration-<timestamp>.dump`
   (abort if the backup fails; `--no-backup` only when a backup was taken manually).
3. To undo an applied migration: `pg_restore --clean --if-exists -d "$DATABASE_URL" <dump>`
   (restores the whole database to its pre-migration state).

## 10. Issue types

| Type | Severity | Meaning | Typical resolution |
|---|---|---|---|
| `MISSING_REQUIRED` | ERROR | required value empty/invalid, row not imported | fix the source and re-run, or create the record in the app |
| `UNRESOLVED_REFERENCE` | ERROR/WARNING/INFO | referenced record not found | fix the reference or create the missing master record |
| `AMBIGUOUS_REFERENCE` | ERROR/WARNING | several candidates | make names unique / use codes, or link manually |
| `DUPLICATE_KEY_CONFLICT` | ERROR | same key, different content | decide which row is correct |
| `DUPLICATE_ROW` / `POSSIBLE_DUPLICATE` | INFO | identical rows | confirm |
| `AMBIGUOUS_DATE` / `AMBIGUOUS_NUMBER` | WARNING/ERROR | text value readable in two ways | declare `formats` in the mapping |
| `INVALID_VALUE` / `INVALID_DATE` / `INVALID_NUMBER` / `INVALID_TIME` / `INVALID_BOOLEAN` | WARNING/ERROR | unreadable or out-of-range value | fix the source |
| `UNMAPPED_VALUE` | WARNING | status/type label not in the value map | extend `valueMap` |
| `UNRESOLVED_PRODUCT` | WARNING | product kept as text | link the product in the app |
| `UNALLOCATED_TO_LINE` | INFO | delivery/return not tied to one PO line | allocate in the app |
| `INCONSISTENT_VALUE` | WARNING | source status contradicts amounts | check the source |
| `MULTIPLE_PRIMARY_CONTACTS` | WARNING | second primary contact demoted | choose the primary contact |
| `NUMBER_AS_TEXT` / `VALUE_TRUNCATED` | WARNING | data-quality notes | check the value |
| `REFERENCE_FALLBACK` / `DERIVED_VALUE` / `NO_KEY_VALUE` | WARNING/INFO | how a value was obtained | review |
| `MODIFIED_IN_APP` | INFO | not overwritten because edited in the app | none |
| `DATABASE_REJECTED` | ERROR | the database refused the row (constraint) | read the message |

## 11. Provisional sheet mapping

| Target | Sheet (provisional) | Key (provisional) | Notes |
|---|---|---|---|
| customers | `Customers` | Customer Code, else Customer Name | |
| contacts | `Contacts` | none (row+content) | customer by code, then name |
| products | `Products` | Product Code | |
| leads | `Leads` | none | customer, contact, product, owner (PIC) |
| activities | `Activities` | none | date + optional time columns |
| follow_ups | `Follow Ups` | none | |
| purchase_orders | `Purchase Orders` | Customer Name + PO Number | |
| po_lines | `PO Lines` | none | PO by number + customer; product by code, then name |
| deliveries | `Deliveries` | none | line derived from (PO, product) |
| returns | `Returns` | none | customer derived from the PO when missing |
| stock | `Stock` | none | either `Stock Type` + `Qty`, or one column per type (`quantityColumns`) |
| leadtime | `Lead Time` | none | |
| inbound_maklon | `Inbound Maklon` | none | table structure provisional |
| invoices_payments | `Invoices Payments` | Invoice Number | |
| po_financials | `PO Financials` | none | table structure provisional; summary only |

## 12. Questions to settle when the workbook arrives

1. Does every AppSheet table have a key column (e.g. `ID`/`Row ID`)? Use it as `key` and use
   `via: 'key'` references. This is the most robust re-run strategy.
2. Are dates real Excel dates or text? If text: `DD/MM/YYYY` or AppSheet's default `MM/DD/YYYY`?
3. Are PO lines a separate sheet, or is the PO sheet one row per item (use `allowRepeatedKeys`)?
4. Stock layout: one row per product and stock type, or one column per type?
5. Do legacy delivery/return rows have a status? If not, confirm the `DELIVERED` / `RECEIVED` defaults.
6. Which legacy columns hold computed values (delivered, outstanding, PO value) to list in
   `reconcile` so `migrate:verify` can compare them with the database calculations?
7. PIC names: which application users do they correspond to?
