"""Approval Management (PRD §9).

Approval yang dibuat workflow (linked_to_workflow=True) diputuskan lewat proses
decision-nya, sehingga keputusan langsung menggerakkan workflow. Approval manual
(mis. 2D Approval) hanya mencatat keputusan.
"""
from __future__ import annotations

from ..constants import APPROVAL_STATUSES, APPROVAL_TYPES
from ..extensions import db
from ..models import Approval, DocumentVersion
from . import permissions as perm
from .context import Ctx, customer_name, get_process, get_project, log_activity, notify, project_pics
from .errors import AppError, forbidden, invalid, not_found
from .workflow_engine import complete_process, describe_version, next_approval_code


def request_approval(ctx: Ctx, project_id: str, process_id: str, type: str, approver_type: str,
                     document_version_id: str | None = None, revision: str | None = None,
                     approver_name: str | None = None, comment: str | None = None) -> Approval:
    """Ajukan approval manual (di luar workflow otomatis)."""
    project = get_project(project_id)
    proc = get_process(process_id)
    if not perm.can_request_approval(ctx.actor) or not perm.can_view_project(ctx.actor, project):
        raise forbidden("Role Anda tidak dapat mengajukan approval.")
    if not project.is_active:
        raise AppError("INVALID_STATE", "Project sudah ditutup.")
    errors = {}
    if type not in APPROVAL_TYPES:
        errors["type"] = "Pilih Approval Type."
    if not document_version_id and not (revision or "").strip():
        errors["revision"] = "Pilih dokumen atau isi revision yang diajukan."
    if approver_type == "internal" and not (approver_name or "").strip():
        errors["approver_name"] = "Isi nama approver internal."
    if errors:
        raise invalid("Lengkapi data approval.", errors)
    dup = Approval.query.filter_by(process_id=proc.id, type=type, status="pending").first()
    if dup:
        raise AppError("CONFLICT", f"{APPROVAL_TYPES[type]} untuk proses ini masih Pending ({dup.code}).")
    approval = Approval(
        code=next_approval_code(ctx.today.year),
        project_id=project.id,
        process_id=proc.id,
        type=type,
        revision=describe_version(document_version_id) if document_version_id else revision.strip(),
        document_version_id=document_version_id or None,
        requested_date=ctx.today,
        requested_by_id=ctx.actor.id,
        approver_type=approver_type,
        approver_name=f"Customer — {customer_name(project)}" if approver_type == "customer" else approver_name.strip(),
        status="pending",
        comment=(comment or "").strip() or None,
        iteration=proc.iteration,
        linked_to_workflow=False,
        created_at=ctx.now,
    )
    db.session.add(approval)
    log_activity(ctx, project, "approval_requested", f"{APPROVAL_TYPES[type]} diajukan ({approval.revision})", process_id=proc.id)
    notify(ctx, project_pics(project), "approval_requested", f"{APPROVAL_TYPES[type]} diminta", f"{project.code} · {project.name} — {approval.revision}", project_id=project.id)
    return approval


def decide_approval(ctx: Ctx, approval_id: str, decision: str | None = None, outcome_key: str | None = None,
                    target_key: str | None = None, comment: str | None = None, attachment_version_id: str | None = None) -> None:
    approval = db.session.get(Approval, approval_id)
    if not approval:
        raise not_found("Approval tidak ditemukan.")
    project = approval.project
    proc = approval.process
    if not perm.can_decide_approval(ctx.actor, project, approval):
        raise forbidden("Anda tidak memiliki akses untuk memutuskan approval ini.")
    if approval.status != "pending":
        raise AppError("INVALID_STATE", f"Approval ini sudah diputuskan ({APPROVAL_STATUSES[approval.status]}).")

    if approval.linked_to_workflow:
        if not proc.is_running or project.current_process_id != proc.id:
            raise AppError("INVALID_STATE", "Proses approval ini sudah tidak aktif.")
        outcome = proc.outcome(outcome_key) or next((o for o in proc.outcomes if o.get("approval_status") == decision), None)
        if not outcome:
            raise invalid("Pilih keputusan approval.")
        complete_process(ctx, proc.id, outcome_key=outcome["key"], target_key=target_key, comment=comment, attachment_version_id=attachment_version_id)
        return

    if decision not in ("approved", "rejected", "revision_required"):
        raise invalid("Pilih keputusan approval.")
    if decision != "approved" and not (comment or "").strip():
        raise invalid("Komentar wajib diisi untuk penolakan / revision required.", {"comment": "Komentar wajib diisi."})
    approval.status = decision
    approval.decision_date = ctx.today
    approval.decided_by_id = ctx.actor.id
    approval.comment = (comment or "").strip() or None
    approval.attachment_version_id = attachment_version_id
    if approval.document_version_id:
        version = db.session.get(DocumentVersion, approval.document_version_id)
        if version:
            version.status = "approved" if decision == "approved" else "rejected"
    approved = decision == "approved"
    log_activity(ctx, project, "approval_decided", f"{APPROVAL_TYPES[approval.type]}: {APPROVAL_STATUSES[decision]}", process_id=proc.id,
                 detail=" — ".join(x for x in [approval.revision, approval.comment] if x))
    notify(ctx, project_pics(project), "approval_approved" if approved else "approval_rejected",
           f"{APPROVAL_TYPES[approval.type]} {'disetujui' if approved else 'ditolak'}", f"{project.code} · {project.name} — {approval.revision}", project_id=project.id)
    if project.status == "waiting_approval" and not Approval.query.filter_by(project_id=project.id, status="pending").first():
        project.status = "on_progress"


def can_decide_now(user, approval: Approval) -> bool:
    """Approval bisa diputuskan user ini sekarang (pending, proses aktif, project tidak Hold/ditutup)."""
    project = approval.project
    if approval.status != "pending" or project.status in ("hold", "completed", "cancelled"):
        return False
    if not perm.can_decide_approval(user, project, approval):
        return False
    if approval.linked_to_workflow:
        return approval.process.is_running and project.current_process_id == approval.process_id
    return True
