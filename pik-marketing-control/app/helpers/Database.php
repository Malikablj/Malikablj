<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Akses database lewat PDO dengan prepared statements.
 * Semua nilai dari user WAJIB dikirim sebagai parameter, bukan digabung ke SQL.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionLevel = 0;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $config = require APP_ROOT . '/config/database.php';
            if (!empty($config['socket'])) {
                $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $config['socket'], $config['database'], $config['charset']);
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
            }
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $pdo->exec("SET NAMES {$config['charset']} COLLATE {$config['collation']}");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $tz = (string) $config['timezone'];
            if (preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
                $pdo->exec("SET time_zone = '{$tz}'");
            }
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Dipakai test untuk memakai koneksi lain. */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$transactionLevel = 0;
    }

    /** @param array<string|int,mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            } elseif (is_bool($value)) {
                $type = PDO::PARAM_INT;
                $value = $value ? 1 : 0;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @return list<mixed> */
    public static function fetchColumn(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $columns = array_keys($data);
        foreach ($columns as $column) {
            self::assertIdentifier($column);
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)),
            implode(', ', array_map(static fn ($c) => ':' . $c, $columns))
        );
        self::query($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdentifier($table);
        if ($data === []) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            self::assertIdentifier($column);
            $sets[] = "`{$column}` = :set_{$column}";
            $params['set_' . $column] = $value;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return self::query($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        self::assertIdentifier($table);
        return self::query(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    /**
     * Menjalankan callback dalam transaksi. Mendukung pemanggilan bersarang
     * (hanya transaksi terluar yang benar-benar COMMIT/ROLLBACK).
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $isOuter = self::$transactionLevel === 0;
        if ($isOuter) {
            $pdo->beginTransaction();
        }
        self::$transactionLevel++;
        try {
            $result = $callback();
            self::$transactionLevel--;
            if ($isOuter) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$transactionLevel--;
            if ($isOuter && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $identifier);
        }
    }

    /** Escape karakter wildcard LIKE (% dan _) pada input pencarian. */
    public static function like(string $term): string
    {
        return '%' . addcslashes($term, '%_\\') . '%';
    }
}
