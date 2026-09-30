<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use App\Services\PrCalculator;
use App\Services\PrNumberGenerator;
use App\Support\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalculationTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function decimalInputs(): iterable
    {
        yield 'integer string' => ['280000', '280000.00'];
        yield 'dot decimal' => ['1.5', '1.50'];
        yield 'comma decimal' => ['1,5', '1.50'];
        yield 'leading zeros' => ['007', '7.00'];
        yield 'trailing zeros beyond scale' => ['2.500', '2.50'];
        yield 'int' => [12, '12.00'];
        yield 'float' => [0.1, '0.10'];
        yield 'too many decimals' => ['1.234', null];
        yield 'negative' => ['-5', null];
        yield 'thousand separators' => ['1.000.000', null];
        yield 'text' => ['abc', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('decimalInputs')]
    public function test_decimal_parse(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, Decimal::parse($input));
    }

    public function test_units_round_trip(): void
    {
        self::assertSame(28000050, Decimal::toUnits('280000.50'));
        self::assertSame('280000.50', Decimal::fromUnits(28000050));
        self::assertSame('0.05', Decimal::fromUnits(5));
        self::assertSame(-1, Decimal::compare('1.00', '1.01'));
    }

    public function test_reference_document_totals(): void
    {
        $calculator = new PrCalculator();
        $result = $calculator->calculate([
            ['quantity' => '2.00', 'unit_price' => '65000.00'],
            ['quantity' => '3.00', 'unit_price' => '50000.00'],
        ], '0.00');

        self::assertSame(['130000.00', '150000.00'], $result['lines']);
        self::assertSame('280000.00', $result['subtotal']);
        self::assertSame('0.00', $result['tax_amount']);
        self::assertSame('280000.00', $result['grand_total']);
    }

    public function test_tax_and_half_up_rounding(): void
    {
        $calculator = new PrCalculator();

        self::assertSame('50000.00', $calculator->lineTotal('1.50', '33333.33'));   // 49999.995
        self::assertSame('0.01', $calculator->lineTotal('0.50', '0.01'));           // 0.005
        self::assertSame(
            ['subtotal' => '100000.00', 'tax_amount' => '11000.00', 'grand_total' => '111000.00'],
            $calculator->totals(['100000.00'], '11.00'),
        );
        self::assertSame('1.13', $calculator->totals(['10.25'], '11.00')['tax_amount']); // 1.1275
    }

    public function test_overflow_is_detected(): void
    {
        $calculator = new PrCalculator();

        self::assertNull($calculator->lineTotal('999999.99', '999999999999.99'));
        self::assertNull($calculator->totals(['999999999999.99', '1.00'], '0'));
    }

    public function test_pr_number_format_matches_pik_document(): void
    {
        self::assertSame(
            'PR/PIK/SEPT/2026-PDPR077',
            PrNumberGenerator::format('PR/PIK', new DateTimeImmutable('2026-09-15'), 'PD', 77),
        );
    }

    public function test_migration_splitter_supports_delimiter(): void
    {
        $sql = "-- komentar\nCREATE TABLE a (id INT);\n\nDELIMITER \$\$\nCREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW\nBEGIN\n  SET @x = 1;\nEND\$\$\nDELIMITER ;\nINSERT INTO a VALUES (1);\n";

        self::assertSame([
            'CREATE TABLE a (id INT)',
            "CREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW\nBEGIN\n  SET @x = 1;\nEND",
            'INSERT INTO a VALUES (1)',
        ], Migrator::splitStatements($sql));
    }

    public function test_money_formatting_without_floats(): void
    {
        self::assertSame('Rp280.000', money('280000.00'));
        self::assertSame('Rp1.250,50', money('1250.50'));
        self::assertSame('Rp0', money('0.00'));
        self::assertSame('9.999.999.999.999,99', money('9999999999999.99', false));
        self::assertSame('1,5', number_id('1.50'));
    }
}
