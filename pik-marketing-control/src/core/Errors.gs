/**
 * Error model shared by every server module.
 *
 * Expected failures are AppErrors: a normal Error with a stable machine-readable `code` and a safe
 * Indonesian `message` that may be shown to users. Anything else is an internal error: it is logged
 * server-side and returned to the client as a generic message so technical details never leak.
 */

const ERROR_CODE = Object.freeze({
  VALIDATION: 'VALIDATION_ERROR',
  NOT_FOUND: 'NOT_FOUND',
  CONFLICT: 'CONFLICT',
  FORBIDDEN: 'FORBIDDEN',
  NOT_REGISTERED: 'NOT_REGISTERED',
  LOCK_TIMEOUT: 'LOCK_TIMEOUT',
  SCHEMA_MISMATCH: 'SCHEMA_MISMATCH',
  CONFIG_MISSING: 'CONFIG_MISSING',
  READ_ONLY: 'READ_ONLY',
  NOT_IMPLEMENTED: 'NOT_IMPLEMENTED',
  INTERNAL: 'INTERNAL_ERROR'
});

const GENERIC_ERROR_MESSAGE = 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi Admin.';

/** @return {Error & { code: string, details: * }} */
function appError_(code, message, details) {
  const error = /** @type {*} */ (new Error(message));
  error.name = 'AppError';
  error.code = code;
  error.details = details === undefined ? null : details;
  return error;
}

function isAppError_(error) {
  return Boolean(error) && error.name === 'AppError' && typeof error.code === 'string';
}

function okResponse_(data) {
  return { success: true, data: data === undefined ? null : data };
}

function errorResponse_(error) {
  if (isAppError_(error) && error.code !== ERROR_CODE.INTERNAL) {
    return { success: false, error: { code: error.code, message: error.message, details: error.details } };
  }
  console.error(error && error.stack ? error.stack : String(error));
  return { success: false, error: { code: ERROR_CODE.INTERNAL, message: GENERIC_ERROR_MESSAGE, details: null } };
}

/** Runs a client-facing request and wraps the outcome in the standard { success, data | error } envelope. */
function handleRequest_(callback) {
  try {
    return okResponse_(callback());
  } catch (error) {
    return errorResponse_(error);
  }
}
