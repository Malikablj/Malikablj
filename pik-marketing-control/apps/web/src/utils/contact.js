/** wa.me link for an Indonesian phone number ("0812-..." → 62812...), or null. */
export function whatsappLink(phone) {
  if (!phone) return null;
  let digits = String(phone).replace(/\D/g, '');
  if (digits.startsWith('0')) digits = `62${digits.slice(1)}`;
  else if (digits.startsWith('8')) digits = `62${digits}`;
  return digits.length >= 9 ? `https://wa.me/${digits}` : null;
}

export function telLink(phone) {
  if (!phone) return null;
  const cleaned = String(phone).replace(/[^\d+]/g, '');
  return cleaned ? `tel:${cleaned}` : null;
}
