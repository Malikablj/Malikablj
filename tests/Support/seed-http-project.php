<?php
declare(strict_types=1);

/**
 * Seed project berjalan untuk test HTTP: NPR 2 part (Body New Mold, Cap Subcont) oleh sales@test.local,
 * feedback diselesaikan npd@test.local pada 28-09-2026, proses aktif diaktifkan. Mencetak id project.
 */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require __DIR__ . '/NprFixtures.php';

use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Workflow\WorkflowEngine;

$seeder = new class {
    use Tests\Support\NprFixtures;

    public function run(): int
    {
        $sales = User::find((int) Db::value("SELECT id FROM users WHERE email = 'sales@test.local'"));
        $npd = User::find((int) Db::value("SELECT id FROM users WHERE email = 'npd@test.local'"));
        Clock::freeze('2026-09-28 09:00:00');
        [, , $projectId] = $this->completedNpr($sales, $npd, $this->makeCustomer(), [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        Clock::freeze(null);
        (new WorkflowEngine())->activateReady($projectId);
        return $projectId;
    }
};
echo $seeder->run(), PHP_EOL;
