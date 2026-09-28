const TONE_CLASS = {
  success: 'badge-success',
  warning: 'badge-warning',
  danger: 'badge-danger',
  info: 'badge-info',
  accent: 'badge-accent',
  neutral: '',
};

export function Badge({ tone = 'neutral', children, className = '' }) {
  return <span className={`badge ${TONE_CLASS[tone] ?? ''} ${className}`}>{children}</span>;
}

/** Badge for an enum value, e.g. <StatusBadge enumDef={PO_STATUS} value="OPEN" /> */
export function StatusBadge({ enumDef, value }) {
  if (!value) return <span className="muted">–</span>;
  return <Badge tone={enumDef.tones[value]}>{enumDef.labels[value] ?? value}</Badge>;
}

export function PriorityDot({ priority }) {
  if (!priority) return null;
  const label = { HIGH: 'Prioritas tinggi', MEDIUM: 'Prioritas sedang', LOW: 'Prioritas rendah' }[priority];
  return <span className={`priority-dot ${priority}`} role="img" aria-label={label} title={label} />;
}
