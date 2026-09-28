/** Validation schemas for customers, contacts, leads, activities and follow-ups. */
import { z } from 'zod';
import {
  ACTIVITY_TYPE,
  CUSTOMER_STATUS,
  FOLLOW_UP_STATUS,
  LEAD_STATUS,
  PRIORITY,
} from '../constants.js';
import {
  booleanField,
  enumField,
  optionalDate,
  optionalEmail,
  optionalId,
  optionalNumber,
  optionalText,
  optionalTime,
  requiredDate,
  requiredDateTime,
  requiredId,
  requiredText,
} from './common.js';

// ---------------------------------------------------------------- customers
const customerBase = z.object({
  customer_code: optionalText('Kode customer', 100),
  name: requiredText('Nama customer', 255),
  industry: optionalText('Industri', 150),
  address: optionalText('Alamat', 2000),
  phone: optionalText('Telepon', 100),
  email: optionalEmail(),
  website: optionalText('Website', 255),
  notes: optionalText('Catatan', 5000),
});

export const customerCreateSchema = customerBase.extend({
  status: enumField('Status customer', CUSTOMER_STATUS.values).default('ACTIVE'),
});

export const customerUpdateSchema = customerBase
  .extend({ status: enumField('Status customer', CUSTOMER_STATUS.values) })
  .partial();

// ----------------------------------------------------------------- contacts
const contactBase = z.object({
  name: requiredText('Nama kontak', 150),
  position: optionalText('Jabatan', 150),
  phone: optionalText('Telepon', 100),
  email: optionalEmail(),
  whatsapp: optionalText('WhatsApp', 100),
  notes: optionalText('Catatan', 5000),
});

export const contactCreateSchema = contactBase.extend({ is_primary: booleanField().default(false) });
export const contactUpdateSchema = contactBase.extend({ is_primary: booleanField() }).partial();

// -------------------------------------------------------------------- leads
const leadBase = z.object({
  customer_id: requiredId('Customer'),
  contact_id: optionalId('Kontak'),
  product_id: optionalId('Produk'),
  name: requiredText('Nama lead', 255),
  source: optionalText('Sumber lead', 100),
  estimated_value: optionalNumber('Estimasi nilai', { min: 0 }),
  owner_user_id: optionalId('PIC'),
  expected_closing_date: optionalDate('Perkiraan closing'),
  notes: optionalText('Catatan', 5000),
  lost_reason: optionalText('Alasan lost', 2000),
});

export const leadCreateSchema = leadBase.extend({
  status: enumField('Status lead', LEAD_STATUS.values).default('NEW'),
  priority: enumField('Prioritas', PRIORITY.values).default('MEDIUM'),
});

export const leadUpdateSchema = leadBase
  .extend({
    status: enumField('Status lead', LEAD_STATUS.values),
    priority: enumField('Prioritas', PRIORITY.values),
  })
  .partial();

export const leadStatusSchema = z.object({
  status: enumField('Status lead', LEAD_STATUS.values),
  lost_reason: optionalText('Alasan lost', 2000),
});

// --------------------------------------------------------------- activities
const activityBase = z.object({
  customer_id: requiredId('Customer'),
  contact_id: optionalId('Kontak'),
  lead_id: optionalId('Lead'),
  type: enumField('Jenis aktivitas', ACTIVITY_TYPE.values),
  subject: requiredText('Judul aktivitas', 255),
  description: optionalText('Deskripsi', 10000),
  owner_user_id: optionalId('PIC'),
  activity_at: requiredDateTime('Waktu aktivitas'),
});

export const activityCreateSchema = activityBase;
export const activityUpdateSchema = activityBase.partial();

// --------------------------------------------------------------- follow-ups
const followUpBase = z.object({
  customer_id: requiredId('Customer'),
  lead_id: optionalId('Lead'),
  activity_id: optionalId('Aktivitas'),
  owner_user_id: optionalId('PIC'),
  follow_up_date: requiredDate('Tanggal follow up'),
  follow_up_time: optionalTime('Jam follow up'),
  notes: optionalText('Catatan', 5000),
});

export const followUpCreateSchema = followUpBase.extend({
  priority: enumField('Prioritas', PRIORITY.values).default('MEDIUM'),
  status: enumField('Status follow up', FOLLOW_UP_STATUS.values).default('PLANNED'),
});

export const followUpUpdateSchema = followUpBase
  .extend({
    priority: enumField('Prioritas', PRIORITY.values),
    status: enumField('Status follow up', FOLLOW_UP_STATUS.values),
  })
  .partial();

export const followUpCompleteSchema = z.object({
  outcome: optionalText('Hasil follow up', 5000),
});

export const followUpRescheduleSchema = z.object({
  follow_up_date: requiredDate('Tanggal baru'),
  follow_up_time: optionalTime('Jam follow up'),
  notes: optionalText('Catatan', 5000),
});
