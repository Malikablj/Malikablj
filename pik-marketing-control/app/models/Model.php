<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Code;
use App\Helpers\Database;
use DomainException;
use PDOException;

/**
 * Model dasar: operasi CRUD + audit log otomatis + penanganan relasi.
 * Setiap model turunan menentukan TABLE, ENTITY (nama di audit log)
 * dan LABEL (kolom yang dipakai sebagai label record).
 */
abstract class Model
{
    public const TABLE = '';
    public const ENTITY = '';
    public const LABEL = 'code';
    /** true bila tabel punya kolom created_by/updated_by */
    protected const TRACK_USER = true;

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM `' . static::TABLE . '` WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function findByCode(string $code): ?array
    {
        return Database::fetch('SELECT * FROM `' . static::TABLE . '` WHERE code = :code', ['code' => $code]);
    }

    public static function count(): int
    {
        return (int) Database::fetchValue('SELECT COUNT(*) FROM `' . static::TABLE . '`');
    }

    /** @param array<string,mixed> $row */
    public static function label(array $row): string
    {
        $value = $row[static::LABEL] ?? null;
        return $value !== null && $value !== '' ? (string) $value : (string) ($row['code'] ?? ('#' . ($row['id'] ?? '')));
    }

    /**
     * Simpan record baru. Kode bisnis dibuat otomatis bila belum ada.
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        if (!isset($data['code']) || $data['code'] === '') {
            $data['code'] = Code::generate(static::TABLE);
        }
        if (static::TRACK_USER) {
            $userId = Auth::id();
            $data['created_by'] = $data['created_by'] ?? $userId;
            $data['updated_by'] = $data['updated_by'] ?? $userId;
        }
        $id = Database::insert(static::TABLE, $data);
        Audit::log('create', static::ENTITY, $id, static::label($data), Audit::snapshot($data));
        return $id;
    }

    /**
     * Update record dan catat field yang berubah di audit log.
     * @param array<string,mixed> $data
     * @return array<string,array{old:mixed,new:mixed}> perubahan
     */
    public static function update(int $id, array $data, ?array $before = null): array
    {
        $before ??= static::find($id);
        if ($before === null) {
            throw new DomainException('Data tidak ditemukan.');
        }
        $changes = Audit::diff($before, $data);
        if ($changes === []) {
            return [];
        }
        if (static::TRACK_USER) {
            $data['updated_by'] = Auth::id();
        }
        Database::update(static::TABLE, $data, 'id = :id', ['id' => $id]);
        Audit::log('update', static::ENTITY, $id, static::label(array_merge($before, $data)), $changes);
        return $changes;
    }

    /**
     * Hapus record. Bila masih dipakai tabel lain (foreign key RESTRICT),
     * dilempar DomainException dengan pesan yang ramah.
     */
    public static function delete(int $id, ?array $before = null): void
    {
        $before ??= static::find($id);
        if ($before === null) {
            throw new DomainException('Data tidak ditemukan.');
        }
        try {
            Database::delete(static::TABLE, 'id = :id', ['id' => $id]);
        } catch (PDOException $e) {
            if (self::isForeignKeyViolation($e)) {
                throw new DomainException('Data tidak dapat dihapus karena masih terhubung dengan data lain.');
            }
            throw $e;
        }
        Audit::log('delete', static::ENTITY, $id, static::label($before), Audit::snapshot($before));
    }

    public static function isForeignKeyViolation(PDOException $e): bool
    {
        $info = $e->errorInfo ?? [];
        return ($info[0] ?? '') === '23000' && in_array((int) ($info[1] ?? 0), [1451, 1452, 1216, 1217], true);
    }

    /**
     * Opsi <select> id => label.
     * @return array<int,string>
     */
    public static function options(string $labelSql, string $where = '1=1', array $params = [], string $order = ''): array
    {
        $order = $order !== '' ? $order : $labelSql;
        $rows = Database::fetchAll('SELECT id, ' . $labelSql . ' AS label FROM `' . static::TABLE . '` WHERE ' . $where . ' ORDER BY ' . $order, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['label'];
        }
        return $out;
    }
}
