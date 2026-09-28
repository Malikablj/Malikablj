import { ChevronDown, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useApi } from '../../hooks/useApi.js';
import { useDebouncedValue } from '../../hooks/useDebouncedValue.js';

/**
 * Searchable single select backed by an API list endpoint (ARIA combobox).
 * Used for customer, product and PO pickers so thousands of records are never loaded at once.
 *
 * props: path ("/customers"), params (extra filters), value (id), selectedLabel (label of value),
 *        onChange(id, row), getLabel(row), getDescription?(row), placeholder, disabled
 */
export function AsyncSelect({
  path,
  params,
  value,
  selectedLabel,
  onChange,
  getLabel,
  getDescription,
  placeholder = 'Pilih…',
  disabled = false,
  id,
  ...ariaProps
}) {
  const listId = useId();
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const [active, setActive] = useState(0);
  const containerRef = useRef(null);
  const search = useDebouncedValue(text, 250);
  const { data, loading } = useApi(path, { ...params, q: search, page_size: 20 }, { enabled: open });
  const options = data ?? [];

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => {
      if (!containerRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const choose = (row) => {
    onChange(row ? row.id : '', row);
    setOpen(false);
    setText('');
  };

  const onKeyDown = (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setOpen(true);
      setActive((index) => Math.min(index + 1, options.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && open && options[active]) {
      event.preventDefault();
      choose(options[active]);
    } else if (event.key === 'Escape' && open) {
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
    }
  };

  return (
    <div className="popover-anchor" ref={containerRef}>
      <div className="search-bar">
        <input
          id={id}
          className="input"
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={open && options[active] ? `${listId}-${active}` : undefined}
          value={open ? text : (selectedLabel ?? '')}
          placeholder={value ? (selectedLabel ?? placeholder) : placeholder}
          onChange={(event) => {
            setText(event.target.value);
            setActive(0);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          disabled={disabled}
          autoComplete="off"
          style={{ paddingRight: 56 }}
          {...ariaProps}
        />
        <div style={{ position: 'absolute', right: 6, display: 'flex', gap: 2 }}>
          {value && !disabled && (
            <button type="button" className="btn btn-ghost btn-icon btn-sm" onClick={() => choose(null)} aria-label="Kosongkan pilihan">
              <X size={14} />
            </button>
          )}
          <ChevronDown size={16} className="muted" style={{ alignSelf: 'center', marginRight: 4 }} aria-hidden="true" />
        </div>
      </div>
      {open && (
        <div className="popover left" style={{ width: '100%', minWidth: 260 }}>
          <ul id={listId} role="listbox" className="list-plain">
            {loading && !options.length && <li className="menu-section-title">Memuat…</li>}
            {!loading && !options.length && <li className="menu-section-title">Tidak ada hasil</li>}
            {options.map((row, index) => (
              <li
                key={row.id}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={row.id === value}
                className="menu-item"
                data-active={index === active}
                onMouseDown={(event) => {
                  event.preventDefault();
                  choose(row);
                }}
                onMouseEnter={() => setActive(index)}
              >
                <span className="stack-sm" style={{ gap: 0 }}>
                  <span>{getLabel(row)}</span>
                  {getDescription && <span className="cell-sub">{getDescription(row)}</span>}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
