"""Dokumen per proses + revisi (PRD §8).

- Setiap dokumen selalu terhubung ke Project dan Process.
- Upload revisi baru TIDAK menimpa revisi lama: versi lama berstatus Superseded,
  sedangkan Rejected/Approved tetap tercatat sebagai history.
"""
from __future__ import annotations

import mimetypes
import uuid
from pathlib import Path

from flask import current_app
from werkzeug.utils import secure_filename

from ..constants import ALLOWED_EXTENSIONS, DOC_TYPES, format_revision
from ..extensions import db
from ..models import Document, DocumentVersion
from . import permissions as perm
from .context import Ctx, get_process, get_project, log_activity, notify, project_pics
from .errors import AppError, forbidden, invalid, not_found
from .sample_files import generate_sample_file

MAX_FILE_SIZE = 25 * 1024 * 1024


def _norm(s: str) -> str:
    return " ".join((s or "").lower().split())


def upload_document(ctx: Ctx, project_id: str, process_id: str, mode: str, file_name: str, content: bytes | None,
                    doc_type: str | None = None, name: str | None = None, document_id: str | None = None,
                    description: str | None = None, note: str | None = None, stored_name: str | None = None):
    """mode: "new" (dokumen baru), "revision" (Rev +1), "version" (versi baru pada revisi yang sama).

    `stored_name="seed"` dipakai data demo: file dibuat otomatis saat di-download.
    """
    project = get_project(project_id)
    proc = get_process(process_id)
    if proc.project_id != project.id:
        raise invalid("Proses tidak sesuai dengan project.")
    if not perm.can_upload_to_process(ctx.actor, project, proc):
        raise forbidden(f"Role Anda tidak dapat mengupload dokumen pada proses {proc.name}.")
    if project.status == "cancelled":
        raise AppError("INVALID_STATE", "Project Cancelled tidak dapat menerima dokumen baru.")

    errors = {}
    size = len(content) if content is not None else 0
    extension = Path(file_name or "").suffix.lower().lstrip(".")
    if not file_name:
        errors["file"] = "Pilih file untuk diupload."
    elif stored_name != "seed":
        if size == 0:
            errors["file"] = "File kosong tidak dapat diupload."
        elif size > MAX_FILE_SIZE:
            errors["file"] = "Ukuran file maksimal 25 MB."
        elif extension not in ALLOWED_EXTENSIONS:
            errors["file"] = f"Format .{extension or '?'} tidak didukung."
    if mode == "new":
        if not (name or "").strip():
            errors["name"] = "Nama dokumen wajib diisi."
        if doc_type not in DOC_TYPES:
            errors["type"] = "Pilih Document Type."
    elif not document_id:
        errors["document_id"] = "Pilih dokumen yang direvisi."
    if errors:
        raise invalid("Periksa kembali data upload.", errors)

    revision, version_no = 0, 1
    if mode == "new":
        dup = next((d for d in Document.query.filter_by(process_id=proc.id).all() if _norm(d.name) == _norm(name)), None)
        if dup:
            raise AppError("CONFLICT", f'Dokumen "{dup.name}" sudah ada di proses {proc.name}. Upload sebagai revisi baru agar history tetap tersimpan.',
                           {"name": "Nama dokumen sudah digunakan pada proses ini."})
        document = Document(project_id=project.id, process_id=proc.id, type=doc_type, name=name.strip(),
                            description=(description or "").strip() or None, created_at=ctx.now, created_by=ctx.actor.id)
        db.session.add(document)
    else:
        document = db.session.get(Document, document_id)
        if not document or document.project_id != project.id:
            raise not_found("Dokumen yang direvisi tidak ditemukan.")
        versions = list(document.versions)
        max_rev = max(v.revision for v in versions)
        if mode == "revision":
            revision = max_rev + 1
        else:
            revision = max_rev
            version_no = max(v.version for v in versions if v.revision == max_rev) + 1
        for v in versions:
            if v.status == "current":
                v.status = "superseded"
        if (description or "").strip():
            document.description = description.strip()

    if stored_name != "seed":
        stored_name = f"{uuid.uuid4().hex}_{secure_filename(file_name) or 'file'}"
        upload_dir = Path(current_app.config["UPLOAD_DIR"])
        upload_dir.mkdir(parents=True, exist_ok=True)
        (upload_dir / stored_name).write_bytes(content)

    version = DocumentVersion(
        revision=revision,
        version=version_no,
        file_name=file_name,
        mime_type=mimetypes.guess_type(file_name)[0] or "application/octet-stream",
        size=size if stored_name != "seed" else len(generate_sample_file(document, revision, version_no, project, proc.name, file_name)[0]),
        stored_name=stored_name,
        uploaded_by_id=ctx.actor.id,
        uploaded_at=ctx.now,
        status="current",
        note=(note or "").strip() or None,
    )
    document.versions.append(version)
    db.session.flush()
    document.latest_version_id = version.id

    label = f"{document.name} — {format_revision(revision)}" + (f" v{version_no}" if version_no > 1 else "")
    is_revision = mode != "new"
    log_activity(ctx, project, "document_revision" if is_revision else "document_uploaded",
                 f"{'Revisi dokumen' if is_revision else 'Dokumen diupload'}: {label}", process_id=proc.id,
                 detail="\n".join(x for x in [f"{DOC_TYPES[document.type]} · {proc.name}", version.note] if x))
    notify(ctx, [*project_pics(project), proc.pic_id], "document_revision" if is_revision else "document_uploaded",
           "Revisi dokumen baru" if is_revision else "Dokumen baru diupload", f"{project.code} · {label} ({proc.name})", project_id=project.id)
    return document, version


def read_version_file(version: DocumentVersion) -> tuple[bytes, str]:
    """Isi file sebuah versi dokumen + mime type."""
    if version.stored_name == "seed":
        doc = version.document
        return generate_sample_file(doc, version.revision, version.version, doc.project, doc.process.name, version.file_name)
    path = Path(current_app.config["UPLOAD_DIR"]) / (version.stored_name or "")
    if not version.stored_name or not path.exists():
        raise not_found("File tidak ditemukan di storage.")
    return path.read_bytes(), version.mime_type
