<?php
declare(strict_types=1);

namespace App\Core;

/** 422 — validasi input; membawa error per field. */
class ValidationException extends AppException
{
    protected int $httpStatus = 422;

    /** @param array<string,string> $errors field => pesan */
    public function __construct(private array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (string) reset($errors));
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
