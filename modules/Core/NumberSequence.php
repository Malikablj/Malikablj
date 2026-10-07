<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Penomoran aman terhadap request bersamaan. Memakai upsert atomik InnoDB:
 *   INSERT … ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)
 * Baris sequence terkunci (row lock) sampai transaksi pemanggil commit, sehingga dua
 * transaksi tidak pernah mendapat nilai yang sama. Tidak memakai SELECT MAX()+1.
 */
final class NumberSequence
{
    public static function next(string $key): int
    {
        if (!preg_match('/^[A-Z0-9_-]{1,50}$/', $key)) {
            throw new \InvalidArgumentException('Kunci sequence tidak valid');
        }
        Db::execute(
            'INSERT INTO number_sequences (seq_key, current_value) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)',
            [$key]
        );
        return (int) Db::value('SELECT LAST_INSERT_ID()');
    }

    public static function romanMonth(int $month): string
    {
        $r = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Bulan tidak valid');
        }
        return $r[$month - 1];
    }

    /** NO/PIK/NPR/Bulan Romawi/Tahun, mis. 001/PIK/NPR/X/2026 (urut per tahun). */
    public static function nextNprNumber(\DateTimeImmutable $at): array
    {
        $year = (int) $at->format('Y');
        $seq = self::next('NPR-' . $year);
        return [
            'number' => sprintf('%03d/PIK/NPR/%s/%d', $seq, self::romanMonth((int) $at->format('n')), $year),
            'year' => $year,
            'seq' => $seq,
        ];
    }

    /** NPD-YYYY-XXX */
    public static function nextProjectCode(\DateTimeImmutable $at): string
    {
        $year = (int) $at->format('Y');
        return sprintf('NPD-%d-%03d', $year, self::next('NPD-' . $year));
    }

    /** APR-YYYY-NNNN */
    public static function nextApprovalCode(\DateTimeImmutable $at): string
    {
        $year = (int) $at->format('Y');
        return sprintf('APR-%d-%04d', $year, self::next('APR-' . $year));
    }
}
