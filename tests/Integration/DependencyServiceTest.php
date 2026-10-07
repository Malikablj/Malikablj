<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\AuthorizationException;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\User;
use App\Core\ValidationException;
use App\Scheduling\CycleException;
use App\Scheduling\WorkingCalendar;
use App\Workflow\DependencyService;
use App\Workflow\WorkflowEngine;
use Tests\Support\DbTestCase;
use Tests\Support\NprFixtures;

/** Penyesuaian dependency per project (PRD §5.5, FR-WF-04/05, UAT-08). */
final class DependencyServiceTest extends DbTestCase
{
    use NprFixtures;

    private User $sales;
    private User $npd;
    private int $projectId;
    private int $mold;
    private int $sub;
    private DependencyService $deps;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freeze('2026-10-05 09:00:00');
        WorkingCalendar::flush();
        $this->sales = $this->makeUser('admin_sales');
        $this->npd = $this->makeUser('npd_staff');
        [, , $this->projectId] = $this->completedNpr($this->sales, $this->npd, $this->makeCustomer(), [['body', 'new_mold'], ['cap', 'subcont']], ['feasible', 'feasible']);
        $parts = Db::column('SELECT id FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [$this->projectId]);
        [$this->mold, $this->sub] = array_map('intval', $parts);
        $this->deps = new DependencyService();
    }

    protected function tearDown(): void
    {
        Clock::freeze(null);
        parent::tearDown();
    }

    private function id(?int $part, string $code): int
    {
        return (int) Db::value('SELECT id FROM processes WHERE project_id = ? AND part_id <=> ? AND code = ?', [$this->projectId, $part, $code]);
    }

    private function row(?int $part, string $code): array
    {
        return Db::fetch('SELECT * FROM processes WHERE id = ?', [$this->id($part, $code)]);
    }

    public function testParallelOverrideWithPreviewThenSaveAndAudit(): void
    {
        $n5 = $this->id($this->mold, 'N5');
        $before = $this->row($this->mold, 'N5');
        $this->assertSame('2026-10-21', $before['planned_start']);
        // 2D Drawing paralel dengan approval 3D: SS terhadap N4, tetap FS terhadap N2
        $input = [
            ['predecessor_id' => (string) $this->id($this->mold, 'N2'), 'type' => 'FS', 'lag' => '0'],
            ['predecessor_id' => (string) $this->id($this->mold, 'N4'), 'type' => 'SS', 'lag' => '0'],
            ['predecessor_id' => '', 'type' => 'FS', 'lag' => '0'], // baris kosong diabaikan
        ];
        $preview = $this->deps->preview($this->npd, $n5, $input);
        $this->assertSame('2026-10-21', $this->row($this->mold, 'N5')['planned_start'], 'pratinjau tidak menyimpan');
        $this->assertCount(2, $preview['deps']);

        // tanpa N2 (hanya SS N4) → N5 maju ke mulai N4 (14 Okt)
        $input2 = [['predecessor_id' => (string) $this->id($this->mold, 'N4'), 'type' => 'SS', 'lag' => '0']];
        $preview2 = $this->deps->preview($this->npd, $n5, $input2);
        $n5change = array_values(array_filter($preview2['changes'], fn ($c) => $c['process_id'] === $n5))[0];
        $this->assertSame('2026-10-14', $n5change['new_start']);

        $this->deps->save($this->npd, $n5, $input2, 'Customer setuju 2D paralel');
        $after = $this->row($this->mold, 'N5');
        $this->assertSame('2026-10-14', $after['planned_start']);
        $this->assertSame('override', Db::value('SELECT source FROM process_dependencies WHERE process_id = ?', [$n5]));
        $log = Db::fetch("SELECT * FROM audit_logs WHERE action = 'dependency.update' AND entity_id = ?", [$n5]);
        $this->assertNotNull($log);
        $this->assertStringContainsString('N2 FS+0', (string) $log['old_value']);
        $this->assertStringContainsString('N4 SS+0', (string) $log['new_value']);
        $this->assertSame('Customer setuju 2D paralel', $log['reason']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM revision_history WHERE process_id = ? AND revision_type = 'dependency'", [$n5]));
        $this->assertGreaterThan(0, (int) Db::value("SELECT COUNT(*) FROM schedule_changes WHERE project_id = ? AND change_type = 'dependency_change'", [$this->projectId]));
        // proses paralel lain tidak berubah
        $this->assertSame('2026-10-06', $this->row($this->mold, 'N1')['planned_start']);
    }

    public function testCycleIsRejected(): void
    {
        $n1 = $this->id($this->mold, 'N1');
        try {
            $this->deps->save($this->npd, $n1, [['predecessor_id' => (string) $this->id($this->mold, 'N5'), 'type' => 'FS', 'lag' => '0']]);
            $this->fail('lingkaran harus ditolak');
        } catch (CycleException $e) {
            $this->assertContains($n1, $e->processIds());
        }
        $this->assertSame([(string) $this->id(null, 'P2')], array_map('strval', Db::column('SELECT predecessor_id FROM process_dependencies WHERE process_id = ?', [$n1])), 'tidak ada yang tersimpan');
    }

    public function testCrossPartAndInvalidInputRejected(): void
    {
        $n5 = $this->id($this->mold, 'N5');
        try {
            $this->deps->preview($this->npd, $n5, [['predecessor_id' => (string) $this->id($this->sub, 'S1'), 'type' => 'FS', 'lag' => '0']]);
            $this->fail('lintas part');
        } catch (ValidationException $e) {
            $this->assertSame(['deps.1'], array_keys($e->errors()));
        }
        foreach ([['type' => 'XX', 'lag' => '0'], ['type' => 'FS', 'lag' => '120'], ['type' => 'FS', 'lag' => 'a']] as $bad) {
            try {
                $this->deps->preview($this->npd, $n5, [['predecessor_id' => (string) $this->id($this->mold, 'N2')] + $bad]);
                $this->fail('input tidak valid');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        // proses level project (gate) boleh menjadi predecessor
        $preview = $this->deps->preview($this->npd, $n5, [['predecessor_id' => (string) $this->id(null, 'P2'), 'type' => 'FS', 'lag' => '2']]);
        $this->assertSame(2, $preview['deps'][0]['lag']);
    }

    public function testSalesCannotEditAndCompletedProcessLocked(): void
    {
        try {
            $this->deps->save($this->sales, $this->id($this->mold, 'N5'), []);
            $this->fail('Sales tidak boleh');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        Clock::freeze('2026-10-06 09:00:00');
        $engine = new WorkflowEngine();
        $engine->activateReady($this->projectId);
        $engine->complete($this->npd, $this->id($this->mold, 'N3'));
        $this->expectException(BusinessRuleException::class);
        $this->deps->save($this->npd, $this->id($this->mold, 'N3'), []);
    }

    public function testCandidatesExcludeOtherParts(): void
    {
        $codes = array_map(fn ($r) => ($r['part_id'] === null ? 'P:' : '') . $r['code'], $this->deps->candidates($this->row($this->mold, 'N5')));
        $this->assertContains('N4', $codes);
        $this->assertContains('P:G1', $codes);
        $this->assertNotContains('S1', $codes);
        $this->assertNotContains('N5', $codes);
    }
}
