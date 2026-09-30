<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Decimal;

/**
 * Perhitungan nilai PR (sumber kebenaran; JavaScript hanya menampilkan preview).
 *
 *   Line Total  = Quantity x Unit Price
 *   Subtotal    = SUM(Line Total)
 *   Tax Amount  = Subtotal x Tax Rate / 100
 *   Grand Total = Subtotal + Tax Amount
 *
 * Semua pembulatan ke 2 desimal memakai half-up.
 */
final class PrCalculator
{
    /** Batas nilai per baris dan subtotal (masih aman untuk DECIMAL(15,2)). */
    public const MAX_AMOUNT = '999999999999.99';
    public const MAX_QUANTITY = '999999.99';

    /**
     * @return string|null null bila hasil melebihi batas
     */
    public function lineTotal(string $quantity, string $unitPrice): ?string
    {
        // Penjaga overflow integer sebelum perkalian eksak.
        if ((float) $quantity * (float) $unitPrice > 1.0e12) {
            return null;
        }
        $units = Decimal::divideRoundHalfUp(Decimal::toUnits($quantity) * Decimal::toUnits($unitPrice), 100);
        if ($units > Decimal::toUnits(self::MAX_AMOUNT)) {
            return null;
        }

        return Decimal::fromUnits($units);
    }

    /**
     * @param list<string> $lineTotals
     * @return array{subtotal: string, tax_amount: string, grand_total: string}|null
     */
    public function totals(array $lineTotals, string $taxRate): ?array
    {
        $subtotal = 0;
        foreach ($lineTotals as $lineTotal) {
            $subtotal += Decimal::toUnits($lineTotal);
        }
        if ($subtotal > Decimal::toUnits(self::MAX_AMOUNT)) {
            return null;
        }
        $tax = Decimal::divideRoundHalfUp($subtotal * Decimal::toUnits($taxRate), 10000);

        return [
            'subtotal' => Decimal::fromUnits($subtotal),
            'tax_amount' => Decimal::fromUnits($tax),
            'grand_total' => Decimal::fromUnits($subtotal + $tax),
        ];
    }

    /**
     * Hitung ulang seluruh PR dari item yang tersimpan.
     *
     * @param list<array{quantity: string, unit_price: string}> $items
     * @return array{lines: list<string>, subtotal: string, tax_amount: string, grand_total: string}|null
     */
    public function calculate(array $items, string $taxRate): ?array
    {
        $lines = [];
        foreach ($items as $item) {
            $line = $this->lineTotal((string) $item['quantity'], (string) $item['unit_price']);
            if ($line === null) {
                return null;
            }
            $lines[] = $line;
        }
        $totals = $this->totals($lines, $taxRate);

        return $totals === null ? null : ['lines' => $lines] + $totals;
    }
}
