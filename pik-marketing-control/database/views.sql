-- =============================================================================
-- PIK Marketing Control - business calculations
--
-- The single source of truth for derived values. The API and reports query these
-- functions and views; no other layer re-implements the formulas.
-- Views hold no data, so they are dropped and recreated on every run.
-- =============================================================================

-- Follow-up state relative to "today" in the business timezone (passed by the API):
--   DONE / CANCELLED  when the stored status says so,
--   OVERDUE           follow_up_date < today and not done/cancelled,
--   TODAY             follow_up_date = today and not done/cancelled,
--   UPCOMING          otherwise.
CREATE OR REPLACE FUNCTION follow_up_state(p_follow_up_date DATE, p_status TEXT, p_today DATE)
RETURNS TEXT
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE
    WHEN p_status = 'DONE' THEN 'DONE'
    WHEN p_status = 'CANCELLED' THEN 'CANCELLED'
    WHEN p_follow_up_date < p_today THEN 'OVERDUE'
    WHEN p_follow_up_date = p_today THEN 'TODAY'
    ELSE 'UPCOMING'
  END
$$;

-- Outstanding quantity (Technical Specification §7):
--   MAX(0, order_quantity - delivered_quantity + returned_quantity)
CREATE OR REPLACE FUNCTION outstanding_quantity(p_ordered NUMERIC, p_delivered NUMERIC, p_returned NUMERIC)
RETURNS NUMERIC
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT GREATEST(0, coalesce(p_ordered, 0) - coalesce(p_delivered, 0) + coalesce(p_returned, 0))
$$;

DROP VIEW IF EXISTS v_purchase_order_summary;
DROP VIEW IF EXISTS v_po_line_fulfillment;
DROP VIEW IF EXISTS v_stock_current;

-- Fulfillment per PO line.
--   delivered_quantity   deliveries with status DELIVERED
--   in_progress_quantity deliveries SCHEDULED / ON_DELIVERY / DELAYED (not yet delivered)
--   returned_quantity    returns with status RECEIVED / RESOLVED (goods physically back)
-- Cancelled deliveries and returns never count.
CREATE VIEW v_po_line_fulfillment AS
SELECT
  l.id                AS po_line_id,
  l.purchase_order_id,
  l.product_id,
  l.order_quantity,
  coalesce(d.delivered_quantity, 0)   AS delivered_quantity,
  coalesce(d.in_progress_quantity, 0) AS in_progress_quantity,
  coalesce(r.returned_quantity, 0)    AS returned_quantity,
  outstanding_quantity(l.order_quantity, d.delivered_quantity, r.returned_quantity) AS outstanding_quantity
FROM po_lines l
LEFT JOIN LATERAL (
  SELECT
    sum(quantity) FILTER (WHERE status = 'DELIVERED') AS delivered_quantity,
    sum(quantity) FILTER (WHERE status IN ('SCHEDULED', 'ON_DELIVERY', 'DELAYED')) AS in_progress_quantity
  FROM deliveries
  WHERE po_line_id = l.id
) d ON TRUE
LEFT JOIN LATERAL (
  SELECT sum(quantity) FILTER (WHERE status IN ('RECEIVED', 'RESOLVED')) AS returned_quantity
  FROM returns
  WHERE po_line_id = l.id
) r ON TRUE;

-- Totals per purchase order. outstanding_quantity is the sum of line outstanding,
-- so over-delivery of one item never hides a shortage of another.
-- Deliveries/returns recorded against the PO without a line ("unallocated", only
-- possible for migrated legacy data) are reported separately and are NOT netted
-- into outstanding, because they cannot be attributed to an item.
CREATE VIEW v_purchase_order_summary AS
SELECT
  po.id                                   AS purchase_order_id,
  coalesce(f.line_count, 0)               AS line_count,
  coalesce(f.ordered_quantity, 0)         AS ordered_quantity,
  coalesce(f.delivered_quantity, 0)       AS delivered_quantity,
  coalesce(f.in_progress_quantity, 0)     AS in_progress_quantity,
  coalesce(f.returned_quantity, 0)        AS returned_quantity,
  coalesce(f.outstanding_quantity, 0)     AS outstanding_quantity,
  f.total_value,
  coalesce(f.unpriced_line_count, 0)      AS unpriced_line_count,
  coalesce(u.unallocated_delivered_quantity, 0) AS unallocated_delivered_quantity,
  coalesce(ur.unallocated_returned_quantity, 0) AS unallocated_returned_quantity
FROM purchase_orders po
LEFT JOIN LATERAL (
  SELECT
    count(*)                                   AS line_count,
    sum(v.order_quantity)                      AS ordered_quantity,
    sum(v.delivered_quantity)                  AS delivered_quantity,
    sum(v.in_progress_quantity)                AS in_progress_quantity,
    sum(v.returned_quantity)                   AS returned_quantity,
    sum(v.outstanding_quantity)                AS outstanding_quantity,
    sum(l.order_quantity * l.unit_price)       AS total_value,
    count(*) FILTER (WHERE l.unit_price IS NULL) AS unpriced_line_count
  FROM v_po_line_fulfillment v
  JOIN po_lines l ON l.id = v.po_line_id
  WHERE v.purchase_order_id = po.id
) f ON TRUE
LEFT JOIN LATERAL (
  SELECT sum(quantity) FILTER (WHERE status = 'DELIVERED') AS unallocated_delivered_quantity
  FROM deliveries
  WHERE purchase_order_id = po.id AND po_line_id IS NULL
) u ON TRUE
LEFT JOIN LATERAL (
  SELECT sum(quantity) FILTER (WHERE status IN ('RECEIVED', 'RESOLVED')) AS unallocated_returned_quantity
  FROM returns
  WHERE purchase_order_id = po.id AND po_line_id IS NULL
) ur ON TRUE;

-- Current stock: latest snapshot per product (or legacy item text), stock type and warehouse.
CREATE VIEW v_stock_current AS
SELECT DISTINCT ON (coalesce(s.product_id::text, 'item:' || s.item_name), s.stock_type, coalesce(s.warehouse, ''))
  s.id,
  s.product_id,
  s.item_name,
  s.stock_type,
  s.quantity,
  s.warehouse,
  s.stock_date,
  s.notes,
  s.updated_at
FROM stock s
ORDER BY
  coalesce(s.product_id::text, 'item:' || s.item_name),
  s.stock_type,
  coalesce(s.warehouse, ''),
  s.stock_date DESC NULLS LAST,
  s.updated_at DESC;
