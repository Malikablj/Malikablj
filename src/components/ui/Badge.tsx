import type { ReactNode } from 'react';
import type { Tone } from '@/config/labels';
import { cn } from '@/lib/utils';
import { TONE_CHIP, TONE_MARK } from './tone';

/** Status chip: soft tint + text label (color is only a secondary signal). */
export function Badge({ tone = 'gray', children, dot, className, size = 'md' }: { tone?: Tone; children: ReactNode; dot?: boolean; className?: string; size?: 'sm' | 'md' }) {
  return (
    <span
      className={cn(
        'inline-flex max-w-full shrink-0 items-center gap-1.5 truncate rounded-full font-medium',
        size === 'sm' ? 'h-5 px-2 text-[11px]' : 'h-6 px-2.5 text-[12px]',
        TONE_CHIP[tone],
        className,
      )}
    >
      {dot && <span className={cn('size-1.5 shrink-0 rounded-full', TONE_MARK[tone])} aria-hidden="true" />}
      <span className="truncate">{children}</span>
    </span>
  );
}

export function Tag({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <span className={cn('inline-flex h-6 shrink-0 items-center rounded-md bg-surface-2 px-2 text-[12px] font-medium text-ink-2', className)}>{children}</span>
  );
}
