"""Fixture pytest: aplikasi dengan database di memori."""
import pytest
from flask import Flask

from npd.config import TestConfig
from npd.extensions import db


@pytest.fixture
def app(tmp_path):
    """App minimal (tanpa halaman) untuk menguji logika bisnis."""
    app = Flask("npd-test")
    app.config.from_object(TestConfig)
    app.config["UPLOAD_DIR"] = tmp_path / "uploads"
    db.init_app(app)
    with app.app_context():
        from npd import models  # noqa: F401

        db.create_all()
        yield app
        db.session.remove()
