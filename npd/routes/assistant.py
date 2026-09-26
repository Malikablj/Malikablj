"""AI Assistant: tanya jawab berbasis data project (PRD §13). Riwayat chat disimpan per user."""
from __future__ import annotations

from flask import Blueprint, g, redirect, render_template, request, url_for

from ..extensions import db
from ..models import AssistantMessage
from ..services.assistant import SUGGESTED_QUESTIONS, answer_question
from .helpers import ctx

bp = Blueprint("assistant", __name__, url_prefix="/assistant")


@bp.route("", methods=["GET", "POST"])
def index():
    if request.method == "POST":
        question = (request.form.get("question") or "").strip()[:500]
        if question:
            c = ctx()
            db.session.add(AssistantMessage(user_id=g.user.id, question=question, answer=answer_question(g.user, question, c.today), created_at=c.now))
            db.session.commit()
        return redirect(url_for("assistant.index") + "#latest")
    history = AssistantMessage.query.filter_by(user_id=g.user.id).order_by(AssistantMessage.id.desc()).limit(20).all()[::-1]
    return render_template("assistant/index.html", history=history, suggestions=SUGGESTED_QUESTIONS)


@bp.route("/clear", methods=["POST"])
def clear():
    AssistantMessage.query.filter_by(user_id=g.user.id).delete()
    db.session.commit()
    return redirect(url_for("assistant.index"))
