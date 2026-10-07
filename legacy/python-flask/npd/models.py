"""Tabel database (PRD §14 Database Structure).

Setiap class = satu tabel. Kolom bertipe JSON dipakai untuk data yang bentuknya
fleksibel (daftar role, field form proses, isi form, dll.).

Catatan: kolom JSON harus diganti objek baru saat diubah, misalnya
    proc.data = {**proc.data, "machine": "150T"}
bukan proc.data["machine"] = "150T" (perubahan di dalam dict tidak terdeteksi).
"""
from __future__ import annotations

import uuid
from datetime import date, datetime

from flask import current_app, has_app_context
from sqlalchemy import JSON
from werkzeug.security import check_password_hash, generate_password_hash

from .constants import RUNNING_STATUSES
from .extensions import db


def new_id(prefix: str) -> str:
    return f"{prefix}_{uuid.uuid4().hex[:16]}"


# --------------------------------------------------------------------------- users
class User(db.Model):
    __tablename__ = "users"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("usr"))
    name = db.Column(db.String(120), nullable=False)
    email = db.Column(db.String(160), unique=True, nullable=False)
    role = db.Column(db.String(20), nullable=False)
    title = db.Column(db.String(120), default="")
    active = db.Column(db.Boolean, default=True, nullable=False)
    password_hash = db.Column(db.String(255), nullable=False)

    def set_password(self, password: str) -> None:
        # Test memakai metode hash yang lebih ringan agar cepat (lihat config.TestConfig).
        method = current_app.config.get("PASSWORD_HASH_METHOD", "scrypt") if has_app_context() else "scrypt"
        self.password_hash = generate_password_hash(password, method=method)

    def check_password(self, password: str) -> bool:
        return check_password_hash(self.password_hash, password)

    @property
    def initials(self) -> str:
        parts = self.name.split()
        return ((parts[0][:1] if parts else "") + (parts[1][:1] if len(parts) > 1 else "")).upper()


# --------------------------------------------------------------------------- customers
class Customer(db.Model):
    __tablename__ = "customers"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("cus"))
    code = db.Column(db.String(12), unique=True, nullable=False)
    name = db.Column(db.String(120), unique=True, nullable=False)
    contact_name = db.Column(db.String(120))
    contact_email = db.Column(db.String(160))
    active = db.Column(db.Boolean, default=True, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.now)


# --------------------------------------------------------------------------- workflows
class WorkflowTemplate(db.Model):
    """Template workflow per Project Type. `processes` = daftar definisi proses (lihat workflows.py)."""

    __tablename__ = "workflows"
    id = db.Column(db.String(40), primary_key=True)
    project_type = db.Column(db.String(20), unique=True, nullable=False)
    name = db.Column(db.String(120), nullable=False)
    version = db.Column(db.Integer, default=1, nullable=False)
    processes = db.Column(JSON, nullable=False)
    updated_at = db.Column(db.DateTime, default=datetime.now)
    updated_by = db.Column(db.String(40))


# --------------------------------------------------------------------------- projects
class Project(db.Model):
    __tablename__ = "projects"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("prj"))
    code = db.Column(db.String(20), unique=True, nullable=False)  # NPD-YYYY-XXX
    type = db.Column(db.String(20), nullable=False)  # new_mold | subcont
    customer_id = db.Column(db.String(40), db.ForeignKey("customers.id"), nullable=False)
    name = db.Column(db.String(120), nullable=False)
    product_name = db.Column(db.String(120), nullable=False)
    product_description = db.Column(db.Text)
    customer_request = db.Column(db.Text, nullable=False)
    npd_pic_id = db.Column(db.String(40), db.ForeignKey("users.id"), nullable=False)
    sales_pic_id = db.Column(db.String(40), db.ForeignKey("users.id"), nullable=False)
    drafter_id = db.Column(db.String(40), db.ForeignKey("users.id"), nullable=False)
    supplier = db.Column(db.String(120))
    priority = db.Column(db.String(10), nullable=False)
    start_date = db.Column(db.Date, nullable=False)
    target_date = db.Column(db.Date, nullable=False)
    actual_finish = db.Column(db.Date)
    current_process_id = db.Column(db.String(40))
    status = db.Column(db.String(20), nullable=False)
    waiting_for = db.Column(db.String(200))
    next_action = db.Column(db.String(300), nullable=False, default="")
    next_action_due = db.Column(db.Date, nullable=False)
    remarks = db.Column(db.Text)
    status_reason = db.Column(db.Text)
    new_masterbatch = db.Column(db.Boolean)
    workflow_id = db.Column(db.String(40), nullable=False)
    workflow_version = db.Column(db.Integer, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.now)
    created_by = db.Column(db.String(40))
    updated_at = db.Column(db.DateTime, default=datetime.now)

    customer = db.relationship("Customer")
    npd_pic = db.relationship("User", foreign_keys=[npd_pic_id])
    sales_pic = db.relationship("User", foreign_keys=[sales_pic_id])
    drafter = db.relationship("User", foreign_keys=[drafter_id])
    processes = db.relationship("ProjectProcess", back_populates="project", order_by="ProjectProcess.sequence", cascade="all, delete-orphan")

    @property
    def current_process(self) -> "ProjectProcess | None":
        return next((p for p in self.processes if p.id == self.current_process_id), None)

    @property
    def is_active(self) -> bool:
        return self.status not in ("completed", "cancelled")


# --------------------------------------------------------------------------- project_processes
class ProjectProcess(db.Model):
    """Satu proses di dalam project. Definisinya disalin dari template saat project dibuat."""

    __tablename__ = "project_processes"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("prc"))
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    sequence = db.Column(db.Integer, nullable=False)

    # --- definisi (salinan dari template workflow)
    key = db.Column(db.String(60), nullable=False)
    name = db.Column(db.String(120), nullable=False)
    short_name = db.Column(db.String(60), nullable=False)
    description = db.Column(db.Text, default="")
    kind = db.Column(db.String(10), nullable=False)  # task | decision | finish
    pic_role = db.Column(db.String(20), nullable=False)
    actor_roles = db.Column(JSON, default=list)
    upload_roles = db.Column(JSON, default=list)
    is_mandatory = db.Column(db.Boolean, default=True)
    loop_only = db.Column(db.Boolean, default=False)
    requires_document = db.Column(db.Boolean, default=False)
    required_doc_types = db.Column(JSON, default=list)
    suggested_doc_types = db.Column(JSON, default=list)
    requires_approval = db.Column(db.Boolean, default=False)
    approval_type = db.Column(db.String(20))
    approver_type = db.Column(db.String(10))
    outcomes = db.Column(JSON, default=list)
    next_key = db.Column(db.String(60))
    waiting_type = db.Column(db.String(10), default="internal")
    waiting_label = db.Column(db.String(60))
    default_next_action = db.Column(db.String(300), default="")
    duration_days = db.Column(db.Integer, default=3)
    branch_group = db.Column(db.String(30))
    branch = db.Column(db.String(30))
    folder = db.Column(db.String(40), default="Lainnya")
    record_type = db.Column(db.String(30), default="general")
    fields = db.Column(JSON, default=list)
    calendar_category = db.Column(db.String(30))
    custom = db.Column(db.Boolean, default=False)

    # --- kondisi aktual
    status = db.Column(db.String(15), default="not_started", nullable=False)
    pic_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    planned_start = db.Column(db.Date, nullable=False)
    planned_finish = db.Column(db.Date, nullable=False)
    actual_start = db.Column(db.Date)
    actual_finish = db.Column(db.Date)
    waiting_for = db.Column(db.String(200))
    next_action = db.Column(db.String(300))
    next_action_due = db.Column(db.Date)
    remarks = db.Column(db.Text)
    iteration = db.Column(db.Integer, default=0)  # berapa kali proses dimulai
    loop_count = db.Column(db.Integer, default=0)  # berapa kali workflow kembali ke proses ini
    last_outcome = db.Column(db.String(40))
    problem_note = db.Column(db.Text)
    data = db.Column(JSON, default=dict)  # isi form proses
    entered_at = db.Column(db.DateTime)

    project = db.relationship("Project", back_populates="processes")
    pic = db.relationship("User")

    @property
    def is_running(self) -> bool:
        return self.status in RUNNING_STATUSES

    def outcome(self, key: str | None) -> dict | None:
        return next((o for o in (self.outcomes or []) if o["key"] == key), None)


# --------------------------------------------------------------------------- approvals
class Approval(db.Model):
    __tablename__ = "approvals"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("apv"))
    code = db.Column(db.String(20), unique=True, nullable=False)
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    process_id = db.Column(db.String(40), db.ForeignKey("project_processes.id"), nullable=False)
    type = db.Column(db.String(20), nullable=False)
    revision = db.Column(db.String(200), nullable=False)
    document_version_id = db.Column(db.String(40))
    requested_date = db.Column(db.Date, nullable=False)
    requested_by_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    approver_type = db.Column(db.String(10), nullable=False)  # customer | internal
    approver_name = db.Column(db.String(200), nullable=False)
    status = db.Column(db.String(20), default="pending", nullable=False)
    decision_date = db.Column(db.Date)
    decided_by_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    comment = db.Column(db.Text)
    attachment_version_id = db.Column(db.String(40))
    iteration = db.Column(db.Integer, default=1)
    linked_to_workflow = db.Column(db.Boolean, default=True)  # True = keputusan menggerakkan workflow
    created_at = db.Column(db.DateTime, default=datetime.now)

    project = db.relationship("Project")
    process = db.relationship("ProjectProcess")
    requested_by = db.relationship("User", foreign_keys=[requested_by_id])
    decided_by = db.relationship("User", foreign_keys=[decided_by_id])


# --------------------------------------------------------------------------- documents
class Document(db.Model):
    __tablename__ = "documents"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("doc"))
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    process_id = db.Column(db.String(40), db.ForeignKey("project_processes.id"), nullable=False)
    type = db.Column(db.String(30), nullable=False)
    name = db.Column(db.String(200), nullable=False)
    description = db.Column(db.Text)
    created_at = db.Column(db.DateTime, default=datetime.now)
    created_by = db.Column(db.String(40))
    latest_version_id = db.Column(db.String(40))

    project = db.relationship("Project")
    process = db.relationship("ProjectProcess")
    versions = db.relationship("DocumentVersion", back_populates="document", order_by="desc(DocumentVersion.uploaded_at)")

    @property
    def latest(self) -> "DocumentVersion | None":
        return next((v for v in self.versions if v.id == self.latest_version_id), None)


class DocumentVersion(db.Model):
    """Setiap revisi/versi file. Revisi lama TIDAK pernah dihapus (PRD §8)."""

    __tablename__ = "document_versions"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("ver"))
    document_id = db.Column(db.String(40), db.ForeignKey("documents.id"), nullable=False)
    revision = db.Column(db.Integer, default=0)  # Rev 00, 01, ...
    version = db.Column(db.Integer, default=1)  # versi file di dalam revisi yang sama
    file_name = db.Column(db.String(255), nullable=False)
    mime_type = db.Column(db.String(120), default="application/octet-stream")
    size = db.Column(db.Integer, default=0)
    stored_name = db.Column(db.String(255))  # nama file di folder upload ("seed" = file contoh)
    uploaded_by_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    uploaded_at = db.Column(db.DateTime, default=datetime.now)
    status = db.Column(db.String(15), default="current")  # current | superseded | rejected | approved
    note = db.Column(db.Text)

    document = db.relationship("Document", back_populates="versions")
    uploaded_by = db.relationship("User")


# --------------------------------------------------------------------------- trial_records / material_requests / validation_records
class ProcessRecord(db.Model):
    """Snapshot data setiap kali proses diselesaikan (trial record, material request, validation record, ...)."""

    __tablename__ = "process_records"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("rec"))
    number = db.Column(db.String(60), nullable=False)
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    process_id = db.Column(db.String(40), db.ForeignKey("project_processes.id"), nullable=False)
    record_type = db.Column(db.String(30), nullable=False)
    iteration = db.Column(db.Integer, default=1)
    data = db.Column(JSON, default=dict)
    outcome = db.Column(db.String(40))
    outcome_label = db.Column(db.String(80))
    comment = db.Column(db.Text)
    purchasing_status = db.Column(db.String(20))
    created_by_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    created_at = db.Column(db.DateTime, default=datetime.now)

    process = db.relationship("ProjectProcess")
    created_by = db.relationship("User")


# --------------------------------------------------------------------------- activities & comments
class Activity(db.Model):
    """Activity History: semua perubahan penting (PRD §16)."""

    __tablename__ = "activities"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("act"))
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    process_id = db.Column(db.String(40))
    type = db.Column(db.String(30), nullable=False)
    message = db.Column(db.String(300), nullable=False)
    detail = db.Column(db.Text)
    user_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    at = db.Column(db.DateTime, default=datetime.now)

    user = db.relationship("User")


class Comment(db.Model):
    __tablename__ = "comments"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("cmt"))
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"), nullable=False)
    process_id = db.Column(db.String(40))
    user_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    body = db.Column(db.Text, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.now)

    user = db.relationship("User")


# --------------------------------------------------------------------------- notifications
class Notification(db.Model):
    __tablename__ = "notifications"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("ntf"))
    user_id = db.Column(db.String(40), db.ForeignKey("users.id"), nullable=False)
    type = db.Column(db.String(30), nullable=False)
    title = db.Column(db.String(200), nullable=False)
    body = db.Column(db.Text, nullable=False)
    project_id = db.Column(db.String(40))
    created_at = db.Column(db.DateTime, default=datetime.now)
    read = db.Column(db.Boolean, default=False)
    dedupe_key = db.Column(db.String(200))  # mencegah notifikasi ganda


# --------------------------------------------------------------------------- kalender (meeting & follow-up)
class CalendarEvent(db.Model):
    __tablename__ = "calendar_events"
    id = db.Column(db.String(40), primary_key=True, default=lambda: new_id("evt"))
    title = db.Column(db.String(200), nullable=False)
    category = db.Column(db.String(20), nullable=False)  # meeting | follow_up
    date = db.Column(db.Date, nullable=False)
    time = db.Column(db.String(5))
    project_id = db.Column(db.String(40), db.ForeignKey("projects.id"))
    notes = db.Column(db.Text)
    created_by_id = db.Column(db.String(40), db.ForeignKey("users.id"))
    created_at = db.Column(db.DateTime, default=datetime.now)

    project = db.relationship("Project")
    created_by = db.relationship("User")


# --------------------------------------------------------------------------- master_data / pengaturan
class Setting(db.Model):
    """Pengaturan sistem (satu baris, id=1)."""

    __tablename__ = "settings"
    id = db.Column(db.Integer, primary_key=True)
    due_soon_days = db.Column(db.Integer, default=3, nullable=False)
    no_update_days = db.Column(db.Integer, default=7, nullable=False)


def get_settings() -> Setting:
    s = db.session.get(Setting, 1)
    if s is None:
        s = Setting(id=1)
        db.session.add(s)
        db.session.flush()
    return s


# --------------------------------------------------------------------------- riwayat AI Assistant
class AssistantMessage(db.Model):
    __tablename__ = "assistant_messages"
    id = db.Column(db.Integer, primary_key=True, autoincrement=True)
    user_id = db.Column(db.String(40), db.ForeignKey("users.id"), nullable=False)
    question = db.Column(db.Text, nullable=False)
    answer = db.Column(JSON, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.now)


__all__ = [
    "User", "Customer", "WorkflowTemplate", "Project", "ProjectProcess", "Approval", "Document",
    "DocumentVersion", "ProcessRecord", "Activity", "Comment", "Notification", "CalendarEvent",
    "Setting", "AssistantMessage", "get_settings", "new_id", "date", "datetime",
]
