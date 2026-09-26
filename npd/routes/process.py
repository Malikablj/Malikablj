"""Halaman satu proses: detail & planning, form penyelesaian (Complete), tandai Problem."""
from __future__ import annotations

from flask import Blueprint, abort, g, redirect, render_template, request, url_for

from ..constants import DOC_TYPES
from ..extensions import db
from ..models import Activity, Approval, Document, ProcessRecord, User
from ..services import permissions as perm
from ..services import projects as project_service
from ..services import workflow_engine as engine
from ..services.metrics import has_required_document, process_duration
from .helpers import ctx, get_visible_project, run

bp = Blueprint("process", __name__, url_prefix="/projects/<code>/process")


def get_process_of(project, process_id):
    proc = next((p for p in project.processes if p.id == process_id), None)
    if not proc:
        abort(404)
    return proc


def read_form_data(fields: list[dict], form) -> dict:
    """Ubah isian form HTML menjadi data proses sesuai tipe field."""
    data = {}
    for f in fields or []:
        key = f["key"]
        if f["type"] == "multiselect":
            data[key] = form.getlist(key)
            continue
        if key not in form:  # field tidak dikirim -> pakai nilai tersimpan / isian otomatis
            continue
        raw = (form.get(key) or "").strip()
        if f["type"] == "number" and raw:
            try:
                number = float(raw.replace(",", "."))
                data[key] = int(number) if number.is_integer() else number
            except ValueError:
                data[key] = raw
        else:
            data[key] = raw or None
    return data


def field_options(project, fields: list[dict]) -> dict:
    """Pilihan dropdown untuk field bertipe user / doc_revision."""
    options = {}
    for f in fields or []:
        if f["type"] == "user":
            users = User.query.filter_by(active=True).order_by(User.name).all()
            options[f["key"]] = [(u.id, u.name) for u in users if not f.get("role") or u.role == f["role"] or u.role == "admin"]
        elif f["type"] == "doc_revision":
            options[f["key"]] = [(o["version_id"], o["label"]) for o in engine.doc_revision_options(project.id, f)]
    return options


def display_value(f: dict, value) -> str:
    """Nilai field untuk ditampilkan (id user -> nama, id dokumen -> label revisi)."""
    if value in (None, "", []):
        return "—"
    if f["type"] == "user":
        user = db.session.get(User, value)
        return user.name if user else str(value)
    if f["type"] == "doc_revision":
        return engine.describe_version(value)
    if f["type"] == "multiselect" and isinstance(value, list):
        return ", ".join(value)
    if f["type"] == "date":
        from ..services.dates import fmt_date

        return fmt_date(value)
    return str(value)


@bp.route("/<process_id>", methods=["GET", "POST"])
def detail(code, process_id):
    project = get_visible_project(code)
    proc = get_process_of(project, process_id)
    can_plan = perm.can_plan_process(g.user) and project.status != "cancelled"
    can_remark = can_plan or (perm.can_act_on_process(g.user, project, proc) and project.status != "cancelled")
    errors = {}
    if request.method == "POST":
        if can_plan:
            action = lambda: project_service.update_process(
                ctx(), proc.id, pic_id=request.form.get("pic_id", ""), planned_start=request.form.get("planned_start", ""),
                planned_finish=request.form.get("planned_finish", ""), remarks=request.form.get("remarks", ""))
        else:
            action = lambda: project_service.update_process(ctx(), proc.id, remarks=request.form.get("remarks", ""))
        ok, error = run(action, "Proses diperbarui")
        if ok:
            return redirect(url_for("process.detail", code=code, process_id=proc.id))
        errors = error.field_errors

    documents = Document.query.filter_by(process_id=proc.id).order_by(Document.created_at).all()
    all_docs = Document.query.filter_by(project_id=project.id).all()
    records = ProcessRecord.query.filter_by(process_id=proc.id).order_by(ProcessRecord.created_at.desc()).all()
    approvals = Approval.query.filter_by(process_id=proc.id).order_by(Approval.created_at.desc()).all()
    history = Activity.query.filter_by(project_id=project.id, process_id=proc.id).order_by(Activity.at.desc()).all()
    users = [(u.id, f"{u.name} · {u.title}") for u in User.query.filter_by(active=True).order_by(User.name).all()]
    return render_template(
        "process/detail.html", p=project, proc=proc, documents=documents, records=records, approvals=approvals, history=history,
        duration=process_duration(proc, ctx().today), has_required_doc=has_required_document(proc, all_docs),
        can_plan=can_plan, can_remark=can_remark, users=users, errors=errors, display_value=display_value,
        can_act=perm.can_act_on_process(g.user, project, proc), can_upload=perm.can_upload_to_process(g.user, project, proc) and project.status != "cancelled",
    )


@bp.route("/<process_id>/complete", methods=["GET", "POST"])
def complete(code, process_id):
    """Form penyelesaian proses: isi data, pilih hasil keputusan, lalu workflow bergerak otomatis."""
    project = get_visible_project(code)
    proc = get_process_of(project, process_id)
    if proc.kind == "finish":
        return redirect(url_for("projects.finish", code=code))
    if not perm.can_act_on_process(g.user, project, proc):
        abort(403, f"Role Anda tidak memiliki akses untuk menyelesaikan proses {proc.name}.")
    if not proc.is_running or project.current_process_id != proc.id or not project.is_active:
        return redirect(url_for("projects.detail", code=code))

    today = ctx().today
    data = engine.prefill_data(project, proc, today)
    values = {"outcome": request.args.get("outcome", ""), "target": "", "comment": "", "attachment_version_id": ""}
    errors = {}
    if request.method == "POST":
        data = {**data, **read_form_data(proc.fields, request.form)}
        values = {k: request.form.get(k, "") for k in values}
        if request.form.get("action") == "draft":
            ok, error = run(lambda: project_service.update_process(ctx(), proc.id, data=read_form_data(proc.fields, request.form)), "Draft disimpan")
            if ok:
                return redirect(url_for("process.complete", code=code, process_id=proc.id))
        else:
            ok, error = run(lambda: engine.complete_process(
                ctx(), proc.id, outcome_key=values["outcome"] or None, target_key=values["target"] or None,
                data=read_form_data(proc.fields, request.form), comment=values["comment"],
                attachment_version_id=values["attachment_version_id"] or None,
            ), f"{proc.name} diperbarui — workflow berpindah otomatis")
            if ok:
                return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors if error else {}

    # Pilihan "kembali ke proses" untuk outcome yang punya alternatif tujuan.
    names = {p.key: p.name for p in project.processes}
    outcomes = []
    for o in proc.outcomes or []:
        target = o["target"]
        keys = [target["key"], *target.get("alternatives", [])] if target["kind"] == "process" else []
        outcomes.append({**o, "target_options": [(k, names.get(k, k)) for k in keys], "target_text": describe_target(proc, o, names)})

    documents = Document.query.filter_by(project_id=project.id).all()
    attachments = []
    for d in documents:
        for v in d.versions:
            attachments.append((v.id, f"{d.name} — Rev {v.revision:02d}" + (f" v{v.version}" if v.version > 1 else "")))
    pending = Approval.query.filter_by(process_id=proc.id, status="pending", linked_to_workflow=True).first()
    return render_template(
        "process/complete.html", p=project, proc=proc, data=data, values=values, errors=errors, outcomes=outcomes,
        options=field_options(project, proc.fields), has_required_doc=has_required_document(proc, documents),
        required_types=" / ".join(DOC_TYPES[t] for t in proc.required_doc_types or []), attachments=attachments, pending=pending,
        can_upload=perm.can_upload_to_process(g.user, project, proc),
    )


def describe_target(proc, outcome: dict, names: dict) -> str:
    """Penjelasan singkat ke mana workflow bergerak setelah outcome dipilih."""
    target = outcome["target"]
    if target["kind"] == "cancel":
        return "Project menjadi Cancelled"
    if target["kind"] == "repeat":
        return f"{proc.short_name} diulang (status Problem)"
    if target["kind"] == "next":
        return "Lanjut ke proses berikutnya"
    target_proc = next((p for p in proc.project.processes if p.key == target["key"]), None)
    if target_proc and target_proc.sequence < proc.sequence:
        return f"Kembali ke {names.get(target['key'])} (revision loop)"
    return f"Lanjut ke {names.get(target['key'])}"


@bp.route("/<process_id>/problem", methods=["GET", "POST"])
def problem(code, process_id):
    """Tandai proses aktif sebagai Problem, atau tandai problem selesai."""
    project = get_visible_project(code)
    proc = get_process_of(project, process_id)
    if not perm.can_act_on_process(g.user, project, proc):
        abort(403, "Anda tidak memiliki akses pada proses ini.")
    flag = proc.status != "problem"
    note, errors = "", {}
    if request.method == "POST":
        note = request.form.get("note", "")
        ok, error = run(lambda: engine.set_problem(ctx(), proc.id, flag, note), "Proses ditandai Problem" if flag else "Problem ditandai selesai")
        if ok:
            return redirect(url_for("projects.detail", code=code))
        errors = error.field_errors
    return render_template("process/problem.html", p=project, proc=proc, flag=flag, note=note, errors=errors)

