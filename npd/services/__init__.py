"""Business logic (logika bisnis) — tidak bergantung pada halaman/HTTP.

Setiap fungsi menerima `Ctx` (siapa yang melakukan & kapan), memvalidasi hak akses
serta aturan bisnis, lalu mengubah data. Jika ada yang salah, fungsi melempar
`AppError` dan route akan me-rollback transaksi database.
"""
