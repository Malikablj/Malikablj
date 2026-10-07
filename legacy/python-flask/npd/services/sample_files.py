"""Membuat file PDF/SVG kecil untuk dokumen contoh (data demo), agar preview & download benar-benar berfungsi."""
from __future__ import annotations

import unicodedata
from html import escape

from ..constants import format_revision

ARTWORK_COLORS = ["#E8A0B4", "#F2B8C6", "#F7C9D4", "#B7D3E9", "#C9E4C5", "#F5D6A8"]


def _ascii(s: str) -> str:
    return unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()


def make_pdf(title: str, lines: list[str]) -> bytes:
    """PDF satu halaman dengan judul & beberapa baris teks (Helvetica)."""
    def esc(s):
        return _ascii(s).replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")

    content = ["BT", "/F2 18 Tf", "56 780 Td", f"({esc(title)}) Tj", "/F1 11 Tf", "0 -28 Td", "16 TL"]
    content += [f"({esc(line)}) '" for line in lines]
    content += ["ET", "0.85 0.85 0.87 RG 56 768 m 539 768 l S"]
    stream = "\n".join(content)
    objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
        f"<< /Length {len(stream)} >>\nstream\n{stream}\nendstream",
    ]
    out = "%PDF-1.4\n"
    offsets = []
    for i, obj in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n{obj}\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n"
    out += "".join(f"{o:010d} 00000 n \n" for o in offsets)
    out += f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF"
    return out.encode("latin-1")


def make_artwork_svg(product: str, brand: str, revision: str, seed: int) -> bytes:
    color = ARTWORK_COLORS[seed % len(ARTWORK_COLORS)]
    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800" viewBox="0 0 600 800">
  <rect width="600" height="800" fill="#FAFAFA"/>
  <text x="32" y="48" font-family="Helvetica, Arial" font-size="16" fill="#6E6E73">ARTWORK · {escape(revision)}</text>
  <rect x="200" y="110" width="200" height="60" rx="10" fill="#D2D2D7"/>
  <rect x="170" y="170" width="260" height="540" rx="36" fill="{color}"/>
  <rect x="200" y="300" width="200" height="240" rx="16" fill="#FFFFFF" opacity="0.92"/>
  <text x="300" y="380" text-anchor="middle" font-family="Helvetica, Arial" font-weight="700" font-size="28" fill="#1D1D1F">{escape(brand)}</text>
  <text x="300" y="420" text-anchor="middle" font-family="Helvetica, Arial" font-size="15" fill="#424245">{escape(product[:26])}</text>
  <text x="300" y="500" text-anchor="middle" font-family="Helvetica, Arial" font-size="12" fill="#86868B">{escape(revision)}</text>
  <text x="32" y="770" font-family="Helvetica, Arial" font-size="12" fill="#86868B">Dokumen contoh — NPD Project Control</text>
</svg>"""
    return svg.encode()


def make_drawing_svg(title: str, revision: str, kind: str) -> bytes:
    if kind == "3D":
        body = """<polygon points="300,160 460,250 460,470 300,560 140,470 140,250" fill="#E8F1FC" stroke="#0071E3" stroke-width="2"/>
  <polyline points="140,250 300,340 460,250" fill="none" stroke="#0071E3" stroke-width="2"/>
  <line x1="300" y1="340" x2="300" y2="560" stroke="#0071E3" stroke-width="2"/>"""
    else:
        body = """<rect x="180" y="180" width="240" height="380" rx="24" fill="none" stroke="#1D1D1F" stroke-width="2"/>
  <line x1="180" y1="600" x2="420" y2="600" stroke="#C9252D" stroke-width="1.5"/>
  <text x="300" y="625" text-anchor="middle" font-family="Helvetica" font-size="14" fill="#C9252D">Ø 48.00 ±0.10</text>
  <line x1="460" y1="180" x2="460" y2="560" stroke="#C9252D" stroke-width="1.5"/>
  <text x="478" y="375" font-family="Helvetica" font-size="14" fill="#C9252D">120.00</text>"""
        if kind == "MOLD":
            body += '\n  <rect x="120" y="140" width="360" height="460" fill="none" stroke="#86868B" stroke-dasharray="8 6"/>'
    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" width="600" height="760" viewBox="0 0 600 760">
  <rect width="600" height="760" fill="#FFFFFF"/>
  <rect x="16" y="16" width="568" height="728" fill="none" stroke="#D2D2D7"/>
  <text x="32" y="52" font-family="Helvetica, Arial" font-weight="700" font-size="18" fill="#1D1D1F">{escape(title)}</text>
  <text x="32" y="76" font-family="Helvetica, Arial" font-size="13" fill="#6E6E73">{kind} DRAWING · {escape(revision)}</text>
  {body}
  <text x="32" y="720" font-family="Helvetica, Arial" font-size="12" fill="#86868B">Dokumen contoh — NPD Project Control</text>
</svg>"""
    return svg.encode()


def generate_sample_file(document, revision: int, version: int, project, process_name: str, file_name: str) -> tuple[bytes, str]:
    """Kembalikan (isi file, mime type) untuk dokumen contoh."""
    rev = format_revision(revision)
    customer = project.customer.name if project.customer else ""
    if document.type in ("artwork", "trial_photo"):
        return make_artwork_svg(project.name, customer, f"{document.name} · {rev}", revision + len(document.name)), "image/svg+xml"
    if document.type == "drawing_3d":
        return make_drawing_svg(document.name, rev, "3D"), "image/svg+xml"
    if document.type == "drawing_2d":
        return make_drawing_svg(document.name, rev, "2D"), "image/svg+xml"
    if document.type == "mold_drawing":
        return make_drawing_svg(document.name, rev, "MOLD"), "image/svg+xml"
    lines = [
        f"Project      : {project.code} - {project.name}",
        f"Customer     : {customer}",
        f"Process      : {process_name}",
        f"Revision     : {rev}{f' v{version}' if version > 1 else ''}",
        f"File         : {file_name}",
        "",
        "Dokumen contoh yang dibuat otomatis untuk demo NPD Project Control.",
    ]
    return make_pdf(document.name, lines), "application/pdf"
