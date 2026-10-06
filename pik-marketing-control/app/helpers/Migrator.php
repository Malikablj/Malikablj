<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Menjalankan migrasi database (database/migrations/*.php) secara berurutan.
 *
 * - schema.sql = struktur dasar; perubahan sesudahnya ditulis sebagai migrasi.
 * - Migrasi hanya MENAMBAH tabel/kolom/index (tidak ada DROP/TRUNCATE) dan setiap
 *   langkah dicek dulu, sehingga aman dijalankan ulang bila sempat terhenti.
 * - Migrasi yang sudah jalan dicatat di tabel schema_migrations.
 * Dijalankan oleh: php database/migrate.php, php database/install.php,
 * atau Admin lewat Settings › Pembaruan database.
 */
final class Migrator
{
    private static ?bool $current = null;

    public static function directory(): string
    {
        return APP_ROOT . '/database/migrations';
    }

    /** @return list<string> nama migrasi (nama file tanpa .php), urut */
    public static function all(): array
    {
        $files = glob(self::directory() . '/*.php') ?: [];
        sort($files);
        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /** @return list<string> */
    public static function pending(): array
    {
        $all = self::all();
        if ($all === [] || !Schema::hasTable('schema_migrations')) {
            return $all;
        }
        $done = array_map('strval', Database::fetchColumn('SELECT migration FROM schema_migrations'));
        return array_values(array_diff($all, $done));
    }

    /** Struktur database sudah versi terbaru? (di-cache per request) */
    public static function isCurrent(): bool
    {
        return self::$current ??= self::pending() === [];
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Jalankan semua migrasi yang belum jalan.
     * @param (callable(string):void)|null $onRun dipanggil per migrasi yang selesai
     * @return list<string>
     */
    public static function run(?callable $onRun = null): array
    {
        $pdo = Database::connection();
        if ((int) Database::fetchValue("SELECT GET_LOCK('pik_migrate', 30)") !== 1) {
            throw new RuntimeException('Migrasi lain sedang berjalan. Coba lagi sebentar lagi.');
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                migration  VARCHAR(190) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ran = [];
            foreach (self::pending() as $name) {
                $migration = require self::directory() . '/' . $name . '.php';
                if (!is_callable($migration)) {
                    throw new RuntimeException("Migrasi {$name} tidak mengembalikan fungsi.");
                }
                $migration();
                Database::insert('schema_migrations', ['migration' => $name]);
                $ran[] = $name;
                if ($onRun !== null) {
                    $onRun($name);
                }
            }
            self::$current = true;
            return $ran;
        } finally {
            Database::fetchValue("SELECT RELEASE_LOCK('pik_migrate')");
        }
    }
}
