import { FOLLOW_UP_OPEN_STATUSES } from '@pik/shared';
import config from '../config/index.js';
import * as followUpRepository from '../repositories/followUpRepository.js';
import { businessToday } from '../utils/dates.js';
import { businessRule, notFound } from '../utils/errors.js';
import { assertActiveCustomer, assertActiveOwner } from './references.js';

export function list(filters) {
  return followUpRepository.list(filters, businessToday());
}

export function summary(ownerId) {
  return followUpRepository.summary(businessToday(), config.appTimezone, ownerId);
}

export async function get(id) {
  const followUp = await followUpRepository.findById(id, businessToday());
  if (!followUp) throw notFound('Follow up tidak ditemukan.');
  return followUp;
}

const completedAt = (status, previous) => (status === 'DONE' ? previous ?? new Date() : null);

export async function create(values, actor) {
  await assertActiveCustomer(values.customer_id);
  const ownerId = values.owner_user_id === undefined ? actor.id : values.owner_user_id;
  await assertActiveOwner(ownerId);
  const id = await followUpRepository.insert(
    { ...values, owner_user_id: ownerId, completed_at: completedAt(values.status, null) },
    actor.id,
  );
  return get(id);
}

export async function update(id, values, actor) {
  const existing = await get(id);
  if (values.owner_user_id) await assertActiveOwner(values.owner_user_id);
  const changes = { ...values };
  if (values.status !== undefined && values.status !== existing.status) {
    changes.completed_at = completedAt(values.status, null);
  }
  await followUpRepository.update(id, changes, actor.id);
  return get(id);
}

function appendNote(notes, addition) {
  return [notes, addition].filter(Boolean).join('\n\n');
}

/** Marks an open follow-up as done; an optional outcome is appended to the notes. */
export async function complete(id, { outcome }, actor) {
  const existing = await get(id);
  if (!FOLLOW_UP_OPEN_STATUSES.includes(existing.status)) {
    throw businessRule('Hanya follow up yang belum selesai yang dapat ditandai selesai.');
  }
  await followUpRepository.update(
    id,
    {
      status: 'DONE',
      completed_at: new Date(),
      ...(outcome ? { notes: appendNote(existing.notes, `Hasil: ${outcome}`) } : {}),
    },
    actor.id,
  );
  return get(id);
}

/** Moves an open follow-up to a new date (today or later) and records the previous date. */
export async function reschedule(id, { follow_up_date: date, follow_up_time: time, notes }, actor) {
  const existing = await get(id);
  if (!FOLLOW_UP_OPEN_STATUSES.includes(existing.status)) {
    throw businessRule('Follow up yang sudah selesai atau dibatalkan tidak dapat dijadwalkan ulang.');
  }
  if (date < businessToday()) throw businessRule('Tanggal baru tidak boleh sebelum hari ini.');
  const history = `Dijadwalkan ulang dari ${existing.follow_up_date}${notes ? `: ${notes}` : ''}.`;
  await followUpRepository.update(
    id,
    {
      follow_up_date: date,
      follow_up_time: time === undefined ? existing.follow_up_time : time,
      status: 'RESCHEDULE',
      notes: appendNote(existing.notes, history),
    },
    actor.id,
  );
  return get(id);
}
