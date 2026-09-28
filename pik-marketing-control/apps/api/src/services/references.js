/** Checks shared by services before writing references chosen by a user. */
import * as referenceRepository from '../repositories/referenceRepository.js';
import { businessRule, validationError } from '../utils/errors.js';

const missing = (field, message) => validationError(message, { fields: { [field]: message } });

/** An owner/PIC must be an existing, active user. */
export async function assertActiveOwner(userId) {
  if (!userId) return;
  const user = await referenceRepository.userStatus(userId);
  if (!user) throw missing('owner_user_id', 'PIC tidak ditemukan.');
  if (!user.is_active) throw businessRule('PIC yang dipilih sudah tidak aktif.');
}

/** New records may only reference active (non-archived) customers. */
export async function assertActiveCustomer(customerId, db) {
  const customer = await referenceRepository.customerStatus(customerId, db);
  if (!customer) throw missing('customer_id', 'Customer tidak ditemukan.');
  if (!customer.is_active) throw businessRule('Customer ini sudah diarsipkan. Pulihkan customer terlebih dahulu.');
}

export async function assertCustomerExists(customerId, db) {
  if (!(await referenceRepository.customerStatus(customerId, db))) throw missing('customer_id', 'Customer tidak ditemukan.');
}

export async function assertProductExists(productId, db) {
  const found = await referenceRepository.product(productId, db);
  if (!found) throw missing('product_id', 'Produk tidak ditemukan.');
  return found;
}
