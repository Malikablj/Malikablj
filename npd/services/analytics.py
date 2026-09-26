"""Laporan & analytics (PRD §17). Semua angka dihitung dari data di database."""
from __future__ import annotations

from datetime import date
from statistics import mean

from ..constants import APPROVAL_TYPES, PROJECT_TYPES
from ..models import Approval, Project, ProjectProcess, get_settings
from .context import user_name
from .dates import add_days, diff_days, fmt_date
from .metrics import is_due_soon, is_overdue, overdue_days, process_duration


def _avg(values):
    return round(mean(values), 1) if values else None


def weekly_report(projects: list[Project], period_start: date, today: date) -> dict:
    """Weekly NPD Report untuk periode Senin–Minggu."""
    settings = get_settings()
    period_end = add_days(period_start, 6)
    ids = {p.id for p in projects}
    active = [p for p in projects if p.is_active]

    issues = []
    for p in active:
        d = overdue_days(p, today)
        if d:
            issues.append({"code": p.code, "title": f"{p.name} overdue {d} hari", "detail": f"Target {fmt_date(p.target_date)} · {p.next_action}", "severity": "high"})
    for proc in ProjectProcess.query.filter(ProjectProcess.project_id.in_(ids), ProjectProcess.status == "problem").all():
        if proc.project.is_active:
            issues.append({"code": proc.project.code, "title": f"Problem di {proc.name}", "detail": proc.problem_note or "Perlu tindak lanjut", "severity": "high"})
    for a in Approval.query.filter(Approval.project_id.in_(ids), Approval.status.in_(["rejected", "revision_required"])).all():
        if a.decision_date and period_start <= a.decision_date <= period_end:
            issues.append({"code": a.project.code, "title": f"{APPROVAL_TYPES[a.type]} ditolak ({a.revision})", "detail": a.comment or "—", "severity": "medium"})
    issues.sort(key=lambda i: 0 if i["severity"] == "high" else 1)

    actions = []
    for p in active:
        if p.status == "hold" or p.next_action_due > period_end:
            continue
        cur = p.current_process
        actions.append({
            "code": p.code,
            "project_name": p.name,
            "action": p.next_action,
            "due": p.next_action_due,
            "owner": user_name(cur.pic_id if cur else p.npd_pic_id),
            "overdue": p.next_action_due < today,
        })
    actions.sort(key=lambda a: a["due"])

    return {
        "period_start": period_start,
        "period_end": period_end,
        "total_project": sum(1 for p in projects if p.status != "cancelled" and p.created_at.date() <= period_end),
        "new_project": sum(1 for p in projects if period_start <= p.created_at.date() <= period_end),
        "completed": sum(1 for p in projects if p.actual_finish and period_start <= p.actual_finish <= period_end),
        "on_progress": sum(1 for p in active if p.status == "on_progress"),
        "waiting_customer": sum(1 for p in active if p.status == "waiting_approval"),
        "waiting_supplier": sum(1 for p in active if p.status == "waiting_external"),
        "overdue": sum(1 for p in active if is_overdue(p, today)),
        "due_soon": sum(1 for p in active if is_due_soon(p, today, settings.due_soon_days)),
        "top_issues": issues[:10],
        "action_required": actions,
    }


def weekly_report_text(r: dict) -> str:
    """Versi teks weekly report, siap disalin ke email/chat."""
    lines = [
        "WEEKLY NPD REPORT",
        f"Periode: {fmt_date(r['period_start'])} – {fmt_date(r['period_end'])}",
        "",
        f"Total Project     : {r['total_project']}",
        f"New Project       : {r['new_project']}",
        f"Completed         : {r['completed']}",
        f"On Progress       : {r['on_progress']}",
        f"Waiting Customer  : {r['waiting_customer']}",
        f"Waiting Supplier  : {r['waiting_supplier']}",
        f"Overdue           : {r['overdue']}",
        f"Due Soon          : {r['due_soon']}",
        "",
        "TOP ISSUES",
    ]
    lines += [f"{n}. [{i['code']}] {i['title']} — {i['detail']}" for n, i in enumerate(r["top_issues"], 1)] or ["- Tidak ada issue."]
    lines += ["", "ACTION REQUIRED"]
    lines += [
        f"{n}. [{a['code']}] {a['action']} — {a['owner']}, due {fmt_date(a['due'])}{' (TERLAMBAT)' if a['overdue'] else ''}"
        for n, a in enumerate(r["action_required"], 1)
    ] or ["- Tidak ada action."]
    return "\n".join(lines)


def compute_analytics(projects: list[Project], today: date) -> dict:
    ids = {p.id for p in projects}
    completed = [p for p in projects if p.status == "completed" and p.actual_finish]
    lead_times = [diff_days(p.start_date, p.actual_finish) for p in completed]

    stats: dict[str, dict] = {}
    for p in projects:
        for proc in p.processes:
            if proc.kind == "finish":
                continue
            duration = process_duration(proc, today)
            if duration is None or not (proc.status == "completed" or proc.is_running):
                continue
            entry = stats.setdefault(f"{p.type}:{proc.key}", {"key": proc.key, "name": proc.name, "type": p.type, "durations": [], "planned": [], "running": 0})
            entry["durations"].append(duration)
            entry["planned"].append(max(0, diff_days(proc.planned_start, proc.planned_finish)))
            if proc.is_running:
                entry["running"] += 1
    process_stats = sorted(
        (
            {
                "key": e["key"],
                "name": e["name"],
                "type": e["type"],
                "label": f"{e['name']} ({PROJECT_TYPES[e['type']]})",
                "avg_duration": _avg(e["durations"]),
                "avg_planned": _avg(e["planned"]),
                "samples": len(e["durations"]),
                "running": e["running"],
                "max_duration": max(e["durations"]),
            }
            for e in stats.values()
        ),
        key=lambda s: s["avg_duration"],
        reverse=True,
    )
    # Bottleneck = rata-rata keterlambatan terbesar dibanding plan.
    bottleneck = max(process_stats, key=lambda s: (s["avg_duration"] - s["avg_planned"], s["avg_duration"]), default=None)

    customer_approvals = Approval.query.filter(Approval.project_id.in_(ids), Approval.approver_type == "customer").all()
    decided = [diff_days(a.requested_date, a.decision_date) for a in customer_approvals if a.decision_date]
    pending = [diff_days(a.requested_date, today) for a in customer_approvals if a.status == "pending"]

    def loops(ptype, predicate):
        rows = []
        for p in projects:
            if p.type != ptype or p.status == "cancelled":
                continue
            count = sum(1 for a in Approval.query.filter_by(project_id=p.id).all() if predicate(a))
            rows.append({"code": p.code, "name": p.name, "count": count})
        return sorted(rows, key=lambda r: (-r["count"], r["code"]))

    overdue = [overdue_days(p, today) for p in projects if is_overdue(p, today)]
    return {
        "avg_lead_time": _avg(lead_times),
        "completed_count": len(completed),
        "process_stats": process_stats,
        "bottleneck": bottleneck,
        "avg_customer_approval_wait": _avg(decided),
        "pending_customer_approval_wait": _avg(pending),
        "customer_approval_samples": len(decided),
        "artwork_revisions": loops("subcont", lambda a: a.type == "artwork" and a.status in ("rejected", "revision_required")),
        "t0_loops": loops("new_mold", lambda a: a.type == "t0" and a.status == "rejected"),
        "trial_rejections": loops("subcont", lambda a: a.type == "trial" and a.status == "rejected"),
        "overdue_count": len(overdue),
        "avg_overdue_days": _avg(overdue),
        "max_overdue_days": max(overdue) if overdue else 0,
    }
