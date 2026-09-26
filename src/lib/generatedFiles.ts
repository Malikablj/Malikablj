/**
 * Generates small but real files (PDF / SVG) for the demo data so that
 * preview & download work end-to-end for seeded documents.
 */

const ascii = (s: string) => s.normalize('NFD').replace(/[^\x20-\x7E]/g, '');
const pdfEscape = (s: string) => ascii(s).replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');

export function makePdf(title: string, lines: string[]): Blob {
  const content: string[] = ['BT', '/F2 18 Tf', '56 780 Td', `(${pdfEscape(title)}) Tj`, '/F1 11 Tf', '0 -28 Td', '16 TL'];
  for (const line of lines) content.push(`(${pdfEscape(line)}) '`);
  content.push('ET');
  // thin rule under the title
  content.push('0.85 0.85 0.87 RG 56 768 m 539 768 l S');
  const stream = content.join('\n');
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
  ];
  let out = '%PDF-1.4\n';
  const offsets: number[] = [];
  objects.forEach((obj, i) => {
    offsets.push(out.length);
    out += `${i + 1} 0 obj\n${obj}\nendobj\n`;
  });
  const xref = out.length;
  out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (const o of offsets) out += `${String(o).padStart(10, '0')} 00000 n \n`;
  out += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
  return new Blob([out], { type: 'application/pdf' });
}

const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const ARTWORK_COLORS = ['#E8A0B4', '#F2B8C6', '#F7C9D4', '#B7D3E9', '#C9E4C5', '#F5D6A8'];

export function makeArtworkSvg(opts: { product: string; brand: string; revision: string; seed: number }): Blob {
  const color = ARTWORK_COLORS[opts.seed % ARTWORK_COLORS.length];
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800" viewBox="0 0 600 800">
  <rect width="600" height="800" fill="#FAFAFA"/>
  <text x="32" y="48" font-family="Helvetica, Arial" font-size="16" fill="#6E6E73">ARTWORK · ${esc(opts.revision)}</text>
  <rect x="200" y="110" width="200" height="60" rx="10" fill="#D2D2D7"/>
  <rect x="170" y="170" width="260" height="540" rx="36" fill="${color}"/>
  <rect x="200" y="300" width="200" height="240" rx="16" fill="#FFFFFF" opacity="0.92"/>
  <text x="300" y="380" text-anchor="middle" font-family="Helvetica, Arial" font-weight="700" font-size="28" fill="#1D1D1F">${esc(opts.brand)}</text>
  <text x="300" y="420" text-anchor="middle" font-family="Helvetica, Arial" font-size="15" fill="#424245">${esc(opts.product.slice(0, 26))}</text>
  <text x="300" y="500" text-anchor="middle" font-family="Helvetica, Arial" font-size="12" fill="#86868B">${esc(opts.revision)}</text>
  <text x="32" y="770" font-family="Helvetica, Arial" font-size="12" fill="#86868B">Dokumen contoh — NPD Project Control</text>
</svg>`;
  return new Blob([svg], { type: 'image/svg+xml' });
}

export function makeDrawingSvg(opts: { title: string; revision: string; kind: '2D' | '3D' | 'MOLD' }): Blob {
  const body =
    opts.kind === '3D'
      ? `<polygon points="300,160 460,250 460,470 300,560 140,470 140,250" fill="#E8F1FC" stroke="#0071E3" stroke-width="2"/>
         <polyline points="140,250 300,340 460,250" fill="none" stroke="#0071E3" stroke-width="2"/>
         <line x1="300" y1="340" x2="300" y2="560" stroke="#0071E3" stroke-width="2"/>`
      : `<rect x="180" y="180" width="240" height="380" rx="24" fill="none" stroke="#1D1D1F" stroke-width="2"/>
         <line x1="180" y1="600" x2="420" y2="600" stroke="#C9252D" stroke-width="1.5"/>
         <text x="300" y="625" text-anchor="middle" font-family="Helvetica" font-size="14" fill="#C9252D">Ø 48.00 ±0.10</text>
         <line x1="460" y1="180" x2="460" y2="560" stroke="#C9252D" stroke-width="1.5"/>
         <text x="478" y="375" font-family="Helvetica" font-size="14" fill="#C9252D">120.00</text>
         ${opts.kind === 'MOLD' ? '<rect x="120" y="140" width="360" height="460" fill="none" stroke="#86868B" stroke-dasharray="8 6"/>' : ''}`;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="600" height="760" viewBox="0 0 600 760">
  <rect width="600" height="760" fill="#FFFFFF"/>
  <rect x="16" y="16" width="568" height="728" fill="none" stroke="#D2D2D7"/>
  <text x="32" y="52" font-family="Helvetica, Arial" font-weight="700" font-size="18" fill="#1D1D1F">${esc(opts.title)}</text>
  <text x="32" y="76" font-family="Helvetica, Arial" font-size="13" fill="#6E6E73">${opts.kind} DRAWING · ${esc(opts.revision)}</text>
  ${body}
  <text x="32" y="720" font-family="Helvetica, Arial" font-size="12" fill="#86868B">Dokumen contoh — NPD Project Control</text>
</svg>`;
  return new Blob([svg], { type: 'image/svg+xml' });
}
