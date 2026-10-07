"""Membuat jadwal rencana (planned start/finish) setiap proses dari durasi template."""
from datetime import date

from .dates import add_days, diff_days


def workflow_length(defs: list[dict]) -> int:
    """Total hari workflow (cabang alternatif dihitung paralel, proses loop tidak dihitung)."""
    total = 0
    groups: dict[str, dict[str, int]] = {}
    for d in defs:
        if d.get("loop_only"):
            continue
        if d.get("branch_group"):
            g = groups.setdefault(d["branch_group"], {})
            b = d.get("branch") or d["key"]
            g[b] = g.get(b, 0) + d["duration_days"]
            continue
        total += d["duration_days"]
    for g in groups.values():
        total += max(g.values())
    return total


def build_plan(defs: list[dict], start: date, target: date | None = None) -> dict[str, tuple[date, date]]:
    """Kembalikan {process_key: (planned_start, planned_finish)}.

    Jika target diisi, durasi diskalakan agar rencana selesai tepat di target.
    """
    base = workflow_length(defs)
    available = max(1, diff_days(start, target)) if target else base
    scale = available / base if base else 1

    def length(d):
        return max(1, round(d["duration_days"] * scale))

    plan: dict[str, tuple[date, date]] = {}
    cursor = start
    i = 0
    while i < len(defs):
        d = defs[i]
        if d.get("branch_group"):
            group = d["branch_group"]
            group_start = cursor
            branch_cursor: dict[str, date] = {}
            group_end = group_start
            while i < len(defs) and defs[i].get("branch_group") == group:
                cur = defs[i]
                b = cur.get("branch") or cur["key"]
                s = branch_cursor.get(b, group_start)
                f = add_days(s, length(cur))
                plan[cur["key"]] = (s, f)
                branch_cursor[b] = f
                group_end = max(group_end, f)
                i += 1
            cursor = group_end
            continue
        if d.get("loop_only"):
            plan[d["key"]] = (cursor, add_days(cursor, length(d)))
            i += 1
            continue
        finish = add_days(cursor, length(d))
        plan[d["key"]] = (cursor, finish)
        cursor = finish
        i += 1
    if target and defs:
        last = defs[-1]["key"]
        s, f = plan[last]
        if f != target and target > s:
            plan[last] = (s, target)
    return plan
