"""Halaman Project: daftar, buat, detail, edit, status, next action, finish, dll. (PRD §6, §7)."""
from __future__ import annotations

from flask import Blueprint, Response, abort, g, redirect, render_template, request, url_for

from ..constants import (
    APPROVAL_TYPES, MANUAL_STATUSES, PRIORITIES, PRIORITY_RANK, PROCESS_STATUSES, PROJECT_TYPES, PURCHASING_STATUSES, STATUSES,
)
from ..extensions import db
from ..models import (
    Activity, Approval, Comment, Customer, Document, ProcessRecord, User, WorkflowTemplate, get_settings,
)
from ..services import approvals as approval_service
from ..services import permissions as perm
from ..services import projects as project_service
from ..services import workflow_engine as engine
from ..services.assistant import summarize_project
from ..services.dates import add_days, diff_days, fmt_date
from ..services.excel import build_workbook
from ..services.metrics import build_view, has_required_document, process_duration
from .helpers import ctx, get_visible_project, project_views, run

bp = Blueprint("projects", __name__, url_prefix="/projects")

# --------------------------------------------------------------------------- filter & sort daftar project
STATUS_FILTERS = {
    "active": ("Semua aktif", lambda v: v.project.is_active),
    "not_started": ("Not Started", lambda v: v.project.status == "not_started"),
    "on_progress": ("On Progress", lambda v: v.project.status == "on_progress"),
    "waiting": ("Waiting (semua)", lambda v: v.project.status in ("waiting_approval", "waiting_external")),
    "waiting_approval": ("Waiting Approval", lambda v: v.project.status == "waiting_approval"),
    "waiting_external": ("Waiting External", lambda v: v.project.status == "waiting_external"),
    "hold": ("Hold", lambda v: v.project.status == "hold"),
    "overdue": ("Overdue", lambda v: v.flags["overdue"]),
    "due_soon": ("Due Soon", lambda v: v.flags["due_soon"]),
    "completed": ("Completed", lambda v: v.project.status == "completed"),
    "cancelled": ("Cancelled", lambda v: v.project.status == "cancelled"),
}

SORTS = {
    "code_desc": ("Terbaru", lambda v: v.project.code, True),
    "target_asc": ("Target terdekat", lambda v: v.project.target_date, False),
    "next_action": ("Next action terdekat", lambda v: v.project.next_action_due, False),
    "priority": ("Priority tertinggi", lambda v: (-PRIORITY_RANK[v.project.priority], v.project.target_date), False),
    "overdue": ("Paling terlambat", lambda v: (-v.overdue_days, v.days_to_target), False),
    "aging": ("Aging terlama", lambda v: v.aging, True),
    "updated": ("Update terakhir", lambda v: v.project.updated_at, True),
    "name": ("Nama A–Z", lambda v: v.project.name.lower(), False),
}


def filtered_projects(args) -> tuple[list, dict]:
    """Terapkan filter & urutan dari query string. Dipakai halaman daftar dan export Excel."""
    views = project_views()
    f = {k: args.get(k, "") for k in ("q", "type", "status", "priority", "customer", "pic", "process", "mine", "sort")}
    q = f["q"].strip().lower()
    items = []
    for v in views:
        p = v.project
        if f["type"] and p.type != f["type"]:
            continue
        if f["status"] in STATUS_FILTERS and not STATUS_FILTERS[f["status"]][1](v):
            continue
        if f["priority"] and p.priority != f["priority"]:
            continue
        if f["customer"] and v.customer != f["customer"]:
            continue
        if f["pic"] and p.npd_pic.name != f["pic"]:
            continue
        if f["process"] and (not v.current or v.current.name != f["process"]):
            continue
        if f["mine"] and g.user.id not in (p.npd_pic_id, p.sales_pic_id, p.drafter_id, v.current.pic_id if v.current else None):
            continue
        if q and q not in " ".join([p.code, p.name, v.customer, p.product_name, p.npd_pic.name]).lower():
            continue
        items.append(v)
    label, key, reverse = SORTS.get(f["sort"], SORTS["code_desc"])
    items.sort(key=key, reverse=reverse)
    return items, f


@bp.route("")
def index():
    items, f = filtered_projects(request.args)
    views = project_views()
    options = {
        "customers": sorted({v.customer for v in views}),
        "pics": sorted({v.project.npd_pic.name for v in views}),
        "processes": sorted({v.current.name for v in views if v.current and (not f["type"] or v.project.type == f["type"])}),
    }
    active_filters = sum(1 for k in ("status", "priority", "customer", "pic", "process", "mine") if f[k])
    return render_template(
        "projects/index.html", items=items, f=f, options=options, total=len(views), active_filters=active_filters,
        status_filters={k: v[0] for k, v in STATUS_FILTERS.items()}, sorts={k: v[0] for k, v in SORTS.items()},
    )


@bp.route("/export.xlsx")
def export():
    """Export daftar project (sesuai filter yang sedang aktif) ke Excel."""
    items, _ = filtered_projects(request.args)
    header = ["Project ID", "Project Type", "Customer", "Project Name", "Product", "NPD PIC", "Sales PIC", "Drafter", "Priority",
              "Start Date", "Target Finish", "Actual Finish", "Current Process", "Status", "Overdue (hari)", "Waiting For",
              "Next Action", "Next Action Due", "Progress (%)", "Aging (hari)", "Remarks"]
    rows = [header]
    for v in items:
        p = v.project
        rows.append([
            p.code, PROJECT_TYPES[p.type], v.customer, p.name, p.product_name, p.npd_pic.name, p.sales_pic.name, p.drafter.name,
            PRIORITIES[p.priority], p.start_date, p.target_date, p.actual_finish, v.current.name if v.current else "",
            STATUSES[p.status], v.overdue_days or "", p.waiting_for or "", p.next_action, p.next_action_due,
            v.progress["pct"], v.aging, p.remarks or "",
        ])
    data = build_workbook([{"name": "Projects", "rows": rows, "widths": [14, 12, 18, 32, 22, 16, 16, 16, 10, 12, 12, 12, 28, 16, 12, 28, 40, 14, 10, 10, 30]}])
    filename = f"NPD-Projects-{ctx().today.isoformat()}.xlsx"
    return Response(data, mimetype="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                    headers={"Content-Disposition": f'attachment; filename="{filename}"'})


# --------------------------------------------------------------------------- buat & edit
def form_options():
    """Pilihan dropdown untuk form project."""
    users = User.query.filter_by(active=True).order_by(User.name).all()
    return {
        "customers": [(c.id, c.name) for c in Customer.query.filter_by(active=True).order_by(Customer.name).all()],
        "npd": [(u.id, u.name) for u in users if u.role in ("npd_staff", "admin")],
        "sales": [(u.id, u.name) for u in users if u.role == "admin_sales"],
        "drafter": [(u.id, u.name) for u in users if u.role == "drafter"],
        "priorities": list(PRIORITIES.items()),
    }


@bp.route("/new", methods=["GET", "POST"])
def create():
    if not perm.can_create_project(g.user):
        abort(403, "Role Anda tidak dapat membuat project.")
    today = ctx().today
    values = {
        "type": request.args.get("type", ""),
        "priority": "medium",
        "start_date": today.isoformat(),
        "target_date": add_days(today, 90).isoformat(),
        "npd_pic_id": g.user.id if g.user.role == "npd_staff" else "",
        "sales_pic_id": g.user.id if g.user.role == "admin_sales" else "",
    }
    errors = {}
    if request.method == "POST":
        values = request.form.to_dict()
        created = {}
        ok, error = run(lambda: created.update(project=project_service.create_project(ctx(), values)), "Project berhasil dibuat")
        if ok:
            return redirect(url_for("projects.detail", code=created["project"].code))
        errors = error.field_errors
    templates = {t.project_type: t for t in WorkflowTemplate.query.all()}
    return render_template("projects/form.html", mode="create", values=values, errors=errors, opts=form_options(), templates=templates)


@bp.route("/<code>/edit", methods=["GET", "POST"])
def edit(code):
    project = get_visible_project(code)
    if not perm.can_edit_project(g.user, project) or project.status == "cancelled":
        abort(403, "Anda tidak dapat mengubah project ini.")
    values = {k: getattr(project, k) for k in project_service.EDITABLE}
    values["type"] = project.type
    errors = {}
    if request.method == "POST":
        patch = {k: request.form.get(k, "") for k in project_service.EDITABLE}
        values.update(patch)
        ok, error = run(lambda: project_service.update_project(ctx(), project.id, patch), "Project diperbarui")
        if ok:
            return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors
    return render_template("projects/form.html", mode="edit", project=project, values=values, errors=errors, opts=form_options(), templates={})


# --------------------------------------------------------------------------- detail
def timeline_rows(project, processes, today):
    """Posisi bar planned vs actual (dalam persen) untuk grafik timeline."""
    rows = [p for p in processes if not (p.loop_only and p.status == "not_started")]
    start, end = project.start_date, max(project.target_date, today)
    for p in rows:
        start = min(start, p.planned_start, p.actual_start or p.planned_start)
        end = max(end, p.planned_finish, p.actual_finish or p.planned_finish)
    span = max(1, diff_days(start, end))

    def pos(d):
        return diff_days(start, d) / span * 100

    result = []
    for p in rows:
        actual_end = p.actual_finish or (today if p.actual_start and p.is_running else None)
        late = (p.actual_finish > p.planned_finish) if p.actual_finish else (bool(actual_end) and today > p.planned_finish)
        result.append({
            "proc": p,
            "duration": process_duration(p, today),
            "planned_days": max(1, diff_days(p.planned_start, p.planned_finish)),
            "plan_left": pos(p.planned_start),
            "plan_width": max(0.8, pos(p.planned_finish) - pos(p.planned_start)),
            "actual_left": pos(p.actual_start) if p.actual_start and actual_end else None,
            "actual_width": max(0.8, pos(actual_end) - pos(p.actual_start)) if p.actual_start and actual_end else None,
            "tone": "orange" if late else ("green" if p.status == "completed" else "blue"),
        })
    today_pos = pos(today)
    return {"rows": result, "start": start, "end": end, "today_pos": today_pos if 0 <= today_pos <= 100 else None}


def step_meta(p) -> str:
    """Teks kecil di bawah nama proses pada Process Tracker."""
    from ..services.dates import fmt_date_short

    if p.status == "completed":
        return f"Selesai {fmt_date_short(p.actual_finish)}"
    if p.status == "skipped" or (p.loop_only and p.status == "not_started"):
        return "Hanya saat loop" if p.loop_only else "Tidak dijalankan"
    if p.is_running:
        return f"Target {fmt_date_short(p.next_action_due or p.planned_finish)}"
    return f"Plan {fmt_date_short(p.planned_finish)}"


def activity_feed(project, kind: str):
    """Gabungan Activity History + komentar, terbaru di atas."""
    names = {p.id: p.name for p in project.processes}
    items = []
    for a in Activity.query.filter_by(project_id=project.id).all():
        items.append({"kind": "activity", "type": a.type, "message": a.message, "detail": a.detail, "user": a.user.name if a.user else "Sistem",
                      "at": a.at, "process": names.get(a.process_id)})
    for c in Comment.query.filter_by(project_id=project.id).all():
        items.append({"kind": "comment", "type": "comment", "message": c.body, "detail": None, "user": c.user.name if c.user else "—",
                      "at": c.created_at, "process": names.get(c.process_id)})
    if kind == "comment":
        items = [i for i in items if i["kind"] == "comment"]
    elif kind == "document":
        items = [i for i in items if i["type"].startswith("document")]
    elif kind == "workflow":
        items = [i for i in items if i["kind"] == "activity" and not i["type"].startswith("document")]
    items.sort(key=lambda i: i["at"], reverse=True)
    return items


def load_documents(project):
    """Dokumen project dikelompokkan per folder (PRD §8)."""
    documents = Document.query.filter_by(project_id=project.id).all()
    by_process = {}
    for d in documents:
        by_process.setdefault(d.process_id, []).append(d)
    folders = {}
    for proc in project.processes:
        if proc.status == "skipped" and not by_process.get(proc.id):
            continue
        folders.setdefault(proc.folder or "Lainnya", []).append({"proc": proc, "documents": by_process.get(proc.id, [])})
    return documents, dict(sorted(folders.items()))


RECORD_LABEL = {"trial": "Trial Record", "material_request": "Material Request", "material_preparation": "Material Preparation", "validation": "Validation Record"}


def record_summary(r) -> list[str]:
    d = r.data or {}
    parts = [
        f"Tanggal {fmt_date(d['trial_date'])}" if d.get("trial_date") else None,
        f"Tanggal {fmt_date(d['validation_date'])}" if d.get("validation_date") else None,
        f"Mesin {d['machine']}" if d.get("machine") else None,
        f"Result {d['trial_result']}" if d.get("trial_result") else None,
        f"Material {d['material']}" if d.get("material") else None,
        f"Diterima {d['material_received']}" if d.get("material_received") else None,
        f"Qty {d['quantity']}" if d.get("quantity") not in (None, "") else None,
        f"Qty produksi {d['production_quantity']}" if d.get("production_quantity") not in (None, "") else None,
        f"Supplier {d['supplier']}" if d.get("supplier") else None,
        f"Required {fmt_date(d['required_date'])}" if d.get("required_date") else None,
        f"Test: {', '.join(d['tests'])}" if isinstance(d.get("tests"), list) and d["tests"] else None,
    ]
    return [x for x in parts if x]


@bp.route("/<code>")
def detail(code):
    project = get_visible_project(code)
    today = ctx().today
    settings = get_settings()
    documents, folders = load_documents(project)
    approvals = Approval.query.filter_by(project_id=project.id).order_by(Approval.created_at.desc()).all()
    view = build_view(project, today, settings, documents, sum(1 for a in approvals if a.status == "pending"))
    current = view.current
    records = [r for r in ProcessRecord.query.filter_by(project_id=project.id).order_by(ProcessRecord.created_at.desc()).all() if r.record_type != "general"]
    tab = request.args.get("tab", "approvals")
    activity_kind = request.args.get("activity", "all")
    workflow = db.session.get(WorkflowTemplate, project.workflow_id)

    running = bool(current) and current.is_running and project.is_active
    pending_current = next((a for a in approvals if current and a.process_id == current.id and a.status == "pending"), None)
    return render_template(
        "projects/detail.html",
        p=project,
        view=view,
        current=current,
        running=running,
        can_act=bool(current) and perm.can_act_on_process(g.user, project, current),
        has_required_doc=has_required_document(current, documents) if current else True,
        pending_current=pending_current,
        approvals=approvals,
        documents=documents,
        folders=folders,
        records=records,
        record_label=RECORD_LABEL,
        record_summary=record_summary,
        tab=tab,
        activity_kind=activity_kind,
        feed=activity_feed(project, activity_kind) if tab == "activity" else [],
        timeline=timeline_rows(project, project.processes, today),
        step_meta=step_meta,
        workflow_name=f"{workflow.name if workflow else PROJECT_TYPES[project.type]} v{project.workflow_version}",
        last_update=diff_days(project.updated_at.date(), today),
        aging_in_process=diff_days(current.entered_at.date(), today) if current and current.entered_at else None,
        can_decide=lambda a: project.is_active and project.status != "hold" and approval_service.can_decide_now(g.user, a),
        purchasing_statuses=PURCHASING_STATUSES,
    )


@bp.route("/<code>/summary")
def summary(code):
    """Ringkasan AI project (disusun dari data sistem)."""
    project = get_visible_project(code)
    answer = summarize_project(g.user, project, ctx().today)
    return render_template("projects/summary.html", p=project, answer=answer)


# --------------------------------------------------------------------------- aksi project
@bp.route("/<code>/status", methods=["GET", "POST"])
def status(code):
    project = get_visible_project(code)
    if not perm.can_change_status(g.user) or not project.is_active:
        abort(403, "Anda tidak dapat mengubah status project ini.")
    values = {"status": project.status if project.status in MANUAL_STATUSES else "on_progress", "waiting_for": project.waiting_for or "", "reason": ""}
    errors = {}
    if request.method == "POST":
        values = request.form.to_dict()
        ok, error = run(lambda: project_service.update_status(ctx(), project.id, values.get("status"), values.get("waiting_for"), values.get("reason")),
                        "Status project diperbarui")
        if ok:
            return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors
    has_pending = Approval.query.filter_by(project_id=project.id, status="pending").count() > 0
    return render_template("projects/status.html", p=project, values=values, errors=errors, has_pending=has_pending,
                           options=[(s, STATUSES[s]) for s in MANUAL_STATUSES])


@bp.route("/<code>/next-action", methods=["GET", "POST"])
def next_action(code):
    project = get_visible_project(code)
    if not perm.can_update_next_action(g.user, project):
        abort(403, "Anda tidak dapat mengubah Next Action.")
    values = {"next_action": project.next_action, "next_action_due": project.next_action_due.isoformat(), "waiting_for": project.waiting_for or ""}
    errors = {}
    if request.method == "POST":
        values = request.form.to_dict()
        ok, error = run(lambda: project_service.update_next_action(ctx(), project.id, values.get("next_action"), values.get("next_action_due"), values.get("waiting_for")),
                        "Next Action diperbarui")
        if ok:
            return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors
    return render_template("projects/next_action.html", p=project, values=values, errors=errors)


@bp.route("/<code>/finish", methods=["GET", "POST"])
def finish(code):
    project = get_visible_project(code)
    if not perm.can_finish_project(g.user) or not project.is_active:
        abort(403, "Anda tidak dapat menyelesaikan project ini.")
    note = ""
    if request.method == "POST":
        note = request.form.get("closing_note", "")
        ok, _ = run(lambda: engine.finish_project(ctx(), project.id, note), "Project selesai (Completed)")
        if ok:
            return redirect(url_for("projects.detail", code=code))
    missing = engine.unfinished_mandatory(project.processes)
    return render_template("projects/finish.html", p=project, missing=missing, note=note)


@bp.route("/<code>/move", methods=["GET", "POST"])
def move(code):
    """Admin: pindahkan current process (koreksi workflow)."""
    project = get_visible_project(code)
    if not perm.can_override_workflow(g.user) or not project.is_active:
        abort(403, "Hanya Admin yang dapat memindahkan current process.")
    values, errors = {"process_id": "", "reason": ""}, {}
    if request.method == "POST":
        values = request.form.to_dict()
        ok, error = run(lambda: engine.override_current_process(ctx(), project.id, values.get("process_id"), values.get("reason")),
                        "Current process dipindahkan")
        if ok:
            return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors
    options = [(x.id, f"{x.sequence:02d} · {x.name} ({PROCESS_STATUSES[x.status]})") for x in project.processes
               if x.id != project.current_process_id and x.kind != "finish"]
    return render_template("projects/move.html", p=project, values=values, errors=errors, options=options)


@bp.route("/<code>/request-approval", methods=["GET", "POST"])
def request_approval(code):
    project = get_visible_project(code)
    if not perm.can_request_approval(g.user) or not project.is_active:
        abort(403, "Anda tidak dapat mengajukan approval.")
    current = project.current_process
    values = {"process_id": request.args.get("process") or (current.id if current else ""), "type": "2d", "approver_type": "internal",
              "document_version_id": "", "revision": "", "approver_name": "", "comment": ""}
    errors = {}
    if request.method == "POST":
        values = request.form.to_dict()
        ok, error = run(lambda: approval_service.request_approval(
            ctx(), project.id, values.get("process_id"), values.get("type"), values.get("approver_type"),
            values.get("document_version_id") or None, values.get("revision"), values.get("approver_name"), values.get("comment"),
        ), "Approval diajukan")
        if ok:
            return redirect(url_for("projects.detail", code=code, tab="approvals"))
        errors = error.field_errors
    versions = []
    for d in Document.query.filter_by(project_id=project.id).all():
        for v in d.versions:
            if v.status not in ("rejected", "superseded"):
                versions.append((v.id, f"{d.name} — Rev {v.revision:02d}" + (f" v{v.version}" if v.version > 1 else "")))
    processes = [(p.id, f"{p.sequence:02d} · {p.name}") for p in project.processes if p.kind != "finish" and p.status != "skipped"]
    return render_template("projects/request_approval.html", p=project, values=values, errors=errors, versions=versions,
                           processes=processes, types=list(APPROVAL_TYPES.items()))


@bp.route("/<code>/comment", methods=["POST"])
def comment(code):
    project = get_visible_project(code)
    run(lambda: project_service.add_comment(ctx(), project.id, request.form.get("body", ""), request.form.get("process_id") or None), "Komentar terkirim")
    return redirect(url_for("projects.detail", code=code, tab="activity") + "#tabs")


@bp.route("/<code>/records/<record_id>/purchasing", methods=["POST"])
def purchasing(code, record_id):
    get_visible_project(code)
    run(lambda: project_service.update_purchasing_status(ctx(), record_id, request.form.get("status")), "Purchasing status diperbarui")
    return redirect(url_for("projects.detail", code=code, tab="records") + "#tabs")
