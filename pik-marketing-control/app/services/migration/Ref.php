<?php

declare(strict_types=1);

namespace App\Services\Migration;

/** Referensi foreign key berdasarkan kode bisnis (mis. customers.code = CUS-...). */
final class Ref
{
    public function __construct(public readonly string $table, public readonly string $code)
    {
    }
}
