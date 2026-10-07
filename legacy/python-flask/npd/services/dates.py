"""Fungsi bantu tanggal dengan format Indonesia."""
from datetime import date, datetime, timedelta

MONTHS_SHORT = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"]
MONTHS_LONG = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
WEEKDAYS_SHORT = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"]


def today() -> date:
    return date.today()


def add_days(d: date, days: int) -> date:
    return d + timedelta(days=days)


def diff_days(a: date, b: date) -> int:
    """Jumlah hari dari a ke b (b - a)."""
    return (b - a).days


def start_of_week(d: date) -> date:
    return d - timedelta(days=d.weekday())  # Senin


def end_of_week(d: date) -> date:
    return start_of_week(d) + timedelta(days=6)


def start_of_month(d: date) -> date:
    return d.replace(day=1)


def add_months(d: date, months: int) -> date:
    m = d.month - 1 + months
    return date(d.year + m // 12, m % 12 + 1, 1)


def end_of_month(d: date) -> date:
    return add_months(start_of_month(d), 1) - timedelta(days=1)


def parse_date(value) -> date | None:
    """'2026-09-26' -> date. Nilai kosong/tidak valid -> None."""
    if isinstance(value, date):
        return value
    if not value:
        return None
    try:
        return datetime.strptime(str(value)[:10], "%Y-%m-%d").date()
    except ValueError:
        return None


def fmt_date(d) -> str:
    """date -> '22 Sep 2026'."""
    d = parse_date(d) if not isinstance(d, date) else d
    if not d:
        return "—"
    return f"{d.day} {MONTHS_SHORT[d.month - 1]} {d.year}"


def fmt_date_short(d) -> str:
    d = parse_date(d) if not isinstance(d, date) else d
    return f"{d.day} {MONTHS_SHORT[d.month - 1]}" if d else "—"


def fmt_month(d: date) -> str:
    return f"{MONTHS_LONG[d.month - 1]} {d.year}"


def fmt_datetime(dt: datetime | None) -> str:
    if not dt:
        return "—"
    return f"{dt.day} {MONTHS_SHORT[dt.month - 1]} {dt.year}, {dt:%H:%M}"


def describe_due(due: date, ref: date) -> str:
    """'hari ini', 'besok', '3 hari lagi', 'terlambat 2 hari'."""
    d = diff_days(ref, due)
    if d == 0:
        return "hari ini"
    if d == 1:
        return "besok"
    if d > 1:
        return f"{d} hari lagi"
    return f"terlambat {-d} hari"


def fmt_relative(dt: datetime, now: datetime | None = None) -> str:
    now = now or datetime.now()
    minutes = int((now - dt).total_seconds() // 60)
    if minutes < 1:
        return "baru saja"
    if minutes < 60:
        return f"{minutes} menit lalu"
    hours = minutes // 60
    if hours < 24:
        return f"{hours} jam lalu"
    days = hours // 24
    if days < 7:
        return f"{days} hari lalu"
    return fmt_date(dt.date())
