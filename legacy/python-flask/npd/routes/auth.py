"""Login & logout."""
from flask import Blueprint, flash, redirect, render_template, request, session, url_for

from ..extensions import db
from ..models import User
from ..services.context import Ctx
from ..services.notifications import scan_deadlines
from .helpers import safe_next

bp = Blueprint("auth", __name__)


@bp.route("/login", methods=["GET", "POST"])
def login():
    errors, email = {}, ""
    if request.method == "POST":
        email = (request.form.get("email") or "").strip().lower()
        password = request.form.get("password") or ""
        if not email:
            errors["email"] = "Email wajib diisi."
        if not password:
            errors["password"] = "Password wajib diisi."
        if not errors:
            user = User.query.filter_by(email=email).first()
            if not user or not user.check_password(password):
                errors["form"] = "Email atau password salah."
            elif not user.active:
                errors["form"] = "Akun Anda dinonaktifkan. Hubungi Admin."
            else:
                csrf = session.get("csrf")
                session.clear()
                session["user_id"] = user.id
                if csrf:
                    session["csrf"] = csrf
                # Buat notifikasi berbasis waktu (deadline, overdue, dst.) setiap login.
                scan_deadlines(Ctx.for_user(user))
                db.session.commit()
                return redirect(safe_next(request.args.get("next"), url_for("dashboard.index")))
    accounts = User.query.filter_by(active=True).order_by(User.role, User.name).all()
    return render_template("auth/login.html", errors=errors, email=email, accounts=accounts)


@bp.route("/logout", methods=["POST"])
def logout():
    session.clear()
    flash("Anda telah keluar.", "success")
    return redirect(url_for("auth.login"))
