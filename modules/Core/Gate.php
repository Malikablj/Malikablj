<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Otorisasi server-side (PRD §2.3). Sumber: tabel role_permissions (role × permission × scope).
 *
 *   Gate::can($user, 'npr.submit', ['owner_ids' => [$npr['sales_pic_id'], $npr['created_by']]])
 *
 * scope 'all' → diizinkan; scope 'own' → diizinkan hanya bila $user->id ada di context['owner_ids'].
 * Bila izin ber-scope 'own' dicek tanpa context (mis. untuk menampilkan menu), hasilnya true
 * hanya jika context['any'] = true — service WAJIB mengirim owner_ids saat mengubah data.
 */
final class Gate
{
    /** @var array<string,array<string,string>> role_code => [permission => scope] */
    private static array $matrix = [];

    /** @return array<string,string> */
    public static function permissionsFor(string $roleCode): array
    {
        if (!isset(self::$matrix[$roleCode])) {
            $rows = Db::fetchAll(
                'SELECT p.code, rp.scope FROM role_permissions rp
                 JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                 WHERE r.code = ?',
                [$roleCode]
            );
            self::$matrix[$roleCode] = array_column($rows, 'scope', 'code');
        }
        return self::$matrix[$roleCode];
    }

    /** @param array{owner_ids?:list<int|string|null>,any?:bool} $context */
    public static function can(?User $user, string $ability, array $context = []): bool
    {
        if ($user === null || !$user->isActive) {
            return false;
        }
        $scope = self::permissionsFor($user->roleCode)[$ability] ?? null;
        if ($scope === null) {
            return false;
        }
        if ($scope === 'all') {
            return true;
        }
        // scope 'own'
        if (!empty($context['any'])) {
            return true;
        }
        $owners = array_map('intval', array_filter($context['owner_ids'] ?? [], static fn ($v) => $v !== null && $v !== ''));
        return in_array($user->id, $owners, true);
    }

    /** Scope izin untuk user ('all' | 'own' | null). */
    public static function scope(?User $user, string $ability): ?string
    {
        return $user ? (self::permissionsFor($user->roleCode)[$ability] ?? null) : null;
    }

    /**
     * @param array{owner_ids?:list<int|string|null>,any?:bool} $context
     * @throws AuthorizationException
     */
    public static function authorize(?User $user, string $ability, array $context = []): void
    {
        if (!self::can($user, $ability, $context)) {
            if ($user !== null) {
                AuditLogger::log('authz.denied', 'permission', $ability, null, ['context' => array_keys($context)], null, null, $user);
            }
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
    }

    public static function flush(): void
    {
        self::$matrix = [];
    }
}
