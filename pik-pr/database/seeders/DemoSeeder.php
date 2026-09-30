<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Core\Env;
use App\Repositories\UserRepository;
use App\Services\ApprovalService;
use App\Services\PrService;
use RuntimeException;

/**
 * Data demo: user per role, master data, workflow default "Diketahui -> Disetujui",
 * dan beberapa PR di berbagai status. PR dibuat lewat service yang sama dengan
 * aplikasi sehingga nomor PR, approval log, notifikasi, dan audit trail ikut terisi.
 */
final class DemoSeeder
{
    public const DEFAULT_PASSWORD = 'PikDemo2026!';

    /** @var callable(string): void */
    private $out;

    public function __construct(?callable $out = null)
    {
        $this->out = $out ?? static function (string $line): void {
        };
    }

    public static function password(): string
    {
        $password = (string) (Env::get('SEED_PASSWORD') ?? '');

        return $password !== '' ? $password : self::DEFAULT_PASSWORD;
    }

    public function run(): void
    {
        $db = Database::connection();
        if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new RuntimeException('Database sudah berisi user. Jalankan `php bin/migrate.php --fresh --seed` untuk memulai dari data demo yang bersih.');
        }

        $ids = Database::transaction(fn (): array => $this->masterData());
        ($this->out)('  master data, user, dan workflow dibuat');

        $this->demoRequisitions($ids);
        ($this->out)('  PR demo dibuat');

        ($this->out)('');
        ($this->out)('Akun demo (password: ' . self::password() . '):');
        foreach (['superadmin@pik.local' => 'Super Admin', 'admin@pik.local' => 'Admin', 'mitha@pik.local' => 'Requester', 'budi@pik.local' => 'Approver (Diketahui)', 'hendra@pik.local' => 'Approver (Disetujui)'] as $email => $role) {
            ($this->out)(sprintf('  %-24s %s', $email, $role));
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function masterData(): array
    {
        $db = Database::connection();
        $insert = static function (string $table, array $row) use ($db): int {
            $columns = array_keys($row);
            $stmt = $db->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            ));
            $stmt->execute(array_values($row));

            return (int) $db->lastInsertId();
        };

        $departments = [];
        foreach ([
            ['PD', 'Production Injection'],
            ['PB', 'Production Blow Molding'],
            ['QC', 'Quality Control'],
            ['MT', 'Maintenance'],
            ['WH', 'Warehouse'],
            ['PU', 'Purchasing'],
            ['GA', 'HRGA'],
        ] as [$code, $name]) {
            $departments[$code] = $insert('departments', ['code' => $code, 'name' => $name, 'is_active' => 1]);
        }

        $hash = password_hash(self::password(), PASSWORD_DEFAULT);
        $users = [];
        foreach ([
            ['superadmin', 'Super Admin', 'superadmin@pik.local', 'super_admin', null, 'System Administrator'],
            ['admin', 'Rina Kartika', 'admin@pik.local', 'admin', 'PU', 'Admin Purchasing'],
            ['mitha', 'Mitha Alzahra', 'mitha@pik.local', 'requester', 'PD', 'Staff Production Injection'],
            ['dewi', 'Dewi Lestari', 'dewi@pik.local', 'requester', 'QC', 'Staff Quality Control'],
            ['budi', 'Budi Santoso', 'budi@pik.local', 'approver', 'PD', 'Supervisor Production'],
            ['hendra', 'Hendra Wijaya', 'hendra@pik.local', 'approver', 'PD', 'Plant Manager'],
        ] as [$key, $name, $email, $role, $dept, $title]) {
            $users[$key] = $insert('users', [
                'name' => $name,
                'email' => $email,
                'password_hash' => $hash,
                'role' => $role,
                'department_id' => $dept !== null ? $departments[$dept] : null,
                'job_title' => $title,
                'is_active' => 1,
            ]);
        }

        $suppliers = [];
        foreach ([
            ['SHP', 'Shopee', 'Marketplace - toko resmi', 'Online'],
            ['TKP', 'Tokopedia', 'Marketplace - toko resmi', 'Online'],
            ['SNT', 'CV Sinar Teknik', 'Bapak Rudi - 0812-0000-1111', 'Jl. Industri Raya No. 12, Cikarang'],
            ['MPL', 'PT Mitra Plastindo', 'Ibu Sari - 021-555-0101', 'Kawasan Industri Jababeka Blok C-7, Bekasi'],
        ] as [$code, $name, $contact, $address]) {
            $suppliers[$code] = $insert('suppliers', ['code' => $code, 'name' => $name, 'contact' => $contact, 'address' => $address, 'is_active' => 1]);
        }

        $items = [];
        foreach ([
            ['LEM-KR', 'Lem korea', 'Lem serbaguna untuk perbaikan part', 'pcs', '65000.00'],
            ['AUTOSOL', 'Autosol', 'Metal polish untuk permukaan mold', 'pcs', '50000.00'],
            ['GLV-01', 'Sarung tangan kerja', 'Sarung tangan katun bintik', 'pasang', '12000.00'],
            ['MAJUN', 'Majun', 'Kain majun putih', 'kg', '15000.00'],
            ['WD40', 'WD-40 333ml', 'Pelumas & anti karat', 'kaleng', '85000.00'],
        ] as [$code, $name, $description, $unit, $price]) {
            $items[$code] = $insert('items', ['code' => $code, 'name' => $name, 'description' => $description, 'unit' => $unit, 'default_price' => $price, 'is_active' => 1]);
        }

        $workflow = $insert('approval_workflows', [
            'name' => 'Workflow Default PIK',
            'department_id' => null,
            'description' => 'Diketahui oleh supervisor, disetujui oleh plant manager.',
            'is_active' => 1,
        ]);
        $insert('approval_steps', ['workflow_id' => $workflow, 'step_order' => 1, 'label' => 'Diketahui', 'approver_type' => 'user', 'approver_user_id' => $users['budi'], 'is_required' => 1]);
        $insert('approval_steps', ['workflow_id' => $workflow, 'step_order' => 2, 'label' => 'Disetujui', 'approver_type' => 'user', 'approver_user_id' => $users['hendra'], 'is_required' => 1]);

        return ['departments' => $departments, 'users' => $users, 'suppliers' => $suppliers, 'items' => $items];
    }

    /**
     * @param array<string, array<string, int>> $ids
     */
    private function demoRequisitions(array $ids): void
    {
        $userRepo = new UserRepository();
        $mitha = $userRepo->find($ids['users']['mitha']);
        $dewi = $userRepo->find($ids['users']['dewi']);
        $budi = $userRepo->find($ids['users']['budi']);
        $hendra = $userRepo->find($ids['users']['hendra']);
        $admin = $userRepo->find($ids['users']['admin']);

        $prs = new PrService();
        $approvals = new ApprovalService();
        $pd = $ids['departments']['PD'];
        $item = static fn (string $code, string $name, string $qty, string $unit, string $price, string $desc = ''): array => [
            'item_id' => (string) $ids['items'][$code],
            'name' => $name,
            'description' => $desc,
            'quantity' => $qty,
            'unit' => $unit,
            'unit_price' => $price,
        ];
        $input = static fn (int $dept, string $supplier, string $daysAgo, string $tax, string $notes, array $items): array => [
            'department_id' => (string) $dept,
            'supplier_id' => (string) $ids['suppliers'][$supplier],
            'pr_date' => date('Y-m-d', strtotime("-{$daysAgo} days")),
            'tax_rate' => $tax,
            'notes' => $notes,
            'items' => $items,
        ];

        // 1. Selesai (diarsipkan)
        $id = $prs->create($mitha, $input($pd, 'MPL', '12', '11', 'Kebutuhan bulanan area injection.', [
            $item('MAJUN', 'Majun', '25', 'kg', '15000'),
            $item('GLV-01', 'Sarung tangan kerja', '30', 'pasang', '12000'),
        ]));
        $prs->submit($mitha, $id);
        $approvals->approve($budi, $id, 'Sesuai kebutuhan bulanan.');
        $approvals->approve($hendra, $id);
        $prs->complete($admin, $id);

        // 2. Approved - menyerupai dokumen referensi (Production Injection, Shopee, Rp280.000, pajak 0%)
        $id = $prs->create($mitha, $input($pd, 'SHP', '3', '0', 'Untuk perbaikan dan polishing mold line 3.', [
            $item('LEM-KR', 'Lem korea', '2', 'pcs', '65000'),
            $item('AUTOSOL', 'Autosol', '3', 'pcs', '50000'),
        ]));
        $prs->submit($mitha, $id);
        $approvals->approve($budi, $id, 'Sudah dicek, diperlukan untuk maintenance mold.');
        $approvals->approve($hendra, $id, 'Disetujui.');

        // 3. In Review - menunggu "Disetujui"
        $id = $prs->create($mitha, $input($pd, 'SNT', '2', '11', 'Stok pelumas mesin menipis.', [
            $item('WD40', 'WD-40 333ml', '6', 'kaleng', '85000'),
        ]));
        $prs->submit($mitha, $id);
        $approvals->approve($budi, $id, 'OK, stok memang habis.');

        // 4. Submitted - menunggu "Diketahui"
        $id = $prs->create($mitha, $input($pd, 'TKP', '1', '11', 'APD untuk operator shift malam.', [
            $item('GLV-01', 'Sarung tangan kerja', '20', 'pasang', '12000'),
            $item('MAJUN', 'Majun', '10', 'kg', '15000'),
        ]));
        $prs->submit($mitha, $id);

        // 5. Revision Required
        $id = $prs->create($mitha, $input($pd, 'SHP', '5', '0', 'Pengganti lem yang habis.', [
            $item('LEM-KR', 'Lem korea', '10', 'pcs', '70000'),
        ]));
        $prs->submit($mitha, $id);
        $approvals->requestRevision($budi, $id, 'Harga lebih tinggi dari biasanya. Mohon cek ulang harga dan lampirkan pembanding.');

        // 6. Rejected
        $id = $prs->create($mitha, $input($pd, 'SNT', '8', '11', 'Pembelian stok cadangan pelumas.', [
            $item('WD40', 'WD-40 333ml', '24', 'kaleng', '85000'),
        ]));
        $prs->submit($mitha, $id);
        $approvals->approve($budi, $id, 'Diteruskan ke plant manager.');
        $approvals->reject($hendra, $id, 'Stok cadangan belum diperlukan bulan ini.');

        // 7. Draft
        $prs->create($mitha, $input($pd, 'SHP', '0', '0', '', [
            $item('AUTOSOL', 'Autosol', '1', 'pcs', '50000'),
        ]));

        // 8. PR department lain yang menunggu approval
        $id = $prs->create($dewi, $input($ids['departments']['QC'], 'TKP', '1', '0', 'Perlengkapan inspeksi.', [
            $item('GLV-01', 'Sarung tangan kerja', '12', 'pasang', '12000'),
        ]));
        $prs->submit($dewi, $id);
    }
}
