"""Perhitungan turunan project: overdue, due soon, aging, progress, attention (PRD §7, §10)."""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date

from ..models import DocumentVersion, Project, ProjectProcess, Setting
from .dates import diff_days


def overdue_days(p: Project, today: date) -> int:
    """Jika Today > Target Date dan project belum selesai -> jumlah hari keterlambatan."""
    if not p.is_active:
        return 0
    return max(0, diff_days(p.target_date, today))


def is_overdue(p: Project, today: date) -> bool:
    return overdue_days(p, today) > 0


def days_to_target(p: Project, today: date) -> int:
    return diff_days(today, p.target_date)


def is_due_soon(p: Project, today: date, threshold: int) -> bool:
    return p.is_active and 0 <= days_to_target(p, today) <= threshold


def display_status(p: Project, today: date) -> str:
    return "overdue" if is_overdue(p, today) else p.status


def project_aging(p: Project, today: date) -> int:
    """Project Aging = Today − Project Start Date."""
    return max(0, diff_days(p.start_date, p.actual_finish or today))


def process_duration(proc: ProjectProcess, today: date) -> int | None:
    """Process Duration = Actual Finish − Actual Start (proses berjalan dihitung s/d hari ini)."""
    if not proc.actual_start:
        return None
    end = proc.actual_finish or (today if proc.is_running else None)
    return max(0, diff_days(proc.actual_start, end)) if end else None


def next_action_state(p: Project, today: date, threshold: int) -> str:
    if not p.is_active or not p.next_action_due:
        return "ok"
    d = diff_days(today, p.next_action_due)
    if d < 0:
        return "overdue"
    if d <= min(threshold, 1):
        return "due_soon"
    return "ok"


def process_progress(processes: list[ProjectProcess]) -> dict:
    applicable = [p for p in processes if p.status != "skipped" and not (p.loop_only and p.status == "not_started")]
    done = sum(1 for p in applicable if p.status == "completed")
    total = len(applicable)
    return {"done": done, "total": total, "pct": round(done / total * 100) if total else 0}


def days_since_update(p: Project, today: date) -> int:
    return max(0, diff_days(p.updated_at.date(), today)) if p.updated_at else 0


def has_required_document(proc: ProjectProcess, documents) -> bool:
    """Proses sudah punya dokumen wajib (tipe sesuai, versi terbaru bukan Rejected)."""
    if not proc.requires_document:
        return True
    for d in documents:
        if d.process_id != proc.id:
            continue
        if proc.required_doc_types and d.type not in proc.required_doc_types:
            continue
        latest = d.latest
        if latest and latest.status != "rejected":
            return True
    return False


def missing_document_processes(processes: list[ProjectProcess], documents) -> list[ProjectProcess]:
    return [
        p
        for p in processes
        if p.requires_document and (p.is_running or p.status == "completed") and not has_required_document(p, documents)
    ]


@dataclass
class ProjectView:
    """Ringkasan project yang siap ditampilkan (list, dashboard, dll.)."""

    project: Project
    current: ProjectProcess | None
    display_status: str
    overdue_days: int
    days_to_target: int
    aging: int
    progress: dict
    next_action_state: str
    days_since_update: int
    missing_docs: list[str]
    flags: dict = field(default_factory=dict)
    pending_approvals: int = 0

    @property
    def customer(self) -> str:
        return self.project.customer.name if self.project.customer else "—"


def build_view(p: Project, today: date, settings: Setting, documents=None, pending_approvals: int = 0) -> ProjectView:
    from ..models import Document

    docs = documents if documents is not None else Document.query.filter_by(project_id=p.id).all()
    missing = missing_document_processes(p.processes, docs)
    active = p.is_active
    since = days_since_update(p, today)
    flags = {
        "overdue": is_overdue(p, today),
        "due_soon": is_due_soon(p, today, settings.due_soon_days),
        "waiting_approval": active and p.status == "waiting_approval",
        "waiting_external": active and p.status == "waiting_external",
        "no_update": active and since >= settings.no_update_days,
        "missing_document": active and len(missing) > 0,
    }
    return ProjectView(
        project=p,
        current=p.current_process,
        display_status=display_status(p, today),
        overdue_days=overdue_days(p, today),
        days_to_target=days_to_target(p, today),
        aging=project_aging(p, today),
        progress=process_progress(p.processes),
        next_action_state=next_action_state(p, today, settings.due_soon_days),
        days_since_update=since,
        missing_docs=[m.name for m in missing],
        flags=flags,
        pending_approvals=pending_approvals,
    )


__all__ = ["DocumentVersion"]
