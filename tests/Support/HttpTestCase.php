<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Test HTTP end-to-end: menjalankan server bawaan PHP (php -S) terhadap database
 * npd_test_http yang diinstal ulang per kelas test, lalu mengirim request nyata via curl.
 */
abstract class HttpTestCase extends TestCase
{
    public const PASSWORD = 'Passw0rd!';
    protected static string $base = '';
    /** @var resource|null */
    private static $proc = null;
    private static ?PDO $pdo = null;
    private static string $tmp = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $root = dirname(__DIR__, 2);
        $db = 'npd_test_http';
        $env = self::env($db);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/install.php') . ' --database=' . $db . ' --fresh';
        self::runCommand($cmd, $env);
        self::runCommand(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/seed-http.php'), $env);

        $port = self::freePort();
        self::$base = 'http://127.0.0.1:' . $port;
        self::$tmp = sys_get_temp_dir() . '/npd_http_' . getmypid() . '_' . $port;
        @mkdir(self::$tmp . '/sessions', 0700, true);
        $env['SESSION_SAVE_PATH'] = self::$tmp . '/sessions';
        $spec = [0 => ['pipe', 'r'], 1 => ['file', self::$tmp . '/server.log', 'a'], 2 => ['file', self::$tmp . '/server.log', 'a']];
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public'], $spec, $pipes, $root, $env);
        // tunggu server siap
        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                break;
            }
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        self::$proc = null;
        self::$pdo = null;
        parent::tearDownAfterClass();
    }

    /** @return array<string,string> */
    private static function env(string $db): array
    {
        $env = getenv();
        $env['APP_ENV'] = 'testing';
        $env['APP_DEBUG'] = '0';
        $env['DB_NAME'] = $db;
        $env['APP_KEY'] = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
        return $env;
    }

    /** @param array<string,string> $env */
    private static function runCommand(string $cmd, array $env): void
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($p) !== 0) {
            throw new \RuntimeException("Perintah gagal: {$cmd}\n{$out}");
        }
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    /** Koneksi langsung ke database test HTTP (untuk menyiapkan/memeriksa data). */
    protected static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = \App\Core\Config::get('db');
            $cfg['name'] = 'npd_test_http';
            self::$pdo = \App\Core\Db::connect($cfg);
        }
        return self::$pdo;
    }

    protected function client(): HttpClient
    {
        return new HttpClient(self::$base);
    }

    /** Login dan kembalikan client dengan sesi aktif. */
    protected function loginAs(string $email, string $password = self::PASSWORD): HttpClient
    {
        $c = $this->client();
        $c->get('/login.php');
        $res = $c->post('/login.php', ['email' => $email, 'password' => $password, '_csrf' => $c->csrf()]);
        $this->assertSame(303, $res['status'], 'Login gagal untuk ' . $email . ': ' . substr($res['body'], 0, 300));
        return $c;
    }

    protected function clearLoginAttempts(): void
    {
        self::pdo()->exec('DELETE FROM login_attempts');
    }
}

/** Klien HTTP sederhana dengan cookie jar per instance (mensimulasikan satu browser). */
final class HttpClient
{
    private string $jar;
    public string $lastBody = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'npdjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /** @param array<string,mixed>|string|null $data @return array{status:int,headers:array<string,list<string>>,body:string} */
    public function post(string $path, array|string|null $data = [], array $headers = []): array
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string} */
    public function request(string $method, string $path, array|string|null $data = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($data !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->lastBody = $body;
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    /** Token CSRF dari meta tag / hidden input halaman terakhir. */
    public function csrf(): string
    {
        if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $this->lastBody, $m)
            || preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->lastBody, $m)) {
            return $m[1];
        }
        return '';
    }

    public function sessionId(): ?string
    {
        foreach (file($this->jar) ?: [] as $line) {
            $cols = explode("\t", trim($line));
            if (count($cols) >= 7 && $cols[5] === 'NPDSESSID') {
                return $cols[6];
            }
        }
        return null;
    }
}
