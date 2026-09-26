"""Semua halaman (URL) aplikasi. Satu file per menu di sidebar.

register_routes() juga memasang:
- pengecekan login untuk semua halaman (kecuali /login dan file statis)
- proteksi CSRF untuk semua form POST
- halaman error 403/404
"""
from flask import Flask, abort, g, redirect, render_template, request, session, url_for

from ..extensions import db
from ..models import User


def register_routes(app: Flask) -> None:
    from . import (
        approvals, assistant, auth, calendar, dashboard, documents, gantt, notifications, process, projects, reports, settings, tracker,
    )

    modules = (auth, dashboard, projects, process, tracker, gantt, calendar, documents, approvals, reports, assistant, settings, notifications)
    for module in modules:
        app.register_blueprint(module.bp)

    @app.before_request
    def load_user_and_check():
        g.user = None
        user_id = session.get("user_id")
        if user_id:
            user = db.session.get(User, user_id)
            if user and user.active:
                g.user = user
            else:
                session.clear()
        if request.endpoint in ("auth.login", "static") or request.endpoint is None:
            pass
        elif g.user is None:
            return redirect(url_for("auth.login", next=request.full_path))
        if request.method == "POST" and not app.config.get("TESTING"):
            token = session.get("csrf")
            if not token or request.form.get("csrf") != token:
                abort(400, "Sesi form kedaluwarsa. Muat ulang halaman lalu coba lagi.")

    @app.errorhandler(403)
    def forbidden(error):
        if g.get("user") is None:
            return redirect(url_for("auth.login"))
        return render_template("error.html", code=403, title="Akses dibatasi", message=getattr(error, "description", "")), 403

    @app.errorhandler(404)
    def not_found(error):
        if g.get("user") is None:
            return redirect(url_for("auth.login"))
        return render_template("error.html", code=404, title="Halaman tidak ditemukan", message="Alamat yang Anda buka tidak tersedia."), 404

    @app.errorhandler(400)
    def bad_request(error):
        if g.get("user") is None:
            return redirect(url_for("auth.login"))
        return render_template("error.html", code=400, title="Permintaan tidak valid", message=getattr(error, "description", "")), 400
