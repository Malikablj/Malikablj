"""Error yang dipakai logika bisnis. Pesannya langsung ditampilkan ke user."""


class AppError(Exception):
    """Kesalahan yang aman ditampilkan ke user.

    code         : VALIDATION | FORBIDDEN | NOT_FOUND | CONFLICT | INVALID_STATE
    field_errors : pesan per kolom form, mis. {"name": "Project Name wajib diisi."}
    details      : daftar tambahan (mis. proses yang belum selesai)
    """

    def __init__(self, code: str, message: str, field_errors: dict | None = None, details: list | None = None):
        super().__init__(message)
        self.code = code
        self.message = message
        self.field_errors = field_errors or {}
        self.details = details or []


def forbidden(message: str) -> AppError:
    return AppError("FORBIDDEN", message)


def not_found(message: str) -> AppError:
    return AppError("NOT_FOUND", message)


def invalid(message: str, field_errors: dict | None = None, details: list | None = None) -> AppError:
    return AppError("VALIDATION", message, field_errors, details)
