<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Cek ekstensi PHP yang dibutuhkan, agar server yang belum siap (mis. XAMPP
 * dengan extension=zip masih dikomentari) mendapat pesan yang jelas, bukan error 500.
 * Sengaja tanpa dependensi lain: install.php memuatnya sebelum bootstrap.
 */
final class Requirements
{
    /** ekstensi => [wajib untuk seluruh aplikasi?, dipakai untuk] */
    public const EXTENSIONS = [
        'pdo_mysql' => [true, 'koneksi database'],
        'mbstring'  => [true, 'pengolahan teks'],
        'zip'       => [false, 'import & export Excel'],
        'xmlreader' => [false, 'membaca file Excel saat import'],
    ];

    /**
     * Ekstensi yang belum aktif.
     * @param list<string>|null $only batasi pada ekstensi tertentu
     * @param (callable(string):bool)|null $isLoaded untuk test; default extension_loaded()
     * @return list<string>
     */
    public static function missing(?array $only = null, ?callable $isLoaded = null): array
    {
        $isLoaded ??= static fn (string $ext): bool => extension_loaded($ext);
        $names = $only ?? array_keys(self::EXTENSIONS);
        return array_values(array_filter($names, static fn (string $ext): bool => !$isLoaded($ext)));
    }

    /** @return list<string> ekstensi yang tanpanya aplikasi tidak bisa berjalan sama sekali */
    public static function required(): array
    {
        return array_keys(array_filter(self::EXTENSIONS, static fn (array $info): bool => $info[0]));
    }

    /** @return list<string> ekstensi untuk membaca/menulis Excel yang belum aktif */
    public static function excelMissing(): array
    {
        return self::missing(['zip', 'xmlreader']);
    }

    /** Pesan untuk pengguna: apa yang belum aktif dan cara mengaktifkannya. */
    public static function message(array $missing, string $feature): string
    {
        $list = implode(', ', $missing);
        $lines = implode(', ', array_map(static fn (string $ext): string => 'extension=' . $ext, $missing));
        return "{$feature} membutuhkan ekstensi PHP {$list} yang belum aktif di server. "
            . "Cara mengaktifkan: buka file php.ini, hapus tanda ; di depan baris {$lines}, simpan, lalu restart Apache.";
    }
}
