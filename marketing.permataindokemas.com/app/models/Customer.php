<?php

declare(strict_types=1);

namespace App\Models;

use App\Helpers\Database;
use App\Helpers\Paginator;
use DomainException;

final class Customer extends Model
{
    public const TABLE = 'customers';
    public const ENTITY = 'customer';
    public const LABEL = 'name';

    public const STATUSES = ['Active', 'Inactive', 'Potential', 'Dormant'];

    /** Kolom yang boleh diurutkan: key URL => ekspresi SQL */
    private const SORTS = [
        'name'          => 'c.name',
        'status'        => 'c.status',
        'created'       => 'c.created_at',
        'last_activity' => 'act.last_activity',
        'open_po'       => 'po.open_po',
        'outstanding'   => 'po.outstanding',
    ];

    /**
     * @param array{q?:string,status?:string,pic?:int,industry?:string} $f
     */
    public static function paginate(array $f, string $sort, string $dir, int $page): Paginator
    {
        [$where, $params] = self::filters($f);
        $orderCol = self::SORTS[$sort] ?? 'c.name';
        $orderDir = $dir === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT c.*, u.name AS pic_name,
                       COALESCE(con.contact_count, 0) AS contact_count,
                       COALESCE(po.open_po, 0) AS open_po,
                       COALESCE(po.outstanding, 0) AS outstanding,
                       act.last_activity
                FROM customers c
                LEFT JOIN users u ON u.id = c.marketing_pic_id
                LEFT JOIN (SELECT customer_id, COUNT(*) AS contact_count FROM contacts GROUP BY customer_id) con ON con.customer_id = c.id
                LEFT JOIN (SELECT p.customer_id,
                                  SUM(p.status IN (\'Open\',\'On Process\',\'Partial\')) AS open_po,
                                  SUM(CASE WHEN p.status IN (\'Open\',\'On Process\',\'Partial\') THEN COALESCE(t.open_outstanding_qty, 0) ELSE 0 END) AS outstanding
                           FROM purchase_orders p
                           LEFT JOIN (' . PoLine::poTotalsSql() . ') t ON t.po_id = p.id
                           WHERE p.customer_id IS NOT NULL
                           GROUP BY p.customer_id) po ON po.customer_id = c.id
                LEFT JOIN (SELECT customer_id, MAX(activity_date) AS last_activity FROM activities WHERE customer_id IS NOT NULL GROUP BY customer_id) act ON act.customer_id = c.id
                WHERE ' . $where;
        return Paginator::query($sql, $params, "{$orderCol} {$orderDir}, c.id ASC", $page);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filters(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(c.name LIKE :q1 OR c.company LIKE :q2 OR c.code LIKE :q3 OR c.pic LIKE :q4 OR c.phone LIKE :q5 OR c.email LIKE :q6)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $k) {
                $params[$k] = Database::like((string) $f['q']);
            }
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $f['status'];
        }
        if (!empty($f['pic'])) {
            $where[] = 'c.marketing_pic_id = :pic';
            $params['pic'] = (int) $f['pic'];
        }
        if (!empty($f['industry'])) {
            $where[] = 'c.industry = :industry';
            $params['industry'] = $f['industry'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<string,mixed>|null */
    public static function findWithPic(int $id): ?array
    {
        return Database::fetch(
            'SELECT c.*, u.name AS pic_name, cu.name AS created_by_name, uu.name AS updated_by_name
             FROM customers c
             LEFT JOIN users u ON u.id = c.marketing_pic_id
             LEFT JOIN users cu ON cu.id = c.created_by
             LEFT JOIN users uu ON uu.id = c.updated_by
             WHERE c.id = :id',
            ['id' => $id]
        );
    }

    /** Normalisasi nama untuk deteksi duplikat: "PT. Cantik  Mandiri" => "pt cantik mandiri" */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /** @return list<array<string,mixed>> customer lain dengan nama yang sama setelah normalisasi */
    public static function similarByName(string $name, ?int $excludeId = null): array
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '') {
            return [];
        }
        $rows = Database::fetchAll('SELECT id, code, name FROM customers WHERE id <> :ex', ['ex' => $excludeId ?? 0]);
        return array_values(array_filter($rows, static fn ($r) => self::normalizeName((string) $r['name']) === $normalized));
    }

    /**
     * Jumlah data terkait (untuk tab & validasi hapus).
     * @return array<string,int>
     */
    public static function relatedCounts(int $id): array
    {
        $row = Database::fetch(
            'SELECT
                (SELECT COUNT(*) FROM contacts WHERE customer_id = :c1) AS contacts,
                (SELECT COUNT(*) FROM leads WHERE customer_id = :c2) AS leads,
                (SELECT COUNT(*) FROM activities WHERE customer_id = :c3) AS activities,
                (SELECT COUNT(*) FROM follow_up WHERE customer_id = :c4) AS followups,
                (SELECT COUNT(*) FROM purchase_orders WHERE customer_id = :c5) AS pos,
                (SELECT COUNT(*) FROM deliveries d JOIN purchase_orders p ON p.id = d.po_id WHERE p.customer_id = :c6) AS deliveries,
                (SELECT COUNT(*) FROM returns r LEFT JOIN purchase_orders p ON p.id = r.po_id WHERE COALESCE(r.customer_id, p.customer_id) = :c7) AS returns,
                (SELECT COUNT(*) FROM invoices_payments WHERE customer_id = :c8) AS invoices',
            ['c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id, 'c5' => $id, 'c6' => $id, 'c7' => $id, 'c8' => $id]
        ) ?? [];
        return array_map('intval', $row);
    }

    /**
     * Ringkasan untuk halaman detail customer.
     * @return array<string,mixed>
     */
    public static function summary(int $id, string $today): array
    {
        $leads = Database::fetch(
            "SELECT COUNT(*) AS open_count, COALESCE(SUM(potential_value), 0) AS pipeline
             FROM leads WHERE customer_id = :c AND status NOT IN ('Won','Lost','Dormant')",
            ['c' => $id]
        ) ?? [];
        $follow = Database::fetch(
            "SELECT COALESCE(SUM(status NOT IN ('Done','Cancelled')), 0) AS open_count,
                    COALESCE(SUM(status NOT IN ('Done','Cancelled') AND follow_up_date < :d), 0) AS overdue
             FROM follow_up WHERE customer_id = :c",
            ['c' => $id, 'd' => $today]
        ) ?? [];
        $po = Database::fetch(
            "SELECT COUNT(*) AS po_count,
                    COALESCE(SUM(p.status IN ('Open','On Process','Partial')), 0) AS open_po,
                    COALESCE(SUM(t.total_qty), 0) AS total_qty,
                    COALESCE(SUM(t.delivered_qty), 0) AS delivered_qty,
                    COALESCE(SUM(CASE WHEN p.status IN ('Open','On Process','Partial') THEN t.open_outstanding_qty ELSE 0 END), 0) AS outstanding
             FROM purchase_orders p
             LEFT JOIN (" . PoLine::poTotalsSql() . ") t ON t.po_id = p.id
             WHERE p.customer_id = :c",
            ['c' => $id]
        ) ?? [];
        $lastActivity = Database::fetchValue('SELECT MAX(activity_date) FROM activities WHERE customer_id = :c', ['c' => $id]);
        return [
            'leads_open'        => (int) ($leads['open_count'] ?? 0),
            'leads_pipeline'    => (float) ($leads['pipeline'] ?? 0),
            'followups_open'    => (int) ($follow['open_count'] ?? 0),
            'followups_overdue' => (int) ($follow['overdue'] ?? 0),
            'po_count'          => (int) ($po['po_count'] ?? 0),
            'po_open'           => (int) ($po['open_po'] ?? 0),
            'order_qty'         => (int) ($po['total_qty'] ?? 0),
            'delivered_qty'     => (int) ($po['delivered_qty'] ?? 0),
            'outstanding_qty'   => (int) ($po['outstanding'] ?? 0),
            'last_activity'     => $lastActivity !== null ? (string) $lastActivity : null,
        ];
    }

    /**
     * Hapus customer hanya bila tidak punya transaksi/riwayat CRM.
     * Kontak ikut terhapus (ON DELETE CASCADE).
     */
    public static function deleteSafely(int $id): void
    {
        $customer = self::find($id);
        if ($customer === null) {
            throw new DomainException('Customer tidak ditemukan.');
        }
        $counts = self::relatedCounts($id);
        $blocking = [];
        foreach (['leads' => 'lead', 'activities' => 'activity', 'followups' => 'follow up', 'pos' => 'PO', 'invoices' => 'invoice'] as $key => $label) {
            if (($counts[$key] ?? 0) > 0) {
                $blocking[] = $counts[$key] . ' ' . $label;
            }
        }
        if ($blocking !== []) {
            throw new DomainException('Customer tidak dapat dihapus karena masih memiliki ' . implode(', ', $blocking) . '. Ubah status menjadi Inactive bila customer sudah tidak aktif.');
        }
        self::delete($id, $customer);
    }

    /**
     * Customer untuk Order Entry Form: nama customer diketik manual.
     * Dicari berurutan: nama sama persis (tidak peka huruf besar/kecil & spasi), lalu nama
     * yang sama setelah normalisasi ("PT. Cantik" = "pt cantik"). Bila belum ada, customer
     * baru dicatat otomatis di menu Customers (status Active, PIC = sales bila dikenali).
     * @return array{id:int,created:bool,name:string}
     */
    public static function findOrCreateForOrder(string $name, ?string $orderLabel = null, ?int $picUserId = null): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            throw new DomainException('Nama customer wajib diisi.');
        }
        $exact = Database::fetch(
            "SELECT id, name FROM customers WHERE LOWER(TRIM(name)) = LOWER(:n) ORDER BY status = 'Active' DESC, id ASC LIMIT 1",
            ['n' => $name]
        );
        if ($exact !== null) {
            return ['id' => (int) $exact['id'], 'created' => false, 'name' => (string) $exact['name']];
        }
        $similar = self::similarByName($name);
        if ($similar !== []) {
            return ['id' => (int) $similar[0]['id'], 'created' => false, 'name' => (string) $similar[0]['name']];
        }
        $id = self::create([
            'name'             => mb_substr($name, 0, 190),
            'status'           => 'Active',
            'marketing_pic_id' => $picUserId,
            'notes'            => 'Dicatat otomatis dari Order Entry Form' . ($orderLabel ? ' ' . $orderLabel : '') . '. Lengkapi alamat, kontak, dan data lainnya di menu Customers.',
        ]);
        return ['id' => $id, 'created' => true, 'name' => mb_substr($name, 0, 190)];
    }

    /** @return list<string> nama customer (saran input Order Entry Form) */
    public static function nameSuggestions(int $limit = 2000): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT name FROM customers ORDER BY name LIMIT ' . max(1, $limit)));
    }

    /** @return array<int,string> */
    public static function selectOptions(): array
    {
        return self::options('name');
    }

    /** @return list<string> */
    public static function industries(): array
    {
        return array_map('strval', Database::fetchColumn('SELECT DISTINCT industry FROM customers WHERE industry IS NOT NULL AND industry <> \'\' ORDER BY industry'));
    }

    /** @return list<string> */
    public static function sources(): array
    {
        $existing = array_map('strval', Database::fetchColumn('SELECT DISTINCT source FROM customers WHERE source IS NOT NULL AND source <> \'\' ORDER BY source'));
        return array_values(array_unique(array_merge(['Referral', 'Website', 'Exhibition', 'WhatsApp', 'Instagram', 'Cold Call', 'Existing Customer'], $existing)));
    }
}
