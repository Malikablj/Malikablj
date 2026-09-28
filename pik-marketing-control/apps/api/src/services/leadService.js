import { checkLeadTransition } from '@pik/shared';
import * as leadRepository from '../repositories/leadRepository.js';
import { businessRule, notFound } from '../utils/errors.js';
import { assertActiveCustomer, assertActiveOwner } from './references.js';

const CLOSED = new Set(['WON', 'LOST']);

export function list(filters) {
  return leadRepository.list(filters);
}

export function board(filters) {
  return leadRepository.board(filters);
}

export async function get(id) {
  const lead = await leadRepository.findById(id);
  if (!lead) throw notFound('Lead tidak ditemukan.');
  return lead;
}

/** Adds status bookkeeping (status_changed_at, closed_at) when the status changes. */
function statusFields(status) {
  const now = new Date();
  return { status_changed_at: now, closed_at: CLOSED.has(status) ? now : null };
}

export async function create(values, actor) {
  await assertActiveCustomer(values.customer_id);
  const ownerId = values.owner_user_id === undefined ? actor.id : values.owner_user_id;
  await assertActiveOwner(ownerId);
  const check = checkLeadTransition({ from: null, to: values.status, role: actor.role, lostReason: values.lost_reason });
  if (!check.ok) throw businessRule(check.reason);
  const id = await leadRepository.insert({ ...values, owner_user_id: ownerId, ...statusFields(values.status) }, actor.id);
  return get(id);
}

export async function update(id, values, actor) {
  const existing = await get(id);
  if (values.customer_id && values.customer_id !== existing.customer_id) await assertActiveCustomer(values.customer_id);
  if (values.owner_user_id) await assertActiveOwner(values.owner_user_id);
  const changes = { ...values };
  if (values.status !== undefined && values.status !== existing.status) {
    const check = checkLeadTransition({
      from: existing.status,
      to: values.status,
      role: actor.role,
      lostReason: values.lost_reason !== undefined ? values.lost_reason : existing.lost_reason,
    });
    if (!check.ok) throw businessRule(check.reason);
    Object.assign(changes, statusFields(values.status));
  }
  await leadRepository.update(id, changes, actor.id);
  return get(id);
}

/** Pipeline move (Kanban drag or status menu). */
export function changeStatus(id, { status, lost_reason: lostReason }, actor) {
  return update(id, lostReason !== undefined ? { status, lost_reason: lostReason } : { status }, actor);
}
