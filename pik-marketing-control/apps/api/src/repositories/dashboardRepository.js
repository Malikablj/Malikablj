/** Aggregates for the dashboard. Every number comes from SQL over live data. */
import { LEAD_OPEN_STATUSES, PO_OPEN_STATUSES } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, sqlDate } from '../utils/sql.js';

/** @param ownerId restrict owner-bearing records (leads, follow-ups, POs) to one user, or null for all */
export async function kpis(today, ownerId) {
  const t = sqlDate(today);
  const owner = (alias) => (ownerId ? `AND ${alias}.owner_user_id = $1` : '');
  const { rows } = await query(
    `SELECT
       (SELECT count(*) FROM customers WHERE is_active) AS total_customers,
       (SELECT count(*) FROM leads l WHERE l.status IN ${codeList(LEAD_OPEN_STATUSES)} ${owner('l')}) AS active_leads,
       (SELECT coalesce(sum(l.estimated_value), 0) FROM leads l WHERE l.status IN ${codeList(LEAD_OPEN_STATUSES)} ${owner('l')}) AS pipeline_value,
       (SELECT count(*) FROM follow_ups f WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'TODAY' ${owner('f')}) AS follow_up_today,
       (SELECT count(*) FROM follow_ups f WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'OVERDUE' ${owner('f')}) AS follow_up_overdue,
       (SELECT count(*) FROM follow_ups f WHERE follow_up_state(f.follow_up_date, f.status, ${t}) = 'UPCOMING'
                                          AND f.follow_up_date <= ${t} + 7 ${owner('f')}) AS follow_up_upcoming,
       (SELECT count(*) FROM purchase_orders po WHERE po.status IN ${codeList(PO_OPEN_STATUSES)} ${owner('po')}) AS open_purchase_orders,
       (SELECT count(*) FROM purchase_orders po JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
         WHERE po.status IN ${codeList(PO_OPEN_STATUSES)} AND s.outstanding_quantity > 0
           AND po.expected_delivery_date < ${t} ${owner('po')}) AS late_purchase_orders`,
    ownerId ? [ownerId] : [],
  );
  return rows[0];
}

/** Outstanding quantity of open POs per unit: quantities in different units are never summed together. */
export async function outstandingByUnit(ownerId) {
  const { rows } = await query(
    `SELECT coalesce(l.unit, p.unit, '-') AS unit, sum(v.outstanding_quantity) AS quantity
     FROM v_po_line_fulfillment v
     JOIN po_lines l ON l.id = v.po_line_id
     JOIN purchase_orders po ON po.id = l.purchase_order_id
     LEFT JOIN products p ON p.id = l.product_id
     WHERE po.status IN ${codeList(PO_OPEN_STATUSES)} ${ownerId ? 'AND po.owner_user_id = $1' : ''}
     GROUP BY 1 HAVING sum(v.outstanding_quantity) > 0
     ORDER BY 2 DESC`,
    ownerId ? [ownerId] : [],
  );
  return rows;
}

export async function pipeline(ownerId) {
  const { rows } = await query(
    `SELECT status, count(*) AS count, coalesce(sum(estimated_value), 0) AS total_value
     FROM leads ${ownerId ? 'WHERE owner_user_id = $1' : ''} GROUP BY status`,
    ownerId ? [ownerId] : [],
  );
  return rows;
}

/** Deliveries per status in a window around today (last 30 days to next 14 days). */
export async function deliveryStatus(today, ownerId) {
  const t = sqlDate(today);
  const { rows } = await query(
    `SELECT d.status, count(*) AS count
     FROM deliveries d JOIN purchase_orders po ON po.id = d.purchase_order_id
     WHERE d.delivery_date BETWEEN ${t} - 30 AND ${t} + 14 ${ownerId ? 'AND po.owner_user_id = $1' : ''}
     GROUP BY d.status`,
    ownerId ? [ownerId] : [],
  );
  return rows;
}

export async function upcomingDeliveries(today, ownerId, limit = 8) {
  const t = sqlDate(today);
  const { rows } = await query(
    `SELECT d.id, d.delivery_date, d.quantity, d.status, d.delivery_number, po.id AS purchase_order_id, po.po_number,
            c.name AS customer_name, coalesce(p.name, d.item_name) AS product_name, coalesce(l.unit, p.unit) AS unit,
            (d.delivery_date < ${t}) AS is_late
     FROM deliveries d
     JOIN purchase_orders po ON po.id = d.purchase_order_id
     JOIN customers c ON c.id = po.customer_id
     LEFT JOIN products p ON p.id = d.product_id
     LEFT JOIN po_lines l ON l.id = d.po_line_id
     WHERE d.status IN ('SCHEDULED', 'ON_DELIVERY', 'DELAYED') ${ownerId ? 'AND po.owner_user_id = $1' : ''}
     ORDER BY d.delivery_date NULLS LAST
     LIMIT ${Number(limit)}`,
    ownerId ? [ownerId] : [],
  );
  return rows;
}
