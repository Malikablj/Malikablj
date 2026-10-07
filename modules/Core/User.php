<?php
declare(strict_types=1);

namespace App\Core;

/** Pengguna yang sedang beraksi (dibangun dari baris users JOIN roles). */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly int $roleId,
        public readonly string $roleCode,
        public readonly ?string $jobTitle,
        public readonly string $language,
        public readonly string $theme,
        public readonly bool $isActive,
        public readonly bool $mustChangePassword,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (int) $row['role_id'],
            (string) $row['role_code'],
            $row['job_title'] !== null ? (string) $row['job_title'] : null,
            (string) ($row['language'] ?? 'id'),
            (string) ($row['theme'] ?? 'system'),
            (bool) $row['is_active'],
            (bool) ($row['must_change_password'] ?? false),
        );
    }

    public static function find(int $id): ?self
    {
        $row = Db::fetch(
            'SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public function hasRole(string ...$codes): bool
    {
        return in_array($this->roleCode, $codes, true);
    }

    public function isAdmin(): bool
    {
        return $this->roleCode === 'admin';
    }

    public function isReadOnly(): bool
    {
        return $this->roleCode === 'management';
    }
}
