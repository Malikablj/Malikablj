"""Dokumen: daftar semua dokumen, detail + preview + revision history, download, upload (PRD §8)."""
from __future__ import annotations

from urllib.parse import quote

from flask import Blueprint, Response, abort, g, redirect, render_template, request, url_for

from ..constants import ALLOWED_EXTENSIONS, DOC_TYPES, DOCUMENT_FOLDERS
from ..extensions import db
from ..models import Document, DocumentVersion
from ..services import documents as document_service
from ..services import permissions as perm
from ..services.errors import AppError
from .helpers import ctx, get_visible_project, run, safe_next, visible_projects

bp = Blueprint("documents", __name__, url_prefix="/documents")

PREVIEW_IMAGE = ("image/png", "image/jpeg", "image/webp", "image/svg+xml")


def get_visible_document(doc_id: str) -> Document:
    document = db.session.get(Document, doc_id)
    if not document:
        abort(404)
    if not perm.can_view_project(g.user, document.project):
        abort(403, "Anda tidak memiliki akses ke dokumen ini.")
    return document


@bp.route("")
def index():
    projects = visible_projects()
    ids = [p.id for p in projects]
    documents = Document.query.filter(Document.project_id.in_(ids)).all()
    f = {k: request.args.get(k, "") for k in ("q", "project", "folder", "type", "status", "sort")}
    q = f["q"].strip().lower()
    rows = []
    for d in documents:
        latest = d.latest
        if not latest:
            continue
        if f["project"] and d.project.code != f["project"]:
            continue
        if f["folder"] and d.process.folder != f["folder"]:
            continue
        if f["type"] and d.type != f["type"]:
            continue
        if f["status"] and latest.status != f["status"]:
            continue
        haystack = " ".join([d.name, d.project.code, d.project.name, d.project.customer.name, d.process.name, latest.file_name]).lower()
        if q and q not in haystack:
            continue
        rows.append(d)
    if f["sort"] == "name":
        rows.sort(key=lambda d: d.name.lower())
    elif f["sort"] == "revisions":
        rows.sort(key=lambda d: -len(d.versions))
    else:
        rows.sort(key=lambda d: d.latest.uploaded_at, reverse=True)
    uploadable = [p for p in projects if p.status != "cancelled" and not perm.is_read_only(g.user)]
    return render_template("documents/index.html", rows=rows, f=f, total=len(documents), projects=projects,
                           folders=DOCUMENT_FOLDERS, uploadable=uploadable)


@bp.route("/<doc_id>")
def detail(doc_id):
    document = get_visible_document(doc_id)
    versions = list(document.versions)
    selected = next((v for v in versions if v.id == request.args.get("v")), None) or document.latest or (versions[0] if versions else None)
    preview_kind = None
    if selected:
        if selected.mime_type in PREVIEW_IMAGE:
            preview_kind = "image"
        elif selected.mime_type == "application/pdf":
            preview_kind = "pdf"
    project = document.project
    can_upload = perm.can_upload_to_process(g.user, project, document.process) and project.status != "cancelled"
    return render_template("documents/detail.html", d=document, versions=versions, selected=selected, preview_kind=preview_kind,
                           p=project, can_upload=can_upload)


@bp.route("/version/<version_id>")
def version(version_id):
    """Tautan pendek ke satu versi dokumen (dipakai di approval)."""
    v = db.session.get(DocumentVersion, version_id)
    if not v:
        abort(404)
    get_visible_document(v.document_id)
    return redirect(url_for("documents.detail", doc_id=v.document_id, v=v.id))


@bp.route("/file/<version_id>")
def file(version_id):
    """Isi file: tampil di browser (preview) atau di-download (?download=1)."""
    v = db.session.get(DocumentVersion, version_id)
    if not v:
        abort(404)
    get_visible_document(v.document_id)
    try:
        content, mime = document_service.read_version_file(v)
    except AppError as error:
        abort(404, error.message)
    disposition = "attachment" if request.args.get("download") else "inline"
    headers = {"Content-Disposition": f"{disposition}; filename*=UTF-8''{quote(v.file_name)}", "X-Content-Type-Options": "nosniff"}
    if disposition == "inline" and mime == "image/svg+xml":
        headers["Content-Security-Policy"] = "default-src 'none'; style-src 'unsafe-inline'"  # SVG upload tidak boleh menjalankan script
    return Response(content, mimetype=mime, headers=headers)


@bp.route("/upload/<code>", methods=["GET", "POST"])
def upload(code):
    """Upload dokumen baru, revisi baru (Rev +1), atau versi baru pada revisi yang sama."""
    project = get_visible_project(code)
    processes = [p for p in project.processes if perm.can_upload_to_process(g.user, project, p) and p.status != "skipped"]
    if not processes or project.status == "cancelled":
        abort(403, "Anda tidak dapat mengupload dokumen pada project ini.")
    source = request.form if request.method == "POST" else request.args
    process_id = source.get("process") or (project.current_process_id if any(p.id == project.current_process_id for p in processes) else processes[0].id)
    proc = next((p for p in processes if p.id == process_id), None)
    if not proc:
        abort(403, "Role Anda tidak dapat mengupload dokumen pada proses ini.")
    document_id = source.get("document") or ""
    mode = source.get("mode") or ("revision" if document_id else "new")
    process_docs = Document.query.filter_by(process_id=proc.id).order_by(Document.created_at).all()
    values = {
        "type": (proc.suggested_doc_types or proc.required_doc_types or ["other"])[0],
        "name": "", "description": "", "note": "",
    }
    errors = {}
    if request.method == "POST":
        values.update({k: request.form.get(k, "") for k in ("type", "name", "description", "note")})
        uploaded = request.files.get("file")
        content = uploaded.read() if uploaded and uploaded.filename else None
        file_name = uploaded.filename if uploaded and uploaded.filename else ""
        ok, error = run(lambda: document_service.upload_document(
            ctx(), project.id, proc.id, mode, file_name, content, doc_type=values["type"], name=values["name"],
            document_id=document_id or None, description=values["description"], note=values["note"],
        ), "Dokumen berhasil diupload")
        if ok:
            return redirect(safe_next(request.form.get("next"), url_for("projects.detail", code=code, tab="documents") + "#tabs"))
        errors = dict(error.field_errors)
        if "document_id" in errors:
            errors["document"] = errors.pop("document_id")
    doc_types = [(k, DOC_TYPES[k]) for k in (proc.suggested_doc_types or [])] + [(k, v) for k, v in DOC_TYPES.items() if k not in (proc.suggested_doc_types or [])]
    return render_template("documents/upload.html", p=project, proc=proc, processes=processes, process_docs=process_docs,
                           document_id=document_id, mode=mode, values=values, errors=errors, doc_types=doc_types,
                           next_url=safe_next(source.get("next"), ""), accept=",".join("." + e for e in sorted(ALLOWED_EXTENSIONS)),
                           doc_options=[(d.id, f"{d.name} ({DOC_TYPES[d.type]}, {len(d.versions)} versi)") for d in process_docs])
