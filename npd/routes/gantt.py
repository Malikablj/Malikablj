"""Timeline / Gantt: rentang project & proses, planned vs actual (PRD §10)."""
from __future__ import annotations

from flask import Blueprint, render_template, request

from ..constants import STATUSES
from ..services.dates import add_days, add_months, diff_days, fmt_date_short, parse_date, start_of_month, MONTHS_SHORT
from .helpers import ctx, project_views

bp = Blueprint("gantt", __name__, url_prefix="/gantt")

ZOOM = {"week": 26, "month": 9, "quarter": 3.2}  # lebar 1 hari (px)
LABEL_WIDTH = 240


@bp.route("")
def index():
    today = ctx().today
    views = project_views()
    f = {k: request.args.get(k, "") for k in ("type", "customer", "pic", "priority", "view", "zoom", "expand")}
    f["status"] = request.args.get("status", "active")
    start = parse_date(request.args.get("from")) or add_months(start_of_month(today), -2)
    end = parse_date(request.args.get("to")) or add_days(add_months(start_of_month(today), 4), -1)
    range_invalid = start > end
    zoom = f["zoom"] if f["zoom"] in ZOOM else "month"
    px = ZOOM[zoom]

    def keep(v):
        p = v.project
        if f["type"] and p.type != f["type"]:
            return False
        if f["customer"] and v.customer != f["customer"]:
            return False
        if f["pic"] and p.npd_pic.name != f["pic"]:
            return False
        if f["priority"] and p.priority != f["priority"]:
            return False
        if f["status"] == "active" and not p.is_active:
            return False
        if f["status"] not in ("", "active") and v.display_status != f["status"]:
            return False
        finish = p.actual_finish or (p.target_date if not p.is_active else max(p.target_date, today))
        return p.start_date <= end and finish >= start

    items = sorted([v for v in views if keep(v)], key=lambda v: v.project.start_date) if not range_invalid else []

    def x(d):
        return diff_days(start, d) * px

    def clamp(d):
        return min(max(d, start), end)

    chart = None
    if not range_invalid and f["view"] != "list":
        width = (diff_days(start, end) + 1) * px
        months = []
        m = start_of_month(start)
        while m <= end:
            s, e = max(m, start), min(add_days(add_months(m, 1), -1), end)
            w = (diff_days(s, e) + 1) * px
            months.append({"label": f"{MONTHS_SHORT[m.month - 1]} {m.year}" if w > 40 else "", "left": x(s), "width": w})
            m = add_months(m, 1)
        weeks = []
        if zoom != "quarter":
            d = start
            while d <= end:
                if d.weekday() == 0:
                    weeks.append({"left": x(d), "label": fmt_date_short(d) if px * 7 > 34 else ""})
                d = add_days(d, 1)
        expanded = set(filter(None, f["expand"].split(",")))
        rows = []
        for v in items:
            p = v.project
            done = p.status == "completed"
            bar_left = x(clamp(p.start_date))
            bar_end = clamp(p.actual_finish or p.target_date) if done else clamp(p.target_date)
            row = {
                "view": v,
                "left": bar_left,
                "width": max(px, x(bar_end) - bar_left + px),
                "done": done,
                "overdue_left": x(clamp(p.target_date)) + px,
                "overdue_width": max(0, x(clamp(today)) - x(clamp(p.target_date))) if v.overdue_days else 0,
                "expanded": p.code in expanded,
                "toggle": ",".join(sorted(expanded ^ {p.code})),
                "processes": [],
            }
            if row["expanded"]:
                for pr in p.processes:
                    if pr.status == "skipped" or (pr.loop_only and pr.status == "not_started"):
                        continue
                    a_end = pr.actual_finish or (today if pr.actual_start and pr.is_running else None)
                    plan_visible = pr.planned_finish >= start and pr.planned_start <= end
                    actual_visible = bool(pr.actual_start and a_end and a_end >= start and pr.actual_start <= end)
                    row["processes"].append({
                        "proc": pr,
                        "plan": (x(clamp(pr.planned_start)), max(px, x(clamp(pr.planned_finish)) - x(clamp(pr.planned_start)) + px)) if plan_visible else None,
                        "actual": (x(clamp(pr.actual_start)), max(px, x(clamp(a_end)) - x(clamp(pr.actual_start)) + px)) if actual_visible else None,
                        "tone": "green" if pr.status == "completed" else ("red" if pr.status == "problem" else "blue"),
                    })
            rows.append(row)
        today_x = x(today) + px / 2 if start <= today <= end else None
        chart = {"width": width, "months": months, "weeks": weeks, "rows": rows, "today_x": today_x, "label_width": LABEL_WIDTH}

    list_rows = []
    for v in items:
        p = v.project
        span = max(1, diff_days(p.start_date, p.target_date))
        list_rows.append({"view": v, "elapsed": round(min(100, max(0, diff_days(p.start_date, today) / span * 100)))})

    return render_template(
        "gantt.html", f=f, zoom=zoom, start=start, end=end, range_invalid=range_invalid, chart=chart, list_rows=list_rows,
        customers=sorted({v.customer for v in views}), pics=sorted({v.project.npd_pic.name for v in views}), total=len(views),
        statuses=STATUSES,
    )
