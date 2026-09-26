"""Pengaturan (khusus Admin): user & role, customer, workflow template, threshold sistem.
Juga CRUD event kalender (meeting & follow-up) untuk semua role kecuali Management.
"""
from __future__ import annotations

import re
import uuid
from copy import deepcopy

from ..constants import DOC_TYPES, ROLES
from ..extensions import db
from ..models import CalendarEvent, Customer, Project, User, WorkflowTemplate, get_settings
from . import permissions as perm
from .context import Ctx
from .dates import parse_date
from .errors import AppError, forbidden, invalid, not_found

EMAIL_RE = re.compile(r"^[^\s@]+@[^\s@]+\.[^\s@]+$")


def _require_admin(ctx: Ctx) -> None:
    if not perm.can_manage_settings(ctx.actor):
        raise forbidden("Hanya Admin yang dapat mengelola pengaturan.")


def _norm(s: str) -> str:
    return " ".join((s or "").lower().split())


# --------------------------------------------------------------------------- users
def save_user(ctx: Ctx, values: dict, user_id: str | None = None) -> User:
    _require_admin(ctx)
    name = (values.get("name") or "").strip()
    email = (values.get("email") or "").strip().lower()
    role = values.get("role")
    password = values.get("password") or ""
    errors = {}
    if not name:
        errors["name"] = "Nama wajib diisi."
    if not EMAIL_RE.match(email):
        errors["email"] = "Email tidak valid."
    if role not in ROLES:
        errors["role"] = "Pilih role."
    if (not user_id and len(password) < 6) or (user_id and password and len(password) < 6):
        errors["password"] = "Password minimal 6 karakter."
    if "email" not in errors and User.query.filter(User.email == email, User.id != (user_id or "")).first():
        errors["email"] = "Email sudah digunakan."
    if errors:
        raise invalid("Periksa kembali data user.", errors)
    active = bool(values.get("active"))
    if user_id:
        user = db.session.get(User, user_id)
        if not user:
            raise not_found("User tidak ditemukan.")
        if user.id == ctx.actor.id and (not active or role != "admin"):
            raise AppError("INVALID_STATE", "Anda tidak dapat menonaktifkan atau menurunkan role akun Anda sendiri.")
    else:
        user = User()
        db.session.add(user)
    user.name, user.email, user.role, user.active = name, email, role, active
    user.title = (values.get("title") or "").strip() or ROLES[role]
    if password:
        user.set_password(password)
    db.session.flush()
    return user


def change_password(ctx: Ctx, current: str, new: str, confirm: str) -> None:
    """Ganti password akun sendiri."""
    errors = {}
    if not ctx.actor.check_password(current or ""):
        errors["current"] = "Password saat ini salah."
    if len(new or "") < 6:
        errors["new"] = "Password baru minimal 6 karakter."
    elif new != confirm:
        errors["confirm"] = "Konfirmasi password tidak sama."
    if errors:
        raise invalid("Password belum diganti.", errors)
    ctx.actor.set_password(new)


# --------------------------------------------------------------------------- customers
def save_customer(ctx: Ctx, values: dict, customer_id: str | None = None) -> Customer:
    _require_admin(ctx)
    name = (values.get("name") or "").strip()
    code = (values.get("code") or "").strip().upper()
    contact_email = (values.get("contact_email") or "").strip()
    errors = {}
    if not name:
        errors["name"] = "Nama customer wajib diisi."
    if not code:
        errors["code"] = "Kode customer wajib diisi."
    elif not re.fullmatch(r"[A-Z0-9-]{2,12}", code):
        errors["code"] = "2–12 karakter huruf/angka."
    if contact_email and not EMAIL_RE.match(contact_email):
        errors["contact_email"] = "Email tidak valid."
    others = Customer.query.filter(Customer.id != (customer_id or "")).all()
    if "name" not in errors and any(_norm(c.name) == _norm(name) for c in others):
        errors["name"] = "Customer dengan nama ini sudah ada."
    if "code" not in errors and any(c.code == code for c in others):
        errors["code"] = "Kode sudah digunakan."
    if errors:
        raise invalid("Periksa kembali data customer.", errors)
    if customer_id:
        customer = db.session.get(Customer, customer_id)
        if not customer:
            raise not_found("Customer tidak ditemukan.")
    else:
        customer = Customer(created_at=ctx.now)
        db.session.add(customer)
    customer.name, customer.code = name, code
    customer.contact_name = (values.get("contact_name") or "").strip() or None
    customer.contact_email = contact_email or None
    customer.active = bool(values.get("active"))
    db.session.flush()
    return customer


def delete_customer(ctx: Ctx, customer_id: str) -> None:
    _require_admin(ctx)
    used = Project.query.filter_by(customer_id=customer_id).count()
    if used:
        raise AppError("CONFLICT", f"Customer digunakan oleh {used} project. Nonaktifkan customer sebagai gantinya.")
    customer = db.session.get(Customer, customer_id)
    if customer:
        db.session.delete(customer)


# --------------------------------------------------------------------------- workflow template
def _get_workflow(workflow_id: str) -> WorkflowTemplate:
    wf = db.session.get(WorkflowTemplate, workflow_id)
    if not wf:
        raise not_found("Workflow tidak ditemukan.")
    return wf


def _validate_process(values: dict) -> dict:
    errors = {}
    if "name" in values and not (values.get("name") or "").strip():
        errors["name"] = "Nama proses wajib diisi."
    days = values.get("duration_days")
    if days is not None and (not isinstance(days, int) or not 1 <= days <= 365):
        errors["duration_days"] = "Durasi 1–365 hari."
    if "actor_roles" in values and not values["actor_roles"]:
        errors["actor_roles"] = "Pilih minimal satu role."
    if values.get("requires_document") and not values.get("required_doc_types"):
        errors["required_doc_types"] = "Pilih minimal satu document type wajib."
    return errors


def _bump(ctx: Ctx, wf: WorkflowTemplate, processes: list[dict]) -> None:
    wf.processes = processes  # objek baru agar perubahan JSON tersimpan
    wf.version += 1
    wf.updated_at = ctx.now
    wf.updated_by = ctx.actor.id


def update_workflow_process(ctx: Ctx, workflow_id: str, key: str, values: dict) -> WorkflowTemplate:
    _require_admin(ctx)
    wf = _get_workflow(workflow_id)
    processes = deepcopy(wf.processes)
    proc = next((p for p in processes if p["key"] == key), None)
    if not proc:
        raise not_found("Proses tidak ditemukan.")
    errors = _validate_process(values)
    if errors:
        raise invalid("Periksa kembali data proses.", errors)
    if proc["kind"] == "finish" and values.get("is_mandatory") is False:
        raise invalid("Proses Finish selalu wajib.")
    proc["name"] = values["name"].strip()
    proc["short_name"] = (values.get("short_name") or "").strip() or proc["name"]
    proc["description"] = (values.get("description") or "").strip()
    proc["pic_role"] = values["pic_role"]
    proc["actor_roles"] = values["actor_roles"]
    proc["upload_roles"] = sorted(set(values["actor_roles"]) | set(proc.get("upload_roles") or []))
    proc["duration_days"] = values["duration_days"]
    proc["is_mandatory"] = bool(values.get("is_mandatory")) if not proc.get("loop_only") else False
    proc["requires_document"] = bool(values.get("requires_document"))
    proc["required_doc_types"] = values.get("required_doc_types") if proc["requires_document"] else []
    proc["default_next_action"] = (values.get("default_next_action") or "").strip() or f"Selesaikan {proc['name']}"
    _bump(ctx, wf, processes)
    return wf


def add_workflow_process(ctx: Ctx, workflow_id: str, after_key: str, values: dict) -> WorkflowTemplate:
    _require_admin(ctx)
    wf = _get_workflow(workflow_id)
    processes = deepcopy(wf.processes)
    idx = next((i for i, p in enumerate(processes) if p["key"] == after_key), -1)
    if idx < 0:
        raise not_found("Posisi proses tidak ditemukan.")
    if processes[idx]["kind"] == "finish":
        raise invalid("Proses baru tidak dapat ditambahkan setelah Finish.")
    errors = _validate_process(values)
    name = (values.get("name") or "").strip()
    if not name:
        errors["name"] = "Nama proses wajib diisi."
    elif any(_norm(p["name"]) == _norm(name) for p in processes):
        errors["name"] = "Nama proses sudah ada di workflow ini."
    if errors:
        raise invalid("Periksa kembali data proses.", errors)
    prev, nxt = processes[idx], processes[idx + 1] if idx + 1 < len(processes) else None
    same_branch = bool(prev.get("branch_group")) and nxt and nxt.get("branch_group") == prev["branch_group"] and nxt.get("branch") == prev.get("branch")
    actor_roles = values.get("actor_roles") or [values["pic_role"]]
    requires_doc = bool(values.get("requires_document"))
    new_proc = {
        "key": f"custom_{uuid.uuid4().hex[:8]}",
        "name": name,
        "short_name": (values.get("short_name") or "").strip() or name,
        "description": (values.get("description") or "").strip() or "Proses tambahan yang dikonfigurasi Admin.",
        "kind": "task",
        "pic_role": values["pic_role"],
        "actor_roles": actor_roles,
        "upload_roles": actor_roles,
        "is_mandatory": bool(values.get("is_mandatory", True)),
        "loop_only": False,
        "requires_document": requires_doc,
        "required_doc_types": values.get("required_doc_types") if requires_doc else [],
        "suggested_doc_types": values.get("required_doc_types") or ["other"],
        "requires_approval": False,
        "approval_type": None,
        "approver_type": None,
        "outcomes": [],
        "next_key": None,
        "waiting_type": "internal",
        "waiting_label": None,
        "default_next_action": (values.get("default_next_action") or "").strip() or f"Selesaikan {name}",
        "duration_days": values["duration_days"],
        "branch_group": prev.get("branch_group") if same_branch else None,
        "branch": prev.get("branch") if same_branch else None,
        "folder": prev.get("folder", "Lainnya"),
        "record_type": "general",
        "fields": [{"key": "notes", "label": "Catatan", "type": "textarea", "required": False}],
        "calendar_category": None,
        "custom": True,
    }
    processes.insert(idx + 1, new_proc)
    _bump(ctx, wf, processes)
    return wf


def move_workflow_process(ctx: Ctx, workflow_id: str, key: str, direction: int) -> WorkflowTemplate:
    _require_admin(ctx)
    wf = _get_workflow(workflow_id)
    processes = deepcopy(wf.processes)
    idx = next((i for i, p in enumerate(processes) if p["key"] == key), -1)
    if idx < 0 or not processes[idx].get("custom"):
        raise AppError("INVALID_STATE", "Hanya proses tambahan yang dapat dipindahkan. Urutan proses inti mengikuti PRD.")
    swap = idx + direction
    if swap < 1 or swap >= len(processes) - 1:
        raise AppError("INVALID_STATE", "Proses tidak dapat dipindah ke posisi tersebut.")
    proc, other = processes[idx], processes[swap]
    if other.get("branch_group") != proc.get("branch_group"):
        proc["branch_group"], proc["branch"] = other.get("branch_group"), other.get("branch")
    processes[idx], processes[swap] = other, proc
    _bump(ctx, wf, processes)
    return wf


def remove_workflow_process(ctx: Ctx, workflow_id: str, key: str) -> WorkflowTemplate:
    _require_admin(ctx)
    wf = _get_workflow(workflow_id)
    proc = next((p for p in wf.processes if p["key"] == key), None)
    if not proc or not proc.get("custom"):
        raise AppError("INVALID_STATE", "Proses inti tidak dapat dihapus.")
    _bump(ctx, wf, [deepcopy(p) for p in wf.processes if p["key"] != key])
    return wf


# --------------------------------------------------------------------------- pengaturan sistem
def update_settings(ctx: Ctx, due_soon_days: int, no_update_days: int):
    _require_admin(ctx)
    errors = {}
    if not 1 <= due_soon_days <= 30:
        errors["due_soon_days"] = "Isi 1–30 hari."
    if not 1 <= no_update_days <= 60:
        errors["no_update_days"] = "Isi 1–60 hari."
    if errors:
        raise invalid("Periksa kembali pengaturan.", errors)
    s = get_settings()
    s.due_soon_days, s.no_update_days = due_soon_days, no_update_days
    return s


def reset_demo_data(ctx: Ctx) -> None:
    """Hapus semua data & file upload, lalu isi ulang data demo relatif terhadap hari ini."""
    import shutil
    from pathlib import Path

    from flask import current_app

    from .seed import seed_demo_data

    _require_admin(ctx)
    db.session.rollback()
    db.drop_all()
    db.create_all()
    upload_dir = Path(current_app.config["UPLOAD_DIR"])
    if upload_dir.exists():
        shutil.rmtree(upload_dir)
    upload_dir.mkdir(parents=True, exist_ok=True)
    seed_demo_data(ctx.today)


# --------------------------------------------------------------------------- kalender
def save_event(ctx: Ctx, values: dict, event_id: str | None = None) -> CalendarEvent:
    if not perm.can_manage_calendar(ctx.actor):
        raise forbidden("Role Anda hanya memiliki akses baca.")
    title = (values.get("title") or "").strip()
    day = parse_date(values.get("date"))
    time = (values.get("time") or "").strip()
    category = values.get("category")
    project_id = values.get("project_id") or None
    errors = {}
    if not title:
        errors["title"] = "Judul wajib diisi."
    if not day:
        errors["date"] = "Tanggal wajib diisi."
    if time and not re.fullmatch(r"\d{2}:\d{2}", time):
        errors["time"] = "Format jam HH:MM."
    if category not in ("meeting", "follow_up"):
        errors["category"] = "Pilih kategori."
    if project_id and not db.session.get(Project, project_id):
        errors["project_id"] = "Project tidak ditemukan."
    if errors:
        raise invalid("Periksa kembali data event.", errors)
    if event_id:
        event = db.session.get(CalendarEvent, event_id)
        if not event:
            raise not_found("Event tidak ditemukan.")
        if event.created_by_id != ctx.actor.id and ctx.actor.role != "admin":
            raise forbidden("Hanya pembuat event atau Admin yang dapat mengubah event ini.")
    else:
        event = CalendarEvent(created_by_id=ctx.actor.id, created_at=ctx.now)
        db.session.add(event)
    event.title, event.category, event.date, event.time = title, category, day, time or None
    event.project_id = project_id
    event.notes = (values.get("notes") or "").strip() or None
    db.session.flush()
    return event


def delete_event(ctx: Ctx, event_id: str) -> None:
    event = db.session.get(CalendarEvent, event_id)
    if not event:
        raise not_found("Event tidak ditemukan.")
    if event.created_by_id != ctx.actor.id and ctx.actor.role != "admin":
        raise forbidden("Hanya pembuat event atau Admin yang dapat menghapus event ini.")
    db.session.delete(event)


__all__ = ["DOC_TYPES"]
