<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Exception aplikasi. Setiap exception membawa HTTP status dan pesan yang AMAN ditampilkan
 * kepada pengguna (tanpa detail teknis). Detail teknis hanya ke log.
 */
class AppException extends \RuntimeException
{
    protected int $httpStatus = 400;

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
