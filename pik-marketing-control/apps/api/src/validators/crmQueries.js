/** Query-string schemas for CRM list endpoints. */
import { ACTIVITY_TYPE, CUSTOMER_STATUS, FOLLOW_UP_STATE, LEAD_STATUS, PRIORITY } from '@pik/shared';
import { listQuery, queryBoolean, queryDate, queryEnumList, queryId, queryText } from './common.js';

export const customerListQuery = listQuery({
  status: queryEnumList(CUSTOMER_STATUS.values),
  industry: queryText(150),
  is_active: queryBoolean(),
});

export const leadListQuery = listQuery({
  status: queryEnumList(LEAD_STATUS.values),
  priority: queryEnumList(PRIORITY.values),
  customer_id: queryId(),
  owner_user_id: queryId(),
  closing_from: queryDate(),
  closing_to: queryDate(),
  source: queryText(100),
});

export const activityListQuery = listQuery({
  type: queryEnumList(ACTIVITY_TYPE.values),
  customer_id: queryId(),
  contact_id: queryId(),
  lead_id: queryId(),
  owner_user_id: queryId(),
  from: queryDate(),
  to: queryDate(),
});

export const followUpListQuery = listQuery({
  state: queryEnumList(FOLLOW_UP_STATE.values),
  priority: queryEnumList(PRIORITY.values),
  customer_id: queryId(),
  lead_id: queryId(),
  owner_user_id: queryId(),
  from: queryDate(),
  to: queryDate(),
});
