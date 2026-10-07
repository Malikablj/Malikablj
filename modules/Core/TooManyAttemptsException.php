<?php
declare(strict_types=1);

namespace App\Core;

/** 429 — terlalu banyak percobaan. */
class TooManyAttemptsException extends AppException
{
    protected int $httpStatus = 429;

    public function __construct(string $message, private int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }

    public function retryAfter(): int
    {
        return $this->retryAfterSeconds;
    }
}
