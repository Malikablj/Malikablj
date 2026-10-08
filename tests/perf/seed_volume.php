<?php
declare(strict_types=1);

/**
 * Data volume untuk uji performa (NFR-08) — HANYA pada database khusus yang namanya berakhiran "_perf".
 * Seluruh data dibuat lewat service aplikasi (NPR → feedback → project → penyelesaian proses dengan jam
 * yang dimajukan), sehingga jadwal, run KPI, revisi, notifikasi, dan audit log terbentuk seperti pemakaian nyata.
 *
 *   DB_NAME=npd_perf php bin/install.php --database=npd_perf --fresh
 *   DB_NAME=npd_perf NPD_DEMO_PASSWORD='Rahasia123' php bin/demo-seed.php
 *   DB_NAME=npd_perf php tests/perf/seed_volume.php --projects=200 --seed=42 [--from=2026-01-05] [--until=2026-10-06] [--big]
 * Dapat dijalankan berulang (menambah project). --big menambah satu project 10 part (batas PRD §13.3:
 * hitung ulang jadwal ≤ 1 dtk untuk 10 part × 20 proses).
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/Support/NprFixtures.php';

use App\Core\AppException;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Db;
use App\Core\User;
use App\Project\CommentService;
use App\Project\HoldService;
use App\Project\ProjectService;
use App\Workflow\WorkflowEngine;

$opts = getopt('', ['projects::', 'seed::', 'from::', 'until::', 'big']);
$dbName = (string) (Config::get('db')['name'] ?? '');
if (Config::get('app.env') === 'production' || !str_ends_with($dbName, '_perf')) {
    fwrite(STDERR, "Ditolak: hanya untuk database *_perf di luar production (sekarang: {$dbName})\n");
    exit(1);
}

$seeder = new class ((int) ($opts['projects'] ?? 200), (int) ($opts['seed'] ?? 42), (string) ($opts['from'] ?? '2026-01-05'), (string) ($opts['until'] ?? '2026-10-06'), isset($opts['big'])) {
    use Tests\Support\NprFixtures;

    private const PRODUCTS = ['Botol Lotion 250ml', 'Jar Krim 50g', 'Botol Shampoo 400ml', 'Botol Sabun Cair 500ml', 'Tube Pasta 120g',
        'Botol Serum 30ml', 'Jerigen Detergen 1L', 'Botol Hand Sanitizer 100ml', 'Pot Masker 100g', 'Botol Minyak Rambut 150ml'];
    private const SPECS = [
        [['body', 'new_mold']], [['body', 'new_mold'], ['cap', 'subcont']], [['body', 'subcont']],
        [['body', 'new_mold'], ['cap', 'new_mold']], [['body', 'new_mold'], ['cap', 'subcont'], ['plug', 'subcont']],
    ];

    private WorkflowEngine $engine;
    private User $admin;
    private int $product = 0;

    public function __construct(private int $count, int $seed, private string $from, private string $until, private bool $big)
    {
        mt_srand($seed);
        $this->engine = new WorkflowEngine();
    }

    /** @return array<string,mixed> */
    protected function nprHeader(int $customerId): array
    {
        $h = [
            'product_name' => self::PRODUCTS[$this->product % count(self::PRODUCTS)] . ' #' . ($this->product + 1),
            'request_types' => ['produk_baru'], 'customer_id' => (string) $customerId,
            'invoice_address' => 'Jl. Invoice 1, Jakarta', 'shipping_address' => 'Jl. Gudang 2, Bekasi', 'phone' => '0812-0000-1111',
            'product_applications' => ['kosmetik'], 'product_contents' => ['cair'], 'net_volume_ml' => (string) mt_rand(30, 1000),
            'qty_per_month' => (string) (mt_rand(1, 20) * 5000), 'qty_per_year' => (string) (mt_rand(1, 20) * 60000), 'packaging' => ['box'],
            'regulation_compliance' => 'no', 'attach_sample' => 'ada', 'attach_technical_drawing' => 'tidak_ada', 'attach_mockup' => 'tidak_ada',
            'launching_target' => '2027-' . str_pad((string) mt_rand(1, 12), 2, '0', STR_PAD_LEFT) . '-28',
            'test_methods' => ['leaking_test' => ['checked' => '1', 'value' => '2', 'unit' => 'Kg/Cm²']],
        ];
        return $h;
    }

    /** @return list<User> */
    private function users(string $role): array
    {
        return array_map(static fn ($id) => User::find((int) $id), Db::column(
            'SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = ? AND u.is_active = 1 ORDER BY u.id', [$role]));
    }

    /** User operasional tambahan supaya KPI per PIC punya banyak baris. */
    private function extraUsers(): void
    {
        $hash = (string) Db::value('SELECT password_hash FROM users ORDER BY id LIMIT 1');
        // ±30 pengguna (PRD §13.3): 10 user demo + 20 user operasional
        foreach (['drafter' => 3, 'purchasing' => 3, 'production' => 3, 'quality' => 3, 'npd_staff' => 3, 'admin_sales' => 3, 'management' => 2] as $role => $n) {
            for ($i = 1; $i <= $n; $i++) {
                $email = "perf.{$role}{$i}@pik.local";
                if (!Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
                    Db::insert('users', [
                        'role_id' => (int) Db::value('SELECT id FROM roles WHERE code = ?', [$role]), 'name' => 'Perf ' . ucfirst(str_replace('_', ' ', $role)) . ' ' . $i,
                        'email' => $email, 'password_hash' => $hash, 'job_title' => $role, 'is_active' => 1, 'language' => 'id',
                    ]);
                }
            }
        }
        for ($i = 1; $i <= 12; $i++) {
            if (!Db::value('SELECT id FROM customers WHERE code = ?', ['PF' . $i])) {
                Db::insert('customers', ['code' => 'PF' . $i, 'name' => 'PT Pelanggan Perf ' . $i, 'invoice_address' => 'Jl. A ' . $i, 'shipping_address' => 'Jl. B ' . $i, 'phone' => '021-' . $i, 'is_active' => 1]);
            }
        }
    }

    private function pick(array $list): mixed
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    private function attachRequiredDocs(array $p): void
    {
        foreach ($this->engine->missingDocuments($p) as $type) {
            $docId = Db::insert('documents', [
                'project_id' => (int) $p['project_id'], 'part_id' => $p['part_id'], 'process_id' => (int) $p['id'],
                'doc_type_code' => $type, 'title' => $type . ' ' . $p['code'], 'created_by' => $this->admin->id,
            ]);
            $verId = Db::insert('document_versions', [
                'document_id' => $docId, 'version_no' => 1, 'original_name' => $type . '.pdf', 'stored_path' => 'perf/' . bin2hex(random_bytes(8)),
                'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size_bytes' => 1024, 'sha256' => str_repeat('0', 64), 'uploaded_by' => $this->admin->id,
            ]);
            Db::update('documents', ['current_version_id' => $verId], ['id' => $docId]);
        }
    }

    /** Majukan project sampai tanggal batas: selesaikan proses aktif berurutan (sesekali NG/loop). */
    private function progress(int $projectId): int
    {
        $done = 0;
        $stuck = 0;
        for ($guard = 0; $guard < 600; $guard++) {
            $this->engine->activateReady($projectId);
            $active = Db::fetchAll('SELECT id, planned_finish, actual_start FROM processes WHERE project_id = ? AND status IN ' . Db::in(WorkflowEngine::ACTIVE)
                . ' ORDER BY planned_finish, id', array_merge([$projectId], WorkflowEngine::ACTIVE));
            if (!$active) {
                $next = Db::value("SELECT MIN(planned_start) FROM processes WHERE project_id = ? AND status = 'not_started' AND planned_start IS NOT NULL", [$projectId]);
                if ($next === null || $next > $this->until || $next <= Clock::todayString()) {
                    break;
                }
                Clock::freeze($next . ' 07:00:00');
                continue;
            }
            $completed = false;
            foreach ($active as $a) {
                $base = $a['planned_finish'] ?: Clock::todayString();
                $finish = date('Y-m-d', strtotime($base . ' ' . sprintf('%+d', mt_rand(-2, 4)) . ' days'));
                $finish = max($finish, (string) $a['actual_start'] ?: $finish, Clock::todayString());
                if ($finish > $this->until) {
                    continue;
                }
                Clock::freeze($finish . ' 15:00:00');
                $p = $this->engine->load((int) $a['id']);
                $this->attachRequiredDocs($p);
                $input = ['actual_finish' => $finish, 'lock_version' => (int) $p['lock_version']];
                $options = $this->engine->decisionOptions($p);
                if ($options) {
                    $positive = array_values(array_filter($options, static fn ($o) => ($o['effect'] ?? 'continue') === 'continue'));
                    $negative = array_values(array_filter($options, static fn ($o) => in_array($o['effect'] ?? '', ['loop', 'repeat'], true)));
                    $o = ($negative && (int) $p['loop_count'] === 0 && mt_rand(1, 100) <= 10) ? $negative[0] : ($positive[0] ?? $options[0]);
                    $input['outcome'] = $o['code'];
                    $input['comment'] = 'Catatan hasil ' . $o['code'];
                    $input['decision_maker'] = 'Customer QA';
                }
                try {
                    $this->engine->complete($this->admin, (int) $a['id'], $input);
                    $done++;
                    $completed = true;
                    break;
                } catch (AppException) {
                    continue; // mis. FF masih menunggu predecessor → coba proses aktif lain
                }
            }
            if (!$completed) {
                if (++$stuck > 3) {
                    break;
                }
                $tomorrow = date('Y-m-d', strtotime(Clock::todayString() . ' +1 day'));
                if ($tomorrow > $this->until) {
                    break;
                }
                Clock::freeze($tomorrow . ' 07:00:00');
            } else {
                $stuck = 0;
            }
        }
        return $done;
    }

    public function run(): void
    {
        $this->extraUsers();
        $this->admin = $this->users('admin')[0];
        $sales = $this->users('admin_sales');
        $npds = $this->users('npd_staff');
        $roles = [];
        foreach (ProjectService::PART_ROLES as $r) {
            $roles[$r] = $this->users($r);
        }
        $customers = array_map('intval', Db::column('SELECT id FROM customers ORDER BY id'));
        $start = strtotime($this->from);
        $offset = (int) Db::value('SELECT COUNT(*) FROM projects');
        $span = (int) ((strtotime($this->until) - $start) / 86400) - 3;
        $t0 = microtime(true);
        $steps = 0;
        for ($i = 0; $i < $this->count; $i++) {
            $this->product = $offset + $i;
            $day = date('Y-m-d', $start + (int) round($span * $i / max(1, $this->count - 1)) * 86400);
            Clock::freeze($day . ' 09:00:00');
            $spec = $this->pick(self::SPECS);
            [, , $projectId] = $this->completedNpr($this->pick($sales), $this->pick($npds), $this->pick($customers), $spec, array_fill(0, count($spec), 'feasible'));
            $ps = new ProjectService();
            foreach (array_map('intval', Db::column('SELECT id FROM project_parts WHERE project_id = ?', [$projectId])) as $partId) {
                $ps->assignPartPics($this->admin, $partId, array_map(fn ($us) => (string) $this->pick($us)->id, $roles));
            }
            if (mt_rand(1, 100) <= 25) {
                $ps->updateProject($this->admin, $projectId, ['priority' => $this->pick(['high', 'urgent', 'low'])]);
            }
            $steps += $this->progress($projectId);
            $proc = (int) Db::value('SELECT id FROM processes WHERE project_id = ? ORDER BY id DESC LIMIT 1', [$projectId]);
            for ($c = mt_rand(0, 3); $c > 0; $c--) {
                (new CommentService())->add($this->admin, $projectId, $proc, 'Update progres ' . $c . ' untuk project #' . $projectId);
            }
            if (mt_rand(1, 100) <= 6 && !Db::value('SELECT finished_at FROM projects WHERE id = ?', [$projectId])) {
                Clock::freeze($this->until . ' 16:00:00');
                (new HoldService())->hold($this->admin, $projectId, null, 'Menunggu keputusan customer', date('Y-m-d', strtotime($this->until . ' +30 days')));
            }
            Clock::freeze(null);
            if (($i + 1) % 20 === 0) {
                fwrite(STDERR, sprintf("%d/%d project, %d proses selesai, %.0f s\n", $i + 1, $this->count, $steps, microtime(true) - $t0));
            }
        }
        if ($this->big) {
            // project besar: 10 part, berjalan sejak 1 Sep 2026
            $this->product = $offset + $this->count;
            Clock::freeze('2026-09-01 09:00:00');
            $spec = [];
            for ($k = 0; $k < 10; $k++) {
                $spec[] = [['body', 'cap', 'plug', 'spatula'][$k % 4], $k % 2 ? 'subcont' : 'new_mold', 'new_mould', $k >= 4 ? 'Varian ' . ($k + 1) : null];
            }
            [, , $projectId] = $this->completedNpr($sales[0], $npds[0], $customers[0], $spec, array_fill(0, 10, 'feasible'));
            foreach (array_map('intval', Db::column('SELECT id FROM project_parts WHERE project_id = ?', [$projectId])) as $partId) {
                (new ProjectService())->assignPartPics($this->admin, $partId, array_map(fn ($us) => (string) $this->pick($us)->id, $roles));
            }
            $steps += $this->progress($projectId);
            Clock::freeze(null);
            fwrite(STDERR, "project besar #{$projectId}: " . Db::value('SELECT COUNT(*) FROM processes WHERE project_id = ?', [$projectId]) . " proses\n");
        }
        $stats = Db::fetch("SELECT (SELECT COUNT(*) FROM projects) projects, (SELECT COUNT(*) FROM project_parts) parts,
            (SELECT COUNT(*) FROM processes) processes, (SELECT COUNT(*) FROM processes WHERE status = 'completed') completed,
            (SELECT COUNT(*) FROM process_runs) runs, (SELECT COUNT(*) FROM revision_history) revisions,
            (SELECT COUNT(*) FROM notifications) notifications, (SELECT COUNT(*) FROM audit_logs) audit_logs,
            (SELECT COUNT(*) FROM projects WHERE finished_at IS NOT NULL) finished, (SELECT COUNT(*) FROM projects WHERE is_on_hold = 1) on_hold,
            (SELECT COUNT(*) FROM projects WHERE finished_at IS NULL AND cancelled_at IS NULL) active, (SELECT COUNT(*) FROM users) users");
        echo json_encode($stats + ['seconds' => round(microtime(true) - $t0, 1)], JSON_PRETTY_PRINT), PHP_EOL;
    }
};
try {
    $seeder->run();
} catch (\App\Core\ValidationException $e) {
    fwrite(STDERR, 'Validasi gagal: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
