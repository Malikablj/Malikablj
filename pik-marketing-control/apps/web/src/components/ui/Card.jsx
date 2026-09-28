import { Link } from 'react-router';

export function Card({ className = '', children, ...props }) {
  return (
    <section className={`card ${className}`} {...props}>
      {children}
    </section>
  );
}

export function CardHeader({ title, subtitle, actions }) {
  return (
    <div className="card-header">
      <div className="grow">
        <h2>{title}</h2>
        {subtitle && <div className="card-subtitle">{subtitle}</div>}
      </div>
      {actions && <div className="row">{actions}</div>}
    </div>
  );
}

/** KPI tile. tone: default | warning | danger · to: optional link to the filtered list */
export function KpiCard({ label, value, note, tone, to, icon: Icon, small = false }) {
  const content = (
    <>
      <span className="kpi-label">
        {Icon && <Icon size={15} aria-hidden="true" />}
        {label}
      </span>
      <span className={`kpi-value ${small ? 'small' : ''}`}>{value}</span>
      {note && <span className="kpi-note">{note}</span>}
    </>
  );
  const className = `card kpi ${tone ?? ''}`;
  return to ? (
    <Link to={to} className={className}>
      {content}
    </Link>
  ) : (
    <div className={className}>{content}</div>
  );
}

/** Horizontal strip of labelled figures (detail page headers). */
export function StatStrip({ items }) {
  return (
    <div className="stat-strip">
      {items.map((item) => (
        <div key={item.label}>
          <div className="stat-label">{item.label}</div>
          <div className={`stat-value ${item.className ?? ''}`}>{item.value}</div>
        </div>
      ))}
    </div>
  );
}
