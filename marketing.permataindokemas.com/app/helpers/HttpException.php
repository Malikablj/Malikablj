<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

/** Exception yang dipetakan ke halaman error HTTP (403, 404, 419, ...). */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Permintaan tidak valid.',
            403 => 'Anda tidak memiliki akses ke halaman atau aksi ini.',
            404 => 'Halaman atau data yang Anda cari tidak ditemukan.',
            405 => 'Metode request tidak diizinkan.',
            419 => 'Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            429 => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
            default => 'Terjadi kesalahan pada server.',
        };
    }
}
