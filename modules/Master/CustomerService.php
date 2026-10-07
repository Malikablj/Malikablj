<?php
declare(strict_types=1);

namespace App\Master;

use App\Core\AuditLogger;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;

/** Master customer (PRD §3.2): kode, nama, kontak, alamat invoice & kirim, telepon. Dikelola Admin. */
final class CustomerService
{
    /** @return list<array<string,mixed>> */
    public function list(?string $search = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM projects p WHERE p.customer_id = c.id) AS project_count FROM customers c WHERE 1 = 1';
        $params = [];
        if ($activeOnly) {
            $sql .= ' AND c.is_active = 1';
        }
        if ($search !== null && $search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $sql .= ' AND (c.name LIKE ? OR c.code LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        return Db::fetchAll($sql . ' ORDER BY c.is_active DESC, c.name', $params);
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $row = Db::fetch('SELECT * FROM customers WHERE id = ?', [$id]);
        if (!$row) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $row['contacts'] = Db::fetchAll('SELECT * FROM customer_contacts WHERE customer_id = ? ORDER BY is_primary DESC, name', [$id]);
        return $row;
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): int
    {
        Gate::authorize($actor, 'customer.manage');
        $clean = $this->validate($data, null);
        return Db::transaction(function () use ($actor, $clean): int {
            $id = Db::insert('customers', $clean + ['is_active' => 1, 'created_by' => $actor->id]);
            AuditLogger::log('customer.create', 'customer', $id, null, $clean, null, null, $actor);
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, int $id, array $data): void
    {
        Gate::authorize($actor, 'customer.manage');
        $before = $this->get($id);
        $clean = $this->validate($data, $id);
        Db::transaction(function () use ($actor, $id, $before, $clean): void {
            Db::update('customers', $clean, ['id' => $id]);
            [$o, $n] = AuditLogger::diff($before, $clean);
            if ($n !== []) {
                AuditLogger::log('customer.update', 'customer', $id, $o, $n, null, null, $actor);
            }
        });
    }

    public function setActive(User $actor, int $id, bool $active): void
    {
        Gate::authorize($actor, 'customer.manage');
        $this->get($id);
        Db::transaction(function () use ($actor, $id, $active): void {
            Db::update('customers', ['is_active' => $active ? 1 : 0], ['id' => $id]);
            AuditLogger::log($active ? 'customer.activate' : 'customer.deactivate', 'customer', $id, null, null, null, null, $actor);
        });
    }

    /** @param array<string,mixed> $data name, position, phone, email, is_primary */
    public function addContact(User $actor, int $customerId, array $data): int
    {
        Gate::authorize($actor, 'customer.manage');
        $this->get($customerId);
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['contact_name'] = I18n::t('validation.required_max', ['max' => 120]);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = I18n::t('validation.email');
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return Db::transaction(function () use ($actor, $customerId, $data, $name, $email): int {
            $primary = !empty($data['is_primary']) ? 1 : 0;
            if ($primary) {
                Db::update('customer_contacts', ['is_primary' => 0], ['customer_id' => $customerId]);
            }
            $id = Db::insert('customer_contacts', [
                'customer_id' => $customerId,
                'name' => $name,
                'position' => mb_substr(trim((string) ($data['position'] ?? '')), 0, 120) ?: null,
                'phone' => mb_substr(trim((string) ($data['phone'] ?? '')), 0, 60) ?: null,
                'email' => $email ?: null,
                'is_primary' => $primary,
            ]);
            AuditLogger::log('customer.contact_add', 'customer', $customerId, null, ['contact' => $name], null, null, $actor);
            return $id;
        });
    }

    public function removeContact(User $actor, int $customerId, int $contactId): void
    {
        Gate::authorize($actor, 'customer.manage');
        Db::transaction(function () use ($actor, $customerId, $contactId): void {
            $name = Db::value('SELECT name FROM customer_contacts WHERE id = ? AND customer_id = ?', [$contactId, $customerId]);
            if ($name === null) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            Db::execute('DELETE FROM customer_contacts WHERE id = ? AND customer_id = ?', [$contactId, $customerId]);
            AuditLogger::log('customer.contact_remove', 'customer', $customerId, ['contact' => $name], null, null, null, $actor);
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array{code:string,name:string,invoice_address:?string,shipping_address:?string,phone:?string,email:?string}
     */
    private function validate(array $data, ?int $id): array
    {
        $errors = [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $code)) {
            $errors['code'] = I18n::t('customer.code_invalid');
        } elseif (Db::value('SELECT id FROM customers WHERE code = ? AND (? IS NULL OR id <> ?)', [$code, $id, $id])) {
            $errors['code'] = I18n::t('customer.code_taken');
        }
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = I18n::t('validation.required_max', ['max' => 190]);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = I18n::t('validation.email');
        }
        $phone = trim((string) ($data['phone'] ?? ''));
        if (mb_strlen($phone) > 60) {
            $errors['phone'] = I18n::t('validation.max', ['max' => 60]);
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $text = static fn (string $k): ?string => ($v = trim((string) ($data[$k] ?? ''))) !== '' ? mb_substr($v, 0, 2000) : null;
        return [
            'code' => $code,
            'name' => $name,
            'invoice_address' => $text('invoice_address'),
            'shipping_address' => $text('shipping_address'),
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
        ];
    }
}
