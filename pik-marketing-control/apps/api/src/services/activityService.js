import config from '../config/index.js';
import * as activityRepository from '../repositories/activityRepository.js';
import { notFound } from '../utils/errors.js';
import { assertActiveCustomer, assertActiveOwner } from './references.js';

export function list(filters) {
  return activityRepository.list(filters, config.appTimezone);
}

export async function get(id) {
  const activity = await activityRepository.findById(id);
  if (!activity) throw notFound('Aktivitas tidak ditemukan.');
  return activity;
}

/** Contact and lead must belong to the same customer (enforced by composite foreign keys). */
export async function create(values, actor) {
  await assertActiveCustomer(values.customer_id);
  const ownerId = values.owner_user_id === undefined ? actor.id : values.owner_user_id;
  await assertActiveOwner(ownerId);
  const id = await activityRepository.insert({ ...values, owner_user_id: ownerId }, actor.id);
  return get(id);
}

export async function update(id, values, actor) {
  await get(id);
  if (values.owner_user_id) await assertActiveOwner(values.owner_user_id);
  await activityRepository.update(id, values, actor.id);
  return get(id);
}
