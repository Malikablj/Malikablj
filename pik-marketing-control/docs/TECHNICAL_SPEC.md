<!-- Verbatim copy of Part A of the "PIK Marketing Control - Technical Specification + Claude Code Master Prompt" v1.0 supplied by the product owner. Do not edit: implementation deviations and interpretations are recorded in docs/DECISIONS.md. -->

# PIK Marketing Control
## Technical Specification + Claude Code Master Prompt
**Version:** 1.0  
**Company:** PT Permata Indo Kemas  
**Product:** PIK Marketing Control  
**Target:** Internal Marketing / Sales / Management  
**Primary language:** Bahasa Indonesia  
**Frontend:** React + JavaScript + Vite  
**Backend:** Node.js + Express.js  
**Database:** PostgreSQL  
**API:** REST + JSON  
**Deployment target:** Cloud-ready Web App / PWA

---

# PART A — TECHNICAL SPECIFICATION

## 1. Engineering Principles

1. Build a real working application, not a static prototype.
2. JavaScript only; do not introduce TypeScript.
3. Prefer mature, widely-used libraries.
4. Keep architecture simple and understandable.
5. Separate frontend, backend, database, migration, and shared contracts.
6. Never hardcode business data into UI components.
7. Never expose database credentials to the browser.
8. Validate on both client and server.
9. Enforce authorization on the backend.
10. Preserve source lineage for migrated legacy records.
11. Never guess ambiguous relationships during migration.
12. Prefer soft-delete/archive for important transactional records.
13. Every feature must have loading, empty, error, and success states.
14. Every destructive action requires confirmation.
15. Do not add dependencies unless they materially simplify the implementation.

## 2. Recommended Project Structure

```text
pik-marketing-control/
├── README.md
├── .env.example
├── .gitignore
├── package.json
├── docker-compose.yml
├── apps/
│   ├── web/
│   │   ├── index.html
│   │   ├── vite.config.js
│   │   └── src/
│   │       ├── main.jsx
│   │       ├── App.jsx
│   │       ├── routes/
│   │       ├── pages/
│   │       ├── components/
│   │       ├── layouts/
│   │       ├── services/
│   │       ├── hooks/
│   │       ├── context/
│   │       ├── utils/
│   │       ├── styles/
│   │       └── assets/
│   └── api/
│       ├── src/
│       │   ├── server.js
│       │   ├── app.js
│       │   ├── config/
│       │   ├── middleware/
│       │   ├── routes/
│       │   ├── controllers/
│       │   ├── services/
│       │   ├── repositories/
│       │   ├── validators/
│       │   ├── utils/
│       │   └── db/
│       └── migrations/
├── database/
│   ├── schema.sql
│   ├── seeds/
│   └── views.sql
├── migration/
│   ├── source/
│   ├── scripts/
│   ├── mapping/
│   ├── reports/
│   └── README.md
├── docs/
│   ├── PRD.md
│   ├── TECHNICAL_SPEC.md
│   └── API.md
└── tests/
    ├── api/
    ├── migration/
    └── e2e/
```

## 3. Core Dependencies

Keep the stack intentionally conventional.

### Frontend
- React
- React Router
- Native fetch or a small HTTP client
- A lightweight chart library only where reporting requires it
- CSS / CSS Modules

### Backend
- Node.js
- Express
- PostgreSQL driver
- Password hashing library
- Authentication/session library or secure JWT implementation
- Input validation library
- XLSX parser for migration
- dotenv

Do not add Redux, GraphQL, a large UI framework, or an ORM unless a concrete implementation problem requires it.

## 4. Environment Variables

```env
NODE_ENV=development
PORT=4000
DATABASE_URL=
SESSION_SECRET=
CORS_ORIGIN=http://localhost:5173
```

Never commit `.env`.

Provide `.env.example`.

## 5. Database Conventions

- PostgreSQL.
- UUID primary keys are preferred for application-generated records.
- Use `created_at`, `updated_at` for major entities.
- Use `created_by`, `updated_by` where relevant.
- Use `is_active` for master-data archival.
- Use numeric types for quantities and monetary values.
- Never store calculated outstanding values as the only source of truth when they can be derived safely from transactions.
- Add indexes for foreign keys, status fields, dates, PO numbers, customer names, and common search fields.

## 6. Database Schema

### users
```text
id UUID PK
name VARCHAR(150) NOT NULL
email VARCHAR(255) UNIQUE NOT NULL
password_hash TEXT NOT NULL
role VARCHAR(30) NOT NULL
is_active BOOLEAN DEFAULT TRUE
created_at TIMESTAMP
updated_at TIMESTAMP
```

Roles:
```text
ADMIN
MARKETING
SALES
MANAGEMENT
VIEWER
```

### customers
```text
id UUID PK
customer_code VARCHAR(100) UNIQUE
name VARCHAR(255) NOT NULL
industry VARCHAR(150)
address TEXT
phone VARCHAR(100)
email VARCHAR(255)
website VARCHAR(255)
status VARCHAR(30)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
is_active BOOLEAN DEFAULT TRUE
created_at TIMESTAMP
updated_at TIMESTAMP
```

Customer statuses:
```text
ACTIVE
INACTIVE
POTENTIAL
DORMANT
```

### contacts
```text
id UUID PK
customer_id UUID FK customers(id)
name VARCHAR(150) NOT NULL
position VARCHAR(150)
phone VARCHAR(100)
email VARCHAR(255)
whatsapp VARCHAR(100)
is_primary BOOLEAN DEFAULT FALSE
notes TEXT
created_at TIMESTAMP
updated_at TIMESTAMP
```

### products
```text
id UUID PK
product_code VARCHAR(100)
name VARCHAR(255) NOT NULL
category VARCHAR(150)
customer_id UUID FK customers(id) NULL
description TEXT
unit VARCHAR(50)
lead_time_days INTEGER
status VARCHAR(30)
is_active BOOLEAN DEFAULT TRUE
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

### leads
```text
id UUID PK
customer_id UUID FK customers(id)
contact_id UUID FK contacts(id) NULL
product_id UUID FK products(id) NULL
name VARCHAR(255) NOT NULL
source VARCHAR(100)
estimated_value NUMERIC(18,2)
status VARCHAR(30) NOT NULL
priority VARCHAR(30)
owner_user_id UUID FK users(id)
expected_closing_date DATE
notes TEXT
created_at TIMESTAMP
updated_at TIMESTAMP
```

Lead statuses:
```text
NEW
CONTACTED
QUALIFIED
QUOTATION
NEGOTIATION
WON
LOST
DORMANT
```

### activities
```text
id UUID PK
customer_id UUID FK customers(id)
contact_id UUID FK contacts(id) NULL
lead_id UUID FK leads(id) NULL
type VARCHAR(50) NOT NULL
subject VARCHAR(255) NOT NULL
description TEXT
owner_user_id UUID FK users(id)
activity_at TIMESTAMP NOT NULL
created_at TIMESTAMP
updated_at TIMESTAMP
```

### follow_ups
```text
id UUID PK
customer_id UUID FK customers(id)
lead_id UUID FK leads(id) NULL
activity_id UUID FK activities(id) NULL
owner_user_id UUID FK users(id)
follow_up_date DATE NOT NULL
follow_up_time TIME NULL
priority VARCHAR(30)
status VARCHAR(30) NOT NULL
notes TEXT
created_at TIMESTAMP
updated_at TIMESTAMP
```

Follow-up statuses:
```text
PLANNED
DONE
RESCHEDULE
CANCELLED
OVERDUE
```

### purchase_orders
```text
id UUID PK
po_number VARCHAR(150) NOT NULL
customer_id UUID FK customers(id)
po_date DATE
expected_delivery_date DATE
status VARCHAR(30)
owner_user_id UUID FK users(id)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
UNIQUE(customer_id, po_number)
```

PO statuses:
```text
OPEN
ON_PROCESS
PARTIAL
CLOSED
CANCELLED
```

### po_lines
```text
id UUID PK
purchase_order_id UUID FK purchase_orders(id)
product_id UUID FK products(id)
order_quantity NUMERIC(18,3)
unit VARCHAR(50)
unit_price NUMERIC(18,2)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

### deliveries
```text
id UUID PK
purchase_order_id UUID FK purchase_orders(id)
po_line_id UUID FK po_lines(id) NULL
product_id UUID FK products(id)
delivery_date DATE
quantity NUMERIC(18,3)
status VARCHAR(30)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

Delivery statuses:
```text
SCHEDULED
ON_DELIVERY
DELIVERED
DELAYED
CANCELLED
```

### returns
```text
id UUID PK
purchase_order_id UUID FK purchase_orders(id) NULL
po_line_id UUID FK po_lines(id) NULL
product_id UUID FK products(id)
return_date DATE
quantity NUMERIC(18,3)
reason TEXT
status VARCHAR(30)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

### stock
```text
id UUID PK
product_id UUID FK products(id)
stock_type VARCHAR(30)
quantity NUMERIC(18,3)
warehouse VARCHAR(150)
stock_date DATE
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

### leadtime
```text
id UUID PK
product_id UUID FK products(id) NULL
customer_id UUID FK customers(id) NULL
lead_time_days INTEGER
notes TEXT
created_at TIMESTAMP
updated_at TIMESTAMP
```

### inbound_maklon
Create according to the source workbook's actual columns after migration profiling. Preserve all source lineage fields.

### invoices_payments
```text
id UUID PK
purchase_order_id UUID FK purchase_orders(id)
invoice_number VARCHAR(150)
invoice_date DATE
due_date DATE
amount NUMERIC(18,2)
paid_amount NUMERIC(18,2) DEFAULT 0
payment_status VARCHAR(30)
notes TEXT
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
created_at TIMESTAMP
updated_at TIMESTAMP
```

### po_financials
Create normalized fields from source workbook; treat this as a financial summary, not the only source of transaction truth.

### migration_issues
```text
id UUID PK
entity_type VARCHAR(100)
source_file TEXT
source_sheet TEXT
legacy_row INTEGER
issue_type VARCHAR(100)
description TEXT
candidate_reference TEXT
resolution_status VARCHAR(30) DEFAULT 'OPEN'
resolved_by UUID FK users(id) NULL
resolved_at TIMESTAMP NULL
created_at TIMESTAMP
```

## 7. Derived Business Logic

### PO line delivered quantity
```sql
SUM(deliveries.quantity)
```
for the matching `po_line_id`.

### PO line returned quantity
```sql
SUM(returns.quantity)
```
for the matching `po_line_id`.

### Outstanding quantity
Default business rule:

```text
outstanding =
MAX(0, order_quantity - delivered_quantity + return_quantity)
```

This formula must be reviewed if the business treats returns differently.

### Follow-up overdue
```text
follow_up_date < today
AND status NOT IN ('DONE', 'CANCELLED')
```

### Follow-up today
```text
follow_up_date = today
AND status NOT IN ('DONE', 'CANCELLED')
```

## 8. API Contract

Base:
```text
/api
```

Authentication:
```text
POST /api/auth/login
POST /api/auth/logout
GET  /api/auth/me
```

Customers:
```text
GET    /api/customers
GET    /api/customers/:id
POST   /api/customers
PUT    /api/customers/:id
DELETE /api/customers/:id
```

Contacts:
```text
GET  /api/customers/:customerId/contacts
POST /api/customers/:customerId/contacts
PUT  /api/contacts/:id
```

Leads:
```text
GET  /api/leads
GET  /api/leads/:id
POST /api/leads
PUT  /api/leads/:id
```

Activities:
```text
GET  /api/activities
POST /api/activities
PUT  /api/activities/:id
```

Follow-ups:
```text
GET  /api/follow-ups
POST /api/follow-ups
PUT  /api/follow-ups/:id
```

Purchase Orders:
```text
GET  /api/purchase-orders
GET  /api/purchase-orders/:id
POST /api/purchase-orders
PUT  /api/purchase-orders/:id
```

Deliveries:
```text
GET  /api/deliveries
POST /api/deliveries
PUT /api/deliveries/:id
```

Returns:
```text
GET  /api/returns
POST /api/returns
PUT /api/returns/:id
```

Products / Stock:
```text
GET /api/products
POST /api/products
PUT /api/products/:id

GET /api/stock
```

Reports:
```text
GET /api/dashboard/summary
GET /api/reports/customers
GET /api/reports/leads
GET /api/reports/activities
GET /api/reports/purchase-orders
GET /api/reports/deliveries
```

Every API response should use a consistent shape, e.g.:

```json
{
  "success": true,
  "data": {},
  "meta": {}
}
```

Errors:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "..."
  }
}
```

## 9. Authorization Matrix

| Module | Admin | Marketing | Sales | Management | Viewer |
|---|---|---|---|---|---|
| Dashboard | RW | R | R | R | R |
| Customers | RW | RW | RW | R | R |
| Contacts | RW | RW | RW | R | R |
| Leads | RW | RW | RW | R | R |
| Activities | RW | RW | RW | R | R |
| Follow Up | RW | RW | RW | R | R |
| PO | RW | RW/R | R | R | R |
| Delivery | RW | R | R | R | R |
| Return | RW | R | R | R | R |
| Products | RW | R | R | R | R |
| Stock | RW | R | R | R | R |
| Reports | RW | R | R | R | R |
| Users | RW | — | — | — | — |

`RW = read/write`, `R = read`, `— = no access`.

## 10. Frontend Information Architecture

```text
/login

/
├── dashboard
├── customers
│   ├── :id
│   └── :id/contacts
├── leads
│   └── :id
├── activities
├── follow-ups
├── purchase-orders
│   └── :id
├── deliveries
├── returns
├── products
├── stock
├── reports
└── settings
    └── users
```

## 11. UI Component Rules

Create reusable components:
```text
Button
Input
Textarea
Select
DatePicker
SearchBar
FilterBar
Modal
Drawer
Card
KPI Card
Badge
Table
DataTable
Pagination
EmptyState
LoadingState
ErrorState
Toast
ConfirmDialog
Avatar
Tabs
KanbanBoard
Timeline
```

Forms:
- labels always visible
- clear validation messages
- keyboard accessible
- submit disabled while saving
- success/error feedback
- preserve user input when validation fails

## 12. Design Tokens

```css
--color-bg: #F5F5F7;
--color-surface: #FFFFFF;
--color-text: #111111;
--color-muted: #6E6E73;
--color-border: #D2D2D7;
--color-success: #34C759;
--color-warning: #FF9F0A;
--color-danger: #FF3B30;
--radius-sm: 8px;
--radius-md: 12px;
--radius-lg: 18px;
--shadow-soft: 0 4px 18px rgba(0,0,0,.06);
```

Use spacing based on a consistent 4px/8px rhythm.

## 13. Migration Specification

Input:
```text
PIK_Master_Database_AppSheet.xlsx
```

Migration flow:
```text
Excel
 ↓
profile workbook
 ↓
map sheets/columns
 ↓
normalize
 ↓
validate
 ↓
deterministic IDs / natural-key matching
 ↓
insert/update PostgreSQL
 ↓
generate migration report
 ↓
flag ambiguous relationships
```

Requirements:
1. Inspect workbook sheets before importing.
2. Never assume a sheet's columns without profiling.
3. Preserve source file, sheet, and legacy row.
4. Normalize dates, numeric quantities, and empty values.
5. Detect duplicate candidates.
6. Do not silently discard rows.
7. Generate `migration_issues.csv`.
8. Migration must be rerunnable without duplicating records.
9. Create a migration summary with counts per sheet/table.
10. Keep unmapped source fields in a documented mapping report.

## 14. Testing

Minimum:
- API unit/service tests for critical business rules.
- Migration tests using a small fixture workbook.
- Authentication/authorization tests.
- PO outstanding calculation tests.
- Follow-up overdue/today tests.
- Customer CRUD tests.
- Lead status transition tests.
- At least one end-to-end smoke test covering login → customer → lead → follow-up.

## 15. Definition of Done

A feature is not done when its screen exists.

A feature is done when:
- UI exists.
- API exists.
- Database persistence works.
- Validation works.
- Authorization works.
- Loading/empty/error/success states work.
- Mobile layout works.
- Tests cover critical behavior.
- No business data is hardcoded.
- Documentation is updated.

