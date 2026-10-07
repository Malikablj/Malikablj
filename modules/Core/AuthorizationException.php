<?php
declare(strict_types=1);

namespace App\Core;

/** 403 — tidak berhak. */
class AuthorizationException extends AppException
{
    protected int $httpStatus = 403;
}
