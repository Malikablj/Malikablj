<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * Memuat user yang login dari session. User nonaktif atau sesi yang idle
     * terlalu lama langsung dikeluarkan.
     */
    public static function boot(): void
    {
        self::$user = null;
        $id = Session::get('user_id');
        if (!is_int($id)) {
            return;
        }

        $lifetime = (int) Config::get('app.session.lifetime', 120) * 60;
        $last = (int) Session::get('last_activity', 0);
        if ($last > 0 && time() - $last > $lifetime) {
            Session::invalidate();
            Session::flash('info', 'Sesi Anda berakhir karena tidak ada aktivitas. Silakan login kembali.');

            return;
        }

        $user = (new UserRepository())->find($id);
        if ($user === null || !(bool) $user['is_active']) {
            Session::invalidate();

            return;
        }

        self::$user = $user;
        Session::put('last_activity', time());
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function login(array $user): void
    {
        Session::regenerate();
        Csrf::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('last_activity', time());
        self::$user = $user;
    }

    public static function logout(): void
    {
        Session::invalidate();
        Csrf::regenerate();
        self::$user = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user !== null ? (int) self::$user['id'] : null;
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function reset(): void
    {
        self::$user = null;
    }
}
