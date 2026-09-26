"""Uji workflow engine, business rules, dan AI Assistant."""
from datetime import date, datetime

import pytest

from npd.extensions import db
from npd.models import Approval, Document, DocumentVersion, Project, User
from npd.services import approvals, documents, projects
from npd.services.assistant import answer_question
from npd.services.context import Ctx
from npd.services.dates import add_days
from npd.services.errors import AppError
from npd.services.metrics import display_status, is_overdue
from npd.services.seed import seed_demo_data, seed_master_data
from npd.services.workflow_engine import complete_process, finish_project, override_current_process

TODAY = date(2026, 9, 26)


def ctx(user_id, today=TODAY):
    return Ctx(actor=db.session.get(User, user_id), now=datetime.combine(today, datetime.min.time()), today=today)


def proc(project, key):
    return next(p for p in project.processes if p.key == key)


def new_subcont():
    return projects.create_project(ctx("usr_sales1"), {
        "type": "subcont", "customer_id": "cus_kymm", "name": "Test Tube", "product_name": "Tube 10 ml",
        "customer_request": "Tube test", "npd_pic_id": "usr_npd1", "sales_pic_id": "usr_sales1", "drafter_id": "usr_drafter1",
        "priority": "medium", "start_date": TODAY, "target_date": add_days(TODAY, 60),
    })


def upload(user, project, key, doc_type, name, mode="new", document_id=None):
    return documents.upload_document(ctx(user), project.id, proc(project, key).id, mode, file_name="file.pdf",
                                     content=b"%PDF-1.4 test", doc_type=doc_type, name=name, document_id=document_id)


def test_seed_builds_consistent_demo(app):
    seed_demo_data(TODAY)
    all_projects = Project.query.all()
    assert len(all_projects) == 13
    assert Project.query.filter_by(code="NPD-2026-001").first()
    for p in all_projects:
        if not p.is_active:
            continue
        # Setiap project aktif wajib punya current process, target, next action.
        assert p.current_process_id and p.next_action and p.target_date
        if p.status == "waiting_external":
            assert p.waiting_for
        if p.status == "waiting_approval":
            assert Approval.query.filter_by(project_id=p.id, status="pending").first()
    kymm = Project.query.filter_by(name="Kymm Here We Glow Pink").one()
    assert proc(kymm, "customer_artwork_approval").status == "current"
    assert kymm.status == "waiting_approval"
    artwork = Approval.query.filter_by(project_id=kymm.id, type="artwork").order_by(Approval.created_at).all()
    assert [a.status for a in artwork] == ["rejected", "rejected", "pending"]
    assert "Rev 02" in artwork[-1].revision
    assert Project.query.filter_by(status="completed").count() == 2
    overdue = [p for p in all_projects if is_overdue(p, TODAY)]
    assert len(overdue) >= 2 and display_status(overdue[0], TODAY) == "overdue"


def test_subcont_revision_loop_and_finish_rule(app):
    seed_master_data()
    p = new_subcont()
    assert p.code == "NPD-2026-001"
    npr = proc(p, "npr")
    with pytest.raises(AppError, match="dokumen wajib"):
        complete_process(ctx("usr_sales1"), npr.id, data={"request_number": "N1", "quantity": 1, "material": "PP"})
    upload("usr_sales1", p, "npr", "npr", "NPR")
    complete_process(ctx("usr_sales1"), npr.id, data={"request_number": "N1", "quantity": 1, "material": "PP"})
    assert p.current_process_id == proc(p, "npd_feedback").id

    # Drafter tidak boleh memutuskan NPD Feedback.
    with pytest.raises(AppError):
        complete_process(ctx("usr_drafter1"), proc(p, "npd_feedback").id, outcome_key="accepted")
    complete_process(ctx("usr_npd1"), proc(p, "npd_feedback").id, outcome_key="accepted", data={"feedback": "ok", "feasibility": "Feasible"})

    artwork = proc(p, "artwork")
    doc, _ = upload("usr_drafter1", p, "artwork", "artwork", "Artwork")
    complete_process(ctx("usr_drafter1"), artwork.id, data={"artwork_name": "A"})
    submit = proc(p, "sales_submit_artwork")
    complete_process(ctx("usr_sales1"), submit.id, data={"artwork_revision": doc.latest_version_id})
    assert p.status == "waiting_approval"
    approval = Approval.query.filter_by(project_id=p.id, type="artwork").one()
    assert approval.status == "pending" and approval.revision == "Artwork Rev 00"

    # Rejection wajib komentar & kembali ke Artwork (revision loop).
    with pytest.raises(AppError, match="Komentar"):
        approvals.decide_approval(ctx("usr_sales1"), approval.id, decision="rejected")
    approvals.decide_approval(ctx("usr_sales1"), approval.id, decision="rejected", comment="Warna salah")
    assert approval.status == "rejected"
    assert p.current_process_id == artwork.id and artwork.status == "revision"
    assert submit.status == "not_started"
    assert db.session.get(DocumentVersion, doc.latest_version_id).status == "rejected"
    with pytest.raises(AppError, match="dokumen wajib"):
        complete_process(ctx("usr_drafter1"), artwork.id)
    upload("usr_drafter1", p, "artwork", "artwork", "Artwork", mode="revision", document_id=doc.id)
    assert DocumentVersion.query.filter_by(document_id=doc.id).count() == 2  # revisi lama tetap tersimpan
    complete_process(ctx("usr_drafter1"), artwork.id)
    complete_process(ctx("usr_sales1"), submit.id, data={"artwork_revision": db.session.get(Document, doc.id).latest_version_id})
    second = Approval.query.filter_by(project_id=p.id, type="artwork", status="pending").one()
    assert second.revision == "Artwork Rev 01"
    approvals.decide_approval(ctx("usr_sales1"), second.id, decision="approved")
    assert p.current_process_id == proc(p, "trial_material_prep").id and p.status == "on_progress"

    # Finish ditolak selama mandatory process belum selesai.
    with pytest.raises(AppError) as err:
        finish_project(ctx("usr_npd1"), p.id)
    assert any("Trial Material Preparation" in d for d in err.value.details)
    override_current_process(ctx("usr_admin"), p.id, proc(p, "finish").id, "test")
    with pytest.raises(AppError, match="belum dapat diselesaikan"):
        complete_process(ctx("usr_npd1"), proc(p, "finish").id)


def test_status_business_rules(app):
    seed_master_data()
    p = new_subcont()
    with pytest.raises(AppError) as err:
        projects.update_status(ctx("usr_npd1"), p.id, "waiting_external")
    assert "Waiting For" in err.value.field_errors["waiting_for"]
    with pytest.raises(AppError, match="approval record"):
        projects.update_status(ctx("usr_npd1"), p.id, "waiting_approval")
    with pytest.raises(AppError):
        projects.update_status(ctx("usr_mgmt"), p.id, "hold", reason="x")
    projects.update_status(ctx("usr_npd1"), p.id, "hold", reason="Customer postpone")
    with pytest.raises(AppError, match="Hold"):
        complete_process(ctx("usr_sales1"), proc(p, "npr").id)
    projects.update_status(ctx("usr_npd1"), p.id, "on_progress")
    assert p.status == "on_progress"


def test_duplicate_project_rejected(app):
    seed_master_data()
    new_subcont()
    with pytest.raises(AppError, match="sudah ada"):
        new_subcont()


def test_new_mold_branch_and_t0_loop(app):
    seed_demo_data(TODAY)
    bot = Project.query.filter_by(name="Botanica Diffuser Cap").one()
    assert proc(bot, "masterbatch_dev").status == "skipped"
    assert proc(bot, "mold_correction").status == "completed"
    assert proc(bot, "mold_shipment").status == "current"
    assert bot.status == "waiting_external"
    t0 = Approval.query.filter_by(project_id=bot.id, type="t0").order_by(Approval.created_at).all()
    assert [a.status for a in t0] == ["rejected", "approved"]
    cushion = Project.query.filter_by(name="Aruna Cushion Case").one()
    assert cushion.new_masterbatch is True
    assert proc(cushion, "prototype_3d").status == "skipped"
    assert proc(cushion, "masterbatch_approval").status == "current"


def test_assistant_answers_from_data_and_scope(app):
    seed_demo_data(TODAY)
    admin = db.session.get(User, "usr_admin")
    overdue = answer_question(admin, "Project mana yang overdue?", TODAY)
    assert any(b["type"] == "projects" and b["items"] for b in overdue["blocks"])
    assert "Rev 02" in str(answer_question(admin, "Artwork Kymm Here We Glow Pink terakhir revisi berapa?", TODAY))
    drafter2 = db.session.get(User, "usr_drafter2")
    assert "Customer Artwork Approval" not in str(answer_question(drafter2, "Project Kymm Here We Glow Pink sekarang sampai mana?", TODAY))
    assert "Data tersebut belum tersedia di sistem" in str(answer_question(admin, "Berapa harga saham perusahaan?", TODAY))
