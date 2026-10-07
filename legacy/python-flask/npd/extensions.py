"""Objek bersama yang dipakai seluruh aplikasi (dibuat sekali, di-init di create_app)."""
from flask_sqlalchemy import SQLAlchemy

db = SQLAlchemy()
