import { useCallback, useEffect, useState } from 'react';
import { api, buildQuery } from '../services/api.js';

/**
 * Loads GET /api{path}{params}. Keeps the previous data visible while a new request runs
 * (filters change, reload), so lists never flash empty.
 *
 * `loading` is derived from whether the latest response belongs to the current request, so no
 * state is set synchronously inside the effect.
 *
 * @returns {{ data, meta, error, loading, reload, setData }}
 */
export function useApi(path, params, { enabled = true } = {}) {
  const [version, setVersion] = useState(0);
  const [state, setState] = useState({ requestKey: null, data: undefined, meta: undefined, error: null });
  const query = buildQuery(params);
  const requestKey = enabled && path ? `${path}${query}#${version}` : null;

  useEffect(() => {
    if (!requestKey) return undefined;
    const controller = new AbortController();
    const searchParams = Object.fromEntries(new URLSearchParams(query));
    api
      .get(path, searchParams, { signal: controller.signal })
      .then((response) => setState({ requestKey, data: response.data, meta: response.meta, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((previous) => ({ ...previous, requestKey, error }));
      });
    return () => controller.abort();
  }, [requestKey, path, query]);

  const reload = useCallback(() => setVersion((value) => value + 1), []);
  const setData = useCallback(
    (updater) => setState((previous) => ({ ...previous, data: typeof updater === 'function' ? updater(previous.data) : updater })),
    [],
  );

  return {
    data: state.data,
    meta: state.meta,
    error: requestKey && state.requestKey === requestKey ? state.error : null,
    loading: Boolean(requestKey) && state.requestKey !== requestKey,
    reload,
    setData,
  };
}
