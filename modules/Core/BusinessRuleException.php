<?php
declare(strict_types=1);

namespace App\Core;

/** 422 — aturan bisnis dilanggar (pesan tunggal). */
class BusinessRuleException extends AppException
{
    protected int $httpStatus = 422;
}
