"""Laporan: Weekly NPD Report & Analytics, termasuk export Excel (PRD §17)."""
from __future__ import annotations

from flask import Blueprint, Response, g, render_template, request

from ..constants import PROJECT_TYPES
from ..services import permissions as perm
from ..services.analytics import compute_analytics, weekly_report, weekly_report_text
from ..services.dates import add_days, fmt_date, parse_date, start_of_week
from ..services.excel import build_workbook
from .helpers import ctx, visible_projects

bp = Blueprint("reports", __name__, url_prefix="/reports")

XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def _period():
    today = ctx().today
    current = start_of_week(today)
    start = start_of_week(parse_date(request.args.get("week")) or today)
    return min(start, current), current, today


def _download(data: bytes, filename: str, mimetype: str = XLSX) -> Response:
    return Response(data, mimetype=mimetype, headers={"Content-Disposition": f'attachment; filename="{filename}"'})


@bp.route("")
def index():
    tab = request.args.get("tab", "weekly")
    projects = visible_projects()
    if tab == "analytics":
        a = compute_analytics(projects, ctx().today)
        ptype = request.args.get("type", "subcont")
        stats = [s for s in a["process_stats"] if s["type"] == ptype]
        top = max((s["avg_duration"] for s in stats), default=1) or 1
        bars = [{**s, "pct": s["avg_duration"] / top * 100, "tone": "orange" if s["avg_duration"] > s["avg_planned"] * 1.2 else "blue"} for s in stats]

        def loop_bars(rows):
            top_n = max((r["count"] for r in rows), default=1) or 1
            return [{**r, "pct": r["count"] / top_n * 100} for r in rows]

        return render_template("reports/analytics.html", tab=tab, a=a, ptype=ptype, bars=bars, scope=perm.scope_label(g.user),
                               loops={"artwork": loop_bars(a["artwork_revisions"]), "t0": loop_bars(a["t0_loops"]), "trial": loop_bars(a["trial_rejections"])})
    start, current, today = _period()
    report = weekly_report(projects, start, today)
    return render_template("reports/weekly.html", tab="weekly", r=report, text=weekly_report_text(report), start=start,
                           prev_week=add_days(start, -7), next_week=add_days(start, 7), is_current=start == current,
                           scope=perm.scope_label(g.user))


@bp.route("/weekly.xlsx")
def weekly_xlsx():
    start, _, today = _period()
    r = weekly_report(visible_projects(), start, today)
    summary = [["Metric", "Value"], ["Periode", f"{fmt_date(r['period_start'])} – {fmt_date(r['period_end'])}"],
               ["Total Project", r["total_project"]], ["New Project", r["new_project"]], ["Completed", r["completed"]],
               ["On Progress", r["on_progress"]], ["Waiting Customer", r["waiting_customer"]], ["Waiting Supplier", r["waiting_supplier"]],
               ["Overdue", r["overdue"]], ["Due Soon", r["due_soon"]]]
    issues = [["Project", "Issue", "Detail", "Severity"]] + [[i["code"], i["title"], i["detail"], i["severity"]] for i in r["top_issues"]]
    actions = [["Project", "Project Name", "Action", "Owner", "Due", "Terlambat"]] + [
        [a["code"], a["project_name"], a["action"], a["owner"], a["due"], "Ya" if a["overdue"] else ""] for a in r["action_required"]]
    data = build_workbook([
        {"name": "Summary", "rows": summary, "widths": [24, 30]},
        {"name": "Top Issues", "rows": issues, "widths": [14, 44, 50, 10]},
        {"name": "Action Required", "rows": actions, "widths": [14, 32, 50, 22, 12, 10]},
    ])
    return _download(data, f"Weekly-NPD-Report-{start.isoformat()}.xlsx")


@bp.route("/weekly.txt")
def weekly_txt():
    start, _, today = _period()
    text = weekly_report_text(weekly_report(visible_projects(), start, today))
    return _download(text.encode("utf-8"), f"Weekly-NPD-Report-{start.isoformat()}.txt", "text/plain; charset=utf-8")


@bp.route("/analytics.xlsx")
def analytics_xlsx():
    a = compute_analytics(visible_projects(), ctx().today)
    b = a["bottleneck"]
    kpi = [["Metric", "Value"], ["Average Project Lead Time (hari)", a["avg_lead_time"]], ["Completed projects", a["completed_count"]],
           ["Average Customer Approval Waiting (hari)", a["avg_customer_approval_wait"]],
           ["Pending Customer Approval Waiting (hari)", a["pending_customer_approval_wait"]], ["Overdue count", a["overdue_count"]],
           ["Average overdue duration (hari)", a["avg_overdue_days"]], ["Max overdue (hari)", a["max_overdue_days"]],
           ["Bottleneck process", b["label"] if b else ""]]
    durations = [["Process", "Type", "Avg Actual (hari)", "Avg Plan (hari)", "Sampel", "Maks"]] + [
        [s["name"], PROJECT_TYPES[s["type"]], s["avg_duration"], s["avg_planned"], s["samples"], s["max_duration"]] for s in a["process_stats"]]
    loops = [["Project", "Name", "Metric", "Count"]]
    for key, label in (("artwork_revisions", "Artwork revisions"), ("t0_loops", "T0 loops"), ("trial_rejections", "Trial rejection loops")):
        loops += [[r["code"], r["name"], label, r["count"]] for r in a[key]]
    data = build_workbook([
        {"name": "KPI", "rows": kpi, "widths": [40, 24]},
        {"name": "Process Duration", "rows": durations, "widths": [34, 12, 16, 16, 10, 10]},
        {"name": "Loops", "rows": loops, "widths": [14, 36, 26, 10]},
    ])
    return _download(data, f"NPD-Analytics-{ctx().today.isoformat()}.xlsx")
