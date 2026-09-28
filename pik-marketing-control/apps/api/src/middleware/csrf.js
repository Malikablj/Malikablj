/**
 * CSRF defence for cookie-authenticated requests.
 * State-changing requests must carry `X-Requested-With: XMLHttpRequest`. Browsers cannot add
 * custom headers to cross-site form posts, and cross-origin fetches with custom headers need a
 * CORS preflight that only CORS_ORIGIN passes. Together with SameSite=Lax cookies this blocks CSRF.
 */
import { AppError } from '../utils/errors.js';

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export function requireCsrfHeader(req, res, next) {
  if (SAFE_METHODS.has(req.method)) return next();
  if (req.get('X-Requested-With') !== 'XMLHttpRequest') {
    throw new AppError(403, 'CSRF_REJECTED', 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.');
  }
  return next();
}
