"""Approval Management: daftar approval & keputusan approval manual (PRD §9)."""
from __future__ import annotations

from statistics import mean

from flask import Blueprint, abort, g, redirect, render_template, request, url_for

from ..extensions import db
from ..models import Approval, Document
from ..services import approvals as approval_service
from ..services.dates import diff_days
from .helpers import ctx, run, safe_next, visible_projects

bp = Blueprint("approvals", __name__, url_prefix="/approvals")


def waiting_days(a: Approval, today) -> int:
    return diff_days(a.requested_date, a.decision_date or today)


@bp.route("")
def index():
    today = ctx().today
    ids = [p.id for p in visible_projects()]
    all_rows = Approval.query.filter(Approval.project_id.in_(ids)).order_by(Approval.created_at.desc()).all()
    decidable = {a.id for a in all_rows if approval_service.can_decide_now(g.user, a)}
    tab = request.args.get("tab", "mine")
    f = {k: request.args.get(k, "") for k in ("q", "type", "status", "approver")}
    q = f["q"].strip().lower()
    rows = []
    for a in all_rows:
        if tab == "mine" and a.id not in decidable:
            continue
        if tab == "pending" and a.status != "pending":
            continue
        if f["type"] and a.type != f["type"]:
            continue
        if f["status"] and a.status != f["status"]:
            continue
        if f["approver"] and a.approver_type != f["approver"]:
            continue
        if q and q not in " ".join([a.code, a.project.code, a.project.name, a.project.customer.name, a.revision, a.process.name]).lower():
            continue
        rows.append(a)
    pending_waits = [waiting_days(a, today) for a in all_rows if a.status == "pending"]
    decided_customer = [waiting_days(a, today) for a in all_rows if a.approver_type == "customer" and a.decision_date]
    month = today.replace(day=1)
    stats = {
        "mine": len(decidable),
        "pending": sum(1 for a in all_rows if a.status == "pending"),
        "all": len(all_rows),
        "avg_pending": round(mean(pending_waits), 1) if pending_waits else None,
        "avg_customer": round(mean(decided_customer), 1) if decided_customer else None,
        "rejected_month": sum(1 for a in all_rows if a.status in ("rejected", "revision_required") and a.decision_date and a.decision_date >= month),
    }
    return render_template("approvals/index.html", rows=rows, tab=tab, f=f, stats=stats, decidable=decidable)


@bp.route("/<approval_id>/decide", methods=["GET", "POST"])
def decide(approval_id):
    """Keputusan approval manual. Approval workflow diputuskan dari form proses terkait."""
    approval = db.session.get(Approval, approval_id)
    if not approval:
        abort(404)
    if approval.linked_to_workflow:
        return redirect(url_for("process.complete", code=approval.project.code, process_id=approval.process_id))
    if not approval_service.can_decide_now(g.user, approval):
        abort(403, "Anda tidak dapat memutuskan approval ini.")
    values, errors = {"decision": "approved", "comment": "", "attachment_version_id": ""}, {}
    if request.method == "POST":
        values = {k: request.form.get(k, "") for k in values}
        ok, error = run(lambda: approval_service.decide_approval(
            ctx(), approval.id, decision=values["decision"], comment=values["comment"],
            attachment_version_id=values["attachment_version_id"] or None,
        ), "Keputusan approval disimpan")
        if ok:
            return redirect(safe_next(request.args.get("next"), url_for("projects.detail", code=approval.project.code, tab="approvals")))
        errors = error.field_errors
    attachments = []
    for d in Document.query.filter_by(project_id=approval.project_id).all():
        for v in d.versions:
            attachments.append((v.id, f"{d.name} — Rev {v.revision:02d}" + (f" v{v.version}" if v.version > 1 else "")))
    return render_template("approvals/decide.html", a=approval, values=values, errors=errors, attachments=attachments)
