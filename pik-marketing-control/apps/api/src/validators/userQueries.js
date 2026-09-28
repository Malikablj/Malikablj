import { ROLE } from '@pik/shared';
import { listQuery, queryBoolean, queryEnum } from './common.js';

export const userListQuery = listQuery({
  role: queryEnum(ROLE.values),
  is_active: queryBoolean(),
});
