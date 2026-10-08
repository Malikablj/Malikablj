<?php
declare(strict_types=1);

namespace Tests\Ops;

use App\Ops\BackupService;
use Tests\Support\CliTestCase;

/**
 * NFR-09: backup DB + dokumen, retensi, dan uji pemulihan otomatis (restore drill):
 * hasil pulih identik (CHECKSUM TABLE semua tabel, SHA-256 semua dokumen), arsip rusak ditolak,
 * pemulihan tidak pernah menimpa database/folder yang berisi data.
 */
final class BackupRestoreTest extends CliTestCase
{
    /** @return array<string,array{0:string,1:string}> metode backup → metode pemulihan */
    public static function methods(): array
    {
        return [
            'mysqldump → mysqldump' => ['mysqldump', 'mysqldump'],
            'php → php (proc_open dimatikan hosting)' => ['php', 'php'],
            'mysqldump → importer php' => ['mysqldump', 'php'],
            'dump php → klien mysql' => ['php', 'mysqldump'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('methods')]
    public function testBackupRestoreRoundTripIsIdenticalAndNeverOverwrites(string $backupMethod, string $restoreMethod): void
    {
        $src = $this->freshDb('npd_test_ops');
        $storage = $this->tmpDir('npd_ops_storage');
        mkdir($storage . '/documents/2026/10', 0700, true);
        $env = ['DB_NAME' => $src, 'STORAGE_PATH' => $storage, 'BACKUP_METHOD' => $backupMethod];
        $seed = $this->php('tests/Support/seed-http.php', [], $env);
        $this->assertSame(0, $seed['code'], $seed['out']);
        $seed = $this->php('tests/Support/seed-http-project.php', [], $env);
        $this->assertSame(0, $seed['code'], $seed['out']);
        file_put_contents($storage . '/documents/2026/10/a1b2c3.pdf', "%PDF-1.4\n" . str_repeat('isi dokumen ', 500));
        file_put_contents($storage . '/documents/2026/10/d4e5f6.png', random_bytes(4096));
        $pdo = $this->db($src);
        $pdo->exec("INSERT INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('UNI', 'PT Ünicode – “Kutip” 日本', 'Jl. \\'Apostrof\\'', 'B', '1')");
        // teks multi-baris, titik koma di akhir baris, backslash & NULL harus pulih persis
        $pdo->exec("INSERT INTO customers (code, name, invoice_address, shipping_address, phone) VALUES ('MULTI', 'Baris satu;\\nBaris dua;\\r\\n\\\\ akhir;', 'A;', 'B', NULL)");
        // trigger ikut di-backup bila server mengizinkan user membuatnya (hosting dengan log_bin_trust_function_creators)
        $hasTrigger = true;
        try {
            $pdo->exec("CREATE TRIGGER trg_uji_customers BEFORE INSERT ON customers FOR EACH ROW SET NEW.phone = IFNULL(NEW.phone, '-')");
        } catch (\PDOException) {
            $hasTrigger = false; // 1419: butuh SUPER saat binary log aktif — jalur trigger diuji di test lain
        }

        $dest = $this->tmpDir('npd_ops_backup');
        $r = $this->php('bin/backup.php', ['--dest=' . $dest], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $dirs = glob($dest . '/npd-' . $src . '-*', GLOB_ONLYDIR);
        $this->assertCount(1, $dirs);
        $bk = $dirs[0];
        $this->assertMatchesRegularExpression(BackupService::NAME_RE, basename($bk));
        foreach (['database.sql.gz', $backupMethod === 'php' ? 'documents.zip' : 'documents.tar.gz', 'manifest.json'] as $f) {
            $this->assertFileExists($bk . '/' . $f);
        }
        $this->assertSame('0700', substr(sprintf('%o', fileperms($bk)), -4), 'folder backup hanya untuk pemilik');
        $manifest = json_decode((string) file_get_contents($bk . '/manifest.json'), true);
        $this->assertSame($backupMethod, $manifest['method']);
        $this->assertSame(2, $manifest['documents']['files']);
        $this->assertSame($hasTrigger ? ['trg_uji_customers'] : [], $manifest['triggers']);
        $this->assertGreaterThan(40, count($manifest['tables']));
        $this->assertSame('success', $pdo->query("SELECT status FROM job_runs WHERE job = 'backup' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'tercatat di job_runs');
        $this->assertStringNotContainsString((string) (\App\Core\Config::get('db')['pass'] ?: '§tidak-ada§'), $r['out'], 'password tidak tercetak');

        // pulihkan ke database & folder baru
        $dst = $this->freshDb('npd_test_ops_restore', false);
        $docs = $storage . '/restore-docs';
        $env['BACKUP_METHOD'] = $restoreMethod;
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $dst, '--documents=' . $docs], $env);
        if ($hasTrigger && $restoreMethod === 'mysqldump' && str_contains($r['out'], '1419')) {
            $this->markTestSkipped('klien mysql tidak berhak membuat trigger di server ini');
        }
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('Cocok dengan manifest', $r['out']);
        $this->assertStringContainsString('dipulihkan dengan metode ' . $restoreMethod, $r['out']);

        // identik: checksum tiap tabel (job_runs berbeda wajar: baris backup selesai setelah dump) + sha256 dokumen
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'job_runs'")->fetchAll(\PDO::FETCH_COLUMN);
        $sum = static function (\PDO $p, array $tables): array {
            $out = [];
            foreach ($p->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->fetchAll(\PDO::FETCH_NUM) as [$t, $c]) {
                $out[substr((string) $t, strpos((string) $t, '.') + 1)] = $c;
            }
            return $out;
        };
        $restored = $this->db($dst);
        $this->assertSame($sum($pdo, $tables), $sum($restored, $tables), 'semua tabel identik');
        $this->assertSame('PT Ünicode – “Kutip” 日本', $restored->query("SELECT name FROM customers WHERE code = 'UNI'")->fetchColumn(), 'utf8mb4 utuh');
        $this->assertSame("Baris satu;\nBaris dua;\r\n\\ akhir;", $restored->query("SELECT name FROM customers WHERE code = 'MULTI'")->fetchColumn(), 'teks multi-baris utuh');
        $this->assertNull($restored->query("SELECT phone FROM customers WHERE code = 'MULTI'")->fetchColumn(), 'NULL tetap NULL');
        $this->assertSame(
            (int) $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'")->fetchColumn(),
            (int) $restored->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'")->fetchColumn(),
            'penghitung AUTO_INCREMENT ikut pulih'
        );
        if ($hasTrigger) {
            $this->assertSame(['trg_uji_customers'], $restored->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(\PDO::FETCH_COLUMN));
        }
        foreach (['2026/10/a1b2c3.pdf', '2026/10/d4e5f6.png'] as $f) {
            $this->assertSame(hash_file('sha256', $storage . '/documents/' . $f), hash_file('sha256', $docs . '/' . $f), $f);
        }

        // tidak pernah menimpa: database berisi tabel / folder berisi file → ditolak
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $dst, '--documents=' . $storage . '/lain'], $env);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('sudah berisi tabel', $r['out']);
        $other = $this->freshDb('npd_test_ops_restore2', false);
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $other, '--documents=' . $docs], $env);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('tidak kosong', $r['out']);
        $this->assertSame(0, (int) $this->server()->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$other}'")->fetchColumn(), 'tidak dibuat bila ditolak');

        // verifikasi ulang mendeteksi perubahan
        $restored->exec("DELETE FROM customers WHERE code = 'UNI'");
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--verify-only', '--database=' . $dst, '--documents=' . $docs], $env);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('tabel customers', $r['out']);

        // arsip rusak → ditolak sebelum menyentuh apa pun
        $fh = fopen($bk . '/database.sql.gz', 'r+b');
        fseek($fh, 40);
        fwrite($fh, 'X');
        fclose($fh);
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $other, '--documents=' . $storage . '/baru'], $env);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('SHA-256', $r['out']);
        $this->assertDirectoryDoesNotExist($storage . '/baru');
    }

    public function testTriggersAreRestoredOrSkippedSafely(): void
    {
        $src = $this->freshDb('npd_test_ops');
        $storage = $this->tmpDir('npd_ops_trg');
        $env = ['DB_NAME' => $src, 'STORAGE_PATH' => $storage];
        $dest = $this->tmpDir('npd_ops_trg_bk');
        $r = $this->php('bin/backup.php', ['--dest=' . $dest], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $bk = glob($dest . '/npd-*', GLOB_ONLYDIR)[0];
        $this->assertArrayNotHasKey('documents.tar.gz', json_decode((string) file_get_contents($bk . '/manifest.json'), true)['files'], 'tanpa dokumen → tanpa arsip');

        // sisipkan trigger berformat mysqldump (DEFINER server asal, badan multi-baris) seperti backup produksi ber-hardening
        $sql = (string) gzdecode((string) file_get_contents($bk . '/database.sql.gz'));
        $trigger = "DELIMITER ;;\n/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_audit_logs_no_update` BEFORE UPDATE ON `audit_logs`\n"
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only' */;;\nDELIMITER ;\n";
        $pos = strrpos($sql, '-- Dump completed');
        file_put_contents($bk . '/database.sql.gz', gzencode(substr($sql, 0, $pos) . $trigger . substr($sql, $pos)));
        $m = json_decode((string) file_get_contents($bk . '/manifest.json'), true);
        $m['files']['database.sql.gz']['sha256'] = hash_file('sha256', $bk . '/database.sql.gz');
        $m['triggers'] = ['trg_audit_logs_no_update'];
        file_put_contents($bk . '/manifest.json', json_encode($m));
        $triggers = fn (string $db): array => $this->server()->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$db}'")->fetchAll(\PDO::FETCH_COLUMN);

        // --without-triggers: data pulih utuh, trigger dilewati, pengingat hardening ditampilkan
        $a = $this->freshDb('npd_test_ops_restore', false);
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $a, '--documents=' . $storage . '/a', '--without-triggers'], $env);
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('hardening.sql', $r['out']);
        $this->assertSame([], $triggers($a));

        // dengan trigger: berhasil (DEFINER asal dibuang) ATAU — user tanpa SUPER saat binary log aktif — gagal jelas & bersih
        $b = $this->freshDb('npd_test_ops_restore2', false);
        $r = $this->php('bin/restore.php', ['--from=' . $bk, '--database=' . $b, '--documents=' . $storage . '/b'], $env);
        if ($r['code'] === 0) {
            $this->assertSame(['trg_audit_logs_no_update'], $triggers($b));
            $this->assertNotSame('root@localhost', $this->server()->query("SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$b}'")->fetchColumn());
        } else {
            $this->assertStringContainsString('--without-triggers', $r['out']);
            $this->assertSame(0, (int) $this->server()->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$b}'")->fetchColumn(), 'database hasil sebagian dibersihkan');
            $this->assertDirectoryDoesNotExist($storage . '/b', 'folder hasil sebagian dibersihkan');
        }
    }

    public function testRetentionKeepsRecentAndNewestAndIgnoresForeignFolders(): void
    {
        $root = $this->tmpDir('npd_ops_ret');
        $mk = static function (string $name) use ($root): void {
            mkdir($root . '/' . $name);
            file_put_contents($root . '/' . $name . '/manifest.json', '{}');
        };
        $mk('npd-db1-20260801-013000'); // 68 hari
        $mk('npd-db1-20260905-013000'); // 33 hari
        $mk('npd-db1-20260910-013000'); // 28 hari
        $mk('npd-db1-20261007-013000'); // kemarin
        mkdir($root . '/arsip-manual');   // bukan backup aplikasi
        mkdir($root . '/npd-db1-20260101-000000'); // tanpa manifest → tidak disentuh
        $removed = (new BackupService())->prune($root, 30, new \DateTimeImmutable('2026-10-08 02:00:00'));
        sort($removed);
        $this->assertSame(['npd-db1-20260801-013000', 'npd-db1-20260905-013000'], $removed);
        $this->assertDirectoryExists($root . '/npd-db1-20260910-013000');
        $this->assertDirectoryExists($root . '/arsip-manual');
        $this->assertDirectoryExists($root . '/npd-db1-20260101-000000');
        // backup terbaru selalu disimpan walau lebih tua dari retensi (mis. backup sempat berhenti)
        $only = $this->tmpDir('npd_ops_ret2');
        mkdir($only . '/npd-db1-20260101-013000');
        file_put_contents($only . '/npd-db1-20260101-013000/manifest.json', '{}');
        $this->assertSame([], (new BackupService())->prune($only, 30, new \DateTimeImmutable('2026-10-08')));
    }
}
