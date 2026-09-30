<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Koneksi PDO MySQL tunggal + helper transaksi.
 *
 * Transaksi dapat bersarang: transaksi terluar memakai BEGIN/COMMIT,
 * transaksi di dalamnya memakai SAVEPOINT sehingga service dapat saling
 * memanggil tanpa memecah atomisitas.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $depth = 0;

    public static function connection(): PDO
    {
        return self::$pdo ??= self::connect((array) Config::get('database'));
    }

    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
        self::$depth = 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, bool $selectDatabase = true): PDO
    {
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            throw new RuntimeException('DB_CONNECTION harus "mysql". Aplikasi ini hanya mendukung MySQL 8.0+ (pdo_mysql).');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Ekstensi PHP pdo_mysql belum aktif.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], (int) $config['port']);
        if ($selectDatabase) {
            $dsn .= ';dbname=' . $config['database'];
        }

        $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Mode ketat agar data tidak pernah dipotong/diubah diam-diam oleh MySQL.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,"
            . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY'");
        // Samakan zona waktu MySQL dengan zona waktu aplikasi.
        $pdo->exec('SET time_zone = ' . $pdo->quote((new DateTimeImmutable())->format('P')));

        return $pdo;
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        self::begin();
        try {
            $result = $callback(self::connection());
            self::commit();

            return $result;
        } catch (Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }

    /**
     * Seperti transaction(), tetapi mengulang otomatis bila MySQL membatalkan
     * transaksi karena deadlock (1213) atau lock wait timeout (1205).
     * Pengulangan hanya dilakukan bila ini transaksi terluar.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transactionWithRetry(callable $callback, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return self::transaction($callback);
            } catch (\PDOException $e) {
                $retryable = in_array($e->errorInfo[1] ?? null, [1213, 1205], true);
                if (!$retryable || self::$depth > 0 || $attempt >= $attempts) {
                    throw $e;
                }
                usleep(random_int(20_000, 120_000));
            }
        }
    }

    public static function begin(): void
    {
        $pdo = self::connection();
        if (self::$depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . self::$depth);
        }
        self::$depth++;
    }

    public static function commit(): void
    {
        self::$depth--;
        if (self::$depth === 0) {
            self::connection()->commit();
        } else {
            self::connection()->exec('RELEASE SAVEPOINT sp_' . self::$depth);
        }
    }

    public static function rollBack(): void
    {
        self::$depth--;
        if (self::$depth === 0) {
            if (self::connection()->inTransaction()) {
                self::connection()->rollBack();
            }
        } else {
            self::connection()->exec('ROLLBACK TO SAVEPOINT sp_' . self::$depth);
        }
    }

    public static function depth(): int
    {
        return self::$depth;
    }
}
