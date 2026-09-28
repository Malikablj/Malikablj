import { withTransaction } from '../db/pool.js';
import * as contactRepository from '../repositories/contactRepository.js';
import * as customerRepository from '../repositories/customerRepository.js';
import { notFound } from '../utils/errors.js';

async function requireCustomer(customerId) {
  const customer = await customerRepository.findById(customerId);
  if (!customer) throw notFound('Customer tidak ditemukan.');
  return customer;
}

export async function listForCustomer(customerId, { isActive } = {}) {
  await requireCustomer(customerId);
  return contactRepository.listForCustomer(customerId, { isActive });
}

export async function get(id) {
  const contact = await contactRepository.findById(id);
  if (!contact) throw notFound('Kontak tidak ditemukan.');
  return contact;
}

/** Making a contact primary demotes the customer's previous primary contact in the same transaction. */
export async function create(customerId, values, actor) {
  await requireCustomer(customerId);
  const id = await withTransaction(async (db) => {
    if (values.is_primary) await contactRepository.clearPrimary(customerId, null, actor.id, db);
    return contactRepository.insert({ ...values, customer_id: customerId }, actor.id, db);
  });
  return get(id);
}

export async function update(id, values, actor) {
  const existing = await get(id);
  await withTransaction(async (db) => {
    if (values.is_primary) await contactRepository.clearPrimary(existing.customer_id, id, actor.id, db);
    await contactRepository.update(id, values, actor.id, db);
  });
  return get(id);
}

/** Archived contacts stay linked to past leads/activities but disappear from pickers. */
export async function archive(id, actor) {
  await get(id);
  await contactRepository.update(id, { is_active: false, is_primary: false }, actor.id);
  return get(id);
}
