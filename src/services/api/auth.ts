import { AppError } from '@/domain/errors';
import type { PublicUser } from '@/types';
import { getDb } from '../storage/database';
import { readSession, toPublic, writeSession } from './client';

/** Mock authentication. Swap for an OAuth/SSO or JWT session endpoint. */

export async function login(email: string, password: string): Promise<PublicUser> {
  await new Promise((r) => setTimeout(r, 350));
  const fieldErrors: Record<string, string> = {};
  if (!email.trim()) fieldErrors.email = 'Email wajib diisi.';
  if (!password) fieldErrors.password = 'Password wajib diisi.';
  if (Object.keys(fieldErrors).length) throw new AppError('VALIDATION', 'Lengkapi email dan password.', { fieldErrors });
  const user = getDb().users.find((u) => u.email.toLowerCase() === email.trim().toLowerCase());
  if (!user || user.password !== password) throw new AppError('UNAUTHORIZED', 'Email atau password salah.');
  if (!user.active) throw new AppError('FORBIDDEN', 'Akun Anda dinonaktifkan. Hubungi Admin.');
  writeSession(user.id);
  return toPublic(user);
}

export function logout(): void {
  writeSession(null);
}

/** Synchronous session restore on app start. */
export function restoreSession(): PublicUser | null {
  const id = readSession();
  if (!id) return null;
  const user = getDb().users.find((u) => u.id === id && u.active);
  if (!user) {
    writeSession(null);
    return null;
  }
  return toPublic(user);
}

export function demoAccounts(): PublicUser[] {
  return getDb()
    .users.filter((u) => u.active)
    .map(toPublic);
}
