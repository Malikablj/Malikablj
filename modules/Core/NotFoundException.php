<?php
declare(strict_types=1);

namespace App\Core;

/** 404 — data tidak ditemukan. */
class NotFoundException extends AppException
{
    protected int $httpStatus = 404;
}
