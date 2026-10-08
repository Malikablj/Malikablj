<?php
declare(strict_types=1);

namespace App\Ops;

use App\Core\Config;
use App\Core\Db;
use PDO;

/**
 * Backup & pemulihan (NFR-09, docs/BACKUP_AND_RESTORE.md).
 *
 * Satu backup = folder `npd-<database>-<YYYYmmdd-HHMMSS>/` berisi:
 *   database.sql.gz   dump konsisten tanpa mengunci tabel InnoDB
 *   documents.tar.gz  isi storage/documents (diambil SETELAH dump, sehingga semua file yang dirujuk dump ada)
 *     atau documents.zip (mode PHP)
 *   manifest.json     versi aplikasi, metode, jumlah baris per tabel, trigger, jumlah & ukuran dokumen, SHA-256 tiap arsip
 *
 * Metode (BACKUP_METHOD): `mysqldump` (mysqldump/mysql/tar lewat proc_open) atau `php` (PDO dalam satu snapshot
 * transaksi + ZipArchive — untuk hosting yang menonaktifkan proc_open). Bawaan `auto`: mysqldump bila proc_open
 * tersedia, selain itu php. Pemulihan membaca dump dari kedua metode.
 *
 * Pemulihan TIDAK PERNAH menimpa: hanya ke database kosong/baru dan folder dokumen kosong/baru, lalu
 * hasilnya diverifikasi terhadap manifest. Setelah lolos, aplikasi dialihkan lewat .env (DB_NAME/STORAGE_PATH).
 */
final class BackupService
{
    public const NAME_RE = '/^npd-[A-Za-z0-9_]+-(\d{8})-(\d{6})$/';

    /** @var array<string,mixed> koneksi pemulihan/verifikasi (DB_USER: admin) */
    private array $db;
    /** @var array<string,mixed> koneksi backup (BACKUP_DB_USER bila diisi: SELECT, SHOW VIEW, TRIGGER) */
    private array $dumpDb;
    /** @var array<string,string> */
    private array $bin;
    private string $method;

    /** @param array<string,mixed>|null $db konfigurasi koneksi (bawaan: Config db, user backup bila diisi) */
    public function __construct(?array $db = null, private ?string $documentsDir = null)
    {
        $b = (array) Config::get('backup', []);
        $this->db = $db ?? (array) Config::get('db');
        $this->dumpDb = $this->db;
        if ($db === null && (string) ($b['db_user'] ?? '') !== '') {
            $this->dumpDb['user'] = (string) $b['db_user'];
            $this->dumpDb['pass'] = (string) ($b['db_pass'] ?? '');
        }
        $this->documentsDir = $documentsDir ?? (string) Config::get('storage.documents');
        $this->bin = [
            'mysqldump' => (string) ($b['mysqldump'] ?? 'mysqldump'),
            'mysql' => (string) ($b['mysql'] ?? 'mysql'),
            'tar' => (string) ($b['tar'] ?? 'tar'),
        ];
        $m = strtolower((string) ($b['method'] ?? 'auto'));
        $this->method = in_array($m, ['mysqldump', 'php'], true) ? $m : (self::procAvailable() ? 'mysqldump' : 'php');
    }

    /** Metode yang dipakai: 'mysqldump' atau 'php'. */
    public function method(): string
    {
        return $this->method;
    }

    /** proc_open ada dan tidak dimatikan lewat disable_functions (sering di hosting bersama). */
    public static function procAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    // ------------------------------------------------------------------ backup

    /** @return array<string,mixed> manifest + dir */
    public function create(string $destRoot): array
    {
        if ($this->method === 'mysqldump' && !self::procAvailable()) {
            throw new \RuntimeException('BACKUP_METHOD=mysqldump tetapi proc_open dinonaktifkan — pakai BACKUP_METHOD=auto atau php');
        }
        $dbName = (string) $this->db['name'];
        self::assertDbName($dbName);
        if (!is_dir($destRoot) && !mkdir($destRoot, 0700, true) && !is_dir($destRoot)) {
            throw new \RuntimeException("Folder backup tidak dapat dibuat: {$destRoot}");
        }
        // nama unik per detik; backup lain pada detik yang sama (mis. manual + cron) → tunggu detik berikutnya
        for ($try = 0; ; $try++) {
            $dir = rtrim($destRoot, '/\\') . '/npd-' . $dbName . '-' . date('Ymd-His');
            if (!file_exists($dir) && @mkdir($dir, 0700)) {
                break;
            }
            if ($try >= 3) {
                throw new \RuntimeException("Folder backup tidak dapat dibuat: {$dir}");
            }
            sleep(1);
        }
        try {
            if ($this->method === 'mysqldump') {
                $pdo = Db::connect($this->dumpDb);
                $tables = $this->tableCounts($pdo);
                $triggers = $this->triggers($pdo);
                $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $pdo = null;
                $this->dump($dbName, $dir . '/database.sql.gz');
            } else {
                [$tables, $triggers, $mysqlVersion] = $this->phpDump($dir . '/database.sql.gz');
            }
            $docs = $this->documentStats($this->documentsDir);
            if ($docs['files'] > 0) {
                $this->method === 'mysqldump'
                    ? $this->run([$this->bin['tar'], '-czf', $dir . '/documents.tar.gz', '-C', $this->documentsDir, '.'], 'tar')
                    : $this->zipDocuments($dir . '/documents.zip');
            }
            $files = [];
            foreach (['database.sql.gz', 'documents.tar.gz', 'documents.zip'] as $f) {
                if (is_file($dir . '/' . $f)) {
                    $files[$f] = ['sha256' => hash_file('sha256', $dir . '/' . $f), 'bytes' => filesize($dir . '/' . $f)];
                }
            }
            $manifest = [
                'format' => 1,
                'app' => 'NPD Project Control', 'app_version' => (string) Config::get('app.version'),
                'database' => $dbName, 'mysql_version' => $mysqlVersion, 'method' => $this->method,
                'created_at' => date('c'), 'tables' => $tables, 'triggers' => $triggers, 'documents' => $docs, 'files' => $files,
            ];
            file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $manifest + ['dir' => $dir];
        } catch (\Throwable $e) {
            self::removeTree($dir); // backup setengah jadi tidak boleh terlihat sebagai backup sah
            throw $e;
        }
    }

    /**
     * Hapus backup lebih tua dari $keepDays (berdasarkan waktu di nama folder). Backup terbaru selalu disimpan.
     * Hanya folder bernama npd-<db>-YYYYmmdd-HHMMSS yang disentuh.
     * @return list<string> folder yang dihapus
     */
    public function prune(string $destRoot, int $keepDays, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $limit = $now->modify('-' . max(1, $keepDays) . ' days');
        $found = [];
        foreach (glob(rtrim($destRoot, '/\\') . '/npd-*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match(self::NAME_RE, basename($d), $m) && is_file($d . '/manifest.json')) {
                $at = \DateTimeImmutable::createFromFormat('YmdHis', $m[1] . $m[2]);
                if ($at) {
                    $found[$d] = $at;
                }
            }
        }
        asort($found);
        $newest = array_key_last($found);
        $removed = [];
        foreach ($found as $d => $at) {
            if ($d !== $newest && $at < $limit) {
                self::removeTree($d);
                $removed[] = basename($d);
            }
        }
        return $removed;
    }

    // ------------------------------------------------------------------ restore

    /**
     * Pulihkan backup ke database & folder dokumen yang kosong/baru, lalu verifikasi.
     * @return array{database:string,documents:string,tables:int,rows:int,doc_files:int,triggers:list<string>,mismatches:list<string>}
     */
    public function restore(string $from, string $targetDb, string $targetDocs, bool $withTriggers = true): array
    {
        self::assertDbName($targetDb);
        $manifest = $this->readManifest($from);
        foreach ($manifest['files'] as $f => $meta) {
            if (!is_file($from . '/' . $f) || hash_file('sha256', $from . '/' . $f) !== $meta['sha256']) {
                throw new \RuntimeException("Arsip {$f} rusak atau tidak cocok dengan manifest (SHA-256) — pemulihan dibatalkan");
            }
        }
        // target harus kosong: pemulihan tidak pernah menimpa data
        $server = Db::connect(['name' => ''] + $this->db);
        $st = $server->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
        $st->execute([$targetDb]);
        if ((int) $st->fetchColumn() > 0) {
            throw new \RuntimeException("Database {$targetDb} sudah berisi tabel — pulihkan ke database baru/kosong");
        }
        if (is_dir($targetDocs) && (new \FilesystemIterator($targetDocs))->valid()) {
            throw new \RuntimeException("Folder dokumen {$targetDocs} tidak kosong — pulihkan ke folder baru/kosong");
        }
        $st = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$targetDb]);
        $createdDb = (int) $st->fetchColumn() === 0;
        $createdDocs = !is_dir($targetDocs);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $targetDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $this->method === 'mysqldump'
                ? $this->import($from . '/database.sql.gz', $targetDb, $withTriggers)
                : $this->phpImport($from . '/database.sql.gz', $targetDb, $withTriggers);
            if (!is_dir($targetDocs) && !mkdir($targetDocs, 0750, true) && !is_dir($targetDocs)) {
                throw new \RuntimeException("Folder dokumen tidak dapat dibuat: {$targetDocs}");
            }
            if (isset($manifest['files']['documents.tar.gz'])) {
                if ($this->method === 'mysqldump') {
                    $this->run([$this->bin['tar'], '-xzf', $from . '/documents.tar.gz', '-C', $targetDocs], 'tar');
                } else {
                    $this->extractTarGz($from . '/documents.tar.gz', $targetDocs);
                }
            }
            if (isset($manifest['files']['documents.zip'])) {
                $zip = new \ZipArchive();
                if ($zip->open($from . '/documents.zip') !== true || !$zip->extractTo($targetDocs)) {
                    throw new \RuntimeException('Arsip dokumen (zip) tidak dapat diekstrak');
                }
                $zip->close();
            }
        } catch (\Throwable $e) {
            // bersihkan HANYA yang dibuat oleh perintah ini (database & folder baru berisi hasil sebagian)
            if ($createdDb) {
                $server->exec('DROP DATABASE IF EXISTS `' . $targetDb . '`');
            }
            if ($createdDocs) {
                self::removeTree($targetDocs);
            }
            $hint = str_contains($e->getMessage(), '1419')
                ? ' — user pemulih butuh hak SUPER untuk membuat trigger saat binary log aktif; ulangi dengan --without-triggers lalu jalankan database/hardening.sql sebagai admin'
                : '';
            throw new \RuntimeException($e->getMessage() . $hint, 0, $e);
        }
        $server = null;
        $report = $this->verify($withTriggers ? $manifest : ['triggers' => []] + $manifest, $targetDb, $targetDocs);
        return $report;
    }

    /**
     * Bandingkan database & folder dokumen dengan manifest backup.
     * @param array<string,mixed> $manifest
     * @return array{database:string,documents:string,tables:int,rows:int,doc_files:int,triggers:list<string>,mismatches:list<string>}
     */
    public function verify(array $manifest, string $db, string $docsDir): array
    {
        $pdo = Db::connect(['name' => $db] + $this->db);
        $actual = $this->tableCounts($pdo);
        $mismatches = [];
        foreach ($manifest['tables'] as $t => $n) {
            if (!array_key_exists($t, $actual)) {
                $mismatches[] = "tabel {$t} tidak ada";
            } elseif ((int) $actual[$t] !== (int) $n) {
                $mismatches[] = "tabel {$t}: {$actual[$t]} baris, manifest {$n}";
            }
        }
        foreach (array_diff_key($actual, $manifest['tables']) as $t => $n) {
            $mismatches[] = "tabel {$t} tidak ada di manifest";
        }
        $triggers = $this->triggers($pdo);
        foreach (array_diff((array) ($manifest['triggers'] ?? []), $triggers) as $t) {
            $mismatches[] = "trigger {$t} tidak ada";
        }
        $docs = $this->documentStats($docsDir);
        if ($docs['files'] !== (int) $manifest['documents']['files'] || $docs['bytes'] !== (int) $manifest['documents']['bytes']) {
            $mismatches[] = "dokumen: {$docs['files']} file / {$docs['bytes']} byte, manifest {$manifest['documents']['files']} / {$manifest['documents']['bytes']}";
        }
        return ['database' => $db, 'documents' => $docsDir, 'tables' => count($actual), 'rows' => array_sum($actual),
            'doc_files' => $docs['files'], 'triggers' => $triggers, 'mismatches' => $mismatches];
    }

    /** @return array<string,mixed> */
    public function readManifest(string $from): array
    {
        $m = json_decode((string) @file_get_contents($from . '/manifest.json'), true);
        if (!is_array($m) || ($m['format'] ?? null) !== 1 || !isset($m['tables'], $m['documents'], $m['files']['database.sql.gz'])) {
            throw new \RuntimeException("Bukan folder backup NPD yang sah (manifest.json): {$from}");
        }
        return $m;
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string,int> */
    private function tableCounts(PDO $pdo): array
    {
        $out = [];
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            Db::assertIdentifier((string) $t);
            $out[(string) $t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
        }
        return $out;
    }

    /** Trigger yang terlihat oleh user koneksi (tanpa hak TRIGGER daftar ini kosong). @return list<string> */
    private function triggers(PDO $pdo): array
    {
        return array_map('strval', $pdo->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{files:int,bytes:int} */
    private function documentStats(string $dir): array
    {
        $files = 0;
        $bytes = 0;
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $files++;
                    $bytes += $f->getSize();
                }
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Dump murni PHP: satu koneksi, START TRANSACTION WITH CONSISTENT SNAPSHOT (setara --single-transaction).
     * Jumlah baris & trigger untuk manifest dibaca dari snapshot yang sama, sehingga cocok persis dengan isi dump.
     * Format kompatibel dengan klien mysql maupun phpImport(): CREATE TABLE (SHOW CREATE TABLE), INSERT bertumpuk
     * satu baris per pernyataan, trigger dalam blok DELIMITER ;; tanpa DEFINER.
     * @return array{0:array<string,int>,1:list<string>,2:string}
     */
    private function phpDump(string $out): array
    {
        $pdo = Db::connect($this->dumpDb);
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $tz = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $counts = $this->tableCounts($pdo);
        $triggers = $this->triggers($pdo);
        $gz = gzopen($out, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Tidak dapat menulis {$out}");
        }
        $w = static function (string $s) use ($gz): void {
            if (gzwrite($gz, $s) === false) {
                throw new \RuntimeException('Gagal menulis dump (disk penuh?)');
            }
        };
        $w("-- NPD Project Control — dump PHP (BackupService), server {$version}, " . date('c') . "\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . 'SET time_zone=' . $pdo->quote($tz) . ";\n\n");
        foreach (array_keys($counts) as $t) {
            $create = (string) $pdo->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM)[1];
            $w("--\n-- Tabel `{$t}`\n--\n{$create};\n\n");
            if ($counts[$t] === 0) {
                continue;
            }
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); // baris dialirkan, bukan dimuat semua ke memori
            $st = $pdo->query('SELECT * FROM `' . $t . '`');
            $head = null;
            $batch = [];
            $size = 0;
            $flush = static function () use (&$batch, &$size, &$head, $w): void {
                if ($batch) {
                    $w($head . implode(',', $batch) . ";\n");
                }
                $batch = [];
                $size = 0;
            };
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($head === null) {
                    $head = 'INSERT INTO `' . $t . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ';
                }
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $batch[] = $tuple;
                $size += strlen($tuple);
                if ($size > 524288 || count($batch) >= 500) {
                    $flush();
                }
            }
            $flush();
            $st->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $w("\n");
        }
        foreach ($triggers as $name) {
            $row = $pdo->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $name) . '`')->fetch(PDO::FETCH_ASSOC);
            $sql = (string) ($row['SQL Original Statement'] ?? '');
            $sql = (string) preg_replace('/\bDEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $sql);
            $w("DELIMITER ;;\n" . rtrim($sql) . "\n;;\nDELIMITER ;\n");
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
        gzclose($gz);
        $pdo->exec('COMMIT');
        return [$counts, $triggers, $version];
    }

    /**
     * Impor dump (format phpDump maupun mysqldump) lewat PDO, pernyataan demi pernyataan; memahami blok DELIMITER.
     * Pernyataan di dump selalu berakhir dengan pemisah di akhir baris (INSERT satu baris; teks multi-baris di-escape).
     */
    private function phpImport(string $gzFile, string $targetDb, bool $withTriggers): void
    {
        $pdo = Db::connect(['name' => $targetDb] + $this->db);
        $gz = gzopen($gzFile, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Tidak dapat membaca {$gzFile}");
        }
        $delim = ';';
        $buf = '';
        $n = 0;
        try {
            while (($line = gzgets($gz)) !== false) {
                $trim = trim($line);
                if ($buf === '' && ($trim === '' || str_starts_with($trim, '--'))) {
                    continue;
                }
                if ($buf === '' && preg_match('/^DELIMITER\s+(\S+)$/i', $trim, $m)) {
                    $delim = $m[1];
                    continue;
                }
                $buf .= $line;
                if (!str_ends_with($trim, $delim)) {
                    continue;
                }
                $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
                $buf = '';
                if ($stmt === '') {
                    continue;
                }
                if (preg_match('/^(\/\*!\d+\s*)?CREATE\b.{0,200}?\bTRIGGER\b/is', $stmt)) {
                    if (!$withTriggers) {
                        continue; // dipasang ulang lewat database/hardening.sql
                    }
                    // DEFINER server asal dibuang → trigger dibuat atas nama user pemulih
                    $stmt = (string) preg_replace('/\/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*\/\s*|\bDEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i', '', $stmt);
                }
                $n++;
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    throw new \RuntimeException(sprintf('Impor database gagal pada pernyataan #%d (%s…): %s', $n, mb_substr($stmt, 0, 60), $e->getMessage()), 0, $e);
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Ekstrak documents.tar.gz tanpa program tar (mode PHP). Pembaca ustar sederhana (PharData tidak dapat menelusuri
     * entri berawalan "./" buatan `tar -C dir .`): hanya file biasa, nama panjang GNU (L) & PAX (path) didukung,
     * jalur absolut atau berisi ".." ditolak.
     */
    private function extractTarGz(string $file, string $target): void
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Tidak dapat membaca {$file}");
        }
        $read = static function (int $n) use ($gz): string {
            $buf = '';
            while (strlen($buf) < $n && !gzeof($gz)) {
                $chunk = gzread($gz, $n - strlen($buf));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
            }
            return $buf;
        };
        $longName = null;
        try {
            while (strlen($header = $read(512)) === 512 && trim($header, "\0") !== '') {
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
                $type = $header[156];
                $data = $size > 0 ? $read($size) : '';
                if ($size % 512) {
                    $read(512 - $size % 512); // padding blok
                }
                if ($type === 'L') { // GNU nama panjang untuk entri berikutnya
                    $longName = rtrim($data, "\0");
                    continue;
                }
                if ($type === 'x') { // PAX: ambil path bila ada
                    if (preg_match('/\d+ path=([^\n]*)\n/', $data, $m)) {
                        $longName = $m[1];
                    }
                    continue;
                }
                $path = $longName ?? (substr($header, 257, 5) === 'ustar' && $prefix !== '' ? $prefix . '/' . $name : $name);
                $longName = null;
                if ($type !== '0' && $type !== "\0") {
                    continue; // direktori/symlink/dll. — direktori dibuat dari jalur file
                }
                $rel = (string) preg_replace('#^(\./)+#', '', str_replace('\\', '/', $path));
                if ($rel === '' || str_starts_with($rel, '/') || preg_match('#(^|/)\.\.(/|$)#', $rel)) {
                    throw new \RuntimeException("Jalur tidak aman di arsip dokumen: {$path}");
                }
                $dest = rtrim($target, '/') . '/' . $rel;
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
                    throw new \RuntimeException('Folder dokumen tidak dapat dibuat: ' . dirname($dest));
                }
                if (strlen($data) !== $size || file_put_contents($dest, $data) !== $size) {
                    throw new \RuntimeException("Dokumen {$rel} tidak dapat diekstrak (arsip terpotong?)");
                }
            }
        } finally {
            gzclose($gz);
        }
    }

    /** Arsip zip storage/documents (mode PHP, tanpa tar). */
    private function zipDocuments(string $out): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($out, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \RuntimeException("Tidak dapat membuat {$out}");
        }
        $base = rtrim(str_replace('\\', '/', (string) realpath($this->documentsDir)), '/');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->documentsDir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $path = str_replace('\\', '/', (string) $f->getRealPath());
                $zip->addFile($path, ltrim(substr($path, strlen($base)), '/'));
            }
        }
        if (!$zip->close()) {
            throw new \RuntimeException('Gagal menulis arsip dokumen (zip)');
        }
    }

    private function dump(string $dbName, string $out): void
    {
        $cnf = $this->defaultsFile($this->dumpDb);
        try {
            $err = tempnam(sys_get_temp_dir(), 'npddump');
            $proc = proc_open([$this->bin['mysqldump'], '--defaults-extra-file=' . $cnf, '--single-transaction', '--quick', '--routines', '--triggers',
                '--no-tablespaces', '--hex-blob', '--default-character-set=utf8mb4', ...$this->gtidOption(), $dbName],
                [1 => ['pipe', 'w'], 2 => ['file', $err, 'w']], $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('mysqldump tidak dapat dijalankan (cek MYSQLDUMP_BIN)');
            }
            $gz = gzopen($out, 'wb6');
            $tail = '';
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1 << 20);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                gzwrite($gz, $chunk);
                $tail = substr($tail . $chunk, -256);
            }
            fclose($pipes[1]);
            gzclose($gz);
            $code = proc_close($proc);
            $msg = trim((string) file_get_contents($err));
            @unlink($err);
            // mysqldump menulis "-- Dump completed" di akhir hanya bila dump utuh
            if ($code !== 0 || !str_contains($tail, 'Dump completed')) {
                throw new \RuntimeException('mysqldump gagal (kode ' . $code . '): ' . mb_substr($msg, 0, 500));
            }
        } finally {
            @unlink($cnf);
        }
    }

    private function import(string $gzFile, string $targetDb, bool $withTriggers): void
    {
        $cnf = $this->defaultsFile($this->db);
        try {
            $err = tempnam(sys_get_temp_dir(), 'npdimp');
            $proc = proc_open([$this->bin['mysql'], '--defaults-extra-file=' . $cnf, '--default-character-set=utf8mb4', $targetDb],
                [0 => ['pipe', 'r'], 1 => ['file', $err, 'a'], 2 => ['file', $err, 'a']], $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('mysql client tidak dapat dijalankan (cek MYSQL_BIN)');
            }
            $gz = gzopen($gzFile, 'rb');
            $skipping = false;
            while (($line = gzgets($gz)) !== false) {
                // tanpa trigger: lewati blok "/*!50003 CREATE*/ ... TRIGGER ... */;;" (dipasang ulang lewat hardening.sql)
                if (!$withTriggers && ($skipping || (str_contains($line, '/*!50003 CREATE*/') && str_contains($line, 'TRIGGER')))) {
                    $skipping = !str_ends_with(rtrim($line), '*/;;');
                    continue;
                }
                // DEFINER trigger/rutin dari server asal dibuang → dibuat atas nama user pemulih (tanpa hak SUPER)
                if (str_contains($line, 'DEFINER=')) {
                    $line = (string) preg_replace('/\/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*\/\s*|DEFINER=`[^`]*`@`[^`]*`\s*/', '', $line);
                }
                if (@fwrite($pipes[0], $line) === false) {
                    break; // mysql berhenti karena error — pesan aslinya dibaca dari stderr di bawah
                }
            }
            gzclose($gz);
            @fclose($pipes[0]);
            $code = proc_close($proc);
            $msg = trim((string) file_get_contents($err));
            @unlink($err);
            if ($code !== 0) {
                throw new \RuntimeException('Impor database gagal (kode ' . $code . '): ' . mb_substr($msg, 0, 500));
            }
        } finally {
            @unlink($cnf);
        }
    }

    /**
     * --set-gtid-purged=OFF hanya dikenal mysqldump MySQL; mysqldump/mariadb-dump MariaDB (umum di hosting cPanel)
     * menolak opsi tak dikenal, jadi opsi dipakai hanya bila didukung.
     * @return list<string>
     */
    private function gtidOption(): array
    {
        $p = @proc_open([$this->bin['mysqldump'], '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [];
        }
        $help = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        return str_contains($help, 'set-gtid-purged') ? ['--set-gtid-purged=OFF'] : [];
    }

    /**
     * File opsi MySQL sementara (0600) agar password tidak muncul di daftar proses.
     * @param array<string,mixed> $c
     */
    private function defaultsFile(array $c): string
    {
        $f = tempnam(sys_get_temp_dir(), 'npdcnf');
        chmod($f, 0600);
        $q = static fn ($v) => '"' . addcslashes((string) $v, "\\\"") . '"';
        $lines = ['[client]', 'user=' . $q($c['user'] ?? ''), 'password=' . $q($c['pass'] ?? '')];
        if (!empty($c['socket'])) {
            $lines[] = 'socket=' . $q($c['socket']);
        } else {
            $lines[] = 'host=' . $q($c['host'] ?? '127.0.0.1');
            $lines[] = 'port=' . (int) ($c['port'] ?? 3306);
        }
        file_put_contents($f, implode("\n", $lines) . "\n");
        return $f;
    }

    /** @param list<string> $cmd */
    private function run(array $cmd, string $label): void
    {
        $err = tempnam(sys_get_temp_dir(), 'npdrun');
        $proc = proc_open($cmd, [1 => ['file', $err, 'a'], 2 => ['file', $err, 'a']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("{$label} tidak dapat dijalankan");
        }
        $code = proc_close($proc);
        $msg = trim((string) file_get_contents($err));
        @unlink($err);
        if ($code !== 0) {
            throw new \RuntimeException("{$label} gagal (kode {$code}): " . mb_substr($msg, 0, 500));
        }
    }

    private static function assertDbName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Nama database tidak valid');
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
