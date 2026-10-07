"""Workflow engine: menggerakkan project dari satu proses ke proses berikutnya.

Alur umum:
1. Saat project dibuat, semua proses disalin dari template (instantiate_processes).
2. Proses aktif ("current process") diselesaikan lewat complete_process().
   - Proses "task" lanjut ke proses berikutnya.
   - Proses "decision" memilih outcome: lanjut, lompat ke cabang, kembali (revision loop),
     ulangi (Problem), atau batal.
3. Setiap perpindahan mengatur ulang Status, Waiting For, dan Next Action project,
   membuat Approval record untuk proses approval, dan mencatat Activity History.
4. finish_project() menolak penutupan jika masih ada mandatory process yang belum selesai.
"""
from __future__ import annotations

from ..constants import APPROVAL_DOC_TYPES, APPROVAL_TYPES, DOC_TYPES, PROJECT_TYPES, format_revision
from ..extensions import db
from ..models import Approval, Document, DocumentVersion, ProcessRecord, Project, ProjectProcess, WorkflowTemplate
from . import permissions as perm
from .context import Ctx, assert_active, customer_name, default_pic_id, get_process, get_project, log_activity, notify, pic_label, project_pics
from .dates import add_days
from .errors import AppError, forbidden, invalid, not_found
from .metrics import has_required_document
from .planning import build_plan

RECORD_PREFIX = {
    "t0_trial": "T0",
    "commissioning_trial": "COM",
    "trial_evaluation": "TR",
    "bulk_material_request": "MR",
    "material_preparation": "MP",
    "validation_mass_production": "VAL",
}

DEFINITION_FIELDS = [
    "key", "name", "short_name", "description", "kind", "pic_role", "actor_roles", "upload_roles", "is_mandatory",
    "loop_only", "requires_document", "required_doc_types", "suggested_doc_types", "requires_approval", "approval_type",
    "approver_type", "outcomes", "next_key", "waiting_type", "waiting_label", "default_next_action", "duration_days",
    "branch_group", "branch", "folder", "record_type", "fields", "calendar_category", "custom",
]


# =========================================================================== membuat proses
def instantiate_processes(project: Project, template: WorkflowTemplate) -> list[ProjectProcess]:
    """Salin semua proses dari template ke project, lengkap dengan planned date."""
    plan = build_plan(template.processes, project.start_date, project.target_date)
    result = []
    for index, definition in enumerate(template.processes, start=1):
        start, finish = plan[definition["key"]]
        values = {k: definition.get(k) for k in DEFINITION_FIELDS}
        proc = ProjectProcess(
            **values,
            sequence=index,
            status="not_started",
            pic_id=default_pic_id(project, definition["pic_role"]),
            planned_start=start,
            planned_finish=finish,
            iteration=0,
            loop_count=0,
            data={},
        )
        project.processes.append(proc)
        result.append(proc)
    db.session.flush()
    return result


# =========================================================================== form proses
def prefill_data(project: Project, proc: ProjectProcess, today) -> dict:
    """Nilai awal form: data draft tersimpan, lalu isian otomatis dari data project."""
    sources = {
        "customer": customer_name(project),
        "project_name": project.name,
        "product_name": project.product_name,
        "customer_request": project.customer_request,
        "sales_pic": project.sales_pic_id,
        "npd_pic": project.npd_pic_id,
        "drafter": project.drafter_id,
        "today": today.isoformat(),
        "supplier": project.supplier,
    }
    data = {}
    for f in proc.fields or []:
        value = sources.get(f.get("prefill"))
        if value is not None:
            data[f["key"]] = value
    data.update(proc.data or {})
    # Pilihan revisi dokumen selalu menunjuk revisi yang masih berlaku (default: terbaru).
    for f in proc.fields or []:
        if f["type"] == "doc_revision":
            options = doc_revision_options(project.id, f)
            if not any(o["version_id"] == data.get(f["key"]) for o in options):
                data[f["key"]] = options[0]["version_id"] if options else None
    return data


def doc_revision_options(project_id: str, f: dict) -> list[dict]:
    """Revisi dokumen yang bisa dipilih (bukan Rejected/Superseded)."""
    docs = Document.query.filter_by(project_id=project_id)
    if f.get("doc_type"):
        docs = docs.filter_by(type=f["doc_type"])
    options = []
    for d in docs.all():
        for v in d.versions:
            if v.status in ("rejected", "superseded"):
                continue
            suffix = f" v{v.version}" if v.version > 1 else ""
            options.append({"version_id": v.id, "label": f"{d.name} — {format_revision(v.revision)}{suffix}", "uploaded_at": v.uploaded_at})
    options.sort(key=lambda o: o["uploaded_at"], reverse=True)
    return options


def missing_required_fields(fields: list[dict], data: dict) -> dict:
    errors = {}
    for f in fields or []:
        if not f.get("required"):
            continue
        value = data.get(f["key"])
        if value is None or (isinstance(value, str) and not value.strip()) or (isinstance(value, list) and not value):
            errors[f["key"]] = f"{f['label']} wajib diisi."
    return errors


# =========================================================================== dokumen & approval
def latest_doc_version(project_id: str, types: list[str], prefer_process_id: str | None = None) -> DocumentVersion | None:
    """Versi dokumen terbaru (bukan Rejected) dari tipe tertentu — dipakai sebagai bukti approval."""
    docs = Document.query.filter(Document.project_id == project_id, Document.type.in_(types)).all()

    def pick(candidates):
        best = None
        for d in candidates:
            v = d.latest
            if not v or v.status == "rejected":
                continue
            if best is None or v.uploaded_at > best.uploaded_at:
                best = v
        return best

    if prefer_process_id:
        found = pick([d for d in docs if d.process_id == prefer_process_id])
        if found:
            return found
    return pick(docs)


def describe_version(version_id: str) -> str:
    """'Artwork Rev 02' (atau nama dokumen jika ada beberapa dokumen dengan tipe sama)."""
    v = db.session.get(DocumentVersion, version_id)
    if not v:
        return "Dokumen"
    doc = v.document
    same_type = Document.query.filter_by(project_id=doc.project_id, type=doc.type).count()
    label = doc.name if same_type > 1 else DOC_TYPES[doc.type]
    return f"{label} {format_revision(v.revision)}" + (f" · v{v.version}" if v.version > 1 else "")


def next_approval_code(year: int) -> str:
    numbers = [int(code.rsplit("-", 1)[-1]) for (code,) in db.session.query(Approval.code).all()]
    return f"APV-{year}-{(max(numbers) if numbers else 0) + 1:04d}"


def create_workflow_approval(ctx: Ctx, project: Project, proc: ProjectProcess, submitted_version_id: str | None = None) -> Approval:
    """Approval record dibuat otomatis saat workflow masuk proses approval (PRD §16)."""
    version_id = submitted_version_id
    if version_id:
        revision = describe_version(version_id)
    else:
        latest = latest_doc_version(project.id, APPROVAL_DOC_TYPES[proc.approval_type], proc.id)
        version_id = latest.id if latest else None
        revision = describe_version(latest.id) if latest else f"Iterasi {proc.iteration}"
    is_customer = proc.approver_type == "customer"
    approval = Approval(
        code=next_approval_code(ctx.today.year),
        project_id=project.id,
        process_id=proc.id,
        type=proc.approval_type,
        revision=revision,
        document_version_id=version_id,
        requested_date=ctx.today,
        requested_by_id=ctx.actor.id,
        approver_type=proc.approver_type or "internal",
        approver_name=f"Customer — {customer_name(project)}" if is_customer else pic_label(proc),
        status="pending",
        iteration=proc.iteration,
        linked_to_workflow=True,
        created_at=ctx.now,
    )
    db.session.add(approval)
    label = APPROVAL_TYPES[proc.approval_type]
    log_activity(ctx, project, "approval_requested", f"{label} diajukan ({revision})", process_id=proc.id)
    notify(
        ctx,
        [project.sales_pic_id, project.npd_pic_id] if is_customer else [proc.pic_id, project.npd_pic_id],
        "approval_requested",
        f"{label} diminta",
        f"{project.code} · {project.name} — {revision}{', menunggu keputusan Customer' if is_customer else ''}.",
        project_id=project.id,
    )
    return approval


# =========================================================================== status otomatis
def apply_auto_status(project: Project, proc: ProjectProcess) -> None:
    """Status & Waiting For mengikuti current process."""
    if proc.kind == "decision" and proc.approver_type == "customer":
        project.status = "waiting_approval"
        project.waiting_for = f"Customer — {customer_name(project)}"
    elif proc.waiting_type == "external":
        label = proc.waiting_label or "External"
        project.status = "waiting_external"
        project.waiting_for = f"{label} — {project.supplier}" if project.supplier else label
    else:
        project.status = "on_progress"
        project.waiting_for = pic_label(proc)
    proc.waiting_for = project.waiting_for


def enter_process(ctx: Ctx, project: Project, proc: ProjectProcess, mode: str = "normal", submitted_version_id: str | None = None, reason: str | None = None) -> None:
    """Jadikan `proc` sebagai current process. mode: normal | revision | problem."""
    proc.status = {"revision": "revision", "problem": "problem"}.get(mode, "current")
    proc.iteration = (proc.iteration or 0) + 1
    proc.entered_at = ctx.now
    proc.actual_start = proc.actual_start or ctx.today
    proc.actual_finish = None
    proc.pic_id = proc.pic_id or default_pic_id(project, proc.pic_role)
    project.current_process_id = proc.id
    apply_auto_status(project, proc)

    if mode == "revision":
        next_action = f"Revisi {proc.short_name} sesuai feedback"
    elif mode == "problem":
        next_action = f"Tindak lanjuti hasil {proc.short_name} & ulangi"
    else:
        next_action = proc.default_next_action
    fallback_due = add_days(ctx.today, max(1, min(proc.duration_days, 3)))
    due = proc.planned_finish if (proc.planned_finish >= ctx.today and mode == "normal") else fallback_due
    project.next_action = next_action
    project.next_action_due = due
    proc.next_action = next_action
    proc.next_action_due = due

    verb = {"revision": f"masuk revisi (loop ke-{proc.loop_count})", "problem": "diulang"}.get(mode, "dimulai")
    log_activity(ctx, project, "process_started" if mode == "normal" else "process_loop", f"{proc.name} {verb}", process_id=proc.id, detail=reason)

    if mode == "revision" and proc.key == "artwork":
        notify(ctx, [proc.pic_id, project.npd_pic_id], "artwork_revision", "Revisi artwork diperlukan",
               f"{project.code} · {project.name}: {reason or 'Customer meminta revisi artwork.'}", project_id=project.id)

    if proc.kind == "decision" and proc.requires_approval and proc.approval_type:
        create_workflow_approval(ctx, project, proc, submitted_version_id)
    db.session.flush()


# =========================================================================== menyelesaikan proses
def _next_in_sequence(procs: list[ProjectProcess], current: ProjectProcess) -> ProjectProcess | None:
    if current.next_key:
        return next((p for p in procs if p.key == current.next_key), None)
    return next((p for p in procs if p.sequence > current.sequence and not p.loop_only and p.status != "skipped"), None)


def _create_record(ctx: Ctx, project: Project, proc: ProjectProcess, data: dict, outcome: dict | None, comment: str | None) -> ProcessRecord | None:
    """Simpan snapshot data setiap iterasi (trial record, material request, validation record)."""
    if not proc.fields and not outcome:
        return None
    prefix = RECORD_PREFIX.get(proc.key, "REC")
    count = ProcessRecord.query.filter_by(project_id=project.id, process_id=proc.id).count() + 1
    auto = f"{prefix}-{count:02d}"
    provided = (data.get("trial_number") or "").strip() if isinstance(data.get("trial_number"), str) else ""
    if proc.record_type == "trial" and not provided:
        data["trial_number"] = auto
    mr = data.get("mr_number") if isinstance(data.get("mr_number"), str) else ""
    number = provided or (mr.strip() if mr and mr.strip() else auto)
    record = ProcessRecord(
        number=number,
        project_id=project.id,
        process_id=proc.id,
        record_type=proc.record_type,
        iteration=proc.iteration,
        data=dict(data),
        outcome=outcome["key"] if outcome else None,
        outcome_label=outcome["label"] if outcome else None,
        comment=(comment or "").strip() or None,
        purchasing_status="requested" if proc.record_type == "material_request" else None,
        created_by_id=ctx.actor.id,
        created_at=ctx.now,
    )
    db.session.add(record)
    # Material preparation selesai -> material request dianggap diterima.
    if proc.record_type == "material_preparation":
        for r in ProcessRecord.query.filter_by(project_id=project.id, record_type="material_request").all():
            if r.purchasing_status != "cancelled":
                r.purchasing_status = "received"
    db.session.flush()
    return record


def _decide_linked_approval(ctx: Ctx, project: Project, proc: ProjectProcess, outcome: dict, comment: str | None, attachment_version_id: str | None) -> None:
    approval = Approval.query.filter_by(process_id=proc.id, status="pending", linked_to_workflow=True).first()
    if not approval:
        return
    if not approval.document_version_id:
        # Tautkan bukti yang diupload selama approval pending (mis. T0 / validation report).
        latest = latest_doc_version(project.id, APPROVAL_DOC_TYPES[approval.type], proc.id)
        if latest:
            approval.document_version_id = latest.id
            approval.revision = describe_version(latest.id)
    approval.status = outcome["approval_status"]
    approval.decision_date = ctx.today
    approval.decided_by_id = ctx.actor.id
    approval.comment = (comment or "").strip() or None
    approval.attachment_version_id = attachment_version_id
    if approval.document_version_id:
        version = db.session.get(DocumentVersion, approval.document_version_id)
        if version:
            version.status = "approved" if outcome["approval_status"] == "approved" else "rejected"
    approved = outcome["approval_status"] == "approved"
    label = APPROVAL_TYPES[approval.type]
    log_activity(ctx, project, "approval_decided", f"{label}: {outcome['label']}", process_id=proc.id,
                 detail=" — ".join(x for x in [approval.revision, (comment or "").strip()] if x))
    notify(ctx, project_pics(project), "approval_approved" if approved else "approval_rejected",
           f"{label} {'disetujui' if approved else 'ditolak'}",
           f"{project.code} · {project.name} — {approval.revision}{': ' + comment if comment else ''}", project_id=project.id)


def complete_process(ctx: Ctx, process_id: str, outcome_key: str | None = None, target_key: str | None = None,
                     data: dict | None = None, comment: str | None = None, attachment_version_id: str | None = None) -> None:
    """Selesaikan current process dan pindahkan workflow sesuai hasilnya."""
    proc = get_process(process_id)
    project = proc.project
    if not perm.can_act_on_process(ctx.actor, project, proc):
        raise forbidden(f"Role Anda tidak memiliki akses untuk menyelesaikan proses {proc.name}.")
    assert_active(project)
    if not proc.is_running or project.current_process_id != proc.id:
        raise AppError("INVALID_STATE", f"{proc.name} bukan proses aktif project ini.")
    if proc.kind == "finish":
        finish_project(ctx, project.id, closing_note=comment or (data or {}).get("closing_note"))
        return

    merged = {**prefill_data(project, proc, ctx.today), **(data or {})}
    field_errors = missing_required_fields(proc.fields, merged)
    for f in proc.fields or []:
        if f["type"] == "doc_revision" and merged.get(f["key"]):
            if not any(o["version_id"] == merged[f["key"]] for o in doc_revision_options(project.id, f)):
                field_errors[f["key"]] = f"{f['label']} yang dipilih sudah tidak berlaku (Rejected/Superseded)."
    if field_errors:
        raise invalid("Lengkapi data wajib sebelum menyelesaikan proses.", field_errors)

    documents = Document.query.filter_by(project_id=project.id).all()
    if not has_required_document(proc, documents):
        types = " / ".join(DOC_TYPES[t] for t in proc.required_doc_types) or "dokumen"
        raise invalid(f"Upload dokumen wajib ({types}) pada proses {proc.name} terlebih dahulu.")

    outcome = None
    if proc.kind == "decision":
        outcome = proc.outcome(outcome_key)
        if not outcome:
            raise invalid("Pilih hasil keputusan terlebih dahulu.", {"outcome": "Pilih hasil keputusan."})
        if outcome.get("requires_comment") and not (comment or "").strip():
            raise invalid(f'Komentar wajib diisi untuk keputusan "{outcome["label"]}".', {"comment": "Komentar wajib diisi."})

    procs = list(project.processes)
    record = _create_record(ctx, project, proc, merged, outcome, comment)
    proc.data = merged
    proc.last_outcome = outcome["key"] if outcome else None

    if outcome and outcome.get("approval_status"):
        _decide_linked_approval(ctx, project, proc, outcome, comment, attachment_version_id)
    if outcome and "sets_new_masterbatch" in outcome:
        project.new_masterbatch = outcome["sets_new_masterbatch"]

    target = outcome["target"] if outcome else {"kind": "next"}
    outcome_text = f" — {outcome['label']}" if outcome else ""
    comment_text = (comment or "").strip() or None

    if target["kind"] == "cancel":
        proc.status = "completed"
        proc.actual_finish = ctx.today
        project.status = "cancelled"
        project.status_reason = comment_text
        project.waiting_for = None
        log_activity(ctx, project, "process_completed", f"{proc.name} selesai{outcome_text}", process_id=proc.id, detail=comment_text)
        log_activity(ctx, project, "status_changed", "Status project → Cancelled", detail=comment_text)
        db.session.flush()
        return

    if target["kind"] == "repeat":
        proc.loop_count += 1
        log_activity(ctx, project, "process_problem", f"{proc.name}{outcome_text}", process_id=proc.id, detail=comment_text)
        enter_process(ctx, project, proc, mode="problem", reason=comment_text)
        return

    if target["kind"] == "next":
        nxt = _next_in_sequence(procs, proc)
    else:
        allowed = [target["key"], *target.get("alternatives", [])]
        key = target_key if target_key in allowed else target["key"]
        nxt = next((p for p in procs if p.key == key), None)
    if not nxt:
        raise AppError("INVALID_STATE", "Proses berikutnya tidak ditemukan pada workflow ini.")

    submitted_version_id = next(
        (merged.get(f["key"]) for f in proc.fields or [] if f["type"] == "doc_revision" and merged.get(f["key"])), None
    )

    if nxt.sequence > proc.sequence:
        # Maju: proses selesai, cabang/proses yang dilewati ditandai "Tidak dijalankan".
        proc.status = "completed"
        proc.actual_finish = ctx.today
        log_activity(ctx, project, "process_completed", f"{proc.name} selesai{outcome_text}", process_id=proc.id,
                     detail=" — ".join(x for x in [record.number if record else None, comment_text] if x) or None)
        bypassed = [p for p in procs if proc.sequence < p.sequence < nxt.sequence and p.status != "completed"]
        for p in bypassed:
            p.status = "skipped"
        names = [p.name for p in bypassed if not p.loop_only]
        if names:
            log_activity(ctx, project, "process_skipped", f"Tidak dijalankan: {', '.join(names)}", detail=outcome["label"] if outcome else None)
        if nxt.branch_group:
            for p in procs:
                if p.branch_group == nxt.branch_group and p.branch != nxt.branch and p.status == "not_started":
                    p.status = "skipped"
        loop_entry = bool(nxt.loop_only)
        if loop_entry:
            nxt.loop_count += 1
        enter_process(ctx, project, nxt, mode="revision" if loop_entry else "normal", submitted_version_id=submitted_version_id, reason=comment_text)
        return

    # Mundur: revision loop. Proses di antara tujuan & proses ini harus dikerjakan ulang.
    if proc.kind == "decision":
        proc.status = "not_started"
        proc.actual_finish = None
        log_activity(ctx, project, "process_completed", f"{proc.name}{outcome_text}", process_id=proc.id, detail=comment_text)
    else:
        proc.status = "completed"
        proc.actual_finish = ctx.today
        log_activity(ctx, project, "process_completed", f"{proc.name} selesai{outcome_text}", process_id=proc.id, detail=comment_text)
    for p in procs:
        if nxt.sequence < p.sequence < proc.sequence and p.status == "completed" and not p.loop_only:
            p.status = "not_started"
            p.actual_finish = None
    nxt.loop_count += 1
    enter_process(ctx, project, nxt, mode="revision", reason=comment_text)


# =========================================================================== finish / problem / override
def unfinished_mandatory(procs: list[ProjectProcess]) -> list[ProjectProcess]:
    """Mandatory process yang belum selesai (proses cabang yang tidak dijalankan dianggap terpenuhi)."""
    return [p for p in procs if p.is_mandatory and not p.loop_only and p.kind != "finish" and p.status not in ("completed", "skipped")]


def finish_project(ctx: Ctx, project_id: str, closing_note: str | None = None) -> None:
    project = get_project(project_id)
    if not perm.can_finish_project(ctx.actor):
        raise forbidden("Hanya Admin atau NPD Staff yang dapat menyelesaikan project.")
    assert_active(project, "menyelesaikan project")
    missing = unfinished_mandatory(project.processes)
    if missing:
        raise AppError("INVALID_STATE", "Project belum dapat diselesaikan. Proses mandatory berikut belum selesai:",
                       details=[f"{p.sequence:02d} · {p.name}" for p in missing])
    finish = next((p for p in project.processes if p.kind == "finish"), None)
    if finish:
        finish.status = "completed"
        finish.actual_start = finish.actual_start or ctx.today
        finish.actual_finish = ctx.today
        if closing_note:
            finish.data = {**(finish.data or {}), "closing_note": closing_note}
        project.current_process_id = finish.id
    project.status = "completed"
    project.actual_finish = ctx.today
    project.waiting_for = None
    project.next_action = "Project selesai"
    project.next_action_due = ctx.today
    log_activity(ctx, project, "project_finished", f"Project {PROJECT_TYPES[project.type]} selesai (Completed)",
                 process_id=finish.id if finish else None, detail=closing_note)
    db.session.flush()


def set_problem(ctx: Ctx, process_id: str, flag: bool, note: str) -> None:
    """Tandai proses aktif sebagai Problem (flag=True) atau tandai problem selesai (flag=False)."""
    proc = get_process(process_id)
    project = proc.project
    if not perm.can_act_on_process(ctx.actor, project, proc):
        raise forbidden("Anda tidak memiliki akses pada proses ini.")
    assert_active(project, "mengubah proses")
    if not proc.is_running:
        raise AppError("INVALID_STATE", "Hanya proses aktif yang dapat ditandai.")
    note = (note or "").strip()
    if not note:
        raise invalid("Catatan wajib diisi.", {"note": "Catatan wajib diisi."})
    if flag:
        proc.status = "problem"
        proc.problem_note = note
        log_activity(ctx, project, "process_problem", f"{proc.name} ditandai Problem", process_id=proc.id, detail=note)
    else:
        proc.status = "revision" if proc.loop_count > 0 else "current"
        proc.problem_note = None
        log_activity(ctx, project, "process_updated", f"Problem pada {proc.name} diselesaikan", process_id=proc.id, detail=note)


def override_current_process(ctx: Ctx, project_id: str, process_id: str, reason: str) -> None:
    """Koreksi Admin: pindahkan current process. Mandatory process TIDAK otomatis ditandai selesai."""
    if not perm.can_override_workflow(ctx.actor):
        raise forbidden("Hanya Admin yang dapat memindahkan current process.")
    project = get_project(project_id)
    assert_active(project, "memindahkan proses")
    reason = (reason or "").strip()
    if not reason:
        raise invalid("Alasan wajib diisi.", {"reason": "Alasan wajib diisi."})
    procs = list(project.processes)
    target = next((p for p in procs if p.id == process_id), None)
    if not target:
        raise not_found("Proses tidak ditemukan.")
    current = project.current_process
    if current and current.id == target.id:
        raise AppError("INVALID_STATE", "Proses tersebut sudah menjadi current process.")

    for a in Approval.query.filter_by(project_id=project.id, status="pending", linked_to_workflow=True).all():
        a.status = "revision_required"
        a.decision_date = ctx.today
        a.decided_by_id = ctx.actor.id
        a.comment = f"Dibatalkan otomatis: current process dipindahkan oleh Admin ({reason})."
    if current:
        current.status = "not_started"
        current.actual_finish = None
        if target.sequence < current.sequence:
            for p in procs:
                if target.sequence < p.sequence < current.sequence and p.status == "completed":
                    p.status = "not_started"
                    p.actual_finish = None
        else:
            for p in procs:
                if current.sequence < p.sequence < target.sequence and not p.is_mandatory and p.status == "not_started":
                    p.status = "skipped"
    was_done = target.status == "completed"
    if was_done:
        target.loop_count += 1
    log_activity(ctx, project, "process_updated", f"Admin memindahkan current process ke {target.name}", process_id=target.id, detail=reason)
    enter_process(ctx, project, target, mode="revision" if was_done else "normal", reason=reason)
