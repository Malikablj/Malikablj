/**
 * Password hashing with scrypt from Node's standard library (no native add-ons).
 * Parameters follow an OWASP-listed scrypt configuration: N=2^14, r=8, p=5 (~16 MiB).
 * Stored format: scrypt$N$r$p$<salt base64>$<hash base64>, so parameters can be raised later
 * without invalidating existing hashes.
 */
import { randomBytes, scrypt as scryptCallback, timingSafeEqual } from 'node:crypto';
import { promisify } from 'node:util';

const scrypt = promisify(scryptCallback);
const PARAMS = { N: 16384, r: 8, p: 5 };
const KEY_LENGTH = 64;
const MAX_MEMORY = 64 * 1024 * 1024;

export async function hashPassword(password) {
  const salt = randomBytes(16);
  const key = await scrypt(password.normalize('NFKC'), salt, KEY_LENGTH, { ...PARAMS, maxmem: MAX_MEMORY });
  return `scrypt$${PARAMS.N}$${PARAMS.r}$${PARAMS.p}$${salt.toString('base64')}$${key.toString('base64')}`;
}

export async function verifyPassword(password, storedHash) {
  const parts = typeof storedHash === 'string' ? storedHash.split('$') : [];
  if (parts.length !== 6 || parts[0] !== 'scrypt') return false;
  const [, N, r, p, saltBase64, keyBase64] = parts;
  const expected = Buffer.from(keyBase64, 'base64');
  const actual = await scrypt(password.normalize('NFKC'), Buffer.from(saltBase64, 'base64'), expected.length, {
    N: Number(N),
    r: Number(r),
    p: Number(p),
    maxmem: MAX_MEMORY,
  });
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

/** A valid hash of a random password, used to keep login timing equal for unknown emails. */
let dummyHashPromise = null;
export function dummyPasswordHash() {
  dummyHashPromise ??= hashPassword(randomBytes(24).toString('base64'));
  return dummyHashPromise;
}
