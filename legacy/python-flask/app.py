"""Jalankan aplikasi:  python app.py  lalu buka http://127.0.0.1:5000

Database SQLite dibuat otomatis di folder data/ dan diisi data demo saat pertama kali dijalankan.
Login demo: andi@npd.local (Admin) / demo123 — akun lain tampil di halaman login.
"""
import os

from npd import create_app

app = create_app()

if __name__ == "__main__":
    app.run(host=os.environ.get("NPD_HOST", "127.0.0.1"), port=int(os.environ.get("NPD_PORT", "5000")), debug=os.environ.get("NPD_DEBUG") == "1")
