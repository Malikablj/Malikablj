<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Aritmetika desimal tanpa float. Nilai disimpan sebagai string berskala tetap
 * ("280000.00") dan dihitung sebagai integer satuan terkecil (sen) sehingga
 * hasilnya selalu sama persis dengan kolom DECIMAL di MySQL.
 */
final class Decimal
{
    /**
     * Mengubah input user menjadi string desimal ternormalisasi, atau null jika tidak valid.
     * Menerima "1500", "1500.5", "1500,5". Angka negatif dan desimal melebihi skala ditolak.
     */
    public static function parse(mixed $value, int $scale = 2): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }
            $value = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (!is_string($value)) {
            return null;
        }

        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }
        if (!preg_match('/^(\d{1,15})(?:\.(\d+))?$/', $value, $m)) {
            return null;
        }

        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > $scale) {
            return null;
        }
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $integer = ltrim($m[1], '0');
        $integer = $integer === '' ? '0' : $integer;

        return $scale > 0 ? $integer . '.' . $fraction : $integer;
    }

    /**
     * "280000.50" (scale 2) => 28000050
     */
    public static function toUnits(string $decimal, int $scale = 2): int
    {
        $decimal = trim($decimal);
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-+');
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $units = (int) (($integer === '' ? '0' : $integer) . $fraction);

        return $negative ? -$units : $units;
    }

    /**
     * 28000050 (scale 2) => "280000.50"
     */
    public static function fromUnits(int $units, int $scale = 2): string
    {
        $negative = $units < 0;
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);
        $integer = $scale > 0 ? substr($digits, 0, -$scale) : $digits;
        $fraction = $scale > 0 ? '.' . substr($digits, -$scale) : '';

        return ($negative ? '-' : '') . $integer . $fraction;
    }

    public static function compare(string $a, string $b, int $scale = 2): int
    {
        return self::toUnits($a, $scale) <=> self::toUnits($b, $scale);
    }

    /**
     * Pembagian bilangan bulat non-negatif dengan pembulatan half-up.
     */
    public static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
