"""Fungsi & variabel yang bisa dipakai langsung di template Jinja2."""
from __future__ import annotations

import secrets
from urllib.parse import urlencode

from flask import Flask, g, request, session
from markupsafe import Markup

from . import constants as C
from .services import dates
from .services import permissions as perm


def csrf_token() -> str:
    """Token anti-CSRF: setiap form POST wajib menyertakannya (lihat routes/__init__.py)."""
    if "csrf" not in session:
        session["csrf"] = secrets.token_urlsafe(24)
    return session["csrf"]


def csrf_field() -> Markup:
    return Markup(f'<input type="hidden" name="csrf" value="{csrf_token()}">')


def url_with(**changes) -> str:
    """URL halaman saat ini dengan query string yang diubah, mis. url_with(tab='documents')."""
    args = request.args.to_dict()
    for key, value in changes.items():
        if value in (None, ""):
            args.pop(key, None)
        else:
            args[key] = value
    query = urlencode(args)
    return f"{request.path}?{query}" if query else request.path


# Ikon garis sederhana (SVG) agar tidak butuh library ikon.
ICONS = {
    "dashboard": '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    "folder": '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    "workflow": '<rect x="3" y="3" width="8" height="8" rx="2"/><path d="M7 11v4a2 2 0 0 0 2 2h4"/><rect x="13" y="13" width="8" height="8" rx="2"/>',
    "gantt": '<path d="M3 3v18h18"/><path d="M7 7h8M9 12h8M7 17h5"/>',
    "calendar": '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    "file": '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
    "check": '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    "chart": '<path d="M3 3v18h18"/><path d="M8 17V11M13 17V7M18 17v-4"/>',
    "sparkles": '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
    "settings": '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    "bell": '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    "search": '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
    "menu": '<path d="M4 6h16M4 12h16M4 18h16"/>',
    "logout": '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    "plus": '<path d="M12 5v14M5 12h14"/>',
    "download": '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
    "upload": '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
    "chevron-left": '<path d="m15 18-6-6 6-6"/>',
    "chevron-right": '<path d="m9 18 6-6-6-6"/>',
    "more": '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
}


def icon(name: str, cls: str = "icon") -> Markup:
    path = ICONS.get(name, "")
    return Markup(f'<svg class="{cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{path}</svg>')


def labels(codes, mapping: dict) -> list[str]:
    """['artwork', 'npr'] + DOC_TYPES -> ['Artwork', 'NPR'] (kode -> label)."""
    return [mapping.get(c, c) for c in codes or []]


def init_app(app: Flask) -> None:
    app.jinja_env.filters.update(
        labels=labels,
        date=dates.fmt_date,
        date_short=dates.fmt_date_short,
        datetime=dates.fmt_datetime,
        relative=dates.fmt_relative,
        rev=C.format_revision,
    )
    app.jinja_env.globals.update(
        C=C,
        perm=perm,
        csrf_field=csrf_field,
        csrf_token=csrf_token,
        url_with=url_with,
        icon=icon,
        describe_due=dates.describe_due,
        diff_days=dates.diff_days,
        fmt_month=dates.fmt_month,
    )

    @app.context_processor
    def inject_common():
        """Variabel yang tersedia di semua template."""
        user = getattr(g, "user", None)
        data = {"current_user": user, "today": dates.today()}
        if user:
            from .models import Approval, Notification

            data["unread_notifications"] = Notification.query.filter_by(user_id=user.id, read=False).count()
            pending = Approval.query.filter_by(status="pending").all()
            from .services.approvals import can_decide_now

            data["pending_approvals"] = sum(1 for a in pending if can_decide_now(user, a))
        return data
