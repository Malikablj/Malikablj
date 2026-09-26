"""Kalender: deadline, approval, trial, material, validation + meeting/follow-up (PRD §10)."""
from __future__ import annotations

from datetime import date

from flask import Blueprint, abort, g, redirect, render_template, request, url_for

from ..constants import CALENDAR_CATEGORIES
from ..extensions import db
from ..models import CalendarEvent
from ..services import admin as admin_service
from ..services import permissions as perm
from ..services.calendar import calendar_items
from ..services.dates import add_days, add_months, end_of_month, end_of_week, parse_date, start_of_month, start_of_week
from .helpers import ctx, run, visible_projects

bp = Blueprint("calendar", __name__, url_prefix="/calendar")


def parse_month(value: str | None, today: date) -> date:
    try:
        year, month = (int(x) for x in (value or "").split("-")[:2])
        return date(year, month, 1)
    except ValueError:
        return start_of_month(today)


@bp.route("")
def index():
    today = ctx().today
    month = parse_month(request.args.get("month"), today)
    selected = parse_date(request.args.get("day")) or (today if start_of_month(today) == month else month)
    cats = [c for c in request.args.getlist("cat") if c in CALENDAR_CATEGORIES] or list(CALENDAR_CATEGORIES)
    grid_start = start_of_week(month)
    grid_end = end_of_week(end_of_month(month))
    projects = visible_projects()
    items = [i for i in calendar_items(projects, grid_start, grid_end) if i["category"] in cats]
    by_day: dict[date, list] = {}
    for i in items:
        by_day.setdefault(i["date"], []).append(i)
    days = []
    d = grid_start
    while d <= grid_end:
        days.append(d)
        d = add_days(d, 1)
    month_days = sorted(k for k in by_day if k.month == month.month and k.year == month.year)
    return render_template(
        "calendar/index.html", month=month, prev_month=add_months(month, -1), next_month=add_months(month, 1), days=days, by_day=by_day,
        selected=selected, cats=cats, month_days=month_days, can_manage=perm.can_manage_calendar(g.user),
    )


def can_edit_event(event: CalendarEvent) -> bool:
    return perm.can_manage_calendar(g.user) and (event.created_by_id == g.user.id or g.user.role == "admin")


@bp.route("/events/new", methods=["GET", "POST"])
@bp.route("/events/<event_id>", methods=["GET", "POST"])
def event(event_id=None):
    if not perm.can_manage_calendar(g.user):
        abort(403, "Role Anda hanya memiliki akses baca.")
    item = db.session.get(CalendarEvent, event_id) if event_id else None
    if event_id and (not item or not can_edit_event(item)):
        abort(404 if not item else 403)
    values = {
        "title": item.title if item else "",
        "category": item.category if item else request.args.get("category", "meeting"),
        "date": (item.date if item else parse_date(request.args.get("date")) or ctx().today).isoformat(),
        "time": item.time if item else "",
        "project_id": item.project_id if item else "",
        "notes": item.notes if item else "",
    }
    errors = {}
    if request.method == "POST":
        values = {k: request.form.get(k, "") for k in values}
        ok, error = run(lambda: admin_service.save_event(ctx(), values, event_id), "Event disimpan")
        if ok:
            return redirect(url_for("calendar.index", month=values["date"][:7], day=values["date"]))
        errors = error.field_errors
    projects = [(p.id, f"{p.code} · {p.name}") for p in visible_projects() if p.is_active or (item and p.id == item.project_id)]
    return render_template("calendar/event.html", item=item, values=values, errors=errors, projects=projects)


@bp.route("/events/<event_id>/delete", methods=["POST"])
def delete_event(event_id):
    item = db.session.get(CalendarEvent, event_id)
    day = item.date.isoformat() if item else ""
    run(lambda: admin_service.delete_event(ctx(), event_id), "Event dihapus")
    return redirect(url_for("calendar.index", month=day[:7] or None, day=day or None))
