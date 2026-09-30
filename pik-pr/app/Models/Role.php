<?php

declare(strict_types=1);

namespace App\Models;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Approver = 'approver';
    case Requester = 'requester';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Approver => 'Approver',
            self::Requester => 'Requester',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * Role yang boleh ditunjuk sebagai approver berbasis role pada workflow.
     *
     * @return list<string>
     */
    public static function approverRoles(): array
    {
        return [self::Approver->value, self::Admin->value, self::SuperAdmin->value];
    }

    public static function isAdmin(string $role): bool
    {
        return $role === self::Admin->value || $role === self::SuperAdmin->value;
    }
}
