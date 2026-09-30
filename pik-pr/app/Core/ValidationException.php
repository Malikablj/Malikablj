<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Dilempar saat input user tidak valid. Berisi pesan per field.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(public readonly array $errors, string $message = 'Periksa kembali isian yang ditandai.')
    {
        parent::__construct($message);
    }
}
