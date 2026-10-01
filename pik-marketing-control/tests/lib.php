<?php

declare(strict_types=1);

/*
 * Mini test framework (tanpa dependency) untuk PIK Marketing Control.
 * Dipakai oleh tests/run.php.
 */

use App\Helpers\Database;
use App\Helpers\SqlFile;

final class AssertionFailed extends RuntimeException
{
}

/** Test dilewati karena lingkungan tidak mendukung (alasan ditampilkan, bukan dianggap lulus). */
final class TestSkipped extends RuntimeException
{
}

final class TestState
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $group = '';
    public static ?string $filter = null;
}

function group(string $name): void
{
    TestState::$group = $name;
    if (TestState::$filter === null || stripos($name, TestState::$filter) !== false) {
        echo "\n\033[1m{$name}\033[0m\n";
    }
}

function test(string $name, callable $fn): void
{
    if (TestState::$filter !== null && stripos(TestState::$group . ' ' . $name, TestState::$filter) === false) {
        return;
    }
    try {
        $fn();
        TestState::$passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (TestSkipped $e) {
        TestState::$skipped++;
        echo "  \033[33m↷\033[0m {$name} \033[33m(dilewati: {$e->getMessage()})\033[0m\n";
    } catch (Throwable $e) {
        TestState::$failed++;
        $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        TestState::$failures[] = TestState::$group . ' › ' . $name . ': ' . $e->getMessage() . $where;
        echo "  \033[31m✗ {$name}\033[0m\n    " . str_replace("\n", "\n    ", $e->getMessage()) . $where . "\n";
    }
}

function fail(string $message): never
{
    throw new AssertionFailed($message);
}

function skip(string $reason): never
{
    throw new TestSkipped($reason);
}

function assert_true(mixed $condition, string $message = 'Expected condition to be true'): void
{
    if ($condition !== true) {
        fail($message);
    }
}

function assert_false(mixed $condition, string $message = 'Expected condition to be false'): void
{
    if ($condition !== false) {
        fail($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . "\n" : '') . 'Expected: ' . var_export($expected, true) . "\nActual:   " . var_export($actual, true));
    }
}

function assert_equals(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected != $actual) {
        fail(($message !== '' ? $message . "\n" : '') . 'Expected: ' . var_export($expected, true) . "\nActual:   " . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $snippet = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($haystack)) ?? ''), 0, 300);
        fail(($message !== '' ? $message . "\n" : '') . "Expected to find: {$needle}\nIn: {$snippet}…");
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . "\n" : '') . "Did not expect to find: {$needle}");
    }
}

function assert_status(int $expected, HttpResponse $res, string $message = ''): void
{
    if ($res->status !== $expected) {
        $flash = '';
        if (preg_match_all('/invalid-feedback d-block">([^<]*)/', $res->body, $m)) {
            $flash = "\nValidation: " . implode(' | ', $m[1]);
        }
        if (preg_match('/<pre class="error-trace[^>]*>(.*?)<\/pre>/s', $res->body, $m)) {
            $flash .= "\nTrace: " . html_entity_decode(mb_substr($m[1], 0, 800));
        }
        fail(($message !== '' ? $message . "\n" : '') . "Expected HTTP {$expected}, got {$res->status} for {$res->method} {$res->url}" . ($res->location ? " → {$res->location}" : '') . $flash);
    }
}

function assert_redirect(HttpResponse $res, string $pathContains, string $message = ''): void
{
    if (!in_array($res->status, [301, 302, 303], true)) {
        assert_status(302, $res, $message);
    }
    if (!str_contains((string) $res->location, $pathContains)) {
        fail(($message !== '' ? $message . "\n" : '') . "Expected redirect to contain {$pathContains}, got {$res->location}");
    }
}

/** Kosongkan database test lalu buat ulang dari schema.sql + seed.sql. */
function reset_database(): void
{
    $db = (string) Database::fetchValue('SELECT DATABASE()');
    if (!str_ends_with($db, '_test')) {
        throw new RuntimeException("Menolak reset database '{$db}': nama database test harus berakhiran _test");
    }
    $pdo = Database::connection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Database::fetchColumn('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') as $table) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    SqlFile::run(APP_ROOT . '/database/schema.sql');
    SqlFile::run(APP_ROOT . '/database/seed.sql');
}

final class HttpResponse
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $location,
        public readonly array $headers
    ) {
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}

/** Klien HTTP dengan cookie jar sendiri (1 instance = 1 browser/user). */
final class HttpClient
{
    private string $jar;
    public ?string $token = null;

    public function __construct(private string $base)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'pikjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path, array $query = []): HttpResponse
    {
        $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
        $res = $this->request('GET', $url, null, []);
        $this->captureToken($res->body);
        return $res;
    }

    /** @param array<string,mixed> $data */
    public function post(string $path, array $data = [], bool $withToken = true, array $headers = []): HttpResponse
    {
        if ($withToken && !array_key_exists('_token', $data)) {
            if ($this->token === null) {
                $this->get('/login');
            }
            $data['_token'] = $this->token;
        }
        $res = $this->request('POST', $this->base . $path, http_build_query($data), $headers);
        $this->captureToken($res->body);
        return $res;
    }

    /**
     * Upload multipart (file: field => path lokal).
     * @param array<string,mixed> $fields
     * @param array<string,string> $files
     */
    public function upload(string $path, array $fields, array $files): HttpResponse
    {
        if ($this->token === null) {
            $this->get('/login');
        }
        $fields['_token'] = $this->token;
        foreach ($files as $field => $file) {
            $fields[$field] = new CURLFile($file, 'application/octet-stream', basename($file));
        }
        $res = $this->request('POST', $this->base . $path, $fields, []);
        $this->captureToken($res->body);
        return $res;
    }

    public function login(string $email, string $password): HttpResponse
    {
        $this->get('/login');
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function captureToken(string $body): void
    {
        if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $body, $m) || preg_match('/name="_token" value="([a-f0-9]{64})"/', $body, $m)) {
            $this->token = $m[1];
        }
    }

    /** @param string|array<string,mixed>|null $body */
    private function request(string $method, string $url, string|array|null $body, array $headers): HttpResponse
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $content = curl_exec($ch);
        if ($content === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($method, $url, $status, (string) $content, $responseHeaders['location'] ?? null, $responseHeaders);
    }

    /** Download mentah (untuk export file). */
    public function raw(string $path, array $query = []): HttpResponse
    {
        return $this->request('GET', $this->base . $path . ($query ? '?' . http_build_query($query) : ''), null, []);
    }
}

/** Ambil nilai pertama hasil regex dari body. */
function extract_first(string $pattern, string $body): ?string
{
    return preg_match($pattern, $body, $m) ? $m[1] : null;
}

/** Buat user langsung di database test. */
function create_user(string $role, string $email, string $password = 'Rahasia123', bool $mustChange = false): int
{
    return App\Models\User::createWithPassword([
        'name'                 => ucfirst($role) . ' Tester',
        'email'                => $email,
        'role'                 => $role,
        'password'             => $password,
        'must_change_password' => $mustChange ? 1 : 0,
    ]);
}

/** Klien HTTP yang sudah login sebagai role tertentu (user dibuat bila belum ada). */
function client_as(string $role): HttpClient
{
    static $created = [];
    $email = strtolower($role) . '.qa@pik.test';
    if (!isset($created[$email]) && !App\Helpers\Database::fetchValue('SELECT 1 FROM users WHERE email = :e', ['e' => $email])) {
        create_user($role, $email);
    }
    $created[$email] = true;
    $client = new HttpClient(TEST_BASE_URL);
    $res = $client->login($email, 'Rahasia123');
    if ($res->status !== 302) {
        throw new RuntimeException("Login sebagai {$role} gagal (HTTP {$res->status})");
    }
    $client->get('/profile');
    return $client;
}
