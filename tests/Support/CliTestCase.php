<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Test skrip operasional (bin/*.php) sebagai proses terpisah terhadap database test tersendiri
 * (nama wajib berawalan npd_test) dan folder storage sementara — database & storage aplikasi tidak disentuh.
 */
abstract class CliTestCase extends TestCase
{
    /** @var list<string> */
    private array $cleanupDbs = [];
    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDbs as $db) {
            $this->server()->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        }
        foreach ($this->cleanupDirs as $d) {
            self::rmTree($d);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $env @return array{code:int,out:string} */
    protected function php(string $script, array $args = [], array $env = []): array
    {
        $root = dirname(__DIR__, 2);
        $cmd = array_merge([PHP_BINARY, $root . '/' . $script], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env + getenv());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    /** Database test baru (dihapus di tearDown). */
    protected function freshDb(string $name, bool $install = true): string
    {
        $this->assertStringStartsWith('npd_test', $name);
        $this->cleanupDbs[] = $name;
        if ($install) {
            $r = $this->php('bin/install.php', ['--database=' . $name, '--fresh']);
            $this->assertSame(0, $r['code'], $r['out']);
        } else {
            $this->server()->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        }
        return $name;
    }

    protected function tmpDir(string $prefix): string
    {
        $d = sys_get_temp_dir() . '/' . $prefix . '_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($d, 0700, true);
        $this->cleanupDirs[] = $d;
        return $d;
    }

    protected function db(string $name): PDO
    {
        $cfg = \App\Core\Config::get('db');
        $cfg['name'] = $name;
        return \App\Core\Db::connect($cfg);
    }

    protected function server(): PDO
    {
        return $this->db('');
    }

    protected static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
