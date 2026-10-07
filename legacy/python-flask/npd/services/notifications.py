"""Notifikasi berbasis waktu (PRD §12): deadline mendekat, overdue, next action, dokumen wajib.

Notifikasi berbasis event (assignment, approval, dokumen) dikirim langsung oleh service
terkait lewat context.notify(). Fungsi di sini dijalankan saat user login dan aman
dipanggil berulang karena memakai dedupe_key.
"""
from __future__ import annotations

from ..extensions import db
from ..models import Document, Notification, Project, get_settings
from .context import Ctx, notify
from .dates import describe_due, diff_days, fmt_date
from .metrics import has_required_document, is_due_soon, is_overdue, overdue_days


def scan_deadlines(ctx: Ctx) -> None:
    settings = get_settings()
    today = ctx.today
    for p in Project.query.all():
        if not p.is_active or p.status == "hold":
            continue
        pics = [p.npd_pic_id, p.sales_pic_id]
        if is_overdue(p, today):
            notify(ctx, pics, "project_overdue", "Project overdue",
                   f"{p.code} · {p.name} melewati target {fmt_date(p.target_date)} ({overdue_days(p, today)} hari).",
                   project_id=p.id, dedupe_key=f"overdue:{p.id}:{p.target_date}", include_actor=True)
        elif is_due_soon(p, today, settings.due_soon_days):
            notify(ctx, pics, "deadline_approaching", "Deadline project mendekat",
                   f"{p.code} · {p.name} — target {fmt_date(p.target_date)} ({describe_due(p.target_date, today)}).",
                   project_id=p.id, dedupe_key=f"deadline:{p.id}:{p.target_date}", include_actor=True)
        cur = p.current_process
        assignees = [cur.pic_id if cur else None, p.npd_pic_id]
        days = diff_days(today, p.next_action_due)
        key = f"{p.id}:{p.next_action_due}:{p.next_action}"
        if days < 0:
            notify(ctx, assignees, "next_action_overdue", "Next Action terlambat",
                   f'{p.code}: "{p.next_action}" {describe_due(p.next_action_due, today)}.', project_id=p.id, dedupe_key=f"na-over:{key}", include_actor=True)
        elif days <= 1:
            notify(ctx, assignees, "next_action_due", "Next Action jatuh tempo",
                   f'{p.code}: "{p.next_action}" due {describe_due(p.next_action_due, today)}.', project_id=p.id, dedupe_key=f"na-due:{key}", include_actor=True)
        docs = Document.query.filter_by(project_id=p.id).all()
        for proc in p.processes:
            if not proc.is_running or not proc.requires_document or not proc.actual_start:
                continue
            if diff_days(proc.actual_start, today) < 1 or has_required_document(proc, docs):
                continue
            notify(ctx, [proc.pic_id], "missing_document", "Dokumen wajib belum diupload",
                   f"{p.code} · {proc.name} belum memiliki dokumen wajib.", project_id=p.id, dedupe_key=f"missing:{proc.id}:{proc.iteration}", include_actor=True)


def unread_count(user_id: str) -> int:
    return Notification.query.filter_by(user_id=user_id, read=False).count()


def mark_read(user_id: str, notification_id: str | None = None) -> None:
    """Tandai satu notifikasi (atau semua jika id kosong) sebagai sudah dibaca."""
    query = Notification.query.filter_by(user_id=user_id, read=False)
    if notification_id:
        query = query.filter_by(id=notification_id)
    for n in query.all():
        n.read = True
    db.session.flush()
