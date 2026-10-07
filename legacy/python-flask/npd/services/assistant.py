"""AI Assistant berbasis data (PRD §13).

Assistant ini membaca database project sesuai hak akses user dan menyusun jawaban
dari data tersebut. Aturan PRD:
- tidak mengarang data  -> jika tidak ada: "Data tersebut belum tersedia di sistem."
- mengikuti permission user (hanya project yang boleh dilihat)
- tidak mengubah data

Jawaban berupa dict {"intent", "blocks", "basis"} dengan blok:
  text | projects | summary | list | stats | report
Fungsi `answer_question` bisa diganti LLM selama memakai data ber-scope yang sama.
"""
from __future__ import annotations

import re
import unicodedata
from datetime import date

from ..constants import (
    APPROVAL_STATUSES, APPROVAL_TYPES, DOC_STATUSES, DOC_TYPES, PRIORITIES, PROJECT_TYPES, STATUS_TONE, STATUSES, format_revision,
)
from ..models import Approval, Document, Project, User, get_settings
from . import permissions as perm
from .analytics import compute_analytics, weekly_report, weekly_report_text
from .context import user_name
from .dates import describe_due, end_of_month, end_of_week, fmt_date, fmt_month, start_of_month, start_of_week, add_days
from .metrics import build_view, days_since_update, display_status, is_due_soon, is_overdue, overdue_days

NOT_AVAILABLE = "Data tersebut belum tersedia di sistem."

SUGGESTED_QUESTIONS = [
    "Project Subcont apa saja yang sedang menunggu Customer?",
    "Project Kymm sekarang sampai mana?",
    "Project mana yang overdue?",
    "Project Subcont yang belum memiliki artwork?",
    "Artwork Facetology terakhir revisi berapa?",
    "Berapa kali Kymm melakukan revisi artwork?",
    "Deadline minggu ini apa saja?",
    "Process mana yang paling lama?",
    "Berapa project New Mold bulan ini?",
    "Berapa project Subcont yang sedang berjalan?",
    "Buatkan weekly NPD report.",
    "Project mana yang membutuhkan follow-up?",
]

GENERIC = {
    "project", "projek", "proyek", "yang", "apa", "saja", "mana", "berapa", "sekarang", "sampai", "untuk", "dengan", "dan", "atau", "dari",
    "new", "mold", "subcont", "tube", "bottle", "case", "cap", "jar", "botol", "ml", "artwork", "revisi", "terakhir", "status", "summary",
    "ringkasan", "buatkan", "buat", "tolong", "posisi", "proses", "process", "customer", "kali", "melakukan", "the", "di", "ke", "ini", "itu",
    "sudah", "belum", "sedang", "menunggu", "dimana", "bagaimana", "progress", "rev", "drawing", "report", "trial", "approval",
}

DOC_KEYWORDS = [
    (r"\bartwork\b", ["artwork"], "Artwork"),
    (r"\b3d\b", ["drawing_3d"], "3D Drawing"),
    (r"\b2d\b", ["drawing_2d"], "2D Drawing"),
    (r"mold drawing", ["mold_drawing"], "Mold Drawing"),
    (r"trial report|laporan trial", ["trial_report"], "Trial Report"),
    (r"\bnpr\b", ["npr"], "NPR"),
    (r"\bcoa\b", ["coa"], "COA"),
    (r"validation|validasi", ["validation_report"], "Validation Report"),
]

APPROVAL_KEYWORDS = [
    (r"\bt0\b", "t0", "T0"),
    (r"\b3d\b", "3d", "3D"),
    (r"masterbatch", "masterbatch", "masterbatch"),
    (r"trial", "trial", "trial"),
    (r"artwork", "artwork", "artwork"),
]


def normalize(s: str) -> str:
    s = unicodedata.normalize("NFKD", s.lower()).encode("ascii", "ignore").decode()
    s = re.sub(r"[^a-z0-9\s-]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def _word_in(q: str, token: str) -> bool:
    return re.search(rf"(^|\s){re.escape(token)}(\s|$)", q) is not None


class _Q:
    """Konteks satu pertanyaan."""

    def __init__(self, user: User, question: str, today: date):
        self.user = user
        self.today = today
        self.q = normalize(question)
        self.all_projects = Project.query.all()
        self.visible = [p for p in self.all_projects if perm.can_view_project(user, p)]
        self.active = [p for p in self.visible if p.is_active]
        self.settings = get_settings()

    @property
    def basis(self) -> str:
        return f"Berdasarkan {len(self.visible)} project yang dapat Anda akses · data per {fmt_date(self.today)}"

    def answer(self, intent: str, *blocks) -> dict:
        return {"intent": intent, "blocks": list(blocks), "basis": self.basis}

    def ref(self, p: Project, meta: str | None = None) -> dict:
        cur = p.current_process
        st = display_status(p, self.today)
        default_meta = " · ".join(x for x in [cur.name if cur and p.is_active else None, f"Target {fmt_date(p.target_date)}"] if x)
        label = f"Overdue {overdue_days(p, self.today)} hari" if st == "overdue" else STATUSES[st]
        return {"code": p.code, "name": p.name, "customer": p.customer.name, "meta": meta or default_meta, "badge": {"label": label, "tone": STATUS_TONE[st]}}

    def match_projects(self, pool: list[Project]) -> tuple[list[Project], bool]:
        """Cari project yang disebut di pertanyaan (kode, nama, atau customer)."""
        scored = []
        for p in pool:
            score, name_hits = 0, 0
            if normalize(p.code) in self.q:
                score += 10
            cust = normalize(p.customer.name)
            cust_tokens = [t for t in cust.split() if len(t) >= 3]
            cust_hit = cust in self.q or any(t not in GENERIC and _word_in(self.q, t) for t in cust_tokens)
            if cust_hit:
                score += 2
            for t in normalize(p.name).split():
                if len(t) >= 3 and t not in GENERIC and t not in cust_tokens and _word_in(self.q, t):
                    name_hits += 1
            score += name_hits * 3
            scored.append((p, score, name_hits, cust_hit))
        best = max((s for _, s, _, _ in scored), default=0)
        if best == 0:
            return [], False
        top = [row for row in scored if row[1] == best]
        return [row[0] for row in top], all(row[2] == 0 and row[3] for row in top)

    def type_filter(self):
        if re.search(r"\bsubcont\b|\bsub cont\b", self.q):
            return "subcont"
        if re.search(r"new mold|newmold|mold baru", self.q):
            return "new_mold"
        return None

    def date_range(self):
        t = self.today
        if re.search(r"hari ini|today", self.q):
            return t, t, "hari ini"
        if re.search(r"minggu depan|pekan depan", self.q):
            s = add_days(start_of_week(t), 7)
            return s, add_days(s, 6), "minggu depan"
        if re.search(r"minggu ini|pekan ini|this week", self.q):
            return start_of_week(t), end_of_week(t), "minggu ini"
        if re.search(r"bulan ini|this month", self.q):
            return start_of_month(t), end_of_month(t), f"bulan ini ({fmt_month(t)})"
        return None


def _text(t: str) -> dict:
    return {"type": "text", "text": t}


def _projects(items: list[dict]) -> dict:
    return {"type": "projects", "items": items}


def _list(items: list[str]) -> dict:
    return {"type": "list", "items": items}


def _last_revision_label(p: Project, types: list[str]) -> str | None:
    best = None
    for d in Document.query.filter(Document.project_id == p.id, Document.type.in_(types)).all():
        v = d.latest
        if v and (best is None or v.uploaded_at > best[1]):
            best = (f"{DOC_TYPES[d.type]} {format_revision(v.revision)} ({DOC_STATUSES[v.status]})", v.uploaded_at)
    return best[0] if best else None


def _project_summary(c: _Q, p: Project) -> dict:
    cur = p.current_process
    st = display_status(p, c.today)
    status_text = f"Overdue {overdue_days(p, c.today)} hari ({STATUSES[p.status]})" if st == "overdue" else STATUSES[st]
    rows = [
        ["Project", f"{p.code} · {p.name}"],
        ["Customer", p.customer.name],
        ["Type", PROJECT_TYPES[p.type]],
        ["Current Process", cur.name if cur else "—"],
        ["Status", status_text],
        ["PIC Proses", user_name(cur.pic_id) if cur else "—"],
        ["Waiting For", p.waiting_for or "—"],
        ["Last Update", fmt_date(p.updated_at.date())],
        ["Last Revision", _last_revision_label(p, ["artwork", "drawing_3d", "drawing_2d"]) or "Belum ada dokumen revisi"],
        ["Next Action", f"{p.next_action} (due {fmt_date(p.next_action_due)})"],
        ["Target", fmt_date(p.target_date)],
    ]
    if p.status == "hold" and p.status_reason:
        rows.insert(5, ["Alasan Hold", p.status_reason])
    return {"type": "summary", "title": "PROJECT SUMMARY", "code": p.code, "rows": rows}


def _list_answer(c: _Q, intent: str, items: list[Project], headline, empty: str, meta=None) -> dict:
    if not items:
        return c.answer(intent, _text(empty))
    return c.answer(intent, _text(headline(len(items))), _projects([c.ref(p, meta(p) if meta else None) for p in items]))


def answer_question(user: User, question: str, today: date) -> dict:
    c = _Q(user, question, today)
    q = c.q
    ptype = c.type_filter()
    type_label = f" {PROJECT_TYPES[ptype]}" if ptype else ""

    def in_type(p):
        return not ptype or p.type == ptype

    if not q:
        return c.answer("empty", _text("Silakan ketik pertanyaan tentang project NPD."))

    # Assistant hanya membaca data (read-only).
    if re.match(r"^(tolong |mohon )?(ubah|ganti|hapus|update|set|tandai|setujui|approve|tolak|reject|pindahkan|tutup|selesaikan)\b", q):
        found, _ = c.match_projects(c.visible)
        blocks = [_text("Saya hanya membaca data dan tidak mengubah data project. Perubahan penting harus dilakukan langsung oleh user yang berwenang dengan konfirmasi eksplisit di halaman project.")]
        if found:
            blocks.append(_projects([c.ref(found[0], "Buka project untuk melakukan perubahan")]))
        return c.answer("mutation", *blocks)

    if re.match(r"^(halo|hai|hi|hello|help|bantuan|apa yang bisa|bisa apa)", q):
        return c.answer("help", _text(f"Halo {user.name.split()[0]}! Saya menjawab pertanyaan berdasarkan data project NPD yang dapat Anda akses — posisi project, approval, revisi dokumen, deadline, overdue, bottleneck, dan weekly report."), _list(SUGGESTED_QUESTIONS[:6]))

    if re.search(r"weekly|mingguan|laporan (npd|minggu)|report npd", q):
        r = weekly_report(c.visible, start_of_week(today), today)
        stats = [
            ("Total Project", r["total_project"]), ("New Project", r["new_project"]), ("Completed", r["completed"]),
            ("On Progress", r["on_progress"]), ("Waiting Customer", r["waiting_customer"]), ("Waiting Supplier", r["waiting_supplier"]),
            ("Overdue", r["overdue"]), ("Due Soon", r["due_soon"]),
        ]
        return c.answer(
            "weekly_report",
            _text(f"Weekly NPD Report periode {fmt_date(r['period_start'])} – {fmt_date(r['period_end'])}."),
            {"type": "stats", "items": [{"label": k, "value": str(v)} for k, v in stats]},
            {"type": "report", "title": "Weekly NPD Report", "text": weekly_report_text(r)},
        )

    mentioned, by_customer_only = c.match_projects(c.visible)
    out_of_scope = not mentioned and bool(c.match_projects(c.all_projects)[0])
    no_access = c.answer("no_access", _text("Saya tidak menemukan project tersebut dalam data yang dapat Anda akses."))

    # "Berapa kali Kymm melakukan revisi artwork?"
    if re.search(r"berapa kali", q) and re.search(r"revisi|reject|tolak|ditolak", q):
        if out_of_scope:
            return no_access
        _, atype, alabel = next((k for k in APPROVAL_KEYWORDS if re.search(k[0], q)), APPROVAL_KEYWORDS[4])
        pool = mentioned or [p for p in c.visible if in_type(p)]
        rows = []
        for p in pool:
            n = Approval.query.filter(Approval.project_id == p.id, Approval.type == atype, Approval.status.in_(["rejected", "revision_required"])).count()
            if mentioned or n:
                rows.append((p, n))
        if not rows:
            return c.answer("revision_count", _text(f"Tidak ada revisi {alabel} yang tercatat."))
        total = sum(n for _, n in rows)
        subject = rows[0][0].customer.name if by_customer_only else (rows[0][0].name if len(rows) == 1 else "Project terkait")
        return c.answer("revision_count", _text(f"{subject} tercatat {total} kali revisi {alabel} (approval Rejected / Revision Required)."),
                        _projects([c.ref(p, f"{n}× revisi {alabel}") for p, n in rows]))

    # "Artwork Facetology terakhir revisi berapa?"
    doc_kw = next((k for k in DOC_KEYWORDS if re.search(k[0], q)), None)
    if doc_kw and re.search(r"revisi|rev\b|versi|terakhir|terbaru", q) and "belum" not in q:
        if out_of_scope:
            return no_access
        if not mentioned:
            return c.answer("last_revision", _text('Sebutkan nama project atau customer, misalnya "Artwork Facetology terakhir revisi berapa?".'))
        lines = []
        for p in mentioned:
            docs = Document.query.filter(Document.project_id == p.id, Document.type.in_(doc_kw[1])).all()
            if not docs:
                lines.append(f"{p.name}: belum ada dokumen {doc_kw[2]}.")
                continue
            parts = []
            for d in docs:
                v = d.latest
                parts.append(f"{d.name} — {format_revision(v.revision)} ({DOC_STATUSES[v.status]}, {fmt_date(v.uploaded_at.date())}, {len(d.versions)} versi tersimpan)")
            lines.append(f"{p.name}: {'; '.join(parts)}")
        return c.answer("last_revision", _text(f"Revisi {doc_kw[2]} terakhir:"), _list(lines), _projects([c.ref(p) for p in mentioned]))

    if "belum" in q and "artwork" in q:
        items = [p for p in c.active if p.type == "subcont" and not Document.query.filter_by(project_id=p.id, type="artwork").first()]
        return _list_answer(c, "no_artwork", items, lambda n: f"{n} project Subcont aktif belum memiliki dokumen artwork:",
                            "Semua project Subcont aktif sudah memiliki artwork.",
                            lambda p: f"{p.current_process.name if p.current_process else '—'} · Drafter {user_name(p.drafter_id)}")

    if re.search(r"menunggu|waiting|tunggu", q) and re.search(r"customer|pelanggan|approval|persetujuan", q):
        items = [p for p in c.active if in_type(p) and p.status == "waiting_approval"]

        def meta(p):
            a = Approval.query.filter_by(project_id=p.id, status="pending").first()
            return f"{APPROVAL_TYPES[a.type]} · {a.revision} · menunggu sejak {fmt_date(a.requested_date)}" if a else (p.waiting_for or "")

        return _list_answer(c, "waiting_customer", items, lambda n: f"{n} project{type_label} sedang menunggu Customer / approval:",
                            f"Tidak ada project{type_label} yang sedang menunggu Customer.", meta)

    if re.search(r"menunggu|waiting|tunggu", q) and re.search(r"supplier|external|eksternal|vendor|mold maker", q):
        items = [p for p in c.active if in_type(p) and p.status == "waiting_external"]
        return _list_answer(c, "waiting_external", items, lambda n: f"{n} project{type_label} sedang menunggu pihak eksternal:",
                            "Tidak ada project yang menunggu pihak eksternal.", lambda p: p.waiting_for or "—")

    if re.search(r"overdue|terlambat|telat|lewat deadline|melewati", q):
        items = sorted([p for p in c.active if in_type(p) and is_overdue(p, today)], key=lambda p: -overdue_days(p, today))
        return _list_answer(c, "overdue", items, lambda n: f"{n} project{type_label} overdue:", f"Tidak ada project{type_label} yang overdue.",
                            lambda p: f"Terlambat {overdue_days(p, today)} hari · {p.current_process.name if p.current_process else '—'} · Next: {p.next_action}")

    if re.search(r"due soon|mendekati deadline|hampir (deadline|jatuh tempo)", q):
        items = [p for p in c.active if in_type(p) and is_due_soon(p, today, c.settings.due_soon_days)]
        return _list_answer(c, "due_soon", items, lambda n: f"{n} project due soon (≤ {c.settings.due_soon_days} hari):", "Tidak ada project yang due soon.",
                            lambda p: f"Target {fmt_date(p.target_date)} ({describe_due(p.target_date, today)})")

    if re.search(r"deadline|jatuh tempo|\bdue\b|tenggat", q):
        start, end, label = c.date_range() or (start_of_week(today), end_of_week(today), "minggu ini")
        lines = []
        for p in c.active:
            if not in_type(p):
                continue
            if start <= p.target_date <= end:
                lines.append((p.target_date, f"{fmt_date(p.target_date)} — Target project {p.code} {p.name}"))
            if start <= p.next_action_due <= end:
                lines.append((p.next_action_due, f"{fmt_date(p.next_action_due)} — Next action {p.code}: {p.next_action}"))
            cur = p.current_process
            if cur and start <= cur.planned_finish <= end and cur.planned_finish != p.next_action_due:
                lines.append((cur.planned_finish, f"{fmt_date(cur.planned_finish)} — Planned finish {cur.name} ({p.code})"))
        lines.sort(key=lambda x: x[0])
        blocks = [_text(f"Tidak ada deadline {label} ({fmt_date(start)} – {fmt_date(end)}).")] if not lines else [
            _text(f"Deadline {label} ({fmt_date(start)} – {fmt_date(end)}):"), _list([t for _, t in lines])]
        late = [p for p in c.active if in_type(p) and p.next_action_due < start]
        if late and start >= today:
            blocks += [_text(f"Catatan: {len(late)} next action sudah lewat due sebelum periode ini."),
                       _projects([c.ref(p, f"Next action {describe_due(p.next_action_due, today)}: {p.next_action}") for p in late])]
        return c.answer("deadlines", *blocks)

    if (re.search(r"paling lama|terlama|bottleneck|lama", q) and re.search(r"proses|process|tahap", q)) or "bottleneck" in q:
        a = compute_analytics([p for p in c.visible if in_type(p)], today)
        if not a["process_stats"]:
            return c.answer("bottleneck", _text(NOT_AVAILABLE))
        top = a["process_stats"][:5]
        b = a["bottleneck"]
        head = f"Process dengan rata-rata durasi terlama adalah {top[0]['label']}: {top[0]['avg_duration']} hari (plan {top[0]['avg_planned']} hari, {top[0]['samples']} sampel)."
        if b:
            head += f" Bottleneck terbesar terhadap plan: {b['label']} (+{max(0, round(b['avg_duration'] - b['avg_planned'], 1))} hari)."
        return c.answer("bottleneck", _text(head), _list([
            f"{i}. {s['label']} — rata-rata {s['avg_duration']} hari, maks {s['max_duration']} hari" + (f", {s['running']} sedang berjalan" if s["running"] else "")
            for i, s in enumerate(top, 1)
        ]))

    if re.search(r"berapa|jumlah|total", q) and re.search(r"project|projek|proyek", q):
        pool = [p for p in c.visible if in_type(p) and p.status != "cancelled"]
        label = f"project{type_label}"
        if re.search(r"berjalan|aktif|on progress|sedang|jalan", q):
            pool = [p for p in pool if p.is_active]
            label += " yang sedang berjalan"
        elif re.search(r"selesai|completed", q):
            pool = [p for p in pool if p.status == "completed"]
            label += " yang sudah selesai"
        rng = c.date_range()
        if rng:
            s, e, rlabel = rng
            pool = [p for p in pool if s <= p.created_at.date() <= e or s <= p.start_date <= e]
            label += f" yang dibuat {rlabel}"
        counts: dict[str, int] = {}
        for p in pool:
            k = STATUSES[display_status(p, today)]
            counts[k] = counts.get(k, 0) + 1
        blocks = [_text(f"Ada {len(pool)} {label}.")]
        if pool:
            blocks += [{"type": "stats", "items": [{"label": k, "value": str(v)} for k, v in counts.items()]}, _projects([c.ref(p) for p in pool])]
        return c.answer("count", *blocks)

    if re.search(r"follow.?up|tindak lanjut|perhatian|perlu di|butuh", q):
        rows = []
        for p in c.active:
            if not in_type(p) or p.status == "hold":
                continue
            view = build_view(p, today, c.settings)
            f = view.flags
            reasons = []
            if f["overdue"]:
                reasons.append(f"overdue {view.overdue_days} hari")
            if p.next_action_due < today:
                reasons.append(f"next action {describe_due(p.next_action_due, today)}")
            if f["due_soon"]:
                reasons.append("due soon")
            if f["waiting_approval"]:
                reasons.append("menunggu approval customer")
            if f["waiting_external"]:
                reasons.append(f"menunggu {p.waiting_for or 'external'}")
            if f["no_update"]:
                reasons.append(f"tidak ada update {days_since_update(p, today)} hari")
            if f["missing_document"]:
                reasons.append("dokumen wajib belum ada")
            if any(x.status == "problem" for x in p.processes):
                reasons.append("ada problem di proses")
            if reasons:
                score = (5 if f["overdue"] else 0) + (3 if p.next_action_due < today else 0) + (2 if f["due_soon"] else 0) + len(reasons)
                rows.append((score, p, reasons))
        if not rows:
            return c.answer("follow_up", _text("Tidak ada project yang membutuhkan follow-up saat ini."))
        rows.sort(key=lambda r: -r[0])
        return c.answer("follow_up", _text(f"{len(rows)} project membutuhkan follow-up (diurutkan dari yang paling mendesak):"),
                        _projects([c.ref(p, f"{' · '.join(reasons)} → {p.next_action}") for _, p, reasons in rows]))

    if re.search(r"\b(saya|aku)\b", q) and re.search(r"tugas|pekerjaan|project|kerjaan|action", q):
        items = [p for p in c.active if p.current_process and p.current_process.pic_id == user.id]
        return _list_answer(c, "my_work", items, lambda n: f"{n} project sedang menunggu tindakan Anda:", "Tidak ada proses aktif yang di-assign ke Anda.",
                            lambda p: f"{p.current_process.name} · {p.next_action} (due {fmt_date(p.next_action_due)})")

    if "approval" in q and re.search(r"pending|belum diputus|menunggu keputusan", q):
        ids = {p.id for p in c.visible}
        pending = [a for a in Approval.query.filter_by(status="pending").all() if a.project_id in ids]
        if not pending:
            return c.answer("approvals", _text("Tidak ada approval berstatus Pending."))
        return c.answer("approvals", _text(f"{len(pending)} approval berstatus {APPROVAL_STATUSES['pending']}:"),
                        _projects([c.ref(a.project, f"{a.code} · {APPROVAL_TYPES[a.type]} · {a.revision} · {a.approver_name}") for a in pending]))

    if re.search(r"\bhold\b|ditunda", q):
        items = [p for p in c.active if in_type(p) and p.status == "hold"]
        return _list_answer(c, "hold", items, lambda n: f"{n} project sedang Hold:", "Tidak ada project yang sedang Hold.", lambda p: p.status_reason or "—")

    if out_of_scope:
        return no_access
    if mentioned:
        if len(mentioned) == 1:
            return c.answer("project_summary", _project_summary(c, mentioned[0]))
        return c.answer("project_summary", _text(f"Ditemukan {len(mentioned)} project yang cocok:"), *[_project_summary(c, p) for p in mentioned])

    if re.search(r"summary|ringkasan|rangkum", q):
        return c.answer("summary_missing", _text("Sebutkan nama project, kode (mis. NPD-2026-001), atau customer yang ingin diringkas."))

    if ptype and re.search(r"apa saja|daftar|list|mana saja", q):
        items = [p for p in c.active if p.type == ptype]
        return _list_answer(c, "list", items, lambda n: f"{n} project {PROJECT_TYPES[ptype]} aktif:", f"Tidak ada project {PROJECT_TYPES[ptype]} aktif.")

    if re.search(r"priority|prioritas|urgent", q):
        items = [p for p in c.active if in_type(p) and p.priority in ("urgent", "high")]
        return _list_answer(c, "priority", items, lambda n: f"{n} project aktif dengan priority High/Urgent:", "Tidak ada project High/Urgent aktif.",
                            lambda p: f"Priority {PRIORITIES[p.priority]} · {p.current_process.name if p.current_process else '—'}")

    return c.answer("unknown", _text(NOT_AVAILABLE), _text("Coba tanyakan tentang posisi project, approval, revisi dokumen, deadline, overdue, bottleneck, atau weekly report."))


def summarize_project(user: User, project: Project, today: date) -> dict:
    """Ringkasan AI untuk satu project (tombol "Ringkasan AI" di halaman project)."""
    return answer_question(user, f"ringkasan {project.code}", today)
