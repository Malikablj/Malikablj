<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;

final class Contact extends Model
{
    public const TABLE = 'contacts';
    public const ENTITY = 'contact';
    public const LABEL = 'name';

    public const STATUSES = ['Active', 'Inactive'];

    /** @param array{q?:string,customer_id?:int,status?:string} $f */
    public static function paginate(array $f, int $page): Paginator
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(ct.name LIKE :q1 OR ct.phone LIKE :q2 OR ct.whatsapp LIKE :q3 OR ct.email LIKE :q4 OR ct.position LIKE :q5 OR c.name LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'ct.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'ct.status = :status';
            $params['status'] = $f['status'];
        }
        return Paginator::query(
            'SELECT ct.*, c.name AS customer_name FROM contacts ct JOIN customers c ON c.id = ct.customer_id WHERE ' . implode(' AND ', $where),
            $params,
            'c.name ASC, ct.is_primary DESC, ct.name ASC',
            $page
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forCustomer(int $customerId): array
    {
        return Database::fetchAll(
            'SELECT * FROM contacts WHERE customer_id = :c ORDER BY is_primary DESC, status ASC, name ASC',
            ['c' => $customerId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findWithCustomer(int $id): ?array
    {
        return Database::fetch(
            'SELECT ct.*, c.name AS customer_name FROM contacts ct JOIN customers c ON c.id = ct.customer_id WHERE ct.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Simpan kontak; bila ditandai primary, kontak primary lain di customer
     * yang sama otomatis dilepas (hanya satu kontak utama per customer).
     * @param array<string,mixed> $data
     */
    public static function save(?int $id, array $data): int
    {
        return Database::transaction(function () use ($id, $data): int {
            if ((int) ($data['is_primary'] ?? 0) === 1) {
                Database::query(
                    'UPDATE contacts SET is_primary = 0 WHERE customer_id = :c AND is_primary = 1' . ($id ? ' AND id <> :id' : ''),
                    $id ? ['c' => $data['customer_id'], 'id' => $id] : ['c' => $data['customer_id']]
                );
            }
            if ($id === null) {
                return self::create($data);
            }
            self::update($id, $data);
            return $id;
        });
    }

    /** Link WhatsApp (wa.me) dari nomor Indonesia: 0812… => 62812… */
    public static function waLink(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }
        return strlen($digits) >= 9 ? 'https://wa.me/' . $digits : null;
    }
}
