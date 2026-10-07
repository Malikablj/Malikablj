"""Aksi pada project: buat, ubah, status, next action, update proses, komentar."""
from __future__ import annotations

from ..constants import MANUAL_STATUSES, PRIORITIES, PROJECT_TYPES, ROLES, STATUSES
from ..extensions import db
from ..models import Approval, Comment, Customer, ProcessRecord, Project, User, WorkflowTemplate
from . import permissions as perm
from .context import Ctx, assert_active, customer_name, get_process, get_project, log_activity, notify, user_name
from .dates import fmt_date, parse_date
from .errors import AppError, forbidden, invalid, not_found
from .workflow_engine import apply_auto_status, enter_process, instantiate_processes

PIC_ROLES = {"npd_pic_id": ("npd_staff", "admin"), "sales_pic_id": ("admin_sales",), "drafter_id": ("drafter",)}

FIELD_LABELS = {
    "customer_id": "Customer",
    "name": "Project Name",
    "product_name": "Product Name",
    "product_description": "Product Description",
    "customer_request": "Customer Request",
    "npd_pic_id": "NPD PIC",
    "sales_pic_id": "Sales PIC",
    "drafter_id": "Drafter",
    "supplier": "Mold Maker / Supplier",
    "priority": "Priority",
    "start_date": "Start Date",
    "target_date": "Target Finish",
    "remarks": "Remarks",
}
EDITABLE = list(FIELD_LABELS)


def _norm(s: str | None) -> str:
    return " ".join((s or "").lower().split())


def validate_project_input(values: dict) -> dict:
    """Validasi data project; kembalikan pesan error per kolom."""
    e = {}
    if values.get("type") not in PROJECT_TYPES:
        e["type"] = "Pilih Project Type."
    customer = db.session.get(Customer, values.get("customer_id") or "")
    if not customer:
        e["customer_id"] = "Pilih customer."
    elif not customer.active:
        e["customer_id"] = "Customer tidak aktif."
    name = (values.get("name") or "").strip()
    if not name:
        e["name"] = "Project Name wajib diisi."
    elif len(name) > 120:
        e["name"] = "Maksimal 120 karakter."
    if not (values.get("product_name") or "").strip():
        e["product_name"] = "Product Name wajib diisi."
    if not (values.get("customer_request") or "").strip():
        e["customer_request"] = "Customer Request wajib diisi."
    for key, roles in PIC_ROLES.items():
        user = db.session.get(User, values.get(key) or "")
        if not user:
            e[key] = "Pilih PIC."
        elif not user.active:
            e[key] = "User tidak aktif."
        elif user.role not in roles:
            e[key] = "Role user tidak sesuai."
    if values.get("priority") not in PRIORITIES:
        e["priority"] = "Pilih priority."
    start = parse_date(values.get("start_date"))
    target = parse_date(values.get("target_date"))
    if not start:
        e["start_date"] = "Start Date wajib diisi."
    if not target:
        e["target_date"] = "Target Finish wajib diisi."
    elif start and target <= start:
        e["target_date"] = "Target Finish harus setelah Start Date."
    return e


def _find_duplicate(customer_id: str, name: str, exclude_id: str | None = None) -> Project | None:
    n = _norm(name)
    for p in Project.query.filter(Project.customer_id == customer_id, Project.status != "cancelled").all():
        if p.id != exclude_id and _norm(p.name) == n:
            return p
    return None


def next_project_code(year: int) -> str:
    """Project ID otomatis: NPD-YYYY-XXX (unik & tidak berubah)."""
    prefix = f"NPD-{year}-"
    numbers = [int(code[len(prefix):]) for (code,) in db.session.query(Project.code).filter(Project.code.like(prefix + "%")).all()]
    return f"{prefix}{(max(numbers) if numbers else 0) + 1:03d}"


def create_project(ctx: Ctx, values: dict) -> Project:
    if not perm.can_create_project(ctx.actor):
        raise forbidden("Role Anda tidak dapat membuat project.")
    errors = validate_project_input(values)
    if errors:
        raise invalid("Periksa kembali data project.", errors)
    dup = _find_duplicate(values["customer_id"], values["name"])
    if dup:
        raise AppError("CONFLICT", f'Project "{dup.name}" untuk customer ini sudah ada ({dup.code}).', {"name": f"Sudah digunakan oleh {dup.code}."})
    template = WorkflowTemplate.query.filter_by(project_type=values["type"]).first()
    if not template:
        raise AppError("INVALID_STATE", "Workflow template untuk project type ini belum tersedia.")

    start = parse_date(values["start_date"])
    project = Project(
        code=next_project_code(ctx.today.year),
        type=values["type"],
        customer_id=values["customer_id"],
        name=values["name"].strip(),
        product_name=values["product_name"].strip(),
        product_description=(values.get("product_description") or "").strip() or None,
        customer_request=values["customer_request"].strip(),
        npd_pic_id=values["npd_pic_id"],
        sales_pic_id=values["sales_pic_id"],
        drafter_id=values["drafter_id"],
        supplier=(values.get("supplier") or "").strip() or None,
        priority=values["priority"],
        start_date=start,
        target_date=parse_date(values["target_date"]),
        status="not_started",
        next_action="",
        next_action_due=start,
        remarks=(values.get("remarks") or "").strip() or None,
        workflow_id=template.id,
        workflow_version=template.version,
        created_at=ctx.now,
        created_by=ctx.actor.id,
        updated_at=ctx.now,
    )
    db.session.add(project)
    db.session.flush()
    procs = instantiate_processes(project, template)
    log_activity(ctx, project, "project_created", f"Project dibuat — {template.name} v{template.version}",
                 detail=f"{customer_name(project)} · {PROJECT_TYPES[project.type]} · Priority {PRIORITIES[project.priority]}")
    enter_process(ctx, project, procs[0])
    if project.start_date > ctx.today:
        project.status = "not_started"
    if (values.get("next_action") or "").strip():
        project.next_action = procs[0].next_action = values["next_action"].strip()
    due = parse_date(values.get("next_action_due"))
    if due:
        project.next_action_due = procs[0].next_action_due = due
    notify(ctx, [project.npd_pic_id, project.sales_pic_id, project.drafter_id], "project_assigned", "Project baru di-assign ke Anda",
           f"{project.code} · {project.name} ({customer_name(project)}, {PROJECT_TYPES[project.type]})", project_id=project.id)
    return project


def _describe(key: str, value) -> str:
    if value in (None, ""):
        return "—"
    if key == "customer_id":
        c = db.session.get(Customer, value)
        return c.name if c else "—"
    if key in PIC_ROLES:
        return user_name(value)
    if key == "priority":
        return PRIORITIES[value]
    if key in ("start_date", "target_date"):
        return fmt_date(value)
    return str(value)


def update_project(ctx: Ctx, project_id: str, patch: dict) -> Project:
    project = get_project(project_id)
    if not perm.can_edit_project(ctx.actor, project):
        raise forbidden("Anda tidak memiliki akses untuk mengubah project ini.")
    if project.status == "cancelled":
        raise AppError("INVALID_STATE", "Project Cancelled tidak dapat diubah.")
    merged = {k: getattr(project, k) for k in EDITABLE}
    merged.update({k: v for k, v in patch.items() if k in EDITABLE})
    merged["type"] = project.type
    errors = validate_project_input(merged)
    if errors:
        raise invalid("Periksa kembali data project.", errors)
    dup = _find_duplicate(merged["customer_id"], merged["name"], project.id)
    if dup:
        raise AppError("CONFLICT", f"Nama project sudah digunakan oleh {dup.code} untuk customer ini.", {"name": f"Sudah digunakan oleh {dup.code}."})

    changes = []
    for key in EDITABLE:
        if key not in patch:
            continue
        value = patch[key]
        if isinstance(value, str):
            value = value.strip() or None
        if key in ("start_date", "target_date"):
            value = parse_date(value)
        if getattr(project, key) == value:
            continue
        changes.append(f"{FIELD_LABELS[key]}: {_describe(key, getattr(project, key))} → {_describe(key, value)}")
        setattr(project, key, value)
        if key in PIC_ROLES:
            notify(ctx, [value], "project_assigned", "Project di-assign ke Anda", f"{project.code} · {project.name} — Anda menjadi {FIELD_LABELS[key]}.", project_id=project.id)
            role = {"npd_pic_id": "npd_staff", "sales_pic_id": "admin_sales", "drafter_id": "drafter"}[key]
            for proc in project.processes:
                if proc.pic_role == role and proc.status not in ("completed", "skipped"):
                    proc.pic_id = value
            cur = project.current_process
            if cur and project.is_active and project.status != "hold":
                apply_auto_status(project, cur)
    if changes:
        log_activity(ctx, project, "project_updated", "Informasi project diperbarui", detail="\n".join(changes))
    return project


def update_status(ctx: Ctx, project_id: str, status: str, waiting_for: str | None = None, reason: str | None = None) -> Project:
    """Update status manual dengan aturan bisnis PRD §16."""
    project = get_project(project_id)
    if not perm.can_change_status(ctx.actor):
        raise forbidden("Hanya Admin atau NPD Staff yang dapat mengubah status project.")
    if not project.is_active:
        raise AppError("INVALID_STATE", f"Project sudah {STATUSES[project.status]}.")
    if status not in MANUAL_STATUSES:
        raise invalid("Status tidak dapat dipilih manual. Completed hanya melalui Finish.")
    if status == project.status and status != "waiting_external":
        raise invalid("Status yang dipilih sama dengan status saat ini.")
    reason = (reason or "").strip()
    waiting_for = (waiting_for or "").strip()
    errors = {}
    if status in ("hold", "cancelled") and not reason:
        errors["reason"] = "Alasan wajib diisi."
    if status == "waiting_external" and not waiting_for:
        errors["waiting_for"] = "Waiting For wajib diisi untuk status Waiting External."
    if errors:
        raise invalid("Lengkapi data status.", errors)
    if status == "waiting_approval":
        pending = Approval.query.filter_by(project_id=project.id, status="pending").first()
        if not pending:
            raise invalid("Status Waiting Approval memerlukan approval record berstatus Pending. Ajukan approval terlebih dahulu.")
        project.waiting_for = waiting_for or pending.approver_name

    previous = project.status
    cur = project.current_process
    if status == "on_progress" and previous == "hold" and cur:
        apply_auto_status(project, cur)  # lanjut dari Hold: status kembali mengikuti proses
    else:
        project.status = status
        if status == "waiting_external" or (status == "on_progress" and waiting_for):
            project.waiting_for = waiting_for
        if status == "cancelled":
            project.waiting_for = None
    project.status_reason = reason or None
    if cur and project.waiting_for:
        cur.waiting_for = project.waiting_for
    detail = "\n".join(x for x in [reason, f"Waiting For: {project.waiting_for}" if project.waiting_for else ""] if x) or None
    log_activity(ctx, project, "status_changed", f"Status: {STATUSES[previous]} → {STATUSES[project.status]}", detail=detail)
    return project


def update_next_action(ctx: Ctx, project_id: str, next_action: str, next_action_due, waiting_for: str | None = None) -> Project:
    project = get_project(project_id)
    if not perm.can_update_next_action(ctx.actor, project):
        raise forbidden("Anda tidak memiliki akses untuk mengubah Next Action.")
    assert_active(project, "mengubah Next Action")
    due = parse_date(next_action_due)
    waiting_for = (waiting_for or "").strip()
    errors = {}
    if not (next_action or "").strip():
        errors["next_action"] = "Next Action wajib diisi."
    if not due:
        errors["next_action_due"] = "Tanggal due wajib diisi."
    if project.status in ("waiting_external", "waiting_approval") and not waiting_for:
        errors["waiting_for"] = "Waiting For wajib diisi selama status Waiting."
    if errors:
        raise invalid("Lengkapi Next Action.", errors)
    before = f"{project.next_action} ({fmt_date(project.next_action_due)})"
    project.next_action = next_action.strip()
    project.next_action_due = due
    project.waiting_for = waiting_for or None
    cur = project.current_process
    if cur:
        cur.next_action = project.next_action
        cur.next_action_due = due
        cur.waiting_for = project.waiting_for
    detail = f"{before} → {project.next_action} ({fmt_date(due)})" + (f"\nWaiting For: {project.waiting_for}" if project.waiting_for else "")
    log_activity(ctx, project, "next_action_updated", "Next Action diperbarui", process_id=cur.id if cur else None, detail=detail)
    return project


def update_process(ctx: Ctx, process_id: str, pic_id=None, planned_start=None, planned_finish=None, remarks=None, data=None) -> None:
    """Ubah PIC / planned date / remarks / draft data sebuah proses."""
    proc = get_process(process_id)
    project = proc.project
    planning = pic_id is not None or planned_start is not None or planned_finish is not None
    if planning and not perm.can_plan_process(ctx.actor):
        raise forbidden("Hanya Admin atau NPD Staff yang dapat mengubah PIC dan planning.")
    if not planning and not perm.can_act_on_process(ctx.actor, project, proc) and not perm.can_plan_process(ctx.actor):
        raise forbidden("Anda tidak memiliki akses pada proses ini.")
    if project.status == "cancelled":
        raise AppError("INVALID_STATE", "Project Cancelled tidak dapat diubah.")

    start = parse_date(planned_start) if planned_start is not None else proc.planned_start
    finish = parse_date(planned_finish) if planned_finish is not None else proc.planned_finish
    errors = {}
    if planned_start is not None and not start:
        errors["planned_start"] = "Tanggal tidak valid."
    if planned_finish is not None and not finish:
        errors["planned_finish"] = "Tanggal tidak valid."
    if start and finish and finish < start:
        errors["planned_finish"] = "Planned Finish harus sama/setelah Planned Start."
    if pic_id is not None:
        user = db.session.get(User, pic_id or "")
        if not user or not user.active:
            errors["pic_id"] = "Pilih PIC yang aktif."
    if errors:
        raise invalid("Periksa kembali data proses.", errors)

    changes = []
    if pic_id is not None and pic_id != proc.pic_id:
        changes.append(f"PIC: {user_name(proc.pic_id)} → {user_name(pic_id)}")
        proc.pic_id = pic_id
        if project.current_process_id == proc.id and proc.is_running and project.status != "hold":
            apply_auto_status(project, proc)
    if planned_start is not None and start != proc.planned_start:
        changes.append(f"Planned Start: {fmt_date(proc.planned_start)} → {fmt_date(start)}")
        proc.planned_start = start
    if planned_finish is not None and finish != proc.planned_finish:
        changes.append(f"Planned Finish: {fmt_date(proc.planned_finish)} → {fmt_date(finish)}")
        proc.planned_finish = finish
    if remarks is not None and ((remarks or "").strip() or None) != proc.remarks:
        changes.append("Remarks diperbarui")
        proc.remarks = (remarks or "").strip() or None
    if data is not None:
        proc.data = {**(proc.data or {}), **data}
        changes.append("Draft data proses disimpan")
    if changes:
        log_activity(ctx, project, "process_updated", f"{proc.name} diperbarui", process_id=proc.id, detail="\n".join(changes))


def add_comment(ctx: Ctx, project_id: str, body: str, process_id: str | None = None) -> Comment:
    project = get_project(project_id)
    if not perm.can_comment(ctx.actor):
        raise forbidden("Role Anda hanya memiliki akses baca.")
    body = (body or "").strip()
    if not body:
        raise invalid("Komentar tidak boleh kosong.", {"body": "Komentar tidak boleh kosong."})
    if len(body) > 2000:
        raise invalid("Komentar maksimal 2000 karakter.", {"body": "Maksimal 2000 karakter."})
    comment = Comment(project_id=project.id, process_id=process_id, user_id=ctx.actor.id, body=body, created_at=ctx.now)
    db.session.add(comment)
    project.updated_at = ctx.now
    return comment


def update_purchasing_status(ctx: Ctx, record_id: str, status: str) -> ProcessRecord:
    from ..constants import PURCHASING_STATUSES

    if not perm.can_update_purchasing(ctx.actor):
        raise forbidden("Hanya Purchasing, NPD Staff, atau Admin yang dapat mengubah purchasing status.")
    record = db.session.get(ProcessRecord, record_id)
    if not record or record.record_type != "material_request":
        raise not_found("Material request tidak ditemukan.")
    if status not in PURCHASING_STATUSES:
        raise invalid("Status purchasing tidak valid.")
    if record.purchasing_status == status:
        return record
    previous = record.purchasing_status
    record.purchasing_status = status
    project = get_project(record.project_id)
    log_activity(ctx, project, "record_updated", f"Purchasing status {record.number} diperbarui", process_id=record.process_id,
                 detail=f"{PURCHASING_STATUSES.get(previous, '—')} → {PURCHASING_STATUSES[status]}")
    return record


__all__ = ["create_project", "update_project", "update_status", "update_next_action", "update_process", "add_comment",
           "update_purchasing_status", "validate_project_input", "ROLES"]
