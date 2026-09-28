/** Validation schemas for authentication, users, finance and migration issue review. */
import { z } from 'zod';
import { MIGRATION_ISSUE_STATUS, ROLE } from '../constants.js';
import {
  booleanField,
  enumField,
  optionalDate,
  optionalNumber,
  optionalText,
  requiredDate,
  requiredEmail,
  requiredId,
  requiredNumber,
  requiredText,
} from './common.js';

export const PASSWORD_MIN_LENGTH = 10;

const password = (label = 'Password') =>
  z
    .string({ error: `${label} wajib diisi.` })
    .min(PASSWORD_MIN_LENGTH, `${label} minimal ${PASSWORD_MIN_LENGTH} karakter.`)
    .max(200, `${label} maksimal 200 karakter.`);

// --------------------------------------------------------------------- auth
export const loginSchema = z.object({
  email: requiredEmail(),
  password: z.string({ error: 'Password wajib diisi.' }).min(1, 'Password wajib diisi.').max(200),
});

export const changePasswordSchema = z
  .object({
    current_password: z.string({ error: 'Password lama wajib diisi.' }).min(1, 'Password lama wajib diisi.'),
    new_password: password('Password baru'),
  })
  .refine((v) => v.current_password !== v.new_password, {
    path: ['new_password'],
    message: 'Password baru harus berbeda dari password lama.',
  });

// -------------------------------------------------------------------- users
export const userCreateSchema = z.object({
  name: requiredText('Nama', 150),
  email: requiredEmail(),
  role: enumField('Role', ROLE.values),
  password: password(),
  is_active: booleanField().default(true),
});

export const userUpdateSchema = z
  .object({
    name: requiredText('Nama', 150),
    email: requiredEmail(),
    role: enumField('Role', ROLE.values),
    is_active: booleanField(),
  })
  .partial();

export const userResetPasswordSchema = z.object({ password: password('Password baru') });

// ------------------------------------------------------------------ finance
const invoiceBase = z.object({
  purchase_order_id: requiredId('PO'),
  invoice_number: requiredText('Nomor invoice', 150),
  invoice_date: requiredDate('Tanggal invoice'),
  due_date: optionalDate('Jatuh tempo'),
  amount: requiredNumber('Nilai invoice', { min: 0, max: 9_999_999_999_999 }),
  paid_amount: optionalNumber('Jumlah dibayar', { min: 0, max: 9_999_999_999_999 }),
  payment_date: optionalDate('Tanggal pembayaran'),
  is_cancelled: booleanField().optional(),
  notes: optionalText('Catatan', 5000),
});

const dueAfterInvoice = (value, ctx) => {
  if (value.invoice_date && value.due_date && value.due_date < value.invoice_date) {
    ctx.addIssue({ code: 'custom', path: ['due_date'], message: 'Jatuh tempo tidak boleh sebelum tanggal invoice.' });
  }
};

export const invoiceCreateSchema = invoiceBase.superRefine(dueAfterInvoice);
export const invoiceUpdateSchema = invoiceBase.partial().superRefine(dueAfterInvoice);

// Provisional structure until the source workbook is profiled (docs/DECISIONS.md).
const poFinancialBase = z.object({
  purchase_order_id: requiredId('PO'),
  currency: optionalText('Mata uang', 10),
  po_value: optionalNumber('Nilai PO', { min: 0 }),
  tax_amount: optionalNumber('Pajak', { min: 0 }),
  total_amount: optionalNumber('Total', { min: 0 }),
  invoiced_amount: optionalNumber('Sudah ditagih', { min: 0 }),
  paid_amount: optionalNumber('Sudah dibayar', { min: 0 }),
  outstanding_amount: optionalNumber('Sisa tagihan'),
  notes: optionalText('Catatan', 5000),
});

export const poFinancialCreateSchema = poFinancialBase;
export const poFinancialUpdateSchema = poFinancialBase.partial();

// ---------------------------------------------------------- migration issues
export const migrationIssueUpdateSchema = z.object({
  resolution_status: enumField('Status', MIGRATION_ISSUE_STATUS.values),
  resolution_notes: optionalText('Catatan penyelesaian', 5000),
});
