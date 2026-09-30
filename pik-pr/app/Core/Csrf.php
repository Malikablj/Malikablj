<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }

        return $token;
    }

    public static function regenerate(): void
    {
        Session::put(self::KEY, bin2hex(random_bytes(32)));
    }

    public static function verify(Request $request): bool
    {
        $expected = Session::get(self::KEY);
        $given = $request->post['_token'] ?? $request->header('x-csrf-token') ?? '';

        return is_string($expected) && is_string($given) && $expected !== '' && hash_equals($expected, $given);
    }
}
