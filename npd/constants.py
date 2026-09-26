"""Daftar nilai tetap (role, status, tipe dokumen, dll.) beserta label & warnanya.

Setiap dictionary memetakan *kode* (disimpan di database) ke *label* (ditampilkan di UI).
Warna ("tone") mengikuti Status Color System di PRD §15:
green = completed/approved, blue = on progress, yellow = waiting/due soon,
red = overdue/rejected, gray = not started, orange = revision/attention.
"""

# ---------------------------------------------------------------- Role & akses
ROLES = {
    "admin": "Admin",
    "admin_sales": "Admin Sales",
    "npd_staff": "NPD Staff",
    "drafter": "Drafter",
    "purchasing": "Purchasing",
    "production": "Production",
    "quality": "Quality",
    "management": "Management",
}

ROLE_DESCRIPTION = {
    "admin": "Akses penuh: user, workflow, master data, semua project",
    "admin_sales": "Create NPR, submit artwork & trial result, dokumen Sales",
    "npd_staff": "Feedback NPR, project control, trial, material, validation",
    "drafter": "Artwork, drawing, revision, dokumen drafting",
    "purchasing": "Material request, purchasing status, dokumen purchasing",
    "production": "Data trial & produksi yang relevan",
    "quality": "Quality, test, dan validation result",
    "management": "Read-only: dashboard, report, analytics",
}

# ---------------------------------------------------------------- Project
PROJECT_TYPES = {"new_mold": "New Mold", "subcont": "Subcont"}

# Status yang disimpan. "overdue" dihitung otomatis dari tanggal (lihat services/metrics.py).
STATUSES = {
    "not_started": "Not Started",
    "on_progress": "On Progress",
    "waiting_approval": "Waiting Approval",
    "waiting_external": "Waiting External",
    "hold": "Hold",
    "overdue": "Overdue",
    "completed": "Completed",
    "cancelled": "Cancelled",
}
STATUS_TONE = {
    "not_started": "gray",
    "on_progress": "blue",
    "waiting_approval": "yellow",
    "waiting_external": "yellow",
    "hold": "orange",
    "overdue": "red",
    "completed": "green",
    "cancelled": "gray",
}
# Status yang boleh dipilih manual lewat "Update Status".
MANUAL_STATUSES = ["on_progress", "waiting_approval", "waiting_external", "hold", "cancelled"]

PRIORITIES = {"low": "Low", "medium": "Medium", "high": "High", "urgent": "Urgent"}
PRIORITY_TONE = {"low": "gray", "medium": "blue", "high": "orange", "urgent": "red"}
PRIORITY_RANK = {"low": 0, "medium": 1, "high": 2, "urgent": 3}

# ---------------------------------------------------------------- Process
PROCESS_STATUSES = {
    "not_started": "Not Started",
    "current": "Current",
    "completed": "Completed",
    "revision": "Revision",
    "problem": "Problem",
    "skipped": "Tidak dijalankan",
}
PROCESS_STATUS_TONE = {
    "not_started": "gray",
    "current": "blue",
    "completed": "green",
    "revision": "orange",
    "problem": "red",
    "skipped": "gray",
}
RUNNING_STATUSES = ("current", "revision", "problem")

# ---------------------------------------------------------------- Dokumen
DOC_TYPES = {
    "npr": "NPR",
    "feedback": "Feedback",
    "artwork": "Artwork",
    "technical_drawing": "Technical Drawing",
    "drawing_3d": "3D Drawing",
    "drawing_2d": "2D Drawing",
    "mold_drawing": "Mold Drawing",
    "approval": "Approval",
    "trial_report": "Trial Report",
    "trial_photo": "Trial Photo",
    "trial_video": "Trial Video",
    "material_request": "Material Request",
    "coa": "COA",
    "material_spec": "Material Specification",
    "validation_report": "Validation Report",
    "customer_document": "Customer Document",
    "supplier_document": "Supplier Document",
    "other": "Other",
}
DOC_STATUSES = {"current": "Current", "superseded": "Superseded", "rejected": "Rejected", "approved": "Approved"}
DOC_STATUS_TONE = {"current": "blue", "superseded": "gray", "rejected": "red", "approved": "green"}

DOCUMENT_FOLDERS = [
    "01 NPR",
    "02 NPD Feedback",
    "03 Artwork",
    "04 Customer Approval",
    "05 Trial / T0",
    "06 Material",
    "07 Mold / Drawing",
    "08 Commissioning",
    "09 Validation",
]

ALLOWED_EXTENSIONS = {
    "pdf", "ai", "eps", "svg", "png", "jpg", "jpeg", "webp", "dwg", "dxf", "step", "stp", "igs", "stl",
    "xlsx", "xls", "docx", "doc", "csv", "txt", "mp4", "mov", "zip",
}

# ---------------------------------------------------------------- Approval
APPROVAL_TYPES = {
    "npr": "NPR Approval",
    "artwork": "Artwork Approval",
    "masterbatch": "Masterbatch Approval",
    "3d": "3D Approval",
    "2d": "2D Approval",
    "mold_drawing": "Mold Drawing Approval",
    "t0": "T0 Approval",
    "trial": "Trial Approval",
    "commissioning": "Commissioning Approval",
    "validation": "Validation Approval",
}
APPROVAL_STATUSES = {"pending": "Pending", "approved": "Approved", "rejected": "Rejected", "revision_required": "Revision Required"}
APPROVAL_STATUS_TONE = {"pending": "yellow", "approved": "green", "rejected": "red", "revision_required": "orange"}

# Tipe dokumen yang menjadi "bukti" untuk tiap jenis approval.
APPROVAL_DOC_TYPES = {
    "npr": ["npr"],
    "artwork": ["artwork"],
    "masterbatch": ["material_spec"],
    "3d": ["drawing_3d"],
    "2d": ["drawing_2d"],
    "mold_drawing": ["mold_drawing", "drawing_2d"],
    "t0": ["trial_report"],
    "trial": ["trial_report"],
    "commissioning": ["trial_report"],
    "validation": ["validation_report"],
}

# ---------------------------------------------------------------- Material request
PURCHASING_STATUSES = {"requested": "Requested", "po_issued": "PO Issued", "in_transit": "In Transit", "received": "Received", "cancelled": "Cancelled"}
PURCHASING_STATUS_TONE = {"requested": "yellow", "po_issued": "blue", "in_transit": "blue", "received": "green", "cancelled": "gray"}

# ---------------------------------------------------------------- Kalender
CALENDAR_CATEGORIES = {
    "deadline": "Deadline",
    "customer_approval": "Customer Approval",
    "trial": "Trial / T0",
    "commissioning": "Commissioning",
    "material_arrival": "Material Arrival",
    "validation": "Validation",
    "meeting": "Meeting",
    "follow_up": "Follow-up",
}
CALENDAR_CATEGORY_TONE = {
    "deadline": "red",
    "customer_approval": "yellow",
    "trial": "blue",
    "commissioning": "orange",
    "material_arrival": "green",
    "validation": "green",
    "meeting": "gray",
    "follow_up": "orange",
}

# ---------------------------------------------------------------- Notifikasi
NOTIFICATION_TYPES = {
    "project_assigned": "Project baru",
    "approval_requested": "Approval diminta",
    "approval_rejected": "Approval ditolak",
    "approval_approved": "Approval disetujui",
    "artwork_revision": "Revisi artwork",
    "document_uploaded": "Dokumen baru",
    "document_revision": "Revisi dokumen",
    "deadline_approaching": "Deadline mendekat",
    "project_overdue": "Project overdue",
    "next_action_due": "Next action jatuh tempo",
    "next_action_overdue": "Next action terlambat",
    "missing_document": "Dokumen wajib belum ada",
}
NOTIFICATION_TONE = {
    "project_assigned": "blue",
    "approval_requested": "yellow",
    "approval_rejected": "red",
    "approval_approved": "green",
    "artwork_revision": "orange",
    "document_uploaded": "blue",
    "document_revision": "blue",
    "deadline_approaching": "yellow",
    "project_overdue": "red",
    "next_action_due": "yellow",
    "next_action_overdue": "red",
    "missing_document": "orange",
}

ATTENTION_LABELS = {
    "overdue": "Overdue",
    "due_soon": "Due Soon",
    "waiting_approval": "Waiting Approval",
    "waiting_external": "Waiting External",
    "no_update": "No Update",
    "missing_document": "Missing Mandatory Document",
}


def format_revision(rev: int) -> str:
    """0 -> 'Rev 00', 2 -> 'Rev 02'."""
    return f"Rev {rev:02d}"
