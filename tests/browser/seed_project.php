<?php
declare(strict_types=1);

/**
 * Data uji browser (DEV/UAT saja): NPR 2 part (Body New Mold, Cap Subcont) dikirim Sales demo,
 * feedback diselesaikan NPD demo pada 28-09-2026, lalu aktivasi proses hari ini. Memakai service aplikasi.
 * Ditolak pada APP_ENV=production. Prasyarat: php bin/demo-seed.php.
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/Support/NprFixtures.php';

use App\Core\Clock;
use App\Core\Config;
use App\Core\Db;
use App\Core\User;
use App\Workflow\WorkflowEngine;

if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "Ditolak pada production\n");
    exit(1);
}

$seeder = new class {
    use Tests\Support\NprFixtures;

    public function run(): int
    {
        $sales = User::find((int) Db::value("SELECT id FROM users WHERE email = 'sari@pik.local'"));
        $npd = User::find((int) Db::value("SELECT id FROM users WHERE email = 'rizki@pik.local'"));
        $customer = (int) Db::value('SELECT id FROM customers ORDER BY id LIMIT 1');
        if (!$sales || !$npd || !$customer) {
            fwrite(STDERR, "Jalankan bin/demo-seed.php terlebih dahulu\n");
            exit(1);
        }
        Clock::freeze('2026-09-28 09:00:00');
        [, , $projectId] = $this->completedNpr($sales, $npd, $customer, [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        Clock::freeze(null);
        (new WorkflowEngine())->activateReady($projectId);
        return $projectId;
    }
};
echo $seeder->run(), PHP_EOL;
