import { AppError } from '@/domain/errors';
import { createSeedDb, SCHEMA_VERSION } from '@/domain/seed';
import type { DbState } from '@/types';

/**
 * Local persistence for the demo backend. The whole dataset lives in
 * localStorage as one JSON document; mutations run on a draft copy so a
 * failing command never leaves partial state behind (atomic writes).
 * Replace `services/api/*` with HTTP calls to move to a real backend.
 */

const KEY = 'npd-project-control:db';

let cache: DbState | null = null;
const listeners = new Set<() => void>();

/** False when the browser blocks storage (private mode, sandboxed frames): the app then runs in memory. */
export const storageAvailable = (() => {
  try {
    const k = '__npd_probe__';
    localStorage.setItem(k, '1');
    localStorage.removeItem(k);
    return true;
  } catch {
    return false;
  }
})();

function safeGet(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function persist(db: DbState): void {
  if (!storageAvailable) return;
  try {
    localStorage.setItem(KEY, JSON.stringify(db));
  } catch (e) {
    const quota = e instanceof DOMException && (e.name === 'QuotaExceededError' || e.code === 22);
    throw new AppError('STORAGE', quota ? 'Penyimpanan browser penuh. Hapus data demo lama di Pengaturan → Sistem.' : 'Gagal menyimpan data ke penyimpanan lokal.');
  }
}

function load(): DbState {
  const raw = safeGet(KEY);
  if (raw) {
    try {
      const parsed = JSON.parse(raw) as DbState;
      if (parsed.schemaVersion === SCHEMA_VERSION && Array.isArray(parsed.projects)) return parsed;
    } catch {
      /* corrupted — reseed below */
    }
  }
  const seeded = createSeedDb();
  try {
    persist(seeded);
  } catch {
    /* storage unavailable (private mode): keep working in memory */
  }
  return seeded;
}

export function getDb(): DbState {
  cache ??= load();
  return cache;
}

/** Runs `fn` on a draft, persists it, then publishes it. Throws without side effects on error. */
export function mutate<T>(fn: (draft: DbState) => T): T {
  const draft = structuredClone(getDb());
  const result = fn(draft);
  persist(draft);
  cache = draft;
  listeners.forEach((l) => l());
  return result;
}

export function resetDatabase(): void {
  cache = createSeedDb();
  persist(cache);
  listeners.forEach((l) => l());
}

export function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

// Keep multiple tabs in sync.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key !== KEY || !e.newValue) return;
    try {
      cache = JSON.parse(e.newValue) as DbState;
      listeners.forEach((l) => l());
    } catch {
      /* ignore */
    }
  });
}
