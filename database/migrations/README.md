# Migrasi skema

File `YYYYMMDD_NNN_keterangan.sql` di folder ini dijalankan berurutan oleh `php bin/migrate.php` dan dicatat di
tabel `schema_migrations`. Setiap perubahan juga dicerminkan di `database/schema.sql`, sehingga instalasi baru
langsung memakai skema terbaru (dan menandai semua migrasi sudah dijalankan). Backup dulu sebelum migrasi di
produksi. Lihat `docs/DEPLOYMENT.md` §9.
