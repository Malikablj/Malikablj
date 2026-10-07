<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\NumberSequence;
use Tests\Support\DbTestCase;

/** FR-NPR-04 / DATA-07/08: penomoran tanpa duplikat meski dikirim bersamaan. */
final class NumberSequenceTest extends DbTestCase
{
    public function testFormatAndYearlyReset(): void
    {
        $a = NumberSequence::nextNprNumber(new \DateTimeImmutable('2026-10-04'));
        $b = NumberSequence::nextNprNumber(new \DateTimeImmutable('2026-11-02'));
        $c = NumberSequence::nextNprNumber(new \DateTimeImmutable('2027-01-05'));
        $this->assertSame('001/PIK/NPR/X/2026', $a['number']);
        $this->assertSame('002/PIK/NPR/XI/2026', $b['number']);
        $this->assertSame('001/PIK/NPR/I/2027', $c['number'], 'urutan direset tiap tahun');
        $this->assertSame('NPD-2026-001', NumberSequence::nextProjectCode(new \DateTimeImmutable('2026-10-04')));
        $this->assertSame('APR-2026-0001', NumberSequence::nextApprovalCode(new \DateTimeImmutable('2026-10-04')));
    }

    public function testConcurrentProcessesNeverDuplicate(): void
    {
        // Proses paralel nyata (koneksi MySQL terpisah) — di luar transaksi test.
        Db::rollbackOuter();
        Db::execute("DELETE FROM number_sequences WHERE seq_key = 'NPR-2099'");
        $workers = 6;
        $perWorker = 15;
        $procs = [];
        $worker = dirname(__DIR__) . '/Support/sequence-worker.php';
        for ($i = 0; $i < $workers; $i++) {
            $procs[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . $perWorker . ' 2>&1', 'r');
        }
        $numbers = [];
        foreach ($procs as $p) {
            while (($line = fgets($p)) !== false) {
                $numbers[] = trim($line);
            }
            pclose($p);
        }
        Db::execute("DELETE FROM number_sequences WHERE seq_key = 'NPR-2099'");
        Db::beginOuter();

        $this->assertCount($workers * $perWorker, $numbers, implode("\n", array_slice($numbers, 0, 5)));
        $this->assertCount(count($numbers), array_unique($numbers), 'Tidak boleh ada nomor NPR ganda');
        $seqs = array_map(static fn ($n) => (int) explode('/', $n)[0], $numbers);
        sort($seqs);
        $this->assertSame(range(1, $workers * $perWorker), $seqs, 'Nomor berurutan tanpa lompatan');
    }

    public function testNoSelectMaxPlusOneInCodebase(): void
    {
        $root = dirname(__DIR__, 2) . '/modules';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.php')) {
                $this->assertDoesNotMatchRegularExpression('/MAX\s*\(\s*[a-z_.`]+\s*\)\s*\+\s*1/i', (string) file_get_contents($f->getPathname()), $f->getPathname());
            }
        }
    }
}
