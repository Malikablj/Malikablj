import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router';

/**
 * List filters kept in the URL (?q=...&status=A,B&page=2): shareable, survive reloads and work
 * with the back button. Changing a filter resets paging to page 1.
 *
 * @param {Record<string, string>} defaults values used when a parameter is absent
 * @param {string[]} listKeys parameters that hold comma-separated lists
 */
export function useListParams(defaults = {}, listKeys = []) {
  const [searchParams, setSearchParams] = useSearchParams();

  const params = useMemo(() => {
    const result = { ...defaults };
    for (const [key, value] of searchParams.entries()) result[key] = value;
    for (const key of listKeys) {
      const value = result[key];
      result[key] = typeof value === 'string' && value ? value.split(',') : Array.isArray(value) ? value : [];
    }
    return result;
    // defaults/listKeys are constant per page
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  const setParams = useCallback(
    (changes, { resetPage = true } = {}) => {
      setSearchParams(
        (current) => {
          const next = new URLSearchParams(current);
          for (const [key, value] of Object.entries(changes)) {
            const text = Array.isArray(value) ? value.join(',') : value;
            if (text === undefined || text === null || text === '' || text === defaults[key]) next.delete(key);
            else next.set(key, String(text));
          }
          if (resetPage && !('page' in changes)) next.delete('page');
          return next;
        },
        { replace: true },
      );
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [setSearchParams],
  );

  return [params, setParams];
}
