<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/** Menjalankan file .sql (schema/seed) statement per statement. */
final class SqlFile
{
    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
                continue;
            }
            if ($ch === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }

    public static function run(string $path): int
    {
        if (!is_file($path)) {
            throw new RuntimeException('File SQL tidak ditemukan: ' . $path);
        }
        $count = 0;
        foreach (self::statements((string) file_get_contents($path)) as $statement) {
            Database::connection()->exec($statement);
            $count++;
        }
        return $count;
    }
}
