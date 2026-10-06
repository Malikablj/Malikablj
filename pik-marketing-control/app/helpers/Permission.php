<?php

declare(strict_types=1);

namespace App\Helpers;

/** Evaluasi matriks hak akses di config/permissions.php. */
final class Permission
{
    public const ROLES = ['Admin', 'Marketing', 'Sales', 'Management', 'Viewer', 'PPIC', 'Produksi', 'Gudang', 'Purchasing'];

    /** Keterangan singkat tiap role (form user & README). */
    public const ROLE_HELP = [
        'Admin'      => 'Semua menu & pengaturan (kecuali review OEF dan isi Surat Jalan, khusus PPIC)',
        'Marketing'  => 'Customer & CRM, input OEF, retur & komplain, produk',
        'Sales'      => 'Customer & CRM',
        'Management' => 'Melihat semua data operasional dan laporan',
        'Viewer'     => 'Hanya melihat',
        'PPIC'       => 'Review OEF (bisa / tidak bisa diproses) dan menu Delivery termasuk Surat Jalan',
        'Produksi'   => 'Menu Stok',
        'Gudang'     => 'Menu Stok dan Inbound Maklon',
        'Purchasing' => 'Menu Inbound Supplier',
    ];

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

    /**
     * Pola di matriks:
     *   "*"              semua permission
     *   "modul.*"        semua aksi di modul
     *   "modul.aksi"     satu permission
     *   "!modul.aksi"    DIKECUALIKAN walaupun cocok dengan pola lain (mis. "*")
     */
    public static function allows(?string $role, string $permission): bool
    {
        if ($role === null || $permission === '') {
            return false;
        }
        $granted = self::matrix()[$role] ?? [];
        [$module] = explode('.', $permission, 2) + [1 => ''];
        if (in_array('!' . $permission, $granted, true) || in_array('!' . $module . '.*', $granted, true)) {
            return false;
        }
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
