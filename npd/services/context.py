"""Konteks aksi (siapa & kapan) serta helper yang dipakai banyak service."""
from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime

from ..constants import ROLES
from ..extensions import db
from ..models import Activity, Customer, Notification, Project, ProjectProcess, User
from .errors import AppError, not_found


@dataclass
class Ctx:
    """Siapa yang melakukan aksi dan kapan.

    Data demo memakai tanggal di masa lalu, karena itu `now`/`today` dikirim
    secara eksplisit dan tidak diambil langsung dari jam sistem.
    """

    actor: User
    now: datetime
    today: date

    @classmethod
    def for_user(cls, user: User) -> "Ctx":
        now = datetime.now()
        return cls(actor=user, now=now, today=now.date())


def get_project(project_id: str) -> Project:
    project = db.session.get(Project, project_id)
    if not project:
        raise not_found("Project tidak ditemukan.")
    return project


def get_process(process_id: str) -> ProjectProcess:
    proc = db.session.get(ProjectProcess, process_id)
    if not proc:
        raise not_found("Proses tidak ditemukan.")
    return proc


def user_name(user_id: str | None) -> str:
    if not user_id:
        return "—"
    user = db.session.get(User, user_id)
    return user.name if user else "User tidak dikenal"


def customer_name(project: Project) -> str:
    return project.customer.name if project.customer else "—"


def first_user_with_role(role: str) -> User | None:
    return User.query.filter_by(role=role, active=True).order_by(User.name).first()


def default_pic_id(project: Project, role: str) -> str | None:
    """PIC default sebuah proses, diambil dari PIC project sesuai role."""
    if role == "admin_sales":
        return project.sales_pic_id
    if role == "npd_staff":
        return project.npd_pic_id
    if role == "drafter":
        return project.drafter_id
    user = first_user_with_role(role)
    return user.id if user else None


def pic_label(proc: ProjectProcess) -> str:
    return f"{ROLES[proc.pic_role]} — {user_name(proc.pic_id)}"


def project_pics(project: Project) -> list[str]:
    return [project.npd_pic_id, project.sales_pic_id, project.drafter_id]


def log_activity(ctx: Ctx, project: Project, type: str, message: str, process_id: str | None = None, detail: str | None = None) -> None:
    """Catat Activity History & tandai project baru saja di-update."""
    db.session.add(Activity(project_id=project.id, process_id=process_id, type=type, message=message, detail=detail, user_id=ctx.actor.id, at=ctx.now))
    project.updated_at = ctx.now


def notify(ctx: Ctx, user_ids, type: str, title: str, body: str, project_id: str | None = None, dedupe_key: str | None = None, include_actor: bool = False) -> None:
    """Kirim notifikasi ke beberapa user (tanpa duplikat, pelaku aksi tidak dikirimi)."""
    recipients = {u for u in user_ids if u}
    if not include_actor:
        recipients.discard(ctx.actor.id)
    for uid in recipients:
        user = db.session.get(User, uid)
        if not user or not user.active:
            continue
        if dedupe_key and Notification.query.filter_by(user_id=uid, dedupe_key=dedupe_key).first():
            continue
        db.session.add(Notification(user_id=uid, type=type, title=title, body=body, project_id=project_id, created_at=ctx.now, dedupe_key=dedupe_key))
    db.session.flush()


def assert_active(project: Project, action: str = "melanjutkan proses") -> None:
    if project.status == "completed":
        raise AppError("INVALID_STATE", f"Project sudah Completed — tidak dapat {action}.")
    if project.status == "cancelled":
        raise AppError("INVALID_STATE", f"Project sudah Cancelled — tidak dapat {action}.")
    if project.status == "hold":
        raise AppError("INVALID_STATE", f"Project sedang Hold. Ubah status project terlebih dahulu untuk {action}.")


__all__ = ["Ctx", "get_project", "get_process", "user_name", "customer_name", "default_pic_id", "pic_label", "project_pics", "log_activity", "notify", "assert_active", "Customer"]
