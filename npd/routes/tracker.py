"""Process Tracker: papan (board) project aktif per proses (PRD §7)."""
from __future__ import annotations

from flask import Blueprint, render_template, request

from ..models import WorkflowTemplate
from .helpers import project_views

bp = Blueprint("tracker", __name__, url_prefix="/tracker")


@bp.route("")
def index():
    ptype = request.args.get("type", "subcont")
    if ptype not in ("subcont", "new_mold"):
        ptype = "subcont"
    q = request.args.get("q", "").strip().lower()
    include_hold = request.args.get("hold", "1") == "1"
    views = project_views()
    active = [v for v in views if v.project.is_active]
    counts = {t: sum(1 for v in active if v.project.type == t) for t in ("subcont", "new_mold")}
    items = [
        v for v in active
        if v.project.type == ptype
        and (include_hold or v.project.status != "hold")
        and (not q or q in " ".join([v.project.code, v.project.name, v.customer]).lower())
    ]
    workflow = WorkflowTemplate.query.filter_by(project_type=ptype).first()
    columns = [{"key": d["key"], "name": d["name"], "loop_only": d.get("loop_only", False)} for d in (workflow.processes if workflow else [])]
    for v in items:  # proses yang sudah dihapus dari template tetap tampil
        if v.current and not any(c["key"] == v.current.key for c in columns):
            columns.append({"key": v.current.key, "name": v.current.name, "loop_only": False})
    for c in columns:
        c["items"] = [v for v in items if v.current and v.current.key == c["key"]]
    return render_template("tracker.html", ptype=ptype, q=q, include_hold=include_hold, columns=columns, counts=counts, total=len(items))
