import type { CommandContext } from '@/domain/context';
import { AppError } from '@/domain/errors';
import { todayISO } from '@/lib/date';
import type { DbState, PublicUser, User } from '@/types';
import { getDb, mutate } from '../storage/database';

/**
 * Mock transport. Every service call goes through `query`/`command`, which
 * resolve the session user (401), simulate network latency and optional
 * failures (Settings → Sistem), and return copies so UI code can never
 * mutate the store directly.
 */

const SESSION_KEY = 'npd-project-control:session';

export function toPublic(u: User): PublicUser {
  const { password: _password, ...rest } = u;
  void _password;
  return rest;
}

export function readSession(): string | null {
  try {
    return localStorage.getItem(SESSION_KEY);
  } catch {
    return null;
  }
}

export function writeSession(userId: string | null): void {
  try {
    if (userId) localStorage.setItem(SESSION_KEY, userId);
    else localStorage.removeItem(SESSION_KEY);
  } catch {
    /* ignore */
  }
}

export function sessionUser(db: DbState = getDb()): PublicUser {
  const id = readSession();
  const user = id ? db.users.find((u) => u.id === id) : undefined;
  if (!user || !user.active) throw new AppError('UNAUTHORIZED', 'Sesi Anda telah berakhir. Silakan login kembali.');
  return toPublic(user);
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

async function transport(): Promise<void> {
  const { settings } = getDb();
  if (settings.simulatedLatency) await sleep(120 + Math.random() * 220);
  if (settings.simulatedFailureRate > 0 && Math.random() * 100 < settings.simulatedFailureRate)
    throw new AppError('NETWORK', 'Gagal terhubung ke server. Periksa koneksi lalu coba lagi.');
}

export function makeContext(actor: PublicUser): CommandContext {
  return { actor, now: new Date().toISOString(), today: todayISO() };
}

/** Read request: `fn` receives the current snapshot and the session user. */
export async function query<T>(fn: (db: DbState, user: PublicUser, today: string) => T): Promise<T> {
  await transport();
  const db = getDb();
  const user = sessionUser(db);
  return structuredClone(fn(db, user, todayISO()));
}

/** Write request: `fn` runs atomically on a draft of the database. */
export async function command<T>(fn: (draft: DbState, ctx: CommandContext) => T): Promise<T> {
  await transport();
  const user = sessionUser();
  const result = mutate((draft) => fn(draft, makeContext(user)));
  return structuredClone(result);
}
