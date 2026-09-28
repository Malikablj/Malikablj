import * as customerRepository from '../repositories/customerRepository.js';
import { businessToday } from '../utils/dates.js';
import { notFound } from '../utils/errors.js';

export function list(filters) {
  return customerRepository.list(filters);
}

export function facets() {
  return customerRepository.facets();
}

export async function get(id) {
  const customer = await customerRepository.findById(id);
  if (!customer) throw notFound('Customer tidak ditemukan.');
  return customer;
}

/** Customer with the workspace summary (counts, pipeline, outstanding, next follow-up). */
export async function getWorkspace(id) {
  const customer = await get(id);
  return { ...customer, summary: await customerRepository.summary(id, businessToday()) };
}

export async function create(values, actor) {
  const id = await customerRepository.insert(values, actor.id);
  return get(id);
}

export async function update(id, values, actor) {
  await get(id);
  await customerRepository.update(id, values, actor.id);
  return get(id);
}

/** Archiving hides the customer from lists and pickers; all history stays intact. */
export async function setActive(id, isActive, actor) {
  await get(id);
  await customerRepository.update(id, { is_active: isActive }, actor.id);
  return get(id);
}
