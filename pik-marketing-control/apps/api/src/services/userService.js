/** User administration (Admin only; enforced by the route). */
import * as sessionRepository from '../repositories/sessionRepository.js';
import * as userRepository from '../repositories/userRepository.js';
import { businessRule, notFound } from '../utils/errors.js';
import { hashPassword } from '../utils/password.js';

export function list(filters) {
  return userRepository.list(filters);
}

export function options() {
  return userRepository.options();
}

export async function get(id) {
  const user = await userRepository.findById(id);
  if (!user) throw notFound('User tidak ditemukan.');
  return user;
}

export async function create(values, actor) {
  return userRepository.create({
    name: values.name,
    email: values.email,
    role: values.role,
    isActive: values.is_active,
    passwordHash: await hashPassword(values.password),
    createdBy: actor.id,
  });
}

/**
 * Guards against locking the organisation out: an admin cannot deactivate or demote their own
 * account, and the last active admin cannot be deactivated or demoted.
 */
export async function update(id, values, actor) {
  const existing = await get(id);
  const deactivating = values.is_active === false && existing.is_active;
  const demoting = values.role !== undefined && values.role !== 'ADMIN' && existing.role === 'ADMIN';
  if (id === actor.id && (deactivating || demoting)) {
    throw businessRule('Anda tidak dapat menonaktifkan atau menurunkan role akun Anda sendiri.');
  }
  if ((deactivating || demoting) && existing.role === 'ADMIN' && existing.is_active && (await userRepository.countActiveAdmins()) <= 1) {
    throw businessRule('Harus ada minimal satu Admin aktif.');
  }
  const updated = await userRepository.update(id, values, actor.id);
  if (deactivating || (values.role !== undefined && values.role !== existing.role)) {
    // Role or access changed: force a fresh login so permissions apply immediately.
    await sessionRepository.deleteForUser(id);
  }
  return updated;
}

export async function resetPassword(id, password, actor) {
  await get(id);
  await userRepository.updatePassword(id, await hashPassword(password), actor.id);
  await sessionRepository.deleteForUser(id);
}
