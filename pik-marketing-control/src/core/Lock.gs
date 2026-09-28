/**
 * Script lock for every write. Re-entrant within one execution: nested calls reuse the lock that is already held.
 */

const DEFAULT_LOCK_TIMEOUT_MS = 30000;

var LOCK_DEPTH_ = 0;

function withScriptLock_(callback, timeoutMs) {
  if (LOCK_DEPTH_ > 0) {
    LOCK_DEPTH_++;
    try {
      return callback();
    } finally {
      LOCK_DEPTH_--;
    }
  }
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(timeoutMs || DEFAULT_LOCK_TIMEOUT_MS)) {
    throw appError_(ERROR_CODE.LOCK_TIMEOUT, 'Sistem sedang memproses permintaan lain. Silakan coba lagi sebentar lagi.');
  }
  LOCK_DEPTH_ = 1;
  try {
    return callback();
  } finally {
    LOCK_DEPTH_ = 0;
    try {
      SpreadsheetApp.flush(); // make the writes visible before another execution can take the lock
    } finally {
      lock.releaseLock();
    }
  }
}

function isScriptLockHeld_() {
  return LOCK_DEPTH_ > 0;
}
