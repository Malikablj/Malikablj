<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Akses MySQL via PDO. SEMUA nilai dikirim sebagai parameter terikat (prepared statement).
 * Nama tabel/kolom pada helper insert()/update() hanya boleh berasal dari kode dan divalidasi
 * dengan whitelist regex — tidak pernah dari input pengguna.
 *
 * Transaksi bersarang didukung dengan SAVEPOINT (dipakai juga oleh test untuk rollback).
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect((array) Config::get('db', []));
        }
        return self::$pdo;
    }

    /** @param array<string,mixed> $cfg */
    public static function connect(array $cfg): PDO
    {
        $dsn = !empty($cfg['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4')
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'] ?? '127.0.0.1', (int) ($cfg['port'] ?? 3306), $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $collation = $cfg['collation'] ?? 'utf8mb4_unicode_ci';
        $pdo->exec("SET NAMES utf8mb4 COLLATE {$collation}");
        $stmt = $pdo->prepare('SET time_zone = ?');
        $stmt->execute([$cfg['timezone'] ?? '+07:00']);
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** Ganti koneksi (dipakai test). */
    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$depth = 0;
    }

    /** @param array<int|string,mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type === PDO::PARAM_BOOL ? PDO::PARAM_INT : $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @param array<int|string,mixed> $params @return array<string,mixed>|null */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params @return list<array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<int|string,mixed> $params @return list<mixed> */
    public static function column(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<int|string,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdentifier']);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $cols)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        self::query($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * UPDATE dengan klausa WHERE berparameter.
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where kolom => nilai (digabung AND)
     */
    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdentifier($table);
        if ($data === [] || $where === []) {
            throw new \InvalidArgumentException('update() membutuhkan data dan where');
        }
        $set = [];
        $params = [];
        foreach ($data as $col => $val) {
            self::assertIdentifier($col);
            $set[] = "`{$col}` = :set_{$col}";
            $params['set_' . $col] = $val;
        }
        $cond = [];
        foreach ($where as $col => $val) {
            self::assertIdentifier($col);
            if ($val === null) {
                $cond[] = "`{$col}` IS NULL";
            } else {
                $cond[] = "`{$col}` = :w_{$col}";
                $params['w_' . $col] = $val;
            }
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $cond));
        return self::execute($sql, $params);
    }

    /**
     * Jalankan callback dalam transaksi. Bersarang → SAVEPOINT.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $level = self::$depth;
        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . $level);
        }
        self::$depth++;
        try {
            $result = $fn();
            self::$depth--;
            if ($level === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT sp_' . $level);
            }
            return $result;
        } catch (\Throwable $e) {
            self::$depth--;
            if ($level === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $level);
            }
            throw $e;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /** Untuk test: mulai transaksi luar yang akan di-rollback. */
    public static function beginOuter(): void
    {
        self::pdo()->beginTransaction();
        self::$depth = 1;
    }

    public static function rollbackOuter(): void
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        self::$depth = 0;
    }

    /** Placeholder IN (?, ?, ?) untuk daftar nilai. @param list<mixed> $values */
    public static function in(array $values): string
    {
        if ($values === []) {
            return '(NULL)';
        }
        return '(' . implode(', ', array_fill(0, count($values), '?')) . ')';
    }

    public static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Identifier SQL tidak valid');
        }
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone((string) Config::get('app.timezone', 'Asia/Jakarta'))))->format('Y-m-d H:i:s');
    }
}
