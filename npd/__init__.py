"""NPD Project Control — aplikasi Flask.

create_app() menyiapkan konfigurasi, database, template helper, dan mendaftarkan
semua halaman (blueprint) dari folder routes/.
"""
from __future__ import annotations

from flask import Flask

from .config import Config
from .extensions import db


def create_app(config_class=Config) -> Flask:
    app = Flask(__name__)
    app.config.from_object(config_class)

    db.init_app(app)

    from . import template_helpers
    from .routes import register_routes

    template_helpers.init_app(app)
    register_routes(app)

    with app.app_context():
        _prepare_database(app)
    return app


def _prepare_database(app: Flask) -> None:
    """Buat tabel jika belum ada, lalu isi data demo pada database kosong."""
    from . import models  # noqa: F401  (mendaftarkan semua tabel)
    from .models import User
    from .services.seed import seed_demo_data

    if not app.config.get("TESTING"):
        app.config["DATA_DIR"].mkdir(parents=True, exist_ok=True)
        app.config["UPLOAD_DIR"].mkdir(parents=True, exist_ok=True)
    db.create_all()
    if app.config.get("SEED_DEMO_DATA") and User.query.count() == 0:
        seed_demo_data()
