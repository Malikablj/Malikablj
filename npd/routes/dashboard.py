"""Dashboard: KPI, Attention Required, grafik (PRD §10)."""
from flask import Blueprint, g, render_template, request

from ..constants import ATTENTION_LABELS, PRIORITIES, PRIORITY_TONE, PROJECT_TYPES, STATUS_TONE, STATUSES
from ..services import permissions as perm
from .helpers import project_views

bp = Blueprint("dashboard", __name__)


def bar_data(counts: dict) -> list[dict]:
    """{'label': jumlah} -> daftar bar terurut, lengkap dengan persentase lebar."""
    top = max(counts.values(), default=1) or 1
    return [{"label": k, "value": v, "pct": v / top * 100} for k, v in sorted(counts.items(), key=lambda kv: -kv[1])]


def count(items, key) -> dict:
    result: dict = {}
    for item in items:
        k = key(item)
        result[k] = result.get(k, 0) + 1
    return result


@bp.route("/")
def index():
    views = project_views()
    customers = sorted({v.customer for v in views})
    pics = sorted({v.project.npd_pic.name for v in views})

    # Filter (real-time lewat query string).
    f_type = request.args.get("type", "")
    f_customer = request.args.get("customer", "")
    f_pic = request.args.get("pic", "")
    f_priority = request.args.get("priority", "")
    items = [
        v for v in views
        if (not f_type or v.project.type == f_type)
        and (not f_customer or v.customer == f_customer)
        and (not f_pic or v.project.npd_pic.name == f_pic)
        and (not f_priority or v.project.priority == f_priority)
    ]
    active = [v for v in items if v.project.is_active]

    kpi = {
        "total": len(items),
        "new_mold": sum(1 for v in items if v.project.type == "new_mold"),
        "subcont": sum(1 for v in items if v.project.type == "subcont"),
        "on_progress": sum(1 for v in active if v.project.status == "on_progress"),
        "waiting": sum(1 for v in active if v.project.status in ("waiting_approval", "waiting_external")),
        "overdue": sum(1 for v in active if v.flags["overdue"]),
        "due_soon": sum(1 for v in active if v.flags["due_soon"]),
        "completed": sum(1 for v in items if v.project.status == "completed"),
    }

    attention_key = request.args.get("attention", "overdue")
    if attention_key not in ATTENTION_LABELS:
        attention_key = "overdue"
    attention = {k: [v for v in active if v.flags[k]] for k in ATTENTION_LABELS}

    status_counts = count(items, lambda v: v.display_status)
    total = len(items) or 1
    by_status = [{"key": s, "label": STATUSES[s], "value": status_counts[s], "tone": STATUS_TONE[s], "pct": status_counts[s] / total * 100} for s in STATUSES if status_counts.get(s)]
    by_type = [{"key": t, "label": PROJECT_TYPES[t], "value": kpi[t], "tone": tone, "pct": kpi[t] / total * 100} for t, tone in (("new_mold", "blue"), ("subcont", "orange")) if kpi[t]]
    top_priority = max((sum(1 for v in items if v.project.priority == p) for p in PRIORITIES), default=1) or 1
    by_priority = []
    for p in ("urgent", "high", "medium", "low"):
        n = sum(1 for v in items if v.project.priority == p)
        by_priority.append({"key": p, "label": PRIORITIES[p], "value": n, "tone": PRIORITY_TONE[p], "pct": n / top_priority * 100})

    my_work = [v for v in active if v.current and v.current.pic_id == g.user.id and v.project.status != "hold"]

    return render_template(
        "dashboard.html",
        kpi=kpi,
        customers=customers,
        pics=pics,
        filters={"type": f_type, "customer": f_customer, "pic": f_pic, "priority": f_priority},
        attention=attention,
        attention_key=attention_key,
        by_process=bar_data(count(active, lambda v: v.current.name if v.current else "—")),
        by_customer=bar_data(count(items, lambda v: v.customer)),
        by_pic=bar_data(count(active, lambda v: v.current.pic.name if v.current and v.current.pic else "—")),
        by_status=by_status,
        by_type=by_type,
        by_priority=by_priority,
        my_work=my_work,
        scope=perm.scope_label(g.user),
    )
