<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Dilempar saat sebuah aksi melanggar aturan bisnis (mis. submit PR tanpa item).
 * Pesannya aman ditampilkan ke user.
 */
final class BusinessRuleException extends RuntimeException
{
}
