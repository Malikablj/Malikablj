/**
 * Final error middleware: every error becomes the standard error envelope.
 * Technical details are logged server-side only; clients get a safe message.
 */
import { ZodError } from 'zod';
import { fieldErrors } from '@pik/shared';
import { AppError } from '../utils/errors.js';
import { mapDatabaseError } from '../utils/dbErrors.js';
import logger from '../utils/logger.js';

function toAppError(error) {
  if (error instanceof AppError) return error;
  if (error instanceof ZodError) {
    const fields = fieldErrors(error);
    const first = Object.values(fields)[0];
    return new AppError(400, 'VALIDATION_ERROR', first || 'Data yang dikirim belum valid.', { fields });
  }
  if (error?.type === 'entity.parse.failed') {
    return new AppError(400, 'INVALID_JSON', 'Format data yang dikirim tidak valid.');
  }
  if (error?.type === 'entity.too.large') {
    return new AppError(413, 'PAYLOAD_TOO_LARGE', 'Data yang dikirim terlalu besar.');
  }
  return mapDatabaseError(error ?? {});
}

// eslint-disable-next-line no-unused-vars -- Express identifies error middleware by its 4 parameters.
export function errorHandler(error, req, res, next) {
  const appError = toAppError(error);
  const status = appError?.status ?? 500;

  if (status >= 500) {
    logger.error('Request failed', {
      method: req.method,
      path: req.originalUrl,
      error: error?.message,
      code: error?.code,
      stack: error?.stack,
    });
  }

  const body = {
    success: false,
    error: {
      code: appError?.code ?? 'INTERNAL_ERROR',
      message: appError?.message ?? 'Terjadi kesalahan pada server. Silakan coba lagi.',
    },
  };
  if (appError?.details) body.error.details = appError.details;
  res.status(status).json(body);
}

export function apiNotFound(req, res) {
  res.status(404).json({
    success: false,
    error: { code: 'NOT_FOUND', message: 'Endpoint tidak ditemukan.' },
  });
}
