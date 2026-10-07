<?php
declare(strict_types=1);

namespace App\Core;

/** 419 — token CSRF tidak valid. */
class CsrfException extends AppException
{
    protected int $httpStatus = 419;
}
