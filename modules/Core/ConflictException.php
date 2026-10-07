<?php
declare(strict_types=1);

namespace App\Core;

/** 409 — konflik penguncian optimistik (data diubah pengguna lain). */
class ConflictException extends AppException
{
    protected int $httpStatus = 409;
}
