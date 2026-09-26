"""Data demo: 11 user (semua role), 7 customer, 13 project.

Setiap project dibuat dengan MENJALANKAN aksi yang sama seperti user (buat project,
upload dokumen, selesaikan proses, approval ditolak, dst.) melalui workflow engine,
relatif terhadap tanggal hari ini. Hasilnya: proses, approval, revisi dokumen, record,
dan activity history selalu konsisten.

Password semua akun demo: demo123
"""
from __future__ import annotations

from datetime import date, datetime, time, timedelta

from ..extensions import db
from ..models import Document, Notification, ProcessRecord, Project, Setting, User, Customer, WorkflowTemplate
from ..workflows import default_workflows
from . import admin, documents, projects
from .context import Ctx
from .dates import add_days
from .notifications import scan_deadlines
from .workflow_engine import complete_process, doc_revision_options, prefill_data, set_problem

DEMO_PASSWORD = "demo123"

USERS = [
    ("usr_admin", "Andi Pratama", "andi@npd.local", "admin", "System Administrator"),
    ("usr_sales1", "Sari Wulandari", "sari@npd.local", "admin_sales", "Admin Sales"),
    ("usr_sales2", "Budi Santoso", "budi@npd.local", "admin_sales", "Admin Sales"),
    ("usr_npd1", "Rizky Hidayat", "rizky@npd.local", "npd_staff", "NPD Engineer"),
    ("usr_npd2", "Maya Putri", "maya@npd.local", "npd_staff", "NPD Engineer"),
    ("usr_drafter1", "Dimas Saputra", "dimas@npd.local", "drafter", "Drafter NPD"),
    ("usr_drafter2", "Nadia Kusuma", "nadia@npd.local", "drafter", "Drafter NPD"),
    ("usr_purch", "Hendra Wijaya", "hendra@npd.local", "purchasing", "Purchasing Officer"),
    ("usr_prod", "Agus Setiawan", "agus@npd.local", "production", "Production Supervisor"),
    ("usr_qa", "Lestari Ayu", "lestari@npd.local", "quality", "QA Engineer"),
    ("usr_mgmt", "Bambang Sutrisno", "bambang@npd.local", "management", "Plant Manager"),
]

CUSTOMERS = [
    ("cus_kymm", "KYM", "Kymm", "Rara (Purchasing)"),
    ("cus_facetology", "FCT", "Facetology", "Dinda (R&D Packaging)"),
    ("cus_aruna", "ARN", "Aruna Beauty", "Mega (Brand Manager)"),
    ("cus_glowlab", "GLW", "Glowlab Indonesia", "Yoga (Procurement)"),
    ("cus_natura", "NTC", "Natura Care", "Fitri (QA Supplier)"),
    ("cus_sinar", "SNR", "Sinar Kosmetika", "Hadi (Purchasing)"),
    ("cus_botanica", "BTH", "Botanica Home", "Lina (Product Dev)"),
]

U = {
    "admin": "usr_admin", "sales1": "usr_sales1", "sales2": "usr_sales2", "npd1": "usr_npd1", "npd2": "usr_npd2",
    "drafter1": "usr_drafter1", "drafter2": "usr_drafter2", "purch": "usr_purch", "prod": "usr_prod", "qa": "usr_qa",
}

FILE_EXT = {"artwork": "svg", "drawing_3d": "svg", "drawing_2d": "svg", "mold_drawing": "svg", "trial_photo": "svg", "trial_video": "mp4"}


class Scenario:
    """Skenario satu project: daftar aksi (hari relatif terhadap hari ini, pelaku, fungsi)."""

    def __init__(self, **info):
        self.info = info
        self.actions = []
        self.project: Project | None = None

    def _push(self, at, actor, fn):
        self.actions.append((at, actor, len(self.actions), fn))
        return self

    def _proc(self, key):
        return next(p for p in self.project.processes if p.key == key)

    def create(self):
        info = self.info

        def run(ctx):
            values = {k: v for k, v in info.items() if k not in ("start", "target", "creator")}
            values["start_date"] = ctx.today
            values["target_date"] = add_days(ctx.today, info["target"] - info["start"])
            self.project = projects.create_project(ctx, values)

        return self._push(info["start"], info["creator"], run)

    def upload(self, at, actor, process_key, doc_type, name, revision_of=None, note=None):
        def run(ctx):
            proc = self._proc(process_key)
            existing = Document.query.filter_by(project_id=self.project.id, name=revision_of).first() if revision_of else None
            rev = len(existing.versions) if existing else 0
            base = "_".join("".join(ch if ch.isalnum() else " " for ch in name).split())
            documents.upload_document(
                ctx, self.project.id, proc.id, "revision" if existing else "new",
                file_name=f"{self.project.code}_{base}_Rev{rev:02d}.{FILE_EXT.get(doc_type, 'pdf')}", content=None,
                doc_type=doc_type, name=name, document_id=existing.id if existing else None, note=note, stored_name="seed",
            )

        return self._push(at, actor, run)

    def complete(self, at, actor, process_key, outcome=None, comment=None, data=None, target=None):
        def run(ctx):
            proc = self._proc(process_key)
            values = {**prefill_data(self.project, proc, ctx.today), **(data or {})}
            for f in proc.fields or []:
                if not f.get("required") or values.get(f["key"]) not in (None, ""):
                    continue
                if f["type"] == "number":
                    values[f["key"]] = 1000
                elif f["type"] == "date":
                    values[f["key"]] = ctx.today.isoformat()
                elif f["type"] == "select":
                    values[f["key"]] = f["options"][0]
                elif f["type"] == "doc_revision":
                    options = doc_revision_options(self.project.id, f)
                    values[f["key"]] = options[0]["version_id"] if options else None
                elif f["type"] == "user":
                    values[f["key"]] = self.project.npd_pic_id
                else:
                    values[f["key"]] = f"{f['label']} — {self.project.product_name}"
            complete_process(ctx, proc.id, outcome_key=outcome, comment=comment, data=values, target_key=target)

        return self._push(at, actor, run)

    def problem(self, at, actor, process_key, note):
        return self._push(at, actor, lambda ctx: set_problem(ctx, self._proc(process_key).id, True, note))

    def next_action(self, at, actor, text, due_offset):
        return self._push(at, actor, lambda ctx: projects.update_next_action(
            ctx, self.project.id, text, add_days(ctx.today, due_offset - at), waiting_for=self.project.waiting_for))

    def status(self, at, actor, status, reason=None):
        return self._push(at, actor, lambda ctx: projects.update_status(ctx, self.project.id, status, reason=reason))

    def comment(self, at, actor, body):
        return self._push(at, actor, lambda ctx: projects.add_comment(ctx, self.project.id, body))

    def purchasing(self, at, actor, status):
        def run(ctx):
            rec = ProcessRecord.query.filter_by(project_id=self.project.id, record_type="material_request").order_by(ProcessRecord.created_at.desc()).first()
            if rec:
                projects.update_purchasing_status(ctx, rec.id, status)

        return self._push(at, actor, run)

    def event(self, at, actor, title, category, date_offset, time_=None):
        return self._push(at, actor, lambda ctx: admin.save_event(ctx, {
            "title": title, "category": category, "date": add_days(ctx.today, date_offset - at).isoformat(), "time": time_, "project_id": self.project.id}))


def subcont_front(s, crew, t, artwork_name, rejections=(), approve=False):
    sales, npd, drafter = crew
    s.upload(t, sales, "npr", "npr", "NPR Document")
    s.complete(t + 1, sales, "npr", data={"request_number": f"NPR-{1000 - t}"})
    s.complete(t + 3, npd, "npd_feedback", outcome="accepted", data={"feedback": "Feasible dengan mesin & mold existing.", "feasibility": "Feasible"})
    s.upload(t + 6, drafter, "artwork", "artwork", artwork_name)
    s.complete(t + 7, drafter, "artwork", data={"artwork_name": artwork_name})
    s.complete(t + 8, sales, "sales_submit_artwork")
    cursor = t + 8
    for reason in rejections:
        s.complete(cursor + 4, sales, "customer_artwork_approval", outcome="not_approved", comment=reason)
        s.upload(cursor + 7, drafter, "artwork", "artwork", artwork_name, revision_of=artwork_name, note=f"Revisi: {reason}")
        s.complete(cursor + 8, drafter, "artwork")
        s.complete(cursor + 9, sales, "sales_submit_artwork")
        cursor += 9
    if approve:
        s.complete(cursor + 3, sales, "customer_artwork_approval", outcome="approved", comment="Artwork disetujui customer.")
    return cursor + 3


def subcont_trial(s, crew, t):
    sales, npd, _ = crew
    s.upload(t, npd, "trial_material_prep", "material_spec", "Material Specification")
    s.complete(t + 2, npd, "trial_material_prep", data={"material": "PP Random Copolymer", "material_type": "PP"})
    s.upload(t + 5, "qa", "trial_evaluation", "trial_report", "Trial Report")
    s.complete(t + 6, npd, "trial_evaluation", data={"machine": "Injection 150T #3", "trial_result": "OK", "tests": ["Visual", "Dimension", "Leak", "Drop"]})
    s.complete(t + 7, sales, "sales_submit_trial")
    return t + 7


def subcont_back(s, crew, t):
    sales, npd, _ = crew
    s.complete(t, sales, "customer_trial_approval", outcome="approved", comment="Hasil trial diterima.")
    s.upload(t + 1, npd, "bulk_material_request", "material_request", "Material Request FORM-NPD-12")
    s.complete(t + 2, npd, "bulk_material_request", data={"mr_number": f"MR-{2600 - t}", "material": "PP Homopolymer", "supplier": "PT Chandra Asri", "quantity": 2500})
    s.purchasing(t + 4, "purch", "po_issued")
    s.purchasing(t + 8, "purch", "in_transit")
    return t + 8


def build_scenarios() -> list[Scenario]:
    out = []

    # 1 — Kymm Here We Glow Pink: artwork ditolak 2x (Rev 00 → Rev 01 → Rev 02), menunggu customer.
    s = Scenario(type="subcont", customer_id="cus_kymm", name="Kymm Here We Glow Pink", product_name="Lip Tint Tube 8 ml",
                 customer_request="Tube lip tint 8 ml warna pink glossy dengan hot stamping logo silver.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales1"], drafter_id=U["drafter1"], supplier="PT Warna Plastindo",
                 priority="high", start=-40, target=9, creator="sales1").create()
    subcont_front(s, ("sales1", "npd1", "drafter1"), -40, "Artwork Kymm Here We Glow Pink",
                  rejections=["Warna pink terlalu gelap, minta lebih soft.", "Posisi logo terlalu ke bawah, naikkan 5 mm."])
    s.next_action(-4, "sales1", "Follow up customer approval", -1)
    s.comment(-4, "sales1", "Rev 02 sudah dikirim via email ke Rara, menunggu konfirmasi brand team.")
    out.append(s)

    # 2 — Facetology sunscreen: overdue, trial bermasalah (leak test NG).
    s = Scenario(type="subcont", customer_id="cus_facetology", name="Facetology Triple Care Sunscreen Tube", product_name="Sunscreen Tube 50 ml",
                 customer_request="Tube 50 ml dengan flip-top cap, printing offset 4 warna.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales1"], drafter_id=U["drafter1"], supplier="PT Tubindo Jaya",
                 priority="urgent", start=-55, target=-3, creator="sales1").create()
    t = subcont_front(s, ("sales1", "npd2", "drafter1"), -55, "Artwork Facetology Sunscreen", rejections=["Font ingredient list terlalu kecil."], approve=True)
    s.upload(t + 1, "npd2", "trial_material_prep", "material_spec", "Material Specification")
    s.complete(t + 3, "npd2", "trial_material_prep", data={"material": "LDPE/HDPE blend", "material_type": "LDPE"})
    s.upload(t + 7, "qa", "trial_evaluation", "trial_report", "Trial Report")
    s.problem(t + 8, "qa", "trial_evaluation", "Leak test NG pada 3 dari 50 sampel — seal cap kurang rapat.")
    s.next_action(t + 8, "npd2", "Adjust parameter sealing & re-trial leak test", t + 14)
    s.event(t + 8, "npd2", "Review hasil leak test dengan QA", "meeting", 2, "10:00")
    s.next_action(-4, "npd2", "Re-trial leak test setelah adjust parameter sealing", 1)
    s.comment(-4, "qa", "Sampel re-trial siap, menunggu slot mesin line tube #1.")
    out.append(s)

    # 3 — Aruna serum: trial ditolak sekali, menunggu approval trial ke-2.
    s = Scenario(type="subcont", customer_id="cus_aruna", name="Aruna Serum Dropper Bottle", product_name="Dropper Bottle 30 ml",
                 customer_request="Botol serum 30 ml frosted dengan dropper, silk screen 1 warna.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales2"], drafter_id=U["drafter2"], supplier="PT Kaca Prima",
                 priority="medium", start=-70, target=20, creator="sales2").create()
    s.upload(-70, "sales2", "npr", "npr", "NPR Document").complete(-69, "sales2", "npr")
    s.complete(-66, "npd1", "npd_feedback", outcome="accepted", data={"feedback": "Feasible.", "feasibility": "Feasible"})
    s.upload(-62, "drafter2", "artwork", "artwork", "Artwork Aruna Serum").complete(-61, "drafter2", "artwork", data={"artwork_name": "Artwork Aruna Serum"})
    s.complete(-60, "sales2", "sales_submit_artwork").complete(-55, "sales2", "customer_artwork_approval", outcome="approved")
    s.upload(-52, "npd1", "trial_material_prep", "material_spec", "Material Specification")
    s.complete(-50, "npd1", "trial_material_prep", data={"material": "PETG", "material_type": "PETG"})
    s.upload(-45, "qa", "trial_evaluation", "trial_report", "Trial Report TR-01")
    s.complete(-44, "npd1", "trial_evaluation", data={"machine": "ISBM 2 cavity", "trial_result": "OK dengan catatan", "problem": "Neck finish mendekati batas toleransi"})
    s.complete(-43, "sales2", "sales_submit_trial")
    s.complete(-36, "sales2", "customer_trial_approval", outcome="not_approved", comment="Dimensi neck di luar toleransi, dropper tidak rapat.")
    s.upload(-28, "qa", "trial_evaluation", "trial_report", "Trial Report TR-02")
    s.complete(-27, "npd1", "trial_evaluation", data={"machine": "ISBM 2 cavity", "trial_result": "OK", "tests": ["Dimension", "Leak", "Assembly"]})
    s.complete(-26, "sales2", "sales_submit_trial")
    s.next_action(-26, "sales2", "Follow up approval hasil trial TR-02", 2)
    s.comment(-3, "sales2", "Customer minta sampel tambahan 20 pcs untuk uji kompatibilitas serum.")
    out.append(s)

    # 4 — Glowlab body mist: material preparation, menunggu supplier, due soon.
    s = Scenario(type="subcont", customer_id="cus_glowlab", name="Glowlab Body Mist Bottle", product_name="Spray Bottle 100 ml",
                 customer_request="Botol PET 100 ml clear dengan fine mist sprayer.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales2"], drafter_id=U["drafter1"], supplier="PT Polyprima",
                 priority="high", start=-90, target=2, creator="sales2").create()
    s.upload(-90, "sales2", "npr", "npr", "NPR Document").complete(-89, "sales2", "npr")
    s.complete(-86, "npd2", "npd_feedback", outcome="accepted", data={"feedback": "Feasible.", "feasibility": "Feasible"})
    s.upload(-80, "drafter1", "artwork", "artwork", "Artwork Glowlab Body Mist").complete(-79, "drafter1", "artwork", data={"artwork_name": "Artwork Glowlab Body Mist"})
    s.complete(-78, "sales2", "sales_submit_artwork").complete(-72, "sales2", "customer_artwork_approval", outcome="approved")
    crew = ("sales2", "npd2", "drafter1")
    t = subcont_back(s, crew, subcont_trial(s, crew, -68) + 5)
    s.next_action(t, "npd2", "Follow up kedatangan material ke Purchasing", 1)
    s.comment(-1, "purch", "ETA material dari supplier H+2, dokumen COA menyusul.")
    out.append(s)

    # 5 — Natura hand cream: Completed.
    s = Scenario(type="subcont", customer_id="cus_natura", name="Natura Care Hand Cream Tube", product_name="Tube 75 ml",
                 customer_request="Tube 75 ml matte dengan screw cap.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales1"], drafter_id=U["drafter2"], supplier="PT Tubindo Jaya",
                 priority="low", start=-120, target=-10, creator="sales1").create()
    crew = ("sales1", "npd1", "drafter2")
    t = subcont_back(s, crew, subcont_trial(s, crew, subcont_front(s, crew, -120, "Artwork Natura Hand Cream", approve=True) + 3) + 6)
    s.upload(t + 3, "npd1", "material_preparation", "coa", "COA Material Batch 2409")
    s.complete(t + 4, "npd1", "material_preparation", data={"material_received": "PP Homopolymer", "quantity": 2500})
    s.upload(t + 8, "qa", "validation_mass_production", "validation_report", "Validation Report")
    s.complete(t + 9, "qa", "validation_mass_production", outcome="pass", data={"machine": "Tube line #2", "production_quantity": 20000})
    s.complete(t + 10, "npd1", "finish", comment="Semua proses selesai, siap mass production.")
    out.append(s)

    # 6 — Sinar compact powder: baru mulai, NPD Feedback.
    s = Scenario(type="subcont", customer_id="cus_sinar", name="Sinar Compact Powder Case", product_name="Compact Case 12 g",
                 customer_request="Compact powder case dengan mirror dan magnet closure.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales2"], drafter_id=U["drafter2"], supplier=None,
                 priority="medium", start=-3, target=60, creator="sales2").create()
    s.upload(-3, "sales2", "npr", "npr", "NPR Document").complete(-2, "sales2", "npr")
    out.append(s)

    # 7 — Kymm lip serum: artwork belum diupload (Missing Mandatory Document, No Update).
    s = Scenario(type="subcont", customer_id="cus_kymm", name="Kymm Lip Serum Tube", product_name="Lip Serum Tube 10 ml",
                 customer_request="Tube 10 ml dengan applicator doe foot, warna nude.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales1"], drafter_id=U["drafter2"], supplier=None,
                 priority="medium", start=-20, target=40, creator="sales1").create()
    s.upload(-20, "sales1", "npr", "npr", "NPR Document").complete(-19, "sales1", "npr")
    s.complete(-15, "npd1", "npd_feedback", outcome="accepted", data={"feedback": "Feasible, gunakan applicator existing.", "feasibility": "Feasible dengan catatan"})
    s.next_action(-12, "npd1", "Drafter menyiapkan artwork Rev 00", -9)
    out.append(s)

    # 8 — Facetology toner: Hold.
    s = Scenario(type="subcont", customer_id="cus_facetology", name="Facetology Toner Bottle", product_name="Bottle 150 ml",
                 customer_request="Botol toner 150 ml PET clear, disc cap.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales1"], drafter_id=U["drafter1"], supplier=None,
                 priority="medium", start=-60, target=15, creator="sales1").create()
    subcont_front(s, ("sales1", "npd2", "drafter1"), -60, "Artwork Facetology Toner", approve=True)
    s.status(-30, "npd2", "hold", "Customer menunda launching ke Q1 — menunggu konfirmasi jadwal baru.")
    out.append(s)

    # 9 — Botanica diffuser cap (New Mold): 3D ditolak sekali, T0 NG → Mold Correction → T0 OK, Mold Shipment.
    s = Scenario(type="new_mold", customer_id="cus_botanica", name="Botanica Diffuser Cap", product_name="Diffuser Cap 28 mm",
                 customer_request="Cap diffuser 28 mm dengan tekstur kayu, 4 cavity.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales2"], drafter_id=U["drafter1"], supplier="PT Presisi Mold Teknik",
                 priority="high", start=-100, target=15, creator="sales2").create()
    s.upload(-100, "sales2", "project_request", "customer_document", "Customer Brief").complete(-99, "sales2", "project_request")
    s.complete(-96, "npd1", "npd_feedback", outcome="accepted", data={"feedback": "Feasible 4 cavity hot runner.", "feasibility": "Feasible"})
    s.upload(-90, "drafter1", "prototype_3d", "drawing_3d", "3D Diffuser Cap").complete(-89, "drafter1", "prototype_3d", data={"design_name": "Diffuser Cap 28 mm"})
    s.complete(-84, "sales2", "approval_3d", outcome="not_approved", comment="Tekstur kayu kurang dalam, grip kurang terasa.")
    s.upload(-80, "drafter1", "prototype_3d", "drawing_3d", "3D Diffuser Cap", revision_of="3D Diffuser Cap").complete(-79, "drafter1", "prototype_3d")
    s.complete(-75, "sales2", "approval_3d", outcome="approved")
    s.upload(-72, "drafter1", "drawing_2d", "drawing_2d", "2D Drawing Diffuser Cap").complete(-71, "drafter1", "drawing_2d", data={"drawing_number": "DWG-BTH-028"})
    s.complete(-67, "npd1", "mold_drawing_approval", outcome="approved")
    s.complete(-35, "npd1", "mold_machining", data={"progress": 100})
    s.upload(-33, "prod", "t0_trial", "trial_report", "T0 Trial Report")
    s.complete(-32, "npd1", "t0_trial", outcome="t0_ng", comment="Flash di parting line & short shot pada cavity 3.", data={"machine": "Injection 220T #5", "trial_result": "NG"})
    s.complete(-20, "npd1", "mold_correction", data={"correction_items": "Perbaikan parting line & venting cavity 3."})
    s.complete(-12, "npd1", "mold_machining")
    s.upload(-9, "prod", "t0_trial", "trial_report", "T0 Trial Report", revision_of="T0 Trial Report")
    s.complete(-8, "npd1", "t0_trial", outcome="t0_ok", data={"machine": "Injection 220T #5", "trial_result": "OK"})
    s.event(-8, "npd1", "Konfirmasi jadwal kirim mold", "follow_up", 1)
    s.comment(-2, "npd1", "Mold maker konfirmasi pengiriman minggu depan, packing sedang disiapkan.")
    out.append(s)

    # 10 — Aruna cushion case (New Mold): New Masterbatch YES, menunggu approval warna.
    s = Scenario(type="new_mold", customer_id="cus_aruna", name="Aruna Cushion Case", product_name="Cushion Compact 15 g",
                 customer_request="Cushion case custom shape dengan warna signature Aruna Rose.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales2"], drafter_id=U["drafter2"], supplier="PT Presisi Mold Teknik",
                 priority="urgent", start=-50, target=45, creator="sales2").create()
    s.upload(-50, "sales2", "project_request", "npr", "Project Request Document").complete(-49, "sales2", "project_request")
    s.complete(-45, "npd2", "npd_feedback", outcome="accepted_masterbatch", data={"feedback": "Butuh masterbatch baru Aruna Rose.", "feasibility": "Feasible dengan catatan"})
    s.upload(-35, "npd2", "masterbatch_dev", "material_spec", "Masterbatch Color Chip Aruna Rose")
    s.complete(-34, "npd2", "masterbatch_dev", data={"masterbatch_code": "MB-ARN-ROSE-01", "color_target": "Aruna Rose (Pantone 7430 C)"})
    s.complete(-28, "sales2", "masterbatch_approval", outcome="not_approved", comment="Warna kurang hangat dibanding standar.")
    s.upload(-18, "npd2", "masterbatch_dev", "material_spec", "Masterbatch Color Chip Aruna Rose", revision_of="Masterbatch Color Chip Aruna Rose")
    s.complete(-17, "npd2", "masterbatch_dev", data={"masterbatch_code": "MB-ARN-ROSE-02"})
    s.next_action(-17, "sales2", "Kirim color chip Rev 01 & follow up customer", 3)
    out.append(s)

    # 11 — Glowlab jar (New Mold): Completed, T0 OK pertama kali.
    s = Scenario(type="new_mold", customer_id="cus_glowlab", name="Glowlab Jar 50 g", product_name="Cream Jar 50 g",
                 customer_request="Jar double wall 50 g dengan inner PP.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales2"], drafter_id=U["drafter1"], supplier="CV Mitra Mold",
                 priority="medium", start=-160, target=-20, creator="npd1").create()
    s.upload(-160, "sales2", "project_request", "npr", "Project Request Document").complete(-159, "sales2", "project_request")
    s.complete(-156, "npd1", "npd_feedback", outcome="accepted", data={"feedback": "Feasible.", "feasibility": "Feasible"})
    s.upload(-150, "drafter1", "prototype_3d", "drawing_3d", "3D Jar 50 g").complete(-149, "drafter1", "prototype_3d", data={"design_name": "Jar 50 g"})
    s.complete(-144, "sales2", "approval_3d", outcome="approved")
    s.upload(-140, "drafter1", "drawing_2d", "drawing_2d", "2D Drawing Jar 50 g").complete(-139, "drafter1", "drawing_2d", data={"drawing_number": "DWG-GLW-050"})
    s.complete(-134, "npd1", "mold_drawing_approval", outcome="approved")
    s.complete(-96, "npd1", "mold_machining")
    s.upload(-93, "prod", "t0_trial", "trial_report", "T0 Trial Report")
    s.complete(-92, "npd1", "t0_trial", outcome="t0_ok", data={"machine": "Injection 180T #2", "trial_result": "OK"})
    s.complete(-86, "npd1", "mold_shipment", data={"condition": "Baik"})
    s.upload(-80, "prod", "commissioning_trial", "trial_report", "Commissioning Report")
    s.complete(-79, "npd1", "commissioning_trial", outcome="ok", data={"machine": "Injection 180T #2", "trial_result": "OK"})
    s.upload(-60, "npd1", "material_preparation", "coa", "COA Material")
    s.complete(-58, "npd1", "material_preparation", data={"material_received": "PP + AS", "quantity": 1800})
    s.upload(-35, "qa", "validation_mass_production", "validation_report", "Validation Report")
    s.complete(-34, "qa", "validation_mass_production", outcome="pass_condition", comment="Lolos dengan catatan: monitor warna inner.",
               data={"machine": "Injection 180T #2", "production_quantity": 15000})
    s.complete(-25, "npd1", "finish")
    out.append(s)

    # 12 — Sinar lipstick case (New Mold): overdue, T0 NG dua kali, kembali ke Mold Machining.
    s = Scenario(type="new_mold", customer_id="cus_sinar", name="Sinar Lipstick Case", product_name="Lipstick Case Magnetic",
                 customer_request="Lipstick case magnetic closure, finishing metallic.",
                 npd_pic_id=U["npd2"], sales_pic_id=U["sales2"], drafter_id=U["drafter2"], supplier="CV Mitra Mold",
                 priority="high", start=-75, target=-5, creator="sales2").create()
    s.upload(-75, "sales2", "project_request", "npr", "Project Request Document").complete(-74, "sales2", "project_request")
    s.complete(-72, "npd2", "npd_feedback", outcome="accepted", data={"feedback": "Feasible.", "feasibility": "Feasible"})
    s.upload(-68, "drafter2", "prototype_3d", "drawing_3d", "3D Lipstick Case").complete(-67, "drafter2", "prototype_3d", data={"design_name": "Lipstick Case Magnetic"})
    s.complete(-64, "sales2", "approval_3d", outcome="approved")
    s.upload(-62, "drafter2", "drawing_2d", "drawing_2d", "2D Drawing Lipstick Case").complete(-61, "drafter2", "drawing_2d", data={"drawing_number": "DWG-SNR-011"})
    s.complete(-58, "npd2", "mold_drawing_approval", outcome="approved")
    s.complete(-36, "npd2", "mold_machining")
    s.upload(-34, "prod", "t0_trial", "trial_report", "T0 Trial Report")
    s.complete(-33, "npd2", "t0_trial", outcome="t0_ng", comment="Magnet housing tidak presisi, clearance 0.3 mm.", data={"machine": "Injection 150T #1", "trial_result": "NG"})
    s.complete(-25, "npd2", "mold_correction", data={"correction_items": "Adjust core magnet housing."})
    s.complete(-18, "npd2", "mold_machining")
    s.upload(-16, "prod", "t0_trial", "trial_report", "T0 Trial Report", revision_of="T0 Trial Report")
    s.complete(-15, "npd2", "t0_trial", outcome="t0_ng", comment="Sink mark pada permukaan cap.", data={"machine": "Injection 150T #1", "trial_result": "NG"})
    s.complete(-8, "npd2", "mold_correction", data={"correction_items": "Tambah cooling channel area cap."})
    s.next_action(-8, "npd2", "Follow up mold maker — jadwal T0 ketiga", -2)
    out.append(s)

    # 13 — Natura pump head (New Mold): baru mulai, 3D sedang dikerjakan.
    s = Scenario(type="new_mold", customer_id="cus_natura", name="Natura Pump Head 24/410", product_name="Lotion Pump 24/410",
                 customer_request="Pump head 24/410 custom actuator dengan lock-down.",
                 npd_pic_id=U["npd1"], sales_pic_id=U["sales1"], drafter_id=U["drafter2"], supplier=None,
                 priority="medium", start=-10, target=80, creator="sales1").create()
    s.upload(-10, "sales1", "project_request", "npr", "Project Request Document").complete(-9, "sales1", "project_request")
    s.complete(-7, "npd1", "npd_feedback", outcome="accepted", data={"feedback": "Feasible, actuator custom.", "feasibility": "Feasible"})
    s.upload(-2, "drafter2", "prototype_3d", "drawing_3d", "3D Pump Actuator")
    out.append(s)
    return out


def seed_master_data() -> None:
    """User, customer, workflow template, dan pengaturan default."""
    for uid, name, email, role, title in USERS:
        user = User(id=uid, name=name, email=email, role=role, title=title, active=True)
        user.set_password(DEMO_PASSWORD)
        db.session.add(user)
    for cid, code, name, contact in CUSTOMERS:
        db.session.add(Customer(id=cid, code=code, name=name, contact_name=contact, contact_email=f"{code.lower()}@customer.example", active=True))
    for wf in default_workflows():
        db.session.add(WorkflowTemplate(id=wf["id"], project_type=wf["project_type"], name=wf["name"], version=1, processes=wf["processes"]))
    db.session.add(Setting(id=1, due_soon_days=3, no_update_days=7))
    db.session.flush()


def seed_demo_data(today: date | None = None) -> None:
    """Isi database kosong dengan data demo lengkap."""
    today = today or date.today()
    seed_master_data()
    users = {u.id: u for u in User.query.all()}
    actions = []
    for si, scenario in enumerate(build_scenarios()):
        for at, actor, order, fn in scenario.actions:
            actions.append((at, si, order, actor, fn))
    actions.sort(key=lambda a: (a[0], a[1], a[2]))
    for i, (at, _si, _order, actor, fn) in enumerate(actions):
        day = add_days(today, at)
        minutes = 8 * 60 + (i * 17) % (9 * 60)
        fn(Ctx(actor=users[U[actor]], now=datetime.combine(day, time(minutes // 60, minutes % 60)), today=day))
    now = datetime.combine(today, datetime.now().time())
    scan_deadlines(Ctx(actor=users["usr_admin"], now=now, today=today))
    # Notifikasi lama dianggap sudah dibaca agar inbox terlihat realistis.
    cutoff = datetime.combine(add_days(today, -5), time())
    for n in Notification.query.filter(Notification.created_at < cutoff).all():
        n.read = True
    db.session.commit()


__all__ = ["seed_demo_data", "seed_master_data", "DEMO_PASSWORD", "timedelta"]
