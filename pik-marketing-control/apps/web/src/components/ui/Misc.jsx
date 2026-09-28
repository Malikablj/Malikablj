import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { avatarColor, initials, percent } from '../../utils/format.js';

export function Avatar({ name, size = 'md' }) {
  return (
    <span className={`avatar ${size === 'sm' ? 'sm' : ''}`} style={{ background: avatarColor(name) }} title={name} aria-hidden="true">
      {initials(name)}
    </span>
  );
}

export function PageHeader({ title, eyebrow, actions, children }) {
  return (
    <header className="page-header">
      <div className="page-title">
        {eyebrow && <span className="eyebrow">{eyebrow}</span>}
        <h1>{title}</h1>
        {children}
      </div>
      {actions && <div className="actions">{actions}</div>}
    </header>
  );
}

/**
 * Search input that reports changes after the user pauses typing (debounced), so lists are
 * not re-queried on every keystroke.
 */
export function SearchBar({ value, onChange, placeholder = 'Cari…', label = 'Cari', delay = 350 }) {
  const [text, setText] = useState(value ?? '');
  const [synced, setSynced] = useState(value ?? '');
  // Follow external changes (e.g. filters reset from the URL).
  if ((value ?? '') !== synced) {
    setSynced(value ?? '');
    setText(value ?? '');
  }
  useEffect(() => {
    if (text === (value ?? '')) return undefined;
    const timer = setTimeout(() => onChange(text), delay);
    return () => clearTimeout(timer);
  }, [text, value, delay, onChange]);
  return (
    <div className="search-bar">
      <Search size={16} aria-hidden="true" />
      <input className="input" type="search" value={text} onChange={(event) => setText(event.target.value)} placeholder={placeholder} aria-label={label} />
    </div>
  );
}

export function Progress({ value, total, tone }) {
  const width = percent(value, total);
  return (
    <div className={`progress ${tone ?? (width >= 100 ? 'success' : '')}`} role="progressbar" aria-valuenow={width} aria-valuemin={0} aria-valuemax={100} aria-label={`${width}%`}>
      <span style={{ width: `${width}%` }} />
    </div>
  );
}

/** Definition list of label/value pairs; empty values show a dash. */
export function DetailList({ items }) {
  return (
    <dl className="dl">
      {items
        .filter((item) => !item.hidden)
        .map((item) => (
          <div key={item.label} style={{ display: 'contents' }}>
            <dt>{item.label}</dt>
            <dd>{item.value === null || item.value === undefined || item.value === '' ? <span className="muted">–</span> : item.value}</dd>
          </div>
        ))}
    </dl>
  );
}

/** "Dari … s/d …" date range for list filters. onChange({ from, to }) with only the changed key. */
export function DateRange({ from, to, onChange, label = 'Periode' }) {
  return (
    <div className="row" role="group" aria-label={label} style={{ gap: 6 }}>
      <input className="input" type="date" value={from ?? ''} max={to || undefined} onChange={(event) => onChange({ from: event.target.value })} aria-label={`${label}: dari tanggal`} style={{ width: 'auto' }} />
      <span className="text-sm muted">s/d</span>
      <input className="input" type="date" value={to ?? ''} min={from || undefined} onChange={(event) => onChange({ to: event.target.value })} aria-label={`${label}: sampai tanggal`} style={{ width: 'auto' }} />
    </div>
  );
}
