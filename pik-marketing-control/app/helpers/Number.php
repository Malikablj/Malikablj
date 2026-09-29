<?php

declare(strict_types=1);

namespace App\Helpers;

/** Parsing & format angka (mendukung format Indonesia: 1.250.000,50). */
final class Number
{
    /**
     * Bilangan bulat. Menerima "10000", "-5", "10.000" / "10,000" (pemisah ribuan).
     */
    public static function parseInt(string $value): ?int
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value) || preg_match('/^-?\d{1,3}(,\d{3})+$/', $value)) {
            return (int) str_replace(['.', ','], '', $value);
        }
        return null;
    }

    /**
     * Angka desimal, dikembalikan sebagai string numerik ("1250000.50") agar
     * presisi DECIMAL tidak hilang oleh float.
     *
     * Urutan pengenalan:
     *   1. "1250000.5"        (format mesin / input type=number)
     *   2. "1.250.000,50"     (format Indonesia: titik ribuan, koma desimal)
     *   3. "1250000,5"        (koma desimal)
     *   4. "1,250,000.50"     (format Inggris)
     */
    public static function parseDecimal(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return self::normalizeDecimalString($value);
        }
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            return self::normalizeDecimalString(str_replace(['.', ','], ['', '.'], $value));
        }
        if (preg_match('/^-?\d+,\d+$/', $value)) {
            return self::normalizeDecimalString(str_replace(',', '.', $value));
        }
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $value)) {
            return self::normalizeDecimalString(str_replace(',', '', $value));
        }
        return null;
    }

    /**
     * Khusus migrasi: parse teks angka berformat Indonesia secara ketat
     * (mis. " 1.846,85" => 1846.85, "270,27" => 270.27, "62.162.220,00").
     * Mengembalikan null bila format tidak jelas-jelas Indonesia.
     */
    public static function parseIndonesianText(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $value) || preg_match('/^-?\d+,\d+$/', $value)) {
            return self::normalizeDecimalString(str_replace(['.', ','], ['', '.'], $value));
        }
        return null;
    }

    private static function normalizeDecimalString(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        if (str_contains($value, '.')) {
            [$int, $frac] = explode('.', $value, 2);
            $frac = rtrim($frac, '0');
        } else {
            $int = $value;
            $frac = '';
        }
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        $result = $frac === '' ? $int : $int . '.' . $frac;
        return ($negative && $result !== '0') ? '-' . $result : $result;
    }

    /** Format jumlah/kuantitas: 1250000 => "1.250.000" */
    public static function qty(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        return number_format((float) $value, 0, ',', '.');
    }

    /** Format rupiah: 1250000.5 => "Rp 1.250.000,50" (desimal hanya bila ada). */
    public static function money(mixed $value, string $empty = '—', bool $withPrefix = true): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        $float = (float) $value;
        $decimals = abs($float - round($float)) >= 0.005 ? 2 : 0;
        $formatted = number_format($float, $decimals, ',', '.');
        return $withPrefix ? 'Rp ' . $formatted : $formatted;
    }

    /** Format angka desimal umum (mis. harga satuan) tanpa nol berlebih. */
    public static function decimal(mixed $value, int $maxDecimals = 4, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        $formatted = number_format((float) $value, $maxDecimals, ',', '.');
        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }
        return $formatted;
    }

    /** Ringkas: 1250000 => "1,25 jt", 3500000000 => "3,5 M" */
    public static function compact(mixed $value): string
    {
        $v = (float) $value;
        $abs = abs($v);
        if ($abs >= 1e12) {
            return rtrim(rtrim(number_format($v / 1e12, 2, ',', '.'), '0'), ',') . ' T';
        }
        if ($abs >= 1e9) {
            return rtrim(rtrim(number_format($v / 1e9, 2, ',', '.'), '0'), ',') . ' M';
        }
        if ($abs >= 1e6) {
            return rtrim(rtrim(number_format($v / 1e6, 2, ',', '.'), '0'), ',') . ' jt';
        }
        if ($abs >= 1e3) {
            return rtrim(rtrim(number_format($v / 1e3, 1, ',', '.'), '0'), ',') . ' rb';
        }
        return number_format($v, 0, ',', '.');
    }
}
