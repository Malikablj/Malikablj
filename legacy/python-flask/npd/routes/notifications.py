"""Notifikasi in-app (PRD §12)."""
from __future__ import annotations

from flask import Blueprint, g, redirect, render_template, request, url_for

from ..extensions import db
from ..models import Notification, Project
from ..services.notifications import mark_read

bp = Blueprint("notifications", __name__, url_prefix="/notifications")


@bp.route("")
def index():
    only_unread = request.args.get("filter") == "unread"
    query = Notification.query.filter_by(user_id=g.user.id)
    if only_unread:
        query = query.filter_by(read=False)
    items = query.order_by(Notification.created_at.desc()).limit(200).all()
    codes = {p.id: p.code for p in Project.query.filter(Project.id.in_({n.project_id for n in items if n.project_id})).all()}
    return render_template("notifications/index.html", items=items, codes=codes, only_unread=only_unread)


@bp.route("/<notification_id>/open", methods=["POST"])
def open_item(notification_id):
    """Tandai dibaca lalu buka project terkait."""
    n = Notification.query.filter_by(id=notification_id, user_id=g.user.id).first()
    mark_read(g.user.id, notification_id)
    db.session.commit()
    project = db.session.get(Project, n.project_id) if n and n.project_id else None
    return redirect(url_for("projects.detail", code=project.code) if project else url_for("notifications.index"))


@bp.route("/read-all", methods=["POST"])
def read_all():
    mark_read(g.user.id)
    db.session.commit()
    return redirect(url_for("notifications.index"))
