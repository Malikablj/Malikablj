import { CircleAlert, Inbox } from 'lucide-react';
import { Button } from './Button.jsx';

export function EmptyState({ icon: Icon = Inbox, title = 'Belum ada data', text, action, compact = false }) {
  return (
    <div className={`state ${compact ? 'compact' : ''}`}>
      <div className="state-icon">
        <Icon size={22} aria-hidden="true" />
      </div>
      <div className="state-title">{title}</div>
      {text && <div className="state-text">{text}</div>}
      {action && <div style={{ marginTop: 8 }}>{action}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry, compact = false }) {
  return (
    <div className={`state error ${compact ? 'compact' : ''}`} role="alert">
      <div className="state-icon">
        <CircleAlert size={22} aria-hidden="true" />
      </div>
      <div className="state-title">Data gagal dimuat</div>
      <div className="state-text">{error?.message ?? 'Terjadi kesalahan. Silakan coba lagi.'}</div>
      {onRetry && (
        <Button size="sm" onClick={onRetry} style={{ marginTop: 8 }}>
          Coba lagi
        </Button>
      )}
    </div>
  );
}

export function LoadingState({ rows = 4 }) {
  return (
    <div className="skeleton-rows" aria-busy="true" aria-label="Memuat data">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="skeleton" style={{ height: 18, width: `${92 - index * 9}%` }} />
      ))}
    </div>
  );
}

export function PageLoading() {
  return (
    <div className="state" aria-busy="true">
      <span className="spinner" style={{ width: 22, height: 22, color: 'var(--color-accent)' }} aria-hidden="true" />
      <span className="state-text">Memuat…</span>
    </div>
  );
}
