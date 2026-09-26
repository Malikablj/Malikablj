export type ErrorCode =
  | 'VALIDATION'
  | 'FORBIDDEN'
  | 'UNAUTHORIZED'
  | 'NOT_FOUND'
  | 'CONFLICT'
  | 'INVALID_STATE'
  | 'NETWORK'
  | 'STORAGE';

/** Error raised by domain commands and services; carries a user-facing message. */
export class AppError extends Error {
  readonly code: ErrorCode;
  readonly details?: string[];
  readonly fieldErrors?: Record<string, string>;

  constructor(code: ErrorCode, message: string, opts?: { details?: string[]; fieldErrors?: Record<string, string> }) {
    super(message);
    this.name = 'AppError';
    this.code = code;
    this.details = opts?.details;
    this.fieldErrors = opts?.fieldErrors;
  }
}

export const isAppError = (e: unknown): e is AppError => e instanceof AppError;

export function errorMessage(e: unknown): string {
  if (isAppError(e)) return e.message;
  if (e instanceof Error) return e.message;
  return 'Terjadi kesalahan yang tidak terduga.';
}
