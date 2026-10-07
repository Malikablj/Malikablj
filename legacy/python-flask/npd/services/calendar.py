"""Item kalender (PRD §10): tanggal dari data project + meeting/follow-up buatan user."""
from __future__ import annotations

from datetime import date

from ..constants import CALENDAR_CATEGORIES
from ..models import CalendarEvent, ProcessRecord, Project
from .dates import parse_date


def calendar_items(projects: list[Project], start: date, end: date) -> list[dict]:
    """Semua item kalender antara start dan end untuk project yang boleh dilihat user."""
    ids = {p.id for p in projects}
    items: list[dict] = []

    def add(**item):
        items.append(item)

    for p in projects:
        customer = p.customer.name
        if p.is_active and start <= p.target_date <= end:
            add(id=f"dl-{p.id}", date=p.target_date, category="deadline", title=f"Deadline {p.name}", subtitle=f"{p.code} · {customer}", project=p)
        if p.is_active and p.status != "hold" and start <= p.next_action_due <= end:
            add(id=f"fu-{p.id}", date=p.next_action_due, category="follow_up", title=p.next_action, subtitle=f"{p.code} · Next Action", project=p)
        for proc in p.processes:
            if not proc.calendar_category or proc.status == "skipped":
                continue
            if not p.is_active and proc.status != "completed":
                continue
            trial_date = parse_date((proc.data or {}).get("trial_date"))
            day = (proc.actual_finish or proc.planned_finish) if proc.status == "completed" else (trial_date or proc.planned_finish)
            if not (start <= day <= end):
                continue
            done = proc.status == "completed"
            add(id=f"pr-{proc.id}", date=day, category=proc.calendar_category, title=f"{proc.name}{' ✓' if done else ''}",
                subtitle=f"{p.code} · {'Selesai' if done else 'Planned'} · {customer}", project=p)

    for r in ProcessRecord.query.filter(ProcessRecord.project_id.in_(ids), ProcessRecord.record_type == "material_request").all():
        day = parse_date((r.data or {}).get("required_date"))
        if day and start <= day <= end and r.purchasing_status != "received":
            p = next(x for x in projects if x.id == r.project_id)
            add(id=f"mr-{r.id}", date=day, category="material_arrival", title=f"Material {r.number} dibutuhkan",
                subtitle=f"{p.code} · {(r.data or {}).get('material', '')}", project=p)

    for ev in CalendarEvent.query.filter(CalendarEvent.date >= start, CalendarEvent.date <= end).all():
        if ev.project_id and ev.project_id not in ids:
            continue
        add(id=f"ev-{ev.id}", event=ev, date=ev.date, time=ev.time, category=ev.category, title=ev.title,
            subtitle=" · ".join(x for x in [ev.project.code if ev.project else None, CALENDAR_CATEGORIES[ev.category], ev.created_by.name if ev.created_by else None] if x),
            project=ev.project, notes=ev.notes)

    items.sort(key=lambda i: (i["date"], i.get("time") or "99"))
    return items
