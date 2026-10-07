<?php

declare(strict_types=1);

namespace App\Helpers;

/** Evaluasi matriks hak akses di config/permissions.php. */
final class Permission
{
    public const ROLES = ['Admin', 'Marketing', 'Sales', 'Management', 'PPIC', 'Produksi', 'Gudang', 'Viewer'];

    /** @var array<string,list<string>>|null */
    private static ?array $matrix = null;

    /** @return array<string,list<string>> */
    public static function matrix(): array
    {
        if (self::$matrix === null) {
            self::$matrix = require APP_ROOT . '/config/permissions.php';
        }
        return self::$matrix;
    }

    public static function allows(?string $role, string $permission): bool
    {
        if ($role === null || $permission === '') {
            return false;
        }
        $granted = self::matrix()[$role] ?? [];
        [$module] = explode('.', $permission, 2) + [1 => ''];
        foreach ($granted as $pattern) {
            if ($pattern === '*' || $pattern === $permission || $pattern === $module . '.*') {
                return true;
            }
        }
        return false;
    }

    /** True bila role punya minimal satu permission di modul tersebut. */
    public static function allowsAny(?string $role, string $module): bool
    {
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            if (self::allows($role, $module . '.' . $action)) {
                return true;
            }
        }
        return false;
    }
}
