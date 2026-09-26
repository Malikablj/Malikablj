"""Konfigurasi aplikasi.

Semua nilai bisa diganti lewat environment variable, misalnya:
    NPD_SECRET_KEY=rahasia NPD_DATA_DIR=/srv/npd python app.py
"""
import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent


class Config:
    # Kunci untuk menandatangani cookie session. WAJIB diganti di production.
    SECRET_KEY = os.environ.get("NPD_SECRET_KEY", "dev-secret-ganti-di-production")

    # Folder data: berisi database SQLite dan file upload.
    DATA_DIR = Path(os.environ.get("NPD_DATA_DIR", BASE_DIR / "data"))
    SQLALCHEMY_DATABASE_URI = os.environ.get("NPD_DATABASE_URL", f"sqlite:///{DATA_DIR / 'npd.sqlite'}")
    UPLOAD_DIR = DATA_DIR / "uploads"

    # Batas ukuran upload dokumen (25 MB).
    MAX_CONTENT_LENGTH = 25 * 1024 * 1024

    # Isi database dengan data demo saat pertama kali dijalankan.
    SEED_DEMO_DATA = os.environ.get("NPD_SEED_DEMO", "1") == "1"


class TestConfig(Config):
    TESTING = True
    SQLALCHEMY_DATABASE_URI = "sqlite://"  # database di memori
    SECRET_KEY = "test"
    SEED_DEMO_DATA = False
    PASSWORD_HASH_METHOD = "pbkdf2:sha256:1000"  # hash ringan agar test cepat
