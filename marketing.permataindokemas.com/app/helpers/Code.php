<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/**
 * Generator ID bisnis dengan format yang sama seperti master workbook,
 * mis. CUS-894EA1DA5F (prefix + 10 karakter heksadesimal acak).
 */
final class Code
{
    public const PREFIXES = [
        'users'             => 'USR',
        'customers'         => 'CUS',
        'contacts'          => 'CON',
        'products'          => 'PRD',
        'leads'             => 'LEAD',
        'activities'        => 'ACT',
        'follow_up'         => 'FUP',
        'purchase_orders'   => 'PO',
        'po_lines'          => 'POL',
        'deliveries'        => 'DEL',
        'returns'           => 'RET',
        'stock'             => 'STK',
        'leadtime'          => 'LT',
        'inbound_maklon'    => 'INB',
        'inbound_supplier'  => 'INS',
        'invoices_payments' => 'PAY',
        'po_financials'     => 'POF',
        'migration_issues'  => 'ISS',
    ];

    public static function generate(string $table): string
    {
        $prefix = self::PREFIXES[$table] ?? throw new RuntimeException('No code prefix for table ' . $table);
        Database::assertIdentifier($table);
        for ($i = 0; $i < 10; $i++) {
            $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(5)));
            $exists = Database::fetchValue("SELECT 1 FROM `{$table}` WHERE code = :code", ['code' => $code]);
            if (!$exists) {
                return $code;
            }
        }
        throw new RuntimeException('Unable to generate a unique code for ' . $table);
    }
}
