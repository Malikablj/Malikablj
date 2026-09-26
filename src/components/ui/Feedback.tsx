import { AlertTriangle, Inbox, Lock, RefreshCw, SearchX } from 'lucide-react';
import type { ReactNode } from 'react';
import { errorMessage, isAppError } from '@/domain/errors';
import { cn } from '@/lib/utils';

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={cn('animate-spin text-current', className ?? 'size-5')} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.2" strokeWidth="3" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse rounded-lg bg-surface-3/70', className)} aria-hidden="true" />;
}

export function PageSkeleton() {
  return (
    <div className="space-y-6" role="status" aria-label="Memuat">
      <Skeleton className="h-8 w-64" />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-24" />
        ))}
      </div>
      <Skeleton className="h-64" />
      <Skeleton className="h-48" />
    </div>
  );
}

export function ListSkeleton({ rows = 5 }: { rows?: number }) {
  return (
    <div className="space-y-2" role="status" aria-label="Memuat">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-14" />
      ))}
    </div>
  );
}

export function EmptyState({
  title,
  description,
  action,
  icon,
  compact,
}: {
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  icon?: ReactNode;
  compact?: boolean;
}) {
  return (
    <div className={cn('flex flex-col items-center justify-center text-center', compact ? 'px-4 py-8' : 'px-6 py-14')}>
      <div className="mb-3 flex size-11 items-center justify-center rounded-full bg-surface-2 text-ink-3">{icon ?? <Inbox className="size-5" />}</div>
      <p className="text-[15px] font-semibold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-[13px] text-ink-2">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function NoResults({ onReset }: { onReset?: () => void }) {
  return (
    <EmptyState
      compact
      icon={<SearchX className="size-5" />}
      title="Tidak ada hasil"
      description="Tidak ada data yang cocok dengan pencarian atau filter."
      action={
        onReset && (
          <button type="button" onClick={onReset} className="text-[13px] font-medium text-accent-ink hover:underline">
            Reset filter
          </button>
        )
      }
    />
  );
}

export function ErrorState({ error, onRetry, compact }: { error: unknown; onRetry?: () => void; compact?: boolean }) {
  const forbidden = isAppError(error) && (error.code === 'FORBIDDEN' || error.code === 'UNAUTHORIZED');
  const notFound = isAppError(error) && error.code === 'NOT_FOUND';
  return (
    <div role="alert" className={cn('flex flex-col items-center justify-center text-center', compact ? 'px-4 py-8' : 'px-6 py-16')}>
      <div className={cn('mb-3 flex size-11 items-center justify-center rounded-full', forbidden ? 'bg-surface-2 text-ink-2' : 'bg-tone-red-soft text-tone-red')}>
        {forbidden ? <Lock className="size-5" /> : <AlertTriangle className="size-5" />}
      </div>
      <p className="text-[15px] font-semibold text-ink">{forbidden ? 'Akses dibatasi' : notFound ? 'Data tidak ditemukan' : 'Gagal memuat data'}</p>
      <p className="mt-1 max-w-sm text-[13px] text-ink-2">{errorMessage(error)}</p>
      {onRetry && !forbidden && !notFound && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-4 inline-flex h-9 items-center gap-2 rounded-[10px] border border-line-strong bg-surface px-3 text-[13px] font-medium hover:bg-surface-2"
        >
          <RefreshCw className="size-4" /> Coba lagi
        </button>
      )}
    </div>
  );
}

export function InlineAlert({ tone = 'red', title, children }: { tone?: 'red' | 'yellow' | 'blue' | 'green'; title?: string; children?: ReactNode }) {
  const cls = {
    red: 'bg-tone-red-soft text-tone-red',
    yellow: 'bg-tone-yellow-soft text-tone-yellow',
    blue: 'bg-tone-blue-soft text-tone-blue',
    green: 'bg-tone-green-soft text-tone-green',
  }[tone];
  return (
    <div role={tone === 'red' ? 'alert' : 'status'} className={cn('rounded-xl px-3.5 py-3 text-[13px] leading-relaxed', cls)}>
      {title && <p className="font-semibold">{title}</p>}
      {children && <div className={cn(title && 'mt-0.5', 'text-ink/85')}>{children}</div>}
    </div>
  );
}
