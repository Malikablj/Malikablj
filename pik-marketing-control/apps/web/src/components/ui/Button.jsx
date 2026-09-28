import { Link } from 'react-router';

/**
 * Button (or router link styled as a button).
 * variant: primary | secondary | ghost | danger | success · size: md | sm · icon: square icon button
 */
export function Button({ variant = 'secondary', size = 'md', icon = false, block = false, loading = false, to, className = '', children, disabled, type = 'button', ...props }) {
  const classes = ['btn', `btn-${variant}`, size === 'sm' && 'btn-sm', icon && 'btn-icon', block && 'btn-block', className]
    .filter(Boolean)
    .join(' ');
  if (to) {
    return (
      <Link to={to} className={classes} {...props}>
        {children}
      </Link>
    );
  }
  return (
    <button type={type} className={classes} disabled={disabled || loading} aria-busy={loading || undefined} {...props}>
      {loading && <span className="spinner" aria-hidden="true" />}
      {children}
    </button>
  );
}
