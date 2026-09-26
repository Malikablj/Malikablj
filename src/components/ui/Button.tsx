import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link, type LinkProps } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { Spinner } from './Feedback';

type Variant = 'primary' | 'secondary' | 'ghost' | 'destructive' | 'plain';
type Size = 'sm' | 'md' | 'lg';

const base =
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-[10px] font-medium transition-[background-color,color,box-shadow,opacity] duration-150 select-none disabled:opacity-45 disabled:pointer-events-none';

const variants: Record<Variant, string> = {
  primary: 'bg-accent text-white hover:bg-accent-hover active:opacity-90 shadow-[0_1px_1px_rgba(0,0,0,0.06)]',
  secondary: 'bg-surface text-ink border border-line-strong hover:bg-surface-2 active:bg-surface-3',
  ghost: 'text-ink-2 hover:bg-surface-2 hover:text-ink active:bg-surface-3',
  destructive: 'bg-tone-red-soft text-tone-red hover:brightness-95 active:brightness-90',
  plain: 'text-accent-ink hover:underline underline-offset-2 px-0',
};

const sizes: Record<Size, string> = {
  sm: 'h-8 px-3 text-[13px]',
  md: 'h-10 px-4 text-[14px] max-md:h-11',
  lg: 'h-12 px-5 text-[15px]',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  block?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'secondary', size = 'md', loading, icon, block, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(base, variants[variant], variant !== 'plain' && sizes[size], block && 'w-full', className)}
      {...rest}
    >
      {loading ? <Spinner className="size-4" /> : icon}
      {children}
    </button>
  );
});

export function ButtonLink({
  variant = 'secondary',
  size = 'md',
  icon,
  className,
  children,
  ...rest
}: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
  return (
    <Link className={cn(base, variants[variant], variant !== 'plain' && sizes[size], className)} {...rest}>
      {icon}
      {children}
    </Link>
  );
}

export const IconButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & { label: string; size?: 'sm' | 'md' }>(
  function IconButton({ label, size = 'md', className, children, type = 'button', ...rest }, ref) {
    return (
      <button
        ref={ref}
        type={type}
        aria-label={label}
        title={label}
        className={cn(
          'inline-flex shrink-0 items-center justify-center rounded-[10px] text-ink-2 transition-colors hover:bg-surface-2 hover:text-ink active:bg-surface-3 disabled:opacity-40',
          size === 'sm' ? 'size-8' : 'size-10 max-md:size-11',
          className,
        )}
        {...rest}
      >
        {children}
      </button>
    );
  },
);
