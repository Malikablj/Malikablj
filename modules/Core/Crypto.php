<?php
declare(strict_types=1);

namespace App\Core;

/** Enkripsi simetris (libsodium secretbox) untuk rahasia yang disimpan di database. */
final class Crypto
{
    private static function key(): string
    {
        $raw = (string) Config::get('app.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY belum diatur atau tidak valid (32 byte base64). Jalankan php bin/generate-key.php');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'v1:')) {
            return null;
        }
        $bin = base64_decode(substr($payload, 3), true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
