"""Fungsi bantu yang dipakai semua route."""
from __future__ import annotations

from flask import abort, flash, g

from ..extensions import db
from ..models import Project, get_settings
from ..services import permissions as perm
from ..services.context import Ctx
from ..services.errors import AppError
from ..services.metrics import build_view


def ctx() -> Ctx:
    """Konteks aksi untuk user yang sedang login."""
    return Ctx.for_user(g.user)


def run(action, success: str | None = None) -> tuple[bool, AppError | None]:
    """Jalankan aksi dalam satu transaksi database.

    Berhasil -> commit + pesan sukses. Gagal (AppError) -> rollback + pesan error.
    Mengembalikan (berhasil?, error).
    """
    try:
        action()
        db.session.commit()
        if success:
            flash(success, "success")
        return True, None
    except AppError as error:
        db.session.rollback()
        flash({"title": error.message, "details": error.details}, "error")
        return False, error


def visible_projects() -> list[Project]:
    return perm.visible_projects_query(g.user).order_by(Project.code.desc()).all()


def get_visible_project(code: str) -> Project:
    project = Project.query.filter_by(code=code).first()
    if not project:
        abort(404)
    if not perm.can_view_project(g.user, project):
        abort(403, "Anda tidak memiliki akses ke project ini.")
    return project


def project_views(projects: list[Project] | None = None):
    """Ringkasan (overdue, progress, flags, dll.) untuk daftar project."""
    from ..models import Approval, Document

    projects = visible_projects() if projects is None else projects
    settings = get_settings()
    today = ctx().today
    ids = [p.id for p in projects]
    docs_by_project: dict[str, list] = {}
    for d in Document.query.filter(Document.project_id.in_(ids)).all():
        docs_by_project.setdefault(d.project_id, []).append(d)
    pending: dict[str, int] = {}
    for a in Approval.query.filter(Approval.project_id.in_(ids), Approval.status == "pending").all():
        pending[a.project_id] = pending.get(a.project_id, 0) + 1
    return [build_view(p, today, settings, docs_by_project.get(p.id, []), pending.get(p.id, 0)) for p in projects]


def safe_next(target: str | None, fallback: str) -> str:
    """Hanya izinkan redirect ke halaman di aplikasi ini (mencegah open redirect)."""
    target = target or ""
    return target if target.startswith("/") and not target.startswith("//") and "\\" not in target else fallback


def form_int(value, default=0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default
