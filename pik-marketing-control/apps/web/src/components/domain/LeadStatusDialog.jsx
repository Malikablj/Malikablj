import { leadStatusSchema, LEAD_STATUS } from '@pik/shared';
import { useToast } from '../../context/contexts.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { Field, Textarea } from '../ui/Field.jsx';
import { FormActions, FormError } from './CrmForms.jsx';

/** Asks for the reason before a lead is marked LOST (required by the pipeline rules). */
export function LostReasonForm({ lead, onDone, onCancel }) {
  const toast = useToast();
  const form = useForm({ status: 'LOST', lost_reason: lead.lost_reason ?? '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(leadStatusSchema, async (data) => {
      if (!data.lost_reason) {
        form.setErrors({ lost_reason: 'Alasan lost wajib diisi.' });
        throw new Error('Alasan lost wajib diisi.');
      }
      const response = await api.patch(`/leads/${lead.id}/status`, data);
      toast.success(`Lead dipindahkan ke ${LEAD_STATUS.labels.LOST}.`);
      onDone(response.data);
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <Field label="Alasan lost" required error={form.errors.lost_reason} hint="Dipakai untuk analisis kenapa peluang tidak berhasil.">
        <Textarea rows={3} autoFocus {...form.bind('lost_reason')} placeholder="mis. harga kurang kompetitif, lead time terlalu lama" />
      </Field>
      <FormActions onCancel={onCancel} submitting={form.submitting} submitLabel="Tandai Lost" />
    </form>
  );
}
