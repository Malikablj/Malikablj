<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Silakan login terlebih dahulu.',
            403 => 'Anda tidak memiliki akses untuk tindakan ini.',
            404 => 'Halaman atau data tidak ditemukan.',
            405 => 'Metode request tidak diizinkan.',
            419 => 'Sesi formulir telah kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            422 => 'Data tidak valid.',
            429 => 'Terlalu banyak percobaan. Coba lagi nanti.',
            default => 'Terjadi kesalahan pada server.',
        };
    }
}
