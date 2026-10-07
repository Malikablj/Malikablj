<?php
declare(strict_types=1);

namespace App\Core;

/** 401 — belum login / sesi berakhir. */
class AuthenticationException extends AppException
{
    protected int $httpStatus = 401;
}
