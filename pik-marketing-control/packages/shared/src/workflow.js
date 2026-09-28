/**
 * Status transition rules. The API enforces these; the UI uses them to disable
 * transitions that would be rejected.
 */
import { LEAD_STATUS, PO_STATUS } from './constants.js';

/**
 * Lead pipeline rules:
 * - Any stage may move to any other stage (sales pipelines move backwards too).
 * - A WON lead is closed business: only an Admin may reopen it.
 * - Moving to LOST requires a reason, so lost-deal analysis is possible.
 *
 * @returns {{ ok: true } | { ok: false, reason: string }}
 */
export function checkLeadTransition({ from, to, role, lostReason }) {
  if (!LEAD_STATUS.values.includes(to)) {
    return { ok: false, reason: 'Status lead tidak dikenal.' };
  }
  if (from === to) return { ok: true };
  if (from === 'WON' && role !== 'ADMIN') {
    return { ok: false, reason: 'Lead yang sudah Won hanya dapat dibuka kembali oleh Admin.' };
  }
  if (to === 'LOST' && !(typeof lostReason === 'string' && lostReason.trim())) {
    return { ok: false, reason: 'Alasan lost wajib diisi.' };
  }
  return { ok: true };
}

/**
 * Purchase order status rules:
 * - OPEN, ON_PROCESS, PARTIAL and CLOSED may move freely between each other.
 * - Cancelling requires a reason (POs are cancelled, never deleted).
 * - A CANCELLED PO may only be reopened (to OPEN) by an Admin.
 *
 * @returns {{ ok: true } | { ok: false, reason: string }}
 */
export function checkPoTransition({ from, to, role, cancelReason }) {
  if (!PO_STATUS.values.includes(to)) {
    return { ok: false, reason: 'Status PO tidak dikenal.' };
  }
  if (from === to) return { ok: true };
  if (from === 'CANCELLED') {
    if (role === 'ADMIN' && to === 'OPEN') return { ok: true };
    return { ok: false, reason: 'PO yang sudah dibatalkan hanya dapat dibuka kembali (Open) oleh Admin.' };
  }
  if (to === 'CANCELLED' && !(typeof cancelReason === 'string' && cancelReason.trim())) {
    return { ok: false, reason: 'Alasan pembatalan PO wajib diisi.' };
  }
  return { ok: true };
}

/** Payment status derived from amounts; CANCELLED is an explicit choice. */
export function derivePaymentStatus({ amount, paidAmount, cancelled }) {
  if (cancelled) return 'CANCELLED';
  const total = Number(amount) || 0;
  const paid = Number(paidAmount) || 0;
  if (paid <= 0) return total > 0 ? 'UNPAID' : 'PAID';
  if (paid >= total) return 'PAID';
  return 'PARTIAL';
}
