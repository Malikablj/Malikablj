import { useRef } from 'react';

/**
 * Tab list (ARIA tabs pattern, arrow keys move between tabs).
 * tabs: [{ id, label, count?, countTone?: 'danger' }]
 */
export function Tabs({ tabs, value, onChange, label = 'Tab' }) {
  const refs = useRef({});
  const onKeyDown = (event, index) => {
    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
    event.preventDefault();
    const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    onChange(next.id);
    refs.current[next.id]?.focus();
  };
  return (
    <div className="tabs" role="tablist" aria-label={label}>
      {tabs.map((tab, index) => (
        <button
          key={tab.id}
          ref={(element) => {
            refs.current[tab.id] = element;
          }}
          type="button"
          role="tab"
          className="tab"
          aria-selected={value === tab.id}
          tabIndex={value === tab.id ? 0 : -1}
          onClick={() => onChange(tab.id)}
          onKeyDown={(event) => onKeyDown(event, index)}
        >
          {tab.label}
          {tab.count !== undefined && tab.count !== null && (
            <span className={`tab-count ${tab.countTone === 'danger' && tab.count > 0 ? 'danger' : ''}`}>{tab.count}</span>
          )}
        </button>
      ))}
    </div>
  );
}

/** Segmented control. options: [{ value, label, icon? }] */
export function Segmented({ options, value, onChange, label }) {
  return (
    <div className="segmented" role="group" aria-label={label}>
      {options.map((option) => {
        const Icon = option.icon;
        return (
          <button key={option.value} type="button" aria-pressed={value === option.value} onClick={() => onChange(option.value)}>
            {Icon && <Icon size={14} aria-hidden="true" />}
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/** Multi-select filter chips. options: [{ value, label }], value: string[] */
export function FilterChips({ options, value = [], onChange, label }) {
  const toggle = (optionValue) =>
    onChange(value.includes(optionValue) ? value.filter((item) => item !== optionValue) : [...value, optionValue]);
  return (
    <div className="chips" role="group" aria-label={label}>
      {options.map((option) => (
        <button key={option.value} type="button" className="chip" aria-pressed={value.includes(option.value)} onClick={() => toggle(option.value)}>
          {option.label}
        </button>
      ))}
    </div>
  );
}
