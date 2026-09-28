import { fieldErrors } from '@pik/shared';
import { useCallback, useState } from 'react';

/**
 * Minimal form state: values, field errors and submission. Validates with the same zod schema
 * the API uses (early feedback), then shows server validation errors on the same fields.
 * User input is preserved when validation fails.
 */
export function useForm(initialValues) {
  const [values, setValues] = useState(initialValues);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  const setValue = useCallback((name, value) => {
    setValues((current) => ({ ...current, [name]: value }));
    setErrors((current) => (current[name] ? { ...current, [name]: undefined } : current));
  }, []);

  /** Props for a native input/select/textarea bound to `name`. */
  const bind = (name) => ({
    name,
    value: values[name] ?? '',
    onChange: (event) => setValue(name, event.target.type === 'checkbox' ? event.target.checked : event.target.value),
    'aria-invalid': errors[name] ? 'true' : undefined,
  });

  /**
   * @param schema zod schema (from @pik/shared) or null to skip client validation
   * @param action async (data) => void; its thrown ApiError is shown on the form
   * @param transform optional (values) => values before validation
   */
  async function submit(schema, action, transform = (v) => v) {
    setFormError(null);
    const input = transform(values);
    let data = input;
    if (schema) {
      const parsed = schema.safeParse(input);
      if (!parsed.success) {
        const found = fieldErrors(parsed.error);
        setErrors(found);
        setFormError(found._form ?? 'Periksa kembali isian yang ditandai.');
        return false;
      }
      data = parsed.data;
    }
    setSubmitting(true);
    try {
      await action(data);
      return true;
    } catch (error) {
      if (error.fieldErrors) setErrors(error.fieldErrors);
      setFormError(error.message ?? 'Data gagal disimpan. Silakan coba lagi.');
      return false;
    } finally {
      setSubmitting(false);
    }
  }

  return { values, setValues, setValue, errors, setErrors, formError, setFormError, submitting, bind, submit };
}
