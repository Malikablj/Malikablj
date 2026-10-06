<?php

declare(strict_types=1);

namespace App\Services\Migration;

use RuntimeException;
use Throwable;

/** Import gagal; status di import_logs sudah dicatat (FAILED / ROLLED_BACK). */
final class ImportFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $logId, public readonly string $status, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
