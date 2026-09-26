"""Uji halaman web (route + template) memakai Flask test client dan data demo."""
import io
import re
from datetime import date

import pytest

from npd import create_app
from npd.config import TestConfig
from npd.extensions import db
from npd.models import Approval, AssistantMessage, Document, Project
from npd.services.dates import add_days


@pytest.fixture
def web(tmp_path):
    class Cfg(TestConfig):
        SEED_DEMO_DATA = True
        DATA_DIR = tmp_path
        UPLOAD_DIR = tmp_path / "uploads"

    app = create_app(Cfg)
    with app.app_context():
        yield app
        db.session.remove()


def login(client, email="andi@npd.local", password="demo123"):
    return client.post("/login", data={"email": email, "password": password})


def text(response) -> str:
    return response.get_data(as_text=True)


def test_login_required_and_login_flow(web):
    client = web.test_client()
    r = client.get("/projects")
    assert r.status_code == 302 and "/login" in r.headers["Location"]
    r = login(client, password="salah")
    assert r.status_code == 200 and "Email atau password salah" in text(r)
    r = login(client)
    assert r.status_code == 302
    r = client.get("/")
    assert r.status_code == 200 and "Attention Required" in text(r)
    client.post("/logout")
    assert client.get("/").status_code == 302


def test_all_main_pages_render(web):
    client = web.test_client()
    login(client)
    project = Project.query.filter_by(name="Kymm Here We Glow Pink").one()
    pages = ["/", "/projects", "/projects/new", f"/projects/{project.code}", f"/projects/{project.code}?tab=documents",
             f"/projects/{project.code}?tab=activity", "/tracker", "/gantt", "/calendar", "/documents", "/approvals",
             "/reports", "/reports?tab=analytics", "/assistant", "/settings", "/settings?tab=workflow", "/notifications"]
    for url in pages:
        r = client.get(url)
        assert r.status_code == 200, url
    detail = text(client.get(f"/projects/{project.code}"))
    assert "Customer Artwork Approval" in detail and "Catat Keputusan Customer" in detail


def test_create_project_and_complete_first_process(web):
    client = web.test_client()
    login(client, "sari@npd.local")  # Admin Sales
    today = date.today()
    form = {
        "type": "subcont", "customer_id": "cus_kymm", "name": "Tube Uji Route", "product_name": "Tube 30 ml",
        "customer_request": "Tube baru warna hijau", "npd_pic_id": "usr_npd1", "sales_pic_id": "usr_sales1", "drafter_id": "usr_drafter1",
        "priority": "high", "start_date": today.isoformat(), "target_date": add_days(today, 60).isoformat(),
    }
    r = client.post("/projects/new", data={**form, "name": ""})
    assert r.status_code == 200 and "Project Name wajib diisi" in text(r)
    r = client.post("/projects/new", data=form)
    assert r.status_code == 302
    project = Project.query.filter_by(name="Tube Uji Route").one()
    assert re.fullmatch(r"NPD-\d{4}-\d{3}", project.code)
    npr = project.current_process
    assert npr.key == "npr"

    complete_url = f"/projects/{project.code}/process/{npr.id}/complete"
    fields = {"action": "complete", "request_number": "NPR-T-001", "quantity": "5000", "material": "PE"}
    r = client.post(complete_url, data=fields)
    assert r.status_code == 200 and "Upload dokumen wajib" in text(r)  # dokumen NPR wajib

    r = client.post(f"/documents/upload/{project.code}", data={
        "process": npr.id, "mode": "new", "type": "npr", "name": "NPR Tube Uji",
        "file": (io.BytesIO(b"%PDF-1.4 npr"), "npr.pdf"),
    }, content_type="multipart/form-data")
    assert r.status_code == 302
    r = client.post(complete_url, data=fields)
    assert r.status_code == 302
    db.session.expire_all()
    project = db.session.get(Project, project.id)
    assert project.current_process.key == "npd_feedback"
    assert Approval.query.filter_by(project_id=project.id, status="pending", type="npr").count() == 1


def test_role_permissions(web):
    client = web.test_client()
    login(client, "bambang@npd.local")  # Management: read-only
    assert client.get("/projects/new").status_code == 403
    assert client.get("/settings?tab=users").status_code == 403
    project = Project.query.filter_by(name="Kymm Here We Glow Pink").one()
    r = client.get(f"/projects/{project.code}/process/{project.current_process_id}/complete")
    assert r.status_code == 403

    sales = web.test_client()
    login(sales, "sari@npd.local")
    listing = text(sales.get("/projects"))
    own = Project.query.filter_by(sales_pic_id="usr_sales1").all()
    other = Project.query.filter(Project.sales_pic_id != "usr_sales1").first()
    assert all(p.code in listing for p in own)
    assert other.code not in listing
    assert sales.get(f"/projects/{other.code}").status_code == 403


def test_document_revision_and_download(web):
    client = web.test_client()
    login(client)
    doc = next(d for d in Document.query.all() if d.type == "artwork")
    before = len(doc.versions)
    r = client.post(f"/documents/upload/{doc.project.code}", data={
        "process": doc.process_id, "mode": "revision", "document": doc.id, "note": "Revisi warna",
        "file": (io.BytesIO(b"\x89PNG test"), "artwork.png"),
    }, content_type="multipart/form-data")
    assert r.status_code == 302
    db.session.expire_all()
    doc = db.session.get(Document, doc.id)
    assert len(doc.versions) == before + 1
    assert [v.status for v in doc.versions].count("current") == 1
    r = client.get(f"/documents/file/{doc.latest_version_id}?download=1")
    assert r.status_code == 200 and r.data == b"\x89PNG test"
    assert "attachment" in r.headers["Content-Disposition"]


def test_exports_and_assistant(web):
    client = web.test_client()
    login(client)
    for url in ("/projects/export.xlsx", "/reports/weekly.xlsx", "/reports/analytics.xlsx"):
        r = client.get(url)
        assert r.status_code == 200 and r.data[:2] == b"PK", url
    assert "WEEKLY NPD REPORT" in text(client.get("/reports/weekly.txt"))
    r = client.post("/assistant", data={"question": "Project mana yang overdue?"})
    assert r.status_code == 302
    message = AssistantMessage.query.one()
    assert message.answer["intent"] == "overdue"
    assert "Project mana yang overdue?" in text(client.get("/assistant"))


def test_csrf_protection(tmp_path):
    class Cfg(TestConfig):
        TESTING = False
        SEED_DEMO_DATA = True
        DATA_DIR = tmp_path
        UPLOAD_DIR = tmp_path / "uploads"

    app = create_app(Cfg)
    client = app.test_client()
    page = text(client.get("/login"))
    token = re.search(r'name="csrf" value="([^"]+)"', page).group(1)
    r = client.post("/login", data={"email": "andi@npd.local", "password": "demo123", "csrf": token})
    assert r.status_code == 302
    with app.app_context():
        code = Project.query.first().code
    r = client.post(f"/projects/{code}/comment", data={"body": "tanpa token"})
    assert r.status_code == 400
    r = client.post(f"/projects/{code}/comment", data={"body": "dengan token", "csrf": token})
    assert r.status_code == 302


def test_redirect_targets_stay_inside_app(web):
    client = web.test_client()
    r = client.post("/login?next=https://evil.example/", data={"email": "andi@npd.local", "password": "demo123"})
    assert r.headers["Location"] == "/"
    code = Project.query.first().code
    page = text(client.get(f"/documents/upload/{code}?next=javascript:alert(1)"))
    assert "javascript:" not in page
