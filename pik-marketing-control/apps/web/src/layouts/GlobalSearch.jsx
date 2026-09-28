import { Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { useNavigate } from 'react-router';
import { useApi } from '../hooks/useApi.js';
import { useDebouncedValue } from '../hooks/useDebouncedValue.js';

const GROUP_LABELS = {
  customers: 'Customer',
  contacts: 'Kontak',
  leads: 'Lead',
  purchase_orders: 'Purchase Order',
  products: 'Produk',
};

/** Header search across customers, contacts, leads, POs and products (keyboard navigable). */
export function GlobalSearch({ autoFocus = false, onNavigate }) {
  const navigate = useNavigate();
  const listId = useId();
  const [text, setText] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const containerRef = useRef(null);
  const query = useDebouncedValue(text.trim(), 250);
  const { data, loading } = useApi('/search', { q: query }, { enabled: query.length >= 2 });
  const groups = query.length >= 2 ? (data ?? []) : [];
  const flat = groups.flatMap((group) => group.items.map((item) => ({ ...item, group: group.group })));

  useEffect(() => {
    if (!open) return undefined;
    const close = (event) => {
      if (!containerRef.current?.contains(event.target)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const go = (item) => {
    setOpen(false);
    setText('');
    navigate(item.url);
    onNavigate?.();
  };

  const onKeyDown = (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => Math.min(index + 1, flat.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && flat[active]) {
      event.preventDefault();
      go(flat[active]);
    } else if (event.key === 'Escape') {
      setOpen(false);
    }
  };

  // Keyboard position of the first item of each group (results are listed group by group).
  const groupStarts = groups.map((_, groupIndex) => groups.slice(0, groupIndex).reduce((sum, group) => sum + group.items.length, 0));
  return (
    <div className="popover-anchor grow" ref={containerRef} style={{ maxWidth: 520 }}>
      <div className="search-bar">
        <Search size={16} aria-hidden="true" />
        <input
          className="input"
          type="search"
          role="combobox"
          aria-label="Cari customer, lead, PO, produk"
          aria-expanded={open && query.length >= 2}
          aria-controls={listId}
          aria-activedescendant={flat[active] ? `${listId}-${active}` : undefined}
          placeholder="Cari customer, lead, PO, produk…"
          value={text}
          autoFocus={autoFocus}
          onChange={(event) => {
            setText(event.target.value);
            setActive(0);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
        />
      </div>
      {open && query.length >= 2 && (
        <div className="popover left" style={{ width: '100%' }}>
          <div id={listId} role="listbox" aria-label="Hasil pencarian">
            {loading && !flat.length && <div className="menu-section-title">Mencari…</div>}
            {!loading && !flat.length && <div className="menu-section-title">Tidak ada hasil untuk “{query}”</div>}
            {groups.map((group, groupIndex) => (
              <div key={group.group} role="group" aria-label={GROUP_LABELS[group.group]}>
                <div className="menu-section-title">{GROUP_LABELS[group.group]}</div>
                {group.items.map((item, itemIndex) => {
                  const position = groupStarts[groupIndex] + itemIndex;
                  return (
                    <div
                      key={`${group.group}-${item.id}`}
                      id={`${listId}-${position}`}
                      role="option"
                      aria-selected={position === active}
                      className="menu-item"
                      data-active={position === active}
                      onMouseDown={(event) => {
                        event.preventDefault();
                        go(item);
                      }}
                      onMouseEnter={() => setActive(position)}
                    >
                      <span className="stack-sm" style={{ gap: 0 }}>
                        <span>{item.title}</span>
                        {item.subtitle && <span className="cell-sub">{item.subtitle}</span>}
                      </span>
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
