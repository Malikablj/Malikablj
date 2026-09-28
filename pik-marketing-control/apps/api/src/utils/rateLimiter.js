/**
 * In-memory fixed-window counter for failed login attempts. Suitable for the single-process
 * deployment this app targets; a multi-instance deployment would move this to PostgreSQL/Redis.
 */
export function createRateLimiter({ limit, windowMs }) {
  const hits = new Map();

  function current(key, now) {
    const entry = hits.get(key);
    if (!entry || entry.resetAt <= now) return null;
    return entry;
  }

  return {
    /** Milliseconds until the key may try again, or 0 when not limited. */
    retryAfter(key, now = Date.now()) {
      const entry = current(key, now);
      return entry && entry.count >= limit ? entry.resetAt - now : 0;
    },
    fail(key, now = Date.now()) {
      const entry = current(key, now) ?? { count: 0, resetAt: now + windowMs };
      entry.count += 1;
      hits.set(key, entry);
      if (hits.size > 10_000) {
        for (const [k, v] of hits) if (v.resetAt <= now) hits.delete(k);
      }
    },
    reset(key) {
      hits.delete(key);
    },
    clear() {
      hits.clear();
    },
  };
}
