/**
 * SHA-256 (FIPS 180-4), HMAC-SHA256 (RFC 2104) and PBKDF2-HMAC-SHA256 (RFC 8018) in plain JavaScript, plus the
 * helpers password hashing and session tokens need (UTF-8, base64url, constant-time comparison, random bytes).
 *
 * Apps Script offers SHA-256 and HMAC through Utilities, but every call crosses into the Java runtime, which makes the
 * thousands of iterations PBKDF2 needs slow. This code runs inside V8. It is checked against Node's crypto module on
 * the standard test vectors and on random inputs (tests/api-auth.test.js).
 *
 * Bytes are plain arrays of integers 0..255; SHA-256 states and blocks are arrays of 32-bit integers.
 */

const SHA256_K = [
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
];

const SHA256_INITIAL_STATE = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];

const BASE64URL_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

/** One SHA-256 compression: the state after absorbing one 64-byte block (16 words). Returns a new state. */
function sha256Compress_(state, block) {
  const w = new Array(64);
  for (let i = 0; i < 16; i++) w[i] = block[i] | 0;
  for (let i = 16; i < 64; i++) {
    const x = w[i - 15];
    const y = w[i - 2];
    const s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
    const s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
    w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
  }
  let a = state[0] | 0;
  let b = state[1] | 0;
  let c = state[2] | 0;
  let d = state[3] | 0;
  let e = state[4] | 0;
  let f = state[5] | 0;
  let g = state[6] | 0;
  let h = state[7] | 0;
  for (let i = 0; i < 64; i++) {
    const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
    const ch = (e & f) ^ (~e & g);
    const t1 = (h + s1 + ch + SHA256_K[i] + w[i]) | 0;
    const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
    const maj = (a & b) ^ (a & c) ^ (b & c);
    const t2 = (s0 + maj) | 0;
    h = g;
    g = f;
    f = e;
    e = (d + t1) | 0;
    d = c;
    c = b;
    b = a;
    a = (t1 + t2) | 0;
  }
  return [(state[0] + a) | 0, (state[1] + b) | 0, (state[2] + c) | 0, (state[3] + d) | 0,
    (state[4] + e) | 0, (state[5] + f) | 0, (state[6] + g) | 0, (state[7] + h) | 0];
}

/**
 * SHA-256 of `bytes` as 8 words. `state`/`prefixLength` continue a hash whose first `prefixLength` bytes (whole
 * blocks) were already absorbed into `state` (used by HMAC); both default to a fresh hash.
 */
function sha256Words_(bytes, state, prefixLength) {
  let current = state || SHA256_INITIAL_STATE;
  const padded = bytes.slice();
  padded.push(0x80);
  while (padded.length % 64 !== 56) padded.push(0);
  const bitLength = ((prefixLength || 0) + bytes.length) * 8;
  const high = Math.floor(bitLength / 0x100000000);
  const low = bitLength >>> 0;
  padded.push((high >>> 24) & 255, (high >>> 16) & 255, (high >>> 8) & 255, high & 255,
    (low >>> 24) & 255, (low >>> 16) & 255, (low >>> 8) & 255, low & 255);
  const block = new Array(16);
  for (let offset = 0; offset < padded.length; offset += 64) {
    for (let i = 0; i < 16; i++) {
      const p = offset + i * 4;
      block[i] = (padded[p] << 24) | (padded[p + 1] << 16) | (padded[p + 2] << 8) | padded[p + 3];
    }
    current = sha256Compress_(current, block);
  }
  return current;
}

function wordsToBytes_(words) {
  const bytes = [];
  words.forEach(function (word) { bytes.push((word >>> 24) & 255, (word >>> 16) & 255, (word >>> 8) & 255, word & 255); });
  return bytes;
}

function sha256Bytes_(bytes) {
  return wordsToBytes_(sha256Words_(bytes));
}

/** HMAC key schedule: SHA-256 states after the inner (0x36) and outer (0x5c) padded key blocks. */
function hmacSha256States_(keyBytes) {
  const key = keyBytes.length > 64 ? sha256Bytes_(keyBytes) : keyBytes.slice();
  while (key.length < 64) key.push(0);
  const inner = new Array(16);
  const outer = new Array(16);
  for (let i = 0; i < 16; i++) {
    const word = (key[i * 4] << 24) | (key[i * 4 + 1] << 16) | (key[i * 4 + 2] << 8) | key[i * 4 + 3];
    inner[i] = word ^ 0x36363636;
    outer[i] = word ^ 0x5c5c5c5c;
  }
  return { inner: sha256Compress_(SHA256_INITIAL_STATE, inner), outer: sha256Compress_(SHA256_INITIAL_STATE, outer) };
}

/** HMAC-SHA256 of a message of any length, as 8 words. */
function hmacSha256WithStates_(states, messageBytes) {
  const innerHash = wordsToBytes_(sha256Words_(messageBytes, states.inner, 64));
  return sha256Words_(innerHash, states.outer, 64);
}

/** Fast path for PBKDF2: HMAC-SHA256 of a 32-byte message given as 8 words (one compression per pad). */
function hmacSha256Of32Words_(states, words) {
  const pad = [0x80000000 | 0, 0, 0, 0, 0, 0, 0, (64 + 32) * 8];
  const innerHash = sha256Compress_(states.inner, words.concat(pad));
  return sha256Compress_(states.outer, innerHash.concat(pad));
}

function hmacSha256Bytes_(keyBytes, messageBytes) {
  return wordsToBytes_(hmacSha256WithStates_(hmacSha256States_(keyBytes), messageBytes));
}

/** PBKDF2-HMAC-SHA256 (RFC 8018 §5.2): `length` bytes derived from password and salt. */
function pbkdf2Sha256Bytes_(passwordBytes, saltBytes, iterations, length) {
  const states = hmacSha256States_(passwordBytes);
  const output = [];
  for (let index = 1; output.length < length; index++) {
    let u = hmacSha256WithStates_(states,
      saltBytes.concat([(index >>> 24) & 255, (index >>> 16) & 255, (index >>> 8) & 255, index & 255]));
    const t = u.slice();
    for (let n = 1; n < iterations; n++) {
      u = hmacSha256Of32Words_(states, u);
      for (let k = 0; k < 8; k++) t[k] ^= u[k];
    }
    wordsToBytes_(t).forEach(function (byte) { output.push(byte); });
  }
  return output.slice(0, length);
}

/** UTF-8 bytes of a string; unpaired surrogates become U+FFFD (as Node and browsers encode them). */
function utf8Bytes_(text) {
  const source = String(text);
  const bytes = [];
  for (let i = 0; i < source.length; i++) {
    let code = source.charCodeAt(i);
    if (code >= 0xd800 && code <= 0xdbff && i + 1 < source.length) {
      const next = source.charCodeAt(i + 1);
      if (next >= 0xdc00 && next <= 0xdfff) {
        code = 0x10000 + ((code - 0xd800) << 10) + (next - 0xdc00);
        i++;
      }
    }
    if (code >= 0xd800 && code <= 0xdfff) code = 0xfffd;
    if (code < 0x80) bytes.push(code);
    else if (code < 0x800) bytes.push(0xc0 | (code >> 6), 0x80 | (code & 63));
    else if (code < 0x10000) bytes.push(0xe0 | (code >> 12), 0x80 | ((code >> 6) & 63), 0x80 | (code & 63));
    else bytes.push(0xf0 | (code >> 18), 0x80 | ((code >> 12) & 63), 0x80 | ((code >> 6) & 63), 0x80 | (code & 63));
  }
  return bytes;
}

/** base64url without padding (RFC 4648 §5). */
function bytesToBase64Url_(bytes) {
  let text = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const chunk = (bytes[i] << 16) | ((bytes[i + 1] || 0) << 8) | (bytes[i + 2] || 0);
    text += BASE64URL_ALPHABET.charAt((chunk >>> 18) & 63) + BASE64URL_ALPHABET.charAt((chunk >>> 12) & 63);
    if (i + 1 < bytes.length) text += BASE64URL_ALPHABET.charAt((chunk >>> 6) & 63);
    if (i + 2 < bytes.length) text += BASE64URL_ALPHABET.charAt(chunk & 63);
  }
  return text;
}

/** Bytes of a base64url text, or null when it is not valid unpadded base64url. */
function base64UrlToBytes_(text) {
  if (typeof text !== 'string' || !/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) return null;
  const bytes = [];
  for (let i = 0; i < text.length; i += 4) {
    const part = text.slice(i, i + 4);
    let chunk = 0;
    for (let k = 0; k < 4; k++) chunk = (chunk << 6) | (k < part.length ? BASE64URL_ALPHABET.indexOf(part.charAt(k)) : 0);
    bytes.push((chunk >>> 16) & 255);
    if (part.length > 2) bytes.push((chunk >>> 8) & 255);
    if (part.length > 3) bytes.push(chunk & 255);
  }
  return bytes;
}

/** Compares two strings in time that depends only on their length (no early exit on the first difference). */
function constantTimeEqual_(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  let difference = 0;
  for (let i = 0; i < a.length; i++) difference |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return difference === 0;
}

/**
 * Unpredictable bytes. Utilities.getUuid() returns random (version 4) UUIDs from a cryptographic generator; two of
 * them are hashed together so every output byte is uniformly distributed.
 */
function randomBytes_(count) {
  const bytes = [];
  while (bytes.length < count) {
    sha256Bytes_(utf8Bytes_(Utilities.getUuid() + ':' + Utilities.getUuid())).forEach(function (byte) { bytes.push(byte); });
  }
  return bytes.slice(0, count);
}
