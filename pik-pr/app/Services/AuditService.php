<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\AuditLogRepository;

final class AuditService
{
    private const HIDDEN_KEYS = ['password', 'password_hash', 'password_confirmation', 'current_password', '_token'];

    private AuditLogRepository $logs;

    public function __construct(?AuditLogRepository $logs = null)
    {
        $this->logs = $logs ?? new AuditLogRepository();
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $old = null,
        ?array $new = null,
    ): void {
        $request = Request::current();

        $this->logs->create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old === null ? null : self::encode($old),
            'new_values' => $new === null ? null : self::encode($new),
            'ip_address' => $request !== null ? mb_substr($request->ip(), 0, 45) : 'cli',
            'user_agent' => $request !== null ? ($request->userAgent() ?: null) : 'cli',
        ]);
    }

    /**
     * Hanya field yang berubah.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $old, array $new): array
    {
        $before = [];
        $after = [];
        foreach ($new as $key => $value) {
            $previous = $old[$key] ?? null;
            if (json_encode($previous) !== json_encode($value)) {
                $before[$key] = $previous;
                $after[$key] = $value;
            }
        }

        return [$before, $after];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function encode(array $values): string
    {
        foreach (self::HIDDEN_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[disembunyikan]';
            }
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
