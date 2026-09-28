import { LEAD_STATUS, PO_OPEN_STATUSES } from '@pik/shared';
import config from '../config/index.js';
import * as activityRepository from '../repositories/activityRepository.js';
import * as dashboardRepository from '../repositories/dashboardRepository.js';
import * as followUpRepository from '../repositories/followUpRepository.js';
import * as poRepository from '../repositories/purchaseOrderRepository.js';
import { addDays, businessToday } from '../utils/dates.js';

const LIMIT = 8;

/**
 * Dashboard data. scope "me" limits leads, follow-ups, activities and POs to the caller as
 * owner/PIC; "all" shows the whole company.
 */
export async function summary(user, scope) {
  const today = businessToday();
  const ownerId = scope === 'me' ? user.id : null;
  const owner = ownerId ? { owner_user_id: ownerId } : {};
  const page = { page: 1, page_size: LIMIT };

  const [kpis, outstanding, pipelineRows, deliveryStatus, upcomingDeliveries, today_, overdue, upcoming, activities, openPos] =
    await Promise.all([
      dashboardRepository.kpis(today, ownerId),
      dashboardRepository.outstandingByUnit(ownerId),
      dashboardRepository.pipeline(ownerId),
      dashboardRepository.deliveryStatus(today, ownerId),
      dashboardRepository.upcomingDeliveries(today, ownerId),
      followUpRepository.list({ ...page, ...owner, state: ['TODAY'] }, today),
      followUpRepository.list({ ...page, ...owner, state: ['OVERDUE'] }, today),
      followUpRepository.list({ ...page, ...owner, state: ['UPCOMING'], to: addDays(today, 7) }, today),
      activityRepository.list({ ...page, ...owner }, config.appTimezone),
      poRepository.list({ ...page, ...owner, status: [...PO_OPEN_STATUSES], has_outstanding: true, sort: 'expected_delivery_date' }, today),
    ]);

  const byStatus = Object.fromEntries(pipelineRows.map((row) => [row.status, row]));
  return {
    scope: ownerId ? 'me' : 'all',
    today,
    kpis: { ...kpis, outstanding_quantity: outstanding },
    pipeline: LEAD_STATUS.values.map((status) => ({
      status,
      count: byStatus[status]?.count ?? 0,
      total_value: byStatus[status]?.total_value ?? 0,
    })),
    follow_ups: {
      today: today_.rows,
      overdue: overdue.rows,
      upcoming: upcoming.rows,
      totals: { today: today_.meta.total, overdue: overdue.meta.total, upcoming: upcoming.meta.total },
    },
    recent_activities: activities.rows,
    open_purchase_orders: openPos.rows,
    deliveries: { by_status: deliveryStatus, upcoming: upcomingDeliveries },
  };
}
