<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Menjalankan file migration SQL (MySQL) di database/migrations secara berurutan.
 * Migration yang sudah dijalankan dicatat di tabel schema_migrations.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    public function __construct(private readonly PDO $db, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /**
     * Membuat database (jika belum ada) dengan charset utf8mb4.
     *
     * @param array<string, mixed> $config
     */
    public static function createDatabase(array $config): void
    {
        $name = (string) $config['database'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('Nama database hanya boleh berisi huruf, angka, dan underscore.');
        }
        $server = Database::connect($config, false);
        $server->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name,
        ));
    }

    public function dropAllTables(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$table]) {
            $this->db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $table) . '`');
            ($this->output)("  dropped {$table}");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @return list<string> nama migration yang baru dijalankan
     */
    public function migrate(string $directory): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            migration   VARCHAR(255) NOT NULL,
            applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $applied = $this->db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            ($this->output)("  migrating {$name}");
            foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                $this->db->exec($statement);
            }
            $stmt = $this->db->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
            $stmt->execute([$name]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Memecah isi file SQL menjadi statement. Mendukung perintah DELIMITER
     * (seperti klien mysql) untuk trigger/procedure.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $delimiter = ';';

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trimmed, $m)) {
                $delimiter = $m[1];
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, $delimiter)) {
                $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
