"""Pengaturan: profil & password (semua user); user, customer, workflow, sistem (khusus Admin)."""
from __future__ import annotations

from flask import Blueprint, abort, flash, g, redirect, render_template, request, url_for

from ..constants import DOC_TYPES, ROLE_DESCRIPTION, ROLES
from ..extensions import db
from ..models import Customer, Project, User, WorkflowTemplate, get_settings
from ..services import admin as admin_service
from ..services import permissions as perm
from .helpers import ctx, form_int, run

bp = Blueprint("settings", __name__, url_prefix="/settings")

ADMIN_TABS = ("users", "customers", "workflow", "system")


def require_admin():
    if not perm.can_manage_settings(g.user):
        abort(403, "Hanya Admin yang dapat mengelola pengaturan ini.")


@bp.route("", methods=["GET", "POST"])
def index():
    tab = request.args.get("tab", "profile")
    if tab in ADMIN_TABS:
        require_admin()
    errors = {}
    if request.method == "POST" and tab == "profile":
        ok, error = run(lambda: admin_service.change_password(ctx(), request.form.get("current"), request.form.get("new"), request.form.get("confirm")),
                        "Password berhasil diganti")
        if ok:
            return redirect(url_for("settings.index"))
        errors = error.field_errors
    data = {"tab": tab, "errors": errors, "is_admin": perm.can_manage_settings(g.user), "role_description": ROLE_DESCRIPTION}
    if tab == "users":
        data["users"] = User.query.order_by(User.active.desc(), User.name).all()
    elif tab == "customers":
        customers = Customer.query.order_by(Customer.name).all()
        data["customers"] = customers
        data["usage"] = {c.id: Project.query.filter_by(customer_id=c.id).count() for c in customers}
    elif tab == "workflow":
        workflows = WorkflowTemplate.query.order_by(WorkflowTemplate.project_type.desc()).all()
        wf = next((w for w in workflows if w.id == request.args.get("wf")), workflows[0] if workflows else None)
        data.update(workflows=workflows, wf=wf, updated_by=db.session.get(User, wf.updated_by) if wf and wf.updated_by else None)
    elif tab == "system":
        data["settings"] = get_settings()
    return render_template("settings/index.html", **data)


@bp.route("/system", methods=["POST"])
def system():
    require_admin()
    run(lambda: admin_service.update_settings(ctx(), form_int(request.form.get("due_soon_days")), form_int(request.form.get("no_update_days"))),
        "Pengaturan sistem disimpan")
    return redirect(url_for("settings.index", tab="system"))


@bp.route("/reset-demo", methods=["POST"])
def reset_demo():
    require_admin()
    if request.form.get("confirm") != "RESET":
        flash({"title": "Ketik RESET untuk konfirmasi.", "details": []}, "error")
        return redirect(url_for("settings.index", tab="system"))
    run(lambda: admin_service.reset_demo_data(ctx()), "Data demo direset")
    return redirect(url_for("dashboard.index"))


# --------------------------------------------------------------------------- user
@bp.route("/users/new", methods=["GET", "POST"])
@bp.route("/users/<user_id>", methods=["GET", "POST"])
def user(user_id=None):
    require_admin()
    item = db.session.get(User, user_id) if user_id else None
    if user_id and not item:
        abort(404)
    values = {"name": item.name, "email": item.email, "role": item.role, "title": item.title, "active": item.active} if item else {"role": "npd_staff", "active": True}
    errors = {}
    if request.method == "POST":
        values = {k: request.form.get(k, "") for k in ("name", "email", "role", "title", "password")}
        values["active"] = bool(request.form.get("active"))
        ok, error = run(lambda: admin_service.save_user(ctx(), values, user_id), "User disimpan")
        if ok:
            return redirect(url_for("settings.index", tab="users"))
        errors = error.field_errors
    return render_template("settings/user.html", item=item, values=values, errors=errors, roles=list(ROLES.items()))


# --------------------------------------------------------------------------- customer
@bp.route("/customers/new", methods=["GET", "POST"])
@bp.route("/customers/<customer_id>", methods=["GET", "POST"])
def customer(customer_id=None):
    require_admin()
    item = db.session.get(Customer, customer_id) if customer_id else None
    if customer_id and not item:
        abort(404)
    values = {k: getattr(item, k) for k in ("name", "code", "contact_name", "contact_email", "active")} if item else {"active": True}
    errors = {}
    if request.method == "POST":
        values = {k: request.form.get(k, "") for k in ("name", "code", "contact_name", "contact_email")}
        values["active"] = bool(request.form.get("active"))
        ok, error = run(lambda: admin_service.save_customer(ctx(), values, customer_id), "Customer disimpan")
        if ok:
            return redirect(url_for("settings.index", tab="customers"))
        errors = error.field_errors
    usage = Project.query.filter_by(customer_id=customer_id).count() if customer_id else 0
    return render_template("settings/customer.html", item=item, values=values, errors=errors, usage=usage)


@bp.route("/customers/<customer_id>/delete", methods=["POST"])
def delete_customer(customer_id):
    require_admin()
    run(lambda: admin_service.delete_customer(ctx(), customer_id), "Customer dihapus")
    return redirect(url_for("settings.index", tab="customers"))


# --------------------------------------------------------------------------- workflow template
def read_process_form(form) -> dict:
    return {
        "name": form.get("name", ""),
        "short_name": form.get("short_name", ""),
        "description": form.get("description", ""),
        "pic_role": form.get("pic_role") if form.get("pic_role") in ROLES else "npd_staff",
        "actor_roles": [r for r in form.getlist("actor_roles") if r in ROLES],
        "duration_days": form_int(form.get("duration_days"), -1),
        "is_mandatory": bool(form.get("is_mandatory")),
        "requires_document": bool(form.get("requires_document")),
        "required_doc_types": [t for t in form.getlist("required_doc_types") if t in DOC_TYPES],
        "default_next_action": form.get("default_next_action", ""),
    }


@bp.route("/workflow/<wf_id>/add", methods=["GET", "POST"])
@bp.route("/workflow/<wf_id>/<key>", methods=["GET", "POST"])
def workflow_process(wf_id, key=None):
    require_admin()
    wf = db.session.get(WorkflowTemplate, wf_id)
    if not wf:
        abort(404)
    proc = next((p for p in wf.processes if p["key"] == key), None) if key else None
    if key and not proc:
        abort(404)
    after = request.args.get("after") or (wf.processes[-2]["key"] if len(wf.processes) > 1 else "")
    values = dict(proc) if proc else {"pic_role": "npd_staff", "actor_roles": ["npd_staff"], "duration_days": 3, "is_mandatory": True,
                                      "requires_document": False, "required_doc_types": []}
    errors = {}
    if request.method == "POST":
        values = read_process_form(request.form)
        if proc:
            action = lambda: admin_service.update_workflow_process(ctx(), wf.id, key, values)
        else:
            action = lambda: admin_service.add_workflow_process(ctx(), wf.id, request.form.get("after") or after, values)
        ok, error = run(action, "Workflow template diperbarui (versi baru)")
        if ok:
            return redirect(url_for("settings.index", tab="workflow", wf=wf.id))
        errors = error.field_errors
    positions = [(p["key"], f"Setelah {i:02d} · {p['name']}") for i, p in enumerate(wf.processes, 1) if p["kind"] != "finish"]
    return render_template("settings/workflow_process.html", wf=wf, proc=proc, values=values, errors=errors, after=after,
                           positions=positions, roles=list(ROLES.items()), doc_types=list(DOC_TYPES.items()))


@bp.route("/workflow/<wf_id>/<key>/move", methods=["POST"])
def workflow_move(wf_id, key):
    require_admin()
    run(lambda: admin_service.move_workflow_process(ctx(), wf_id, key, -1 if request.form.get("direction") == "up" else 1), "Urutan proses diperbarui")
    return redirect(url_for("settings.index", tab="workflow", wf=wf_id))


@bp.route("/workflow/<wf_id>/<key>/remove", methods=["POST"])
def workflow_remove(wf_id, key):
    require_admin()
    run(lambda: admin_service.remove_workflow_process(ctx(), wf_id, key), "Proses dihapus dari template")
    return redirect(url_for("settings.index", tab="workflow", wf=wf_id))
