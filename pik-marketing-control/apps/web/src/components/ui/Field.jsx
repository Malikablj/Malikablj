import { cloneElement, isValidElement, useId } from 'react';

/**
 * Labelled form field. The label is always visible; hint and error are linked to the control
 * with aria-describedby so screen readers announce them.
 */
export function Field({ label, required = false, hint, error, className = '', children }) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const control = isValidElement(children)
    ? cloneElement(children, {
        id: children.props.id ?? id,
        'aria-describedby': [hintId, errorId].filter(Boolean).join(' ') || undefined,
        'aria-invalid': error ? 'true' : children.props['aria-invalid'],
        required: children.props.required ?? required,
      })
    : children;
  return (
    <div className={`field ${className}`}>
      {label && (
        <label className="field-label" htmlFor={children?.props?.id ?? id}>
          {label}
          {required && (
            <span className="required" aria-hidden="true">
              *
            </span>
          )}
        </label>
      )}
      {control}
      {hint && !error && (
        <span className="field-hint" id={hintId}>
          {hint}
        </span>
      )}
      {error && (
        <span className="field-error" id={errorId} role="alert">
          {error}
        </span>
      )}
    </div>
  );
}

export function Input({ className = '', ...props }) {
  return <input className={`input ${className}`} {...props} />;
}

export function Textarea({ className = '', ...props }) {
  return <textarea className={`textarea ${className}`} {...props} />;
}

/** options: [{ value, label }] · placeholder renders an empty first option */
export function Select({ options = [], placeholder, className = '', ...props }) {
  return (
    <select className={`select ${className}`} {...props}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

export function Checkbox({ label, className = '', ...props }) {
  return (
    <label className={`checkbox ${className}`}>
      <input type="checkbox" {...props} />
      <span>{label}</span>
    </label>
  );
}
