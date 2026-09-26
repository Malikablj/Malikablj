import type { ReactNode } from 'react';
import { useDocumentTitle } from '@/hooks/useUtils';
import { cn } from '@/lib/utils';

export function PageHeader({
  title,
  description,
  actions,
  eyebrow,
  className,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
  eyebrow?: ReactNode;
  className?: string;
}) {
  useDocumentTitle(title);
  return (
    <div className={cn('mb-6 flex flex-col gap-4 md:mb-8 md:flex-row md:items-end md:justify-between', className)}>
      <div className="min-w-0">
        {eyebrow && <div className="mb-1.5 text-[13px] text-ink-3">{eyebrow}</div>}
        <h1 className="text-[26px] leading-tight font-semibold tracking-[-0.022em] text-ink md:text-[30px]">{title}</h1>
        {description && <p className="mt-1.5 max-w-2xl text-[14px] text-ink-2">{description}</p>}
      </div>
      {actions && <div className="no-print flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
