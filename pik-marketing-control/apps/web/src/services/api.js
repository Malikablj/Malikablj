/**
 * API client. Every call goes to the same-origin /api (session cookie), sends the CSRF header on
 * mutations and turns failures into ApiError with the server's user-facing message.
 */

export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }

  get fieldErrors() {
    return this.details?.fields ?? null;
  }
}

const unauthorizedListeners = new Set();

/** Called when a request fails with 401 (session expired): the app returns to the login page. */
export function onUnauthorized(listener) {
  unauthorizedListeners.add(listener);
  return () => unauthorizedListeners.delete(listener);
}

/** Serializes query params: skips empty values, joins arrays with commas, sorts keys for stable cache keys. */
export function buildQuery(params = {}) {
  const search = new URLSearchParams();
  for (const key of Object.keys(params).sort()) {
    const value = params[key];
    if (value === undefined || value === null || value === '') continue;
    if (Array.isArray(value)) {
      if (value.length) search.set(key, value.join(','));
    } else {
      search.set(key, String(value));
    }
  }
  const text = search.toString();
  return text ? `?${text}` : '';
}

export function apiUrl(path, params) {
  return `/api${path}${buildQuery(params)}`;
}

function fallbackMessage(status) {
  if (status === 403) return 'Anda tidak memiliki akses untuk tindakan ini.';
  if (status === 404) return 'Data tidak ditemukan.';
  if (status >= 500) return 'Terjadi kesalahan pada server. Silakan coba lagi.';
  return 'Permintaan tidak dapat diproses.';
}

async function request(method, path, { params, body, signal } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET') headers['X-Requested-With'] = 'XMLHttpRequest';

  let response;
  try {
    response = await fetch(apiUrl(path, params), {
      method,
      credentials: 'same-origin',
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal,
    });
  } catch (error) {
    if (error.name === 'AbortError') throw error;
    throw new ApiError(0, 'NETWORK_ERROR', 'Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.');
  }

  let payload;
  try {
    payload = await response.json();
  } catch {
    payload = null; // empty or non-JSON body (e.g. a proxy error page)
  }

  if (!response.ok || payload?.success === false) {
    const error = payload?.error ?? {};
    const apiError = new ApiError(
      response.status,
      error.code ?? 'HTTP_ERROR',
      error.message ?? fallbackMessage(response.status),
      error.details,
    );
    if (response.status === 401 && !path.startsWith('/auth/')) {
      for (const listener of unauthorizedListeners) listener();
    }
    throw apiError;
  }
  return payload ?? { success: true, data: null };
}

export const api = {
  get: (path, params, options) => request('GET', path, { params, ...options }),
  post: (path, body) => request('POST', path, { body: body ?? {} }),
  put: (path, body) => request('PUT', path, { body: body ?? {} }),
  patch: (path, body) => request('PATCH', path, { body: body ?? {} }),
  delete: (path) => request('DELETE', path),
};
