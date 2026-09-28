/** Create/edit forms for customers, contacts, leads, activities and follow-ups. */
import {
  ACTIVITY_TYPE,
  activityCreateSchema,
  activityUpdateSchema,
  contactCreateSchema,
  contactUpdateSchema,
  CUSTOMER_STATUS,
  customerCreateSchema,
  customerUpdateSchema,
  FOLLOW_UP_STATUS,
  followUpCreateSchema,
  followUpUpdateSchema,
  LEAD_SOURCE_SUGGESTIONS,
  LEAD_STATUS,
  leadCreateSchema,
  leadUpdateSchema,
  PRIORITY,
} from '@pik/shared';
import { useId } from 'react';
import { useAuth, useToast } from '../../context/contexts.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { fromDateTimeLocal, toDateTimeLocal } from '../../utils/format.js';
import { Button } from '../ui/Button.jsx';
import { Checkbox, Field, Input, Select, Textarea } from '../ui/Field.jsx';
import { ContactSelect, CustomerSelect, LeadSelect, ProductSelect, UserSelect } from './pickers.jsx';

const nullIfEmpty = (value) => (value === '' || value === undefined ? null : value);

export function FormActions({ onCancel, submitting, submitLabel = 'Simpan' }) {
  return (
    <div className="form-actions">
      {onCancel && (
        <Button variant="secondary" onClick={onCancel} disabled={submitting}>
          Batal
        </Button>
      )}
      <Button type="submit" variant="primary" loading={submitting}>
        {submitLabel}
      </Button>
    </div>
  );
}

export function FormError({ message }) {
  return message ? (
    <div className="form-alert" role="alert">
      {message}
    </div>
  ) : null;
}

// ------------------------------------------------------------------ customer
export function CustomerForm({ customer, onSaved, onCancel }) {
  const toast = useToast();
  const form = useForm({
    customer_code: customer?.customer_code ?? '',
    name: customer?.name ?? '',
    industry: customer?.industry ?? '',
    status: customer?.status ?? 'ACTIVE',
    phone: customer?.phone ?? '',
    email: customer?.email ?? '',
    website: customer?.website ?? '',
    address: customer?.address ?? '',
    notes: customer?.notes ?? '',
  });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(customer ? customerUpdateSchema : customerCreateSchema, async (data) => {
      const response = customer ? await api.put(`/customers/${customer.id}`, data) : await api.post('/customers', data);
      toast.success(customer ? 'Data customer disimpan.' : 'Customer ditambahkan.');
      onSaved(response.data);
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nama customer" required error={form.errors.name} className="span-2">
          <Input {...form.bind('name')} autoFocus />
        </Field>
        <Field label="Kode customer" error={form.errors.customer_code} hint="Opsional, harus unik">
          <Input {...form.bind('customer_code')} />
        </Field>
        <Field label="Status" error={form.errors.status}>
          <Select options={CUSTOMER_STATUS.options} {...form.bind('status')} />
        </Field>
        <Field label="Industri" error={form.errors.industry}>
          <Input {...form.bind('industry')} placeholder="mis. Kosmetik" />
        </Field>
        <Field label="Telepon" error={form.errors.phone}>
          <Input type="tel" {...form.bind('phone')} />
        </Field>
        <Field label="Email" error={form.errors.email}>
          <Input type="email" {...form.bind('email')} />
        </Field>
        <Field label="Website" error={form.errors.website}>
          <Input {...form.bind('website')} />
        </Field>
        <Field label="Alamat" error={form.errors.address} className="span-2">
          <Textarea rows={2} {...form.bind('address')} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={3} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ------------------------------------------------------------------- contact
export function ContactForm({ customerId, contact, onSaved, onCancel }) {
  const toast = useToast();
  const form = useForm({
    name: contact?.name ?? '',
    position: contact?.position ?? '',
    phone: contact?.phone ?? '',
    whatsapp: contact?.whatsapp ?? '',
    email: contact?.email ?? '',
    is_primary: contact?.is_primary ?? false,
    notes: contact?.notes ?? '',
  });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(contact ? contactUpdateSchema : contactCreateSchema, async (data) => {
      const response = contact
        ? await api.put(`/contacts/${contact.id}`, data)
        : await api.post(`/customers/${customerId}/contacts`, data);
      toast.success(contact ? 'Kontak disimpan.' : 'Kontak ditambahkan.');
      onSaved(response.data);
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nama" required error={form.errors.name}>
          <Input {...form.bind('name')} autoFocus />
        </Field>
        <Field label="Jabatan" error={form.errors.position}>
          <Input {...form.bind('position')} />
        </Field>
        <Field label="WhatsApp" error={form.errors.whatsapp}>
          <Input type="tel" {...form.bind('whatsapp')} placeholder="08…" />
        </Field>
        <Field label="Telepon" error={form.errors.phone}>
          <Input type="tel" {...form.bind('phone')} />
        </Field>
        <Field label="Email" error={form.errors.email} className="span-2">
          <Input type="email" {...form.bind('email')} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
        <Checkbox
          label="Kontak utama"
          checked={Boolean(form.values.is_primary)}
          onChange={(event) => form.setValue('is_primary', event.target.checked)}
        />
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ---------------------------------------------------------------------- lead
export function LeadForm({ lead, customer, onSaved, onCancel }) {
  const toast = useToast();
  const { user } = useAuth();
  const sourcesId = useId();
  const form = useForm({
    customer_id: lead?.customer_id ?? customer?.id ?? '',
    customer_name: lead?.customer_name ?? customer?.name ?? '',
    contact_id: lead?.contact_id ?? '',
    product_id: lead?.product_id ?? '',
    product_name: lead?.product_name ?? '',
    name: lead?.name ?? '',
    source: lead?.source ?? '',
    estimated_value: lead?.estimated_value ?? '',
    status: lead?.status ?? 'NEW',
    priority: lead?.priority ?? 'MEDIUM',
    owner_user_id: lead?.owner_user_id ?? user.id,
    expected_closing_date: lead?.expected_closing_date ?? '',
    notes: lead?.notes ?? '',
    lost_reason: lead?.lost_reason ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      lead ? leadUpdateSchema : leadCreateSchema,
      async (data) => {
        const response = lead ? await api.put(`/leads/${lead.id}`, data) : await api.post('/leads', data);
        toast.success(lead ? 'Lead disimpan.' : 'Lead ditambahkan.');
        onSaved(response.data);
      },
      ({ customer_name: _c, product_name: _p, ...values }) => ({
        ...values,
        contact_id: nullIfEmpty(values.contact_id),
        product_id: nullIfEmpty(values.product_id),
        owner_user_id: nullIfEmpty(values.owner_user_id),
      }),
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nama lead / peluang" required error={form.errors.name} className="span-2">
          <Input {...form.bind('name')} placeholder="mis. Botol serum 30ml untuk produk baru" autoFocus />
        </Field>
        <Field label="Customer" required error={form.errors.customer_id}>
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            disabled={Boolean(customer)}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
              form.setValue('contact_id', '');
            }}
          />
        </Field>
        <Field label="Kontak" error={form.errors.contact_id}>
          <ContactSelect customerId={v.customer_id} {...form.bind('contact_id')} />
        </Field>
        <Field label="Produk" error={form.errors.product_id}>
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Sumber" error={form.errors.source}>
          <Input list={sourcesId} {...form.bind('source')} />
        </Field>
        <datalist id={sourcesId}>
          {LEAD_SOURCE_SUGGESTIONS.map((source) => (
            <option key={source} value={source} />
          ))}
        </datalist>
        <Field label="Estimasi nilai (Rp)" error={form.errors.estimated_value}>
          <Input type="number" inputMode="numeric" min="0" step="1000" {...form.bind('estimated_value')} />
        </Field>
        <Field label="Perkiraan closing" error={form.errors.expected_closing_date}>
          <Input type="date" {...form.bind('expected_closing_date')} />
        </Field>
        <Field label="Status" error={form.errors.status}>
          <Select options={LEAD_STATUS.options} {...form.bind('status')} />
        </Field>
        <Field label="Prioritas" error={form.errors.priority}>
          <Select options={PRIORITY.options} {...form.bind('priority')} />
        </Field>
        <Field label="PIC" error={form.errors.owner_user_id}>
          <UserSelect {...form.bind('owner_user_id')} />
        </Field>
        {v.status === 'LOST' && (
          <Field label="Alasan lost" required error={form.errors.lost_reason} className="span-2">
            <Textarea rows={2} {...form.bind('lost_reason')} placeholder="mis. harga, lead time, pilih supplier lain" />
          </Field>
        )}
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={3} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ------------------------------------------------------------------ activity
/** `defaults.activity_at` should be a datetime-local string computed when the form is opened. */
export function ActivityForm({ activity, customer, lead, defaults = {}, onSaved, onCancel }) {
  const toast = useToast();
  const { user } = useAuth();
  const form = useForm({
    customer_id: activity?.customer_id ?? customer?.id ?? lead?.customer_id ?? '',
    customer_name: activity?.customer_name ?? customer?.name ?? lead?.customer_name ?? '',
    contact_id: activity?.contact_id ?? lead?.contact_id ?? '',
    lead_id: activity?.lead_id ?? lead?.id ?? '',
    type: activity?.type ?? defaults.type ?? 'WHATSAPP',
    subject: activity?.subject ?? '',
    description: activity?.description ?? '',
    owner_user_id: activity?.owner_user_id ?? user.id,
    activity_at: activity?.activity_at ? toDateTimeLocal(activity.activity_at) : (defaults.activity_at ?? ''),
  });
  const v = form.values;
  const fixedCustomer = Boolean(customer || lead);
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      activity ? activityUpdateSchema : activityCreateSchema,
      async (data) => {
        const response = activity ? await api.put(`/activities/${activity.id}`, data) : await api.post('/activities', data);
        toast.success(activity ? 'Aktivitas disimpan.' : 'Aktivitas dicatat.');
        onSaved(response.data);
      },
      ({ customer_name: _c, ...values }) => ({
        ...values,
        contact_id: nullIfEmpty(values.contact_id),
        lead_id: nullIfEmpty(values.lead_id),
        owner_user_id: nullIfEmpty(values.owner_user_id),
        activity_at: fromDateTimeLocal(values.activity_at),
      }),
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Jenis aktivitas" required error={form.errors.type}>
          <Select options={ACTIVITY_TYPE.options} {...form.bind('type')} />
        </Field>
        <Field label="Waktu" required error={form.errors.activity_at}>
          <Input type="datetime-local" {...form.bind('activity_at')} />
        </Field>
        <Field label="Judul" required error={form.errors.subject} className="span-2">
          <Input {...form.bind('subject')} placeholder="mis. Kirim penawaran botol 100ml" autoFocus />
        </Field>
        <Field label="Customer" required error={form.errors.customer_id}>
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            disabled={fixedCustomer}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
              form.setValue('contact_id', '');
              form.setValue('lead_id', '');
            }}
          />
        </Field>
        <Field label="Kontak" error={form.errors.contact_id}>
          <ContactSelect customerId={v.customer_id} {...form.bind('contact_id')} />
        </Field>
        <Field label="Lead" error={form.errors.lead_id}>
          <LeadSelect customerId={v.customer_id} {...form.bind('lead_id')} disabled={Boolean(lead) || !v.customer_id} />
        </Field>
        <Field label="PIC" error={form.errors.owner_user_id}>
          <UserSelect {...form.bind('owner_user_id')} />
        </Field>
        <Field label="Deskripsi / hasil" error={form.errors.description} className="span-2">
          <Textarea rows={4} {...form.bind('description')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ----------------------------------------------------------------- follow-up
export function FollowUpForm({ followUp, customer, lead, defaults = {}, onSaved, onCancel }) {
  const toast = useToast();
  const { user } = useAuth();
  const form = useForm({
    customer_id: followUp?.customer_id ?? customer?.id ?? lead?.customer_id ?? '',
    customer_name: followUp?.customer_name ?? customer?.name ?? lead?.customer_name ?? '',
    lead_id: followUp?.lead_id ?? lead?.id ?? '',
    follow_up_date: followUp?.follow_up_date ?? defaults.follow_up_date ?? '',
    follow_up_time: followUp?.follow_up_time?.slice(0, 5) ?? '',
    priority: followUp?.priority ?? 'MEDIUM',
    status: followUp?.status ?? 'PLANNED',
    owner_user_id: followUp?.owner_user_id ?? user.id,
    notes: followUp?.notes ?? '',
  });
  const v = form.values;
  const fixedCustomer = Boolean(customer || lead);
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      followUp ? followUpUpdateSchema : followUpCreateSchema,
      async (data) => {
        const response = followUp ? await api.put(`/follow-ups/${followUp.id}`, data) : await api.post('/follow-ups', data);
        toast.success(followUp ? 'Follow up disimpan.' : 'Follow up dijadwalkan.');
        onSaved(response.data);
      },
      ({ customer_name: _c, status, ...values }) => ({
        ...values,
        ...(followUp ? { status } : {}),
        lead_id: nullIfEmpty(values.lead_id),
        owner_user_id: nullIfEmpty(values.owner_user_id),
        follow_up_time: nullIfEmpty(values.follow_up_time),
      }),
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Customer" required error={form.errors.customer_id} className="span-2">
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            disabled={fixedCustomer}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
              form.setValue('lead_id', '');
            }}
          />
        </Field>
        <Field label="Tanggal" required error={form.errors.follow_up_date}>
          <Input type="date" {...form.bind('follow_up_date')} />
        </Field>
        <Field label="Jam" error={form.errors.follow_up_time} hint="Opsional">
          <Input type="time" {...form.bind('follow_up_time')} />
        </Field>
        <Field label="Lead" error={form.errors.lead_id}>
          <LeadSelect customerId={v.customer_id} {...form.bind('lead_id')} disabled={Boolean(lead) || !v.customer_id} />
        </Field>
        <Field label="Prioritas" error={form.errors.priority}>
          <Select options={PRIORITY.options} {...form.bind('priority')} />
        </Field>
        <Field label="PIC" error={form.errors.owner_user_id}>
          <UserSelect {...form.bind('owner_user_id')} />
        </Field>
        {followUp && (
          <Field label="Status" error={form.errors.status}>
            <Select options={FOLLOW_UP_STATUS.options.filter((option) => option.value !== 'OVERDUE' || followUp.status === 'OVERDUE')} {...form.bind('status')} />
          </Field>
        )}
        <Field label="Catatan / yang akan dibahas" error={form.errors.notes} className="span-2">
          <Textarea rows={3} {...form.bind('notes')} autoFocus={fixedCustomer} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}
