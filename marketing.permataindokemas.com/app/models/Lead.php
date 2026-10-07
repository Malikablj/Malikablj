<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class Lead extends Model
{
    public const TABLE = 'leads';
    public const ENTITY = 'lead';
    public const LABEL = 'lead_name';

    /** Urutan kolom Kanban (pipeline). */
    public const STATUSES = ['New', 'Contacted', 'Qualified', 'Quotation', 'Negotiation', 'Won', 'Lost', 'Dormant'];
    /** Lead yang masih aktif di pipeline. */
    public const OPEN_STATUSES = ['New', 'Contacted', 'Qualified', 'Quotation', 'Negotiation'];
    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];
    public const SOURCES = ['Referral', 'Website', 'Exhibition', 'WhatsApp', 'Instagram', 'Cold Call', 'Existing Customer', 'Email', 'Other'];

    /** @param array{q?:string,pic?:int,priority?:string,customer_id?:int,status?:string,from?:string,to?:string} $f */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(l.lead_name LIKE :q1 OR l.company_name LIKE :q2 OR l.code LIKE :q3 OR l.product_interest LIKE :q4 OR c.name LIKE :q5 OR l.contact_name LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['pic'])) {
            $where[] = 'l.pic_user_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        if (!empty($f['priority']) && in_array($f['priority'], self::PRIORITIES, true)) {
            $where[] = 'l.priority = :priority';
            $params['priority'] = $f['priority'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'l.customer_id = :cid';
            $params['cid'] = (int) $f['customer_id'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'l.status = :status';
            $params['status'] = $f['status'];
        } elseif (($f['status'] ?? '') === 'open') {
            $where[] = "l.status IN ('New','Contacted','Qualified','Quotation','Negotiation')";
        }
        if (!empty($f['from'])) {
            $where[] = 'l.created_at >= :from';
            $params['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $where[] = 'l.created_at <= :to';
            $params['to'] = $f['to'] . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }

    private const SELECT = 'SELECT l.*, c.name AS customer_name, u.name AS pic_name
        FROM leads l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN users u ON u.id = l.pic_user_id';

    /**
     * Data Kanban: lead per status (maks. $limit kartu per kolom) + ringkasan.
     * @return array<string,array{items:list<array<string,mixed>>,count:int,value:float}>
     */
    public static function board(array $f, int $limit = 100): array
    {
        [$where, $params] = self::filters($f);
        $columns = [];
        foreach (self::STATUSES as $status) {
            $columns[$status] = ['items' => [], 'count' => 0, 'value' => 0.0];
        }
        foreach (Database::fetchAll('SELECT l.status, COUNT(*) AS n, COALESCE(SUM(l.potential_value), 0) AS v FROM leads l LEFT JOIN customers c ON c.id = l.customer_id WHERE ' . $where . ' GROUP BY l.status', $params) as $row) {
            if (isset($columns[$row['status']])) {
                $columns[$row['status']]['count'] = (int) $row['n'];
                $columns[$row['status']]['value'] = (float) $row['v'];
            }
        }
        $rows = Database::fetchAll(
            self::SELECT . ' WHERE ' . $where . " ORDER BY FIELD(l.priority, 'Critical','High','Medium','Low'), l.expected_close_date IS NULL, l.expected_close_date ASC, l.id DESC",
            $params
        );
        foreach ($rows as $row) {
            if (isset($columns[$row['status']]) && count($columns[$row['status']]['items']) < $limit) {
                $columns[$row['status']]['items'][] = $row;
            }
        }
        return $columns;
    }

    private const SORTS = [
        'created' => 'l.created_at', 'name' => 'l.lead_name', 'value' => 'l.potential_value',
        'close' => 'l.expected_close_date', 'status' => "FIELD(l.status, 'New','Contacted','Qualified','Quotation','Negotiation','Won','Lost','Dormant')",
    ];

    public static function paginate(array $f, string $sort, string $dir, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        $order = (self::SORTS[$sort] ?? 'l.created_at') . ($dir === 'asc' ? ' ASC' : ' DESC') . ', l.id DESC';
        return Paginator::query(self::SELECT . ' WHERE ' . $where, $params, $order, $page);
    }

    /** @return array<string,mixed>|null */
    public static function findFull(int $id): ?array
    {
        return Database::fetch(
            'SELECT l.*, c.name AS customer_name, u.name AS pic_name, cu.name AS created_by_name
             FROM leads l LEFT JOIN customers c ON c.id = l.customer_id LEFT JOIN users u ON u.id = l.pic_user_id
             LEFT JOIN users cu ON cu.id = l.created_by WHERE l.id = :id',
            ['id' => $id]
        );
    }

    /** Ubah status (dipakai Kanban). @return string status lama */
    public static function changeStatus(int $id, string $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new DomainException('Status lead tidak valid.');
        }
        $lead = self::find($id);
        if ($lead === null) {
            throw new DomainException('Lead tidak ditemukan.');
        }
        $old = (string) $lead['status'];
        if ($old === $status) {
            return $old;
        }
        Database::update('leads', ['status' => $status, 'status_changed_at' => date('Y-m-d H:i:s'), 'updated_by' => Auth::id()], 'id = :id', ['id' => $id]);
        Audit::log('status_change', self::ENTITY, $id, (string) $lead['lead_name'], ['status' => ['old' => $old, 'new' => $status]]);
        return $old;
    }

    /** @param array<string,mixed> $data */
    public static function saveLead(?int $id, array $data): int
    {
        if ($id === null) {
            $data['status_changed_at'] = date('Y-m-d H:i:s');
            $newId = self::create($data);
            self::notifyAssignment($newId, null, $data);
            return $newId;
        }
        $before = self::find($id);
        if ($before !== null && ($data['status'] ?? null) !== $before['status']) {
            $data['status_changed_at'] = date('Y-m-d H:i:s');
        }
        self::update($id, $data, $before);
        self::notifyAssignment($id, $before, $data);
        return $id;
    }

    /** Beri notifikasi ke PIC baru saat lead ditugaskan kepadanya. */
    private static function notifyAssignment(int $id, ?array $before, array $data): void
    {
        $pic = $data['pic_user_id'] ?? null;
        if ($pic === null || (int) $pic === (int) Auth::id() || ($before !== null && (int) $before['pic_user_id'] === (int) $pic)) {
            return;
        }
        Notification::send((int) $pic, 'lead_assigned', 'Lead baru untuk Anda: ' . $data['lead_name'],
            'Ditugaskan oleh ' . (Auth::user()['name'] ?? 'sistem') . '.', '/leads/' . $id, 'lead', $id, 'lead-assigned-' . $id . '-' . $pic);
    }

    /** Hitung ulang tanggal kontak terakhir & follow up berikutnya dari data aktual. */
    public static function refreshDerived(?int $leadId): void
    {
        if ($leadId === null) {
            return;
        }
        Database::query(
            "UPDATE leads SET
                last_contact = (SELECT DATE(MAX(a.activity_date)) FROM activities a WHERE a.lead_id = :l1),
                next_follow_up = (SELECT MIN(f.follow_up_date) FROM follow_up f WHERE f.lead_id = :l2 AND f.status NOT IN ('Done','Cancelled'))
             WHERE id = :l3",
            ['l1' => $leadId, 'l2' => $leadId, 'l3' => $leadId]
        );
    }

    public static function deleteSafely(int $id): void
    {
        $lead = self::find($id);
        if ($lead === null) {
            throw new DomainException('Lead tidak ditemukan.');
        }
        $acts = (int) Database::fetchValue('SELECT COUNT(*) FROM activities WHERE lead_id = :id', ['id' => $id]);
        $fus = (int) Database::fetchValue('SELECT COUNT(*) FROM follow_up WHERE lead_id = :id', ['id' => $id]);
        if ($acts + $fus > 0) {
            throw new DomainException("Lead tidak dapat dihapus karena memiliki {$acts} aktivitas dan {$fus} follow up. Ubah status menjadi Lost atau Dormant.");
        }
        self::delete($id, $lead);
    }

    /**
     * Jadikan prospek (lead tanpa customer) sebagai customer baru.
     * Aktivitas & follow up lead ikut dihubungkan ke customer tersebut.
     */
    public static function convertToCustomer(int $id): int
    {
        return Database::transaction(function () use ($id): int {
            $lead = self::find($id);
            if ($lead === null) {
                throw new DomainException('Lead tidak ditemukan.');
            }
            if ($lead['customer_id'] !== null) {
                throw new DomainException('Lead ini sudah terhubung ke customer.');
            }
            $name = trim((string) ($lead['company_name'] ?: $lead['lead_name']));
            $similar = Customer::similarByName($name);
            if ($similar !== []) {
                throw new DomainException('Customer dengan nama serupa sudah ada (' . $similar[0]['name'] . '). Pilih customer tersebut di form edit lead.');
            }
            $customerId = Customer::create([
                'name'             => mb_substr($name, 0, 190),
                'company'          => $lead['company_name'],
                'pic'              => $lead['contact_name'],
                'phone'            => $lead['phone'],
                'email'            => $lead['email'],
                'status'           => $lead['status'] === 'Won' ? 'Active' : 'Potential',
                'source'           => $lead['source'],
                'marketing_pic_id' => $lead['pic_user_id'],
                'notes'            => 'Dibuat dari lead ' . $lead['code'],
            ]);
            if (!empty($lead['contact_name'])) {
                Contact::create([
                    'customer_id' => $customerId, 'name' => $lead['contact_name'], 'phone' => $lead['phone'],
                    'whatsapp' => $lead['phone'], 'email' => $lead['email'], 'is_primary' => 1, 'status' => 'Active',
                ]);
            }
            self::update($id, ['customer_id' => $customerId], $lead);
            Database::query('UPDATE activities SET customer_id = :c WHERE lead_id = :l AND customer_id IS NULL', ['c' => $customerId, 'l' => $id]);
            Database::query('UPDATE follow_up SET customer_id = :c WHERE lead_id = :l AND customer_id IS NULL', ['c' => $customerId, 'l' => $id]);
            return $customerId;
        });
    }

    /** @return array<int,string> lead untuk pilihan di form (opsional per customer) */
    public static function selectOptions(?int $customerId = null): array
    {
        $rows = Database::fetchAll(
            'SELECT l.id, l.lead_name, l.status, COALESCE(c.name, l.company_name) AS who FROM leads l LEFT JOIN customers c ON c.id = l.customer_id'
            . ($customerId ? ' WHERE l.customer_id = :c' : '') . ' ORDER BY l.created_at DESC LIMIT 500',
            $customerId ? ['c' => $customerId] : []
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['lead_name'] . ($r['who'] ? ' — ' . $r['who'] : '') . ' (' . $r['status'] . ')';
        }
        return $out;
    }

    /** Warna titik status di Kanban. */
    public static function tone(string $status): string
    {
        return match ($status) {
            'New' => '#5AC8FA', 'Contacted' => '#0071E3', 'Qualified' => '#5E5CE6', 'Quotation' => '#FF9F0A',
            'Negotiation' => '#FF6B00', 'Won' => '#34C759', 'Lost' => '#FF3B30', default => '#8E8E93',
        };
    }
}
