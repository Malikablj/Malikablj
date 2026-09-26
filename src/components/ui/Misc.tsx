import type { ReactNode } from 'react';
import type { Tone } from '@/config/labels';
import { cn, initials } from '@/lib/utils';
import { TONE_MARK } from './tone';

const AVATAR_BG = ['bg-[#dbe8fb] text-[#1d4f91]', 'bg-[#e3f4e8] text-[#1a6b35]', 'bg-[#fdeede] text-[#8f4300]', 'bg-[#efe7fb] text-[#5b3a9b]', 'bg-[#fde8ee] text-[#9b2c4b]', 'bg-[#e6f3f6] text-[#12606e]'];

export function Avatar({ name, size = 'md', className }: { name: string; size?: 'sm' | 'md' | 'lg'; className?: string }) {
  const hash = [...name].reduce((s, c) => s + c.charCodeAt(0), 0);
  return (
    <span
      aria-hidden="true"
      className={cn(
        'inline-flex shrink-0 items-center justify-center rounded-full font-semibold',
        size === 'sm' ? 'size-6 text-[10px]' : size === 'lg' ? 'size-10 text-[14px]' : 'size-8 text-[12px]',
        AVATAR_BG[hash % AVATAR_BG.length],
        className,
      )}
    >
      {initials(name)}
    </span>
  );
}

export function ProgressBar({ value, tone = 'blue', label, className }: { value: number; tone?: Tone; label?: string; className?: string }) {
  const v = Math.max(0, Math.min(100, value));
  return (
    <div
      role="progressbar"
      aria-valuenow={Math.round(v)}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
      className={cn('h-1.5 w-full overflow-hidden rounded-full bg-mark-track', className)}
    >
      <div className={cn('h-full rounded-full transition-[width] duration-500', TONE_MARK[tone])} style={{ width: `${v}%` }} />
    </div>
  );
}

export function Kbd({ children }: { children: ReactNode }) {
  return <kbd className="rounded border border-line-strong bg-surface-2 px-1.5 py-0.5 font-sans text-[11px] text-ink-2">{children}</kbd>;
}

export function Divider({ className }: { className?: string }) {
  return <hr className={cn('border-line', className)} />;
}
