"""Hak akses per role (PRD §11).

Aturan yang sama dipakai oleh service (saat mengubah data), query (data apa yang
boleh dilihat), tampilan (tombol apa yang muncul), dan AI Assistant.
"""
from ..models import Approval, Project, ProjectProcess, User

PROJECT_CONTROL = ("admin", "npd_staff")


def can_view_project(user: User, project: Project) -> bool:
    """Admin Sales & Drafter hanya melihat project yang di-assign ke dirinya; role lain melihat semua."""
    if user.role == "admin_sales":
        return project.sales_pic_id == user.id
    if user.role == "drafter":
        return project.drafter_id == user.id
    return True


def visible_projects_query(user: User):
    query = Project.query
    if user.role == "admin_sales":
        query = query.filter(Project.sales_pic_id == user.id)
    elif user.role == "drafter":
        query = query.filter(Project.drafter_id == user.id)
    return query


def scope_label(user: User) -> str:
    if user.role == "admin_sales":
        return "Project dengan Anda sebagai Sales PIC"
    if user.role == "drafter":
        return "Project dengan Anda sebagai Drafter"
    return "Semua project"


def is_read_only(user: User) -> bool:
    return user.role == "management"


def can_create_project(user: User) -> bool:
    return user.role in ("admin", "admin_sales", "npd_staff")


def can_edit_project(user: User, project: Project) -> bool:
    if user.role in PROJECT_CONTROL:
        return True
    return user.role == "admin_sales" and project.sales_pic_id == user.id


def can_change_status(user: User) -> bool:
    return user.role in PROJECT_CONTROL


def can_update_next_action(user: User, project: Project) -> bool:
    if can_edit_project(user, project):
        return True
    cur = project.current_process
    return bool(cur) and user.role in cur.actor_roles and can_view_project(user, project)


def can_act_on_process(user: User, project: Project, proc: ProjectProcess) -> bool:
    if is_read_only(user) or not can_view_project(user, project):
        return False
    return user.role == "admin" or user.role in (proc.actor_roles or [])


def can_plan_process(user: User) -> bool:
    return user.role in PROJECT_CONTROL


def can_upload_to_process(user: User, project: Project, proc: ProjectProcess) -> bool:
    if is_read_only(user) or not can_view_project(user, project):
        return False
    if user.role in PROJECT_CONTROL:
        return True
    return user.role in (proc.upload_roles or []) or user.role in (proc.actor_roles or [])


def can_decide_approval(user: User, project: Project, approval: Approval) -> bool:
    if is_read_only(user) or not can_view_project(user, project):
        return False
    if user.role in PROJECT_CONTROL:
        return True
    if approval.approver_type == "customer":
        return user.role == "admin_sales"
    return bool(approval.process) and user.role in (approval.process.actor_roles or [])


def can_request_approval(user: User) -> bool:
    return user.role in ("admin", "npd_staff", "admin_sales", "drafter")


def can_finish_project(user: User) -> bool:
    return user.role in PROJECT_CONTROL


def can_override_workflow(user: User) -> bool:
    return user.role == "admin"


def can_manage_settings(user: User) -> bool:
    return user.role == "admin"


def can_manage_calendar(user: User) -> bool:
    return not is_read_only(user)


def can_comment(user: User) -> bool:
    return not is_read_only(user)


def can_update_purchasing(user: User) -> bool:
    return user.role in ("admin", "npd_staff", "purchasing")
