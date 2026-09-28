/**
 * Application errors carry an HTTP status, a stable machine-readable code and a
 * user-facing message (Bahasa Indonesia). The error handler turns them into the
 * standard { success: false, error: { code, message, details? } } response.
 */
export class AppError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.name = 'AppError';
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

export const validationError = (message, details) => new AppError(400, 'VALIDATION_ERROR', message, details);
export const badRequest = (message, details) => new AppError(400, 'BAD_REQUEST', message, details);
export const unauthorized = (message = 'Silakan login terlebih dahulu.') => new AppError(401, 'UNAUTHORIZED', message);
export const forbidden = (message = 'Anda tidak memiliki akses untuk tindakan ini.') =>
  new AppError(403, 'FORBIDDEN', message);
export const notFound = (message = 'Data tidak ditemukan.') => new AppError(404, 'NOT_FOUND', message);
export const conflict = (message, details) => new AppError(409, 'CONFLICT', message, details);
export const businessRule = (message, details) => new AppError(422, 'BUSINESS_RULE', message, details);
export const tooManyRequests = (message) => new AppError(429, 'TOO_MANY_REQUESTS', message);
