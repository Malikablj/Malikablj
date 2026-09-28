-- =============================================================================
-- PIK Marketing Control - PostgreSQL schema (baseline)
--
-- Idempotent: running it again only creates what is missing. It never drops or
-- truncates anything. Later structural changes are added as numbered files in
-- apps/api/migrations/ (see apps/api/migrations/README.md).
--
-- Conventions
--   * UUID primary keys (gen_random_uuid, built into PostgreSQL 13+).
--   * TIMESTAMPTZ for instants, DATE for business dates, NUMERIC for quantities/money.
--   * Named constraints (ck_/uq_/fk_) so the API can explain violations to users.
--   * Records are archived (is_active) or cancelled (status), never deleted.
--   * Migrated rows keep lineage: source_file, source_sheet, legacy_row,
--     legacy_key (stable natural key used for re-runs), source_data (the raw
--     source row) and migrated_at.
--   * Business calculations (outstanding quantity, follow-up state) live in
--     database/views.sql, not here.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version    TEXT PRIMARY KEY,
  applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Keeps updated_at current on every UPDATE.
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END
$$;

-- Normalized matching key: lower case, punctuation and repeated whitespace
-- collapsed ("PT. Maju  Jaya" -> "pt maju jaya"). Used for Level-2 name matching.
CREATE OR REPLACE FUNCTION normalize_key(value TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT NULLIF(btrim(regexp_replace(lower(coalesce(value, '')), '[^a-z0-9]+', ' ', 'g')), '')
$$;

-- -----------------------------------------------------------------------------
-- Users and sessions
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash TEXT NOT NULL,
  role          VARCHAR(30) NOT NULL
                CONSTRAINT ck_users_role CHECK (role IN ('ADMIN', 'MARKETING', 'SALES', 'MANAGEMENT', 'VIEWER')),
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at TIMESTAMPTZ,
  created_by    UUID REFERENCES users (id),
  updated_by    UUID REFERENCES users (id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_email ON users (lower(email));
CREATE INDEX IF NOT EXISTS ix_users_name_key ON users (normalize_key(name));

-- Server-side sessions. Only an HMAC of the session token is stored.
CREATE TABLE IF NOT EXISTS user_sessions (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id      UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  token_hash   CHAR(64) NOT NULL CONSTRAINT uq_user_sessions_token UNIQUE,
  expires_at   TIMESTAMPTZ NOT NULL,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip_address   VARCHAR(64),
  user_agent   VARCHAR(500)
);
CREATE INDEX IF NOT EXISTS ix_user_sessions_user ON user_sessions (user_id);
CREATE INDEX IF NOT EXISTS ix_user_sessions_expires ON user_sessions (expires_at);

-- -----------------------------------------------------------------------------
-- Migration bookkeeping
-- -----------------------------------------------------------------------------
-- One row per applied (committed) workbook import. Dry runs roll back and leave no row.
CREATE TABLE IF NOT EXISTS migration_runs (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  source_file     TEXT NOT NULL,
  source_checksum CHAR(64) NOT NULL,
  mapping_version TEXT,
  summary         JSONB,
  started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  finished_at     TIMESTAMPTZ
);

-- Every record a run processed and what happened to it. `npm run migrate:verify` uses this to
-- prove each imported record exists and to find records no longer present in the source.
CREATE TABLE IF NOT EXISTS migration_run_records (
  run_id     UUID NOT NULL REFERENCES migration_runs (id),
  entity     VARCHAR(100) NOT NULL,
  legacy_key TEXT NOT NULL,
  legacy_row INTEGER,
  outcome    VARCHAR(30) NOT NULL
             CONSTRAINT ck_migration_run_records_outcome CHECK (outcome IN ('inserted', 'updated', 'unchanged',
                                                                         'skipped_modified_in_app', 'not_imported'))
);
CREATE INDEX IF NOT EXISTS ix_migration_run_records_run ON migration_run_records (run_id, entity);

CREATE TABLE IF NOT EXISTS migration_issues (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  -- Hash of (entity, sheet, legacy key/row, issue type, detail): re-runs update, not duplicate.
  fingerprint         CHAR(64) NOT NULL CONSTRAINT uq_migration_issues_fingerprint UNIQUE,
  entity_type         VARCHAR(100) NOT NULL,
  source_file         TEXT,
  source_sheet        TEXT,
  legacy_row          INTEGER,
  issue_type          VARCHAR(100) NOT NULL,
  severity            VARCHAR(10) NOT NULL DEFAULT 'WARNING'
                      CONSTRAINT ck_migration_issues_severity CHECK (severity IN ('INFO', 'WARNING', 'ERROR')),
  description         TEXT NOT NULL,
  candidate_reference TEXT,
  source_data         JSONB,
  resolution_status   VARCHAR(30) NOT NULL DEFAULT 'OPEN'
                      CONSTRAINT ck_migration_issues_status CHECK (resolution_status IN ('OPEN', 'RESOLVED', 'IGNORED')),
  resolution_notes    TEXT,
  resolved_by         UUID REFERENCES users (id),
  resolved_at         TIMESTAMPTZ,
  first_seen_run_id   UUID REFERENCES migration_runs (id),
  last_seen_run_id    UUID REFERENCES migration_runs (id),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_migration_issues_status ON migration_issues (resolution_status);
CREATE INDEX IF NOT EXISTS ix_migration_issues_type ON migration_issues (issue_type);
CREATE INDEX IF NOT EXISTS ix_migration_issues_sheet ON migration_issues (source_sheet, legacy_row);

-- -----------------------------------------------------------------------------
-- Customers and contacts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_code VARCHAR(100),
  name          VARCHAR(255) NOT NULL,
  name_key      TEXT GENERATED ALWAYS AS (normalize_key(name)) STORED,
  industry      VARCHAR(150),
  address       TEXT,
  phone         VARCHAR(100),
  email         VARCHAR(255),
  website       VARCHAR(255),
  status        VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
                CONSTRAINT ck_customers_status CHECK (status IN ('ACTIVE', 'INACTIVE', 'POTENTIAL', 'DORMANT')),
  notes         TEXT,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  source_file   TEXT,
  source_sheet  TEXT,
  legacy_row    INTEGER,
  legacy_key    TEXT CONSTRAINT uq_customers_legacy_key UNIQUE,
  source_data   JSONB,
  migrated_at   TIMESTAMPTZ,
  created_by    UUID REFERENCES users (id),
  updated_by    UUID REFERENCES users (id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_customers_customer_code UNIQUE (customer_code)
);
CREATE INDEX IF NOT EXISTS ix_customers_name_trgm ON customers USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS ix_customers_name_key ON customers (name_key);
CREATE INDEX IF NOT EXISTS ix_customers_status ON customers (status) WHERE is_active;

CREATE TABLE IF NOT EXISTS contacts (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id  UUID NOT NULL REFERENCES customers (id),
  name         VARCHAR(150) NOT NULL,
  position     VARCHAR(150),
  phone        VARCHAR(100),
  email        VARCHAR(255),
  whatsapp     VARCHAR(100),
  is_primary   BOOLEAN NOT NULL DEFAULT FALSE,
  notes        TEXT,
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,
  source_file  TEXT,
  source_sheet TEXT,
  legacy_row   INTEGER,
  legacy_key   TEXT CONSTRAINT uq_contacts_legacy_key UNIQUE,
  source_data  JSONB,
  migrated_at  TIMESTAMPTZ,
  created_by   UUID REFERENCES users (id),
  updated_by   UUID REFERENCES users (id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Target of composite foreign keys that keep contact and customer consistent.
  CONSTRAINT uq_contacts_id_customer UNIQUE (id, customer_id)
);
CREATE INDEX IF NOT EXISTS ix_contacts_customer ON contacts (customer_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_one_primary ON contacts (customer_id) WHERE is_primary AND is_active;

-- -----------------------------------------------------------------------------
-- Products
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  product_code   VARCHAR(100),
  name           VARCHAR(255) NOT NULL,
  name_key       TEXT GENERATED ALWAYS AS (normalize_key(name)) STORED,
  category       VARCHAR(150),
  customer_id    UUID REFERENCES customers (id),
  description    TEXT,
  unit           VARCHAR(50),
  lead_time_days INTEGER CONSTRAINT ck_products_lead_time CHECK (lead_time_days >= 0),
  status         VARCHAR(30) NOT NULL DEFAULT 'ACTIVE'
                 CONSTRAINT ck_products_status CHECK (status IN ('ACTIVE', 'DEVELOPMENT', 'DISCONTINUED')),
  is_active      BOOLEAN NOT NULL DEFAULT TRUE,
  source_file    TEXT,
  source_sheet   TEXT,
  legacy_row     INTEGER,
  legacy_key     TEXT CONSTRAINT uq_products_legacy_key UNIQUE,
  source_data    JSONB,
  migrated_at    TIMESTAMPTZ,
  created_by     UUID REFERENCES users (id),
  updated_by     UUID REFERENCES users (id),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Product codes are not declared unique: the source may reuse a code per customer.
-- Duplicates are reported by the migration instead of being rejected.
CREATE INDEX IF NOT EXISTS ix_products_code ON products (upper(product_code));
CREATE INDEX IF NOT EXISTS ix_products_name_trgm ON products USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS ix_products_name_key ON products (name_key);
CREATE INDEX IF NOT EXISTS ix_products_customer ON products (customer_id);
CREATE INDEX IF NOT EXISTS ix_products_category ON products (category);

-- -----------------------------------------------------------------------------
-- CRM: leads, activities, follow-ups
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id           UUID NOT NULL REFERENCES customers (id),
  contact_id            UUID,
  product_id            UUID REFERENCES products (id),
  name                  VARCHAR(255) NOT NULL,
  source                VARCHAR(100),
  estimated_value       NUMERIC(18, 2) CONSTRAINT ck_leads_value CHECK (estimated_value >= 0),
  status                VARCHAR(30) NOT NULL DEFAULT 'NEW'
                        CONSTRAINT ck_leads_status CHECK (status IN ('NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION',
                                                                     'NEGOTIATION', 'WON', 'LOST', 'DORMANT')),
  priority              VARCHAR(30) CONSTRAINT ck_leads_priority CHECK (priority IN ('LOW', 'MEDIUM', 'HIGH')),
  owner_user_id         UUID REFERENCES users (id),
  expected_closing_date DATE,
  notes                 TEXT,
  lost_reason           TEXT,
  status_changed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  closed_at             TIMESTAMPTZ,
  source_file           TEXT,
  source_sheet          TEXT,
  legacy_row            INTEGER,
  legacy_key            TEXT CONSTRAINT uq_leads_legacy_key UNIQUE,
  source_data           JSONB,
  migrated_at           TIMESTAMPTZ,
  created_by            UUID REFERENCES users (id),
  updated_by            UUID REFERENCES users (id),
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_leads_id_customer UNIQUE (id, customer_id),
  CONSTRAINT fk_leads_contact_customer FOREIGN KEY (contact_id, customer_id) REFERENCES contacts (id, customer_id)
);
CREATE INDEX IF NOT EXISTS ix_leads_customer ON leads (customer_id);
CREATE INDEX IF NOT EXISTS ix_leads_status ON leads (status);
CREATE INDEX IF NOT EXISTS ix_leads_owner ON leads (owner_user_id);
CREATE INDEX IF NOT EXISTS ix_leads_contact ON leads (contact_id);
CREATE INDEX IF NOT EXISTS ix_leads_product ON leads (product_id);
CREATE INDEX IF NOT EXISTS ix_leads_closing ON leads (expected_closing_date);
CREATE INDEX IF NOT EXISTS ix_leads_name_trgm ON leads USING gin (name gin_trgm_ops);

CREATE TABLE IF NOT EXISTS activities (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id   UUID NOT NULL REFERENCES customers (id),
  contact_id    UUID,
  lead_id       UUID,
  type          VARCHAR(50) NOT NULL
                CONSTRAINT ck_activities_type CHECK (type IN ('WHATSAPP', 'CALL', 'EMAIL', 'MEETING', 'VISIT',
                                                              'QUOTATION', 'SAMPLE', 'PRESENTATION', 'FOLLOW_UP',
                                                              'COMPLAINT', 'NOTE', 'OTHER')),
  subject       VARCHAR(255) NOT NULL,
  description   TEXT,
  owner_user_id UUID REFERENCES users (id),
  activity_at   TIMESTAMPTZ NOT NULL,
  source_file   TEXT,
  source_sheet  TEXT,
  legacy_row    INTEGER,
  legacy_key    TEXT CONSTRAINT uq_activities_legacy_key UNIQUE,
  source_data   JSONB,
  migrated_at   TIMESTAMPTZ,
  created_by    UUID REFERENCES users (id),
  updated_by    UUID REFERENCES users (id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_activities_id_customer UNIQUE (id, customer_id),
  CONSTRAINT fk_activities_contact_customer FOREIGN KEY (contact_id, customer_id) REFERENCES contacts (id, customer_id),
  CONSTRAINT fk_activities_lead_customer FOREIGN KEY (lead_id, customer_id) REFERENCES leads (id, customer_id)
);
CREATE INDEX IF NOT EXISTS ix_activities_customer_time ON activities (customer_id, activity_at DESC);
CREATE INDEX IF NOT EXISTS ix_activities_time ON activities (activity_at DESC);
CREATE INDEX IF NOT EXISTS ix_activities_lead ON activities (lead_id);
CREATE INDEX IF NOT EXISTS ix_activities_contact ON activities (contact_id);
CREATE INDEX IF NOT EXISTS ix_activities_owner ON activities (owner_user_id);
CREATE INDEX IF NOT EXISTS ix_activities_type ON activities (type);

CREATE TABLE IF NOT EXISTS follow_ups (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id    UUID NOT NULL REFERENCES customers (id),
  lead_id        UUID,
  activity_id    UUID,
  owner_user_id  UUID REFERENCES users (id),
  follow_up_date DATE NOT NULL,
  follow_up_time TIME,
  priority       VARCHAR(30) CONSTRAINT ck_follow_ups_priority CHECK (priority IN ('LOW', 'MEDIUM', 'HIGH')),
  -- OVERDUE is accepted for legacy data; the authoritative overdue signal is
  -- derived by follow_up_state() in views.sql.
  status         VARCHAR(30) NOT NULL DEFAULT 'PLANNED'
                 CONSTRAINT ck_follow_ups_status CHECK (status IN ('PLANNED', 'DONE', 'RESCHEDULE', 'CANCELLED', 'OVERDUE')),
  notes          TEXT,
  completed_at   TIMESTAMPTZ,
  source_file    TEXT,
  source_sheet   TEXT,
  legacy_row     INTEGER,
  legacy_key     TEXT CONSTRAINT uq_follow_ups_legacy_key UNIQUE,
  source_data    JSONB,
  migrated_at    TIMESTAMPTZ,
  created_by     UUID REFERENCES users (id),
  updated_by     UUID REFERENCES users (id),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT fk_follow_ups_lead_customer FOREIGN KEY (lead_id, customer_id) REFERENCES leads (id, customer_id),
  CONSTRAINT fk_follow_ups_activity_customer FOREIGN KEY (activity_id, customer_id) REFERENCES activities (id, customer_id)
);
CREATE INDEX IF NOT EXISTS ix_follow_ups_open_date ON follow_ups (follow_up_date)
  WHERE status IN ('PLANNED', 'RESCHEDULE', 'OVERDUE');
CREATE INDEX IF NOT EXISTS ix_follow_ups_owner_date ON follow_ups (owner_user_id, follow_up_date);
CREATE INDEX IF NOT EXISTS ix_follow_ups_customer ON follow_ups (customer_id);
CREATE INDEX IF NOT EXISTS ix_follow_ups_lead ON follow_ups (lead_id);
CREATE INDEX IF NOT EXISTS ix_follow_ups_activity ON follow_ups (activity_id);
CREATE INDEX IF NOT EXISTS ix_follow_ups_status ON follow_ups (status);

-- -----------------------------------------------------------------------------
-- Purchase orders, lines, deliveries, returns
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
  id                     UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  po_number              VARCHAR(150) NOT NULL,
  customer_id            UUID NOT NULL REFERENCES customers (id),
  po_date                DATE,
  expected_delivery_date DATE,
  status                 VARCHAR(30) NOT NULL DEFAULT 'OPEN'
                         CONSTRAINT ck_purchase_orders_status CHECK (status IN ('OPEN', 'ON_PROCESS', 'PARTIAL', 'CLOSED', 'CANCELLED')),
  owner_user_id          UUID REFERENCES users (id),
  notes                  TEXT,
  cancel_reason          TEXT,
  cancelled_at           TIMESTAMPTZ,
  source_file            TEXT,
  source_sheet           TEXT,
  legacy_row             INTEGER,
  legacy_key             TEXT CONSTRAINT uq_purchase_orders_legacy_key UNIQUE,
  source_data            JSONB,
  migrated_at            TIMESTAMPTZ,
  created_by             UUID REFERENCES users (id),
  updated_by             UUID REFERENCES users (id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT uq_purchase_orders_customer_po UNIQUE (customer_id, po_number),
  CONSTRAINT uq_purchase_orders_id_customer UNIQUE (id, customer_id)
);
CREATE INDEX IF NOT EXISTS ix_purchase_orders_number ON purchase_orders (upper(po_number));
CREATE INDEX IF NOT EXISTS ix_purchase_orders_number_trgm ON purchase_orders USING gin (po_number gin_trgm_ops);
CREATE INDEX IF NOT EXISTS ix_purchase_orders_status ON purchase_orders (status);
CREATE INDEX IF NOT EXISTS ix_purchase_orders_po_date ON purchase_orders (po_date DESC);
CREATE INDEX IF NOT EXISTS ix_purchase_orders_expected ON purchase_orders (expected_delivery_date);
CREATE INDEX IF NOT EXISTS ix_purchase_orders_owner ON purchase_orders (owner_user_id);

CREATE TABLE IF NOT EXISTS po_lines (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  purchase_order_id UUID NOT NULL REFERENCES purchase_orders (id),
  line_no           INTEGER,
  product_id        UUID REFERENCES products (id),
  -- Item text from the source when the product could not be matched safely.
  item_name         VARCHAR(255),
  order_quantity    NUMERIC(18, 3) NOT NULL CONSTRAINT ck_po_lines_quantity CHECK (order_quantity >= 0),
  unit              VARCHAR(50),
  unit_price        NUMERIC(18, 2) CONSTRAINT ck_po_lines_price CHECK (unit_price >= 0),
  notes             TEXT,
  source_file       TEXT,
  source_sheet      TEXT,
  legacy_row        INTEGER,
  legacy_key        TEXT CONSTRAINT uq_po_lines_legacy_key UNIQUE,
  source_data       JSONB,
  migrated_at       TIMESTAMPTZ,
  created_by        UUID REFERENCES users (id),
  updated_by        UUID REFERENCES users (id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT ck_po_lines_item CHECK (product_id IS NOT NULL OR item_name IS NOT NULL),
  CONSTRAINT uq_po_lines_id_po UNIQUE (id, purchase_order_id)
);
CREATE INDEX IF NOT EXISTS ix_po_lines_po ON po_lines (purchase_order_id);
CREATE INDEX IF NOT EXISTS ix_po_lines_product ON po_lines (product_id);

CREATE TABLE IF NOT EXISTS deliveries (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  purchase_order_id UUID NOT NULL REFERENCES purchase_orders (id),
  po_line_id        UUID,
  product_id        UUID REFERENCES products (id),
  item_name         VARCHAR(255),
  delivery_date     DATE,
  quantity          NUMERIC(18, 3) NOT NULL CONSTRAINT ck_deliveries_quantity CHECK (quantity >= 0),
  status            VARCHAR(30) NOT NULL
                    CONSTRAINT ck_deliveries_status CHECK (status IN ('SCHEDULED', 'ON_DELIVERY', 'DELIVERED', 'DELAYED', 'CANCELLED')),
  delivery_number   VARCHAR(100),
  notes             TEXT,
  source_file       TEXT,
  source_sheet      TEXT,
  legacy_row        INTEGER,
  legacy_key        TEXT CONSTRAINT uq_deliveries_legacy_key UNIQUE,
  source_data       JSONB,
  migrated_at       TIMESTAMPTZ,
  created_by        UUID REFERENCES users (id),
  updated_by        UUID REFERENCES users (id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT fk_deliveries_line_po FOREIGN KEY (po_line_id, purchase_order_id) REFERENCES po_lines (id, purchase_order_id)
);
CREATE INDEX IF NOT EXISTS ix_deliveries_po ON deliveries (purchase_order_id);
CREATE INDEX IF NOT EXISTS ix_deliveries_line ON deliveries (po_line_id);
CREATE INDEX IF NOT EXISTS ix_deliveries_product ON deliveries (product_id);
CREATE INDEX IF NOT EXISTS ix_deliveries_date ON deliveries (delivery_date DESC);
CREATE INDEX IF NOT EXISTS ix_deliveries_status ON deliveries (status);

CREATE TABLE IF NOT EXISTS returns (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id       UUID NOT NULL REFERENCES customers (id),
  purchase_order_id UUID,
  po_line_id        UUID,
  product_id        UUID REFERENCES products (id),
  item_name         VARCHAR(255),
  return_date       DATE,
  quantity          NUMERIC(18, 3) NOT NULL CONSTRAINT ck_returns_quantity CHECK (quantity >= 0),
  reason            TEXT,
  status            VARCHAR(30) NOT NULL
                    CONSTRAINT ck_returns_status CHECK (status IN ('REPORTED', 'RECEIVED', 'RESOLVED', 'CANCELLED')),
  return_number     VARCHAR(100),
  notes             TEXT,
  source_file       TEXT,
  source_sheet      TEXT,
  legacy_row        INTEGER,
  legacy_key        TEXT CONSTRAINT uq_returns_legacy_key UNIQUE,
  source_data       JSONB,
  migrated_at       TIMESTAMPTZ,
  created_by        UUID REFERENCES users (id),
  updated_by        UUID REFERENCES users (id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- A PO line is only meaningful together with its PO (composite FKs skip NULL columns).
  CONSTRAINT ck_returns_line_needs_po CHECK (po_line_id IS NULL OR purchase_order_id IS NOT NULL),
  CONSTRAINT fk_returns_po_customer FOREIGN KEY (purchase_order_id, customer_id) REFERENCES purchase_orders (id, customer_id),
  CONSTRAINT fk_returns_line_po FOREIGN KEY (po_line_id, purchase_order_id) REFERENCES po_lines (id, purchase_order_id)
);
CREATE INDEX IF NOT EXISTS ix_returns_customer ON returns (customer_id);
CREATE INDEX IF NOT EXISTS ix_returns_po ON returns (purchase_order_id);
CREATE INDEX IF NOT EXISTS ix_returns_line ON returns (po_line_id);
CREATE INDEX IF NOT EXISTS ix_returns_product ON returns (product_id);
CREATE INDEX IF NOT EXISTS ix_returns_date ON returns (return_date DESC);

-- -----------------------------------------------------------------------------
-- Stock, lead time, inbound maklon
-- -----------------------------------------------------------------------------
-- Stock rows are dated snapshots; current stock is the latest snapshot per
-- product / stock type / warehouse (v_stock_current in views.sql).
CREATE TABLE IF NOT EXISTS stock (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id   UUID REFERENCES products (id),
  item_name    VARCHAR(255),
  stock_type   VARCHAR(30) NOT NULL
               CONSTRAINT ck_stock_type CHECK (stock_type IN ('FG', 'WIP', 'READY', 'RESERVED')),
  quantity     NUMERIC(18, 3) NOT NULL CONSTRAINT ck_stock_quantity CHECK (quantity >= 0),
  warehouse    VARCHAR(150),
  stock_date   DATE,
  notes        TEXT,
  source_file  TEXT,
  source_sheet TEXT,
  legacy_row   INTEGER,
  legacy_key   TEXT CONSTRAINT uq_stock_legacy_key UNIQUE,
  source_data  JSONB,
  migrated_at  TIMESTAMPTZ,
  created_by   UUID REFERENCES users (id),
  updated_by   UUID REFERENCES users (id),
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT ck_stock_item CHECK (product_id IS NOT NULL OR item_name IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_stock_product ON stock (product_id, stock_type, warehouse, stock_date DESC);
CREATE INDEX IF NOT EXISTS ix_stock_type ON stock (stock_type);

CREATE TABLE IF NOT EXISTS leadtime (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id     UUID REFERENCES products (id),
  customer_id    UUID REFERENCES customers (id),
  item_name      VARCHAR(255),
  lead_time_days INTEGER NOT NULL CONSTRAINT ck_leadtime_days CHECK (lead_time_days >= 0),
  notes          TEXT,
  source_file    TEXT,
  source_sheet   TEXT,
  legacy_row     INTEGER,
  legacy_key     TEXT CONSTRAINT uq_leadtime_legacy_key UNIQUE,
  source_data    JSONB,
  migrated_at    TIMESTAMPTZ,
  created_by     UUID REFERENCES users (id),
  updated_by     UUID REFERENCES users (id),
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT ck_leadtime_target CHECK (product_id IS NOT NULL OR customer_id IS NOT NULL OR item_name IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS ix_leadtime_product ON leadtime (product_id);
CREATE INDEX IF NOT EXISTS ix_leadtime_customer ON leadtime (customer_id);

-- PROVISIONAL: columns to be confirmed against the source workbook's
-- "Inbound Maklon" sheet (docs/DECISIONS.md). source_data keeps every source column.
CREATE TABLE IF NOT EXISTS inbound_maklon (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id       UUID REFERENCES customers (id),
  purchase_order_id UUID REFERENCES purchase_orders (id),
  product_id        UUID REFERENCES products (id),
  item_name         VARCHAR(255),
  inbound_date      DATE,
  quantity          NUMERIC(18, 3) CONSTRAINT ck_inbound_maklon_quantity CHECK (quantity >= 0),
  unit              VARCHAR(50),
  document_number   VARCHAR(100),
  notes             TEXT,
  source_file       TEXT,
  source_sheet      TEXT,
  legacy_row        INTEGER,
  legacy_key        TEXT CONSTRAINT uq_inbound_maklon_legacy_key UNIQUE,
  source_data       JSONB,
  migrated_at       TIMESTAMPTZ,
  created_by        UUID REFERENCES users (id),
  updated_by        UUID REFERENCES users (id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_inbound_maklon_customer ON inbound_maklon (customer_id);
CREATE INDEX IF NOT EXISTS ix_inbound_maklon_po ON inbound_maklon (purchase_order_id);
CREATE INDEX IF NOT EXISTS ix_inbound_maklon_product ON inbound_maklon (product_id);
CREATE INDEX IF NOT EXISTS ix_inbound_maklon_date ON inbound_maklon (inbound_date DESC);

-- -----------------------------------------------------------------------------
-- Finance
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invoices_payments (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  purchase_order_id UUID NOT NULL REFERENCES purchase_orders (id),
  invoice_number    VARCHAR(150),
  invoice_date      DATE,
  due_date          DATE,
  amount            NUMERIC(18, 2) NOT NULL DEFAULT 0 CONSTRAINT ck_invoices_amount CHECK (amount >= 0),
  paid_amount       NUMERIC(18, 2) NOT NULL DEFAULT 0 CONSTRAINT ck_invoices_paid CHECK (paid_amount >= 0),
  payment_status    VARCHAR(30) NOT NULL DEFAULT 'UNPAID'
                    CONSTRAINT ck_invoices_payment_status CHECK (payment_status IN ('UNPAID', 'PARTIAL', 'PAID', 'CANCELLED')),
  payment_date      DATE,
  notes             TEXT,
  source_file       TEXT,
  source_sheet      TEXT,
  legacy_row        INTEGER,
  legacy_key        TEXT CONSTRAINT uq_invoices_payments_legacy_key UNIQUE,
  source_data       JSONB,
  migrated_at       TIMESTAMPTZ,
  created_by        UUID REFERENCES users (id),
  updated_by        UUID REFERENCES users (id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_invoices_po ON invoices_payments (purchase_order_id);
CREATE INDEX IF NOT EXISTS ix_invoices_number ON invoices_payments (upper(invoice_number));
CREATE INDEX IF NOT EXISTS ix_invoices_due ON invoices_payments (due_date) WHERE payment_status IN ('UNPAID', 'PARTIAL');

-- PROVISIONAL: a per-PO financial summary as kept in the source workbook. It is
-- informational only; transaction truth is po_lines and invoices_payments.
CREATE TABLE IF NOT EXISTS po_financials (
  id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  purchase_order_id  UUID NOT NULL REFERENCES purchase_orders (id),
  currency           VARCHAR(10) NOT NULL DEFAULT 'IDR',
  po_value           NUMERIC(18, 2),
  tax_amount         NUMERIC(18, 2),
  total_amount       NUMERIC(18, 2),
  invoiced_amount    NUMERIC(18, 2),
  paid_amount        NUMERIC(18, 2),
  outstanding_amount NUMERIC(18, 2),
  notes              TEXT,
  source_file        TEXT,
  source_sheet       TEXT,
  legacy_row         INTEGER,
  legacy_key         TEXT CONSTRAINT uq_po_financials_legacy_key UNIQUE,
  source_data        JSONB,
  migrated_at        TIMESTAMPTZ,
  created_by         UUID REFERENCES users (id),
  updated_by         UUID REFERENCES users (id),
  created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS ix_po_financials_po ON po_financials (purchase_order_id);

-- -----------------------------------------------------------------------------
-- updated_at triggers
-- -----------------------------------------------------------------------------
CREATE OR REPLACE TRIGGER trg_users_updated_at BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_migration_issues_updated_at BEFORE UPDATE ON migration_issues FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_customers_updated_at BEFORE UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_contacts_updated_at BEFORE UPDATE ON contacts FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_products_updated_at BEFORE UPDATE ON products FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_leads_updated_at BEFORE UPDATE ON leads FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_activities_updated_at BEFORE UPDATE ON activities FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_follow_ups_updated_at BEFORE UPDATE ON follow_ups FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_purchase_orders_updated_at BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_po_lines_updated_at BEFORE UPDATE ON po_lines FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_deliveries_updated_at BEFORE UPDATE ON deliveries FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_returns_updated_at BEFORE UPDATE ON returns FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_stock_updated_at BEFORE UPDATE ON stock FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_leadtime_updated_at BEFORE UPDATE ON leadtime FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_inbound_maklon_updated_at BEFORE UPDATE ON inbound_maklon FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_invoices_payments_updated_at BEFORE UPDATE ON invoices_payments FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE OR REPLACE TRIGGER trg_po_financials_updated_at BEFORE UPDATE ON po_financials FOR EACH ROW EXECUTE FUNCTION set_updated_at();
