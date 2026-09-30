<?php

declare(strict_types=1);

/*
 * Smoke test end-to-end lewat HTTP sungguhan terhadap aplikasi yang sedang berjalan
 * (cookie session, token CSRF, form multipart), memakai akun demo dari seeder:
 *
 *   Login -> Create PR -> Save Draft -> Review -> Submit -> Approve (Diketahui)
 *   -> Approve (Disetujui) -> Generate PDF -> Completed/Archive
 *
 *   php tests/e2e/smoke.php [BASE_URL] [PASSWORD]
 *   php tests/e2e/smoke.php http://127.0.0.1:8000 PikDemo2026!
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$password = $argv[2] ?? (getenv('SEED_PASSWORD') ?: 'PikDemo2026!');

final class Browser
{
    private string $jar;

    public function __construct(private readonly string $base)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'jar');
    }

    /**
     * @return array{status: int, headers: string, body: string}
     */
    public function request(string $method, string $path, array $fields = [], bool $multipart = false): array
    {
        $ch = curl_init($this->base . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Referer: ' . $this->base . $path],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $multipart ? $fields : http_build_query($fields);
        }
        curl_setopt_array($ch, $options);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    public function token(string $path): string
    {
        $page = $this->request('GET', $path);
        if (!preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $m)) {
            fail("Token CSRF tidak ditemukan di {$path} (HTTP {$page['status']})");
        }

        return $m[1];
    }

    public function login(string $email, string $password): void
    {
        $response = $this->request('POST', '/login', ['_token' => $this->token('/login'), 'email' => $email, 'password' => $password]);
        if (!str_contains(location($response), '/dashboard')) {
            fail("Login gagal untuk {$email}");
        }
        ok("login {$email}");
    }

    public function logout(): void
    {
        $this->request('POST', '/logout', ['_token' => $this->token('/dashboard')]);
    }
}

function location(array $response): string
{
    return preg_match('/^Location:\s*(\S+)/mi', $response['headers'], $m) ? $m[1] : '';
}

function ok(string $message): void
{
    fwrite(STDOUT, "  \u{2713} {$message}\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "  \u{2717} {$message}\n");
    exit(1);
}

function expect(bool $condition, string $message): void
{
    $condition ? ok($message) : fail($message);
}

fwrite(STDOUT, "Smoke test terhadap {$base}\n");
$browser = new Browser($base);

// 1. Requester membuat draft dengan 2 item + lampiran
$browser->login('mitha@pik.local', $password);
$pdf = tempnam(sys_get_temp_dir(), 'att') . '.pdf';
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n");
$form = $browser->request('GET', '/pr/create');
preg_match('/<option value="(\d+)" data-code="PD"/', $form['body'], $dept);
preg_match('/<option value="(\d+)"[^>]*>\s*Shopee/', $form['body'], $supplier);
expect(isset($dept[1], $supplier[1]), 'form Buat PR memuat department & supplier');

$response = $browser->request('POST', '/pr', [
    '_token' => $browser->token('/pr/create'),
    'department_id' => $dept[1],
    'pr_date' => date('Y-m-d'),
    'supplier_id' => $supplier[1],
    'tax_rate' => '0',
    'notes' => 'Smoke test end-to-end',
    'items[0][name]' => 'Lem korea',
    'items[0][quantity]' => '2',
    'items[0][unit]' => 'pcs',
    'items[0][unit_price]' => '65000',
    'items[1][name]' => 'Autosol',
    'items[1][quantity]' => '3',
    'items[1][unit]' => 'pcs',
    'items[1][unit_price]' => '50000',
    'action' => 'review',
    'attachments[0]' => new CURLFile($pdf, 'application/pdf', 'penawaran.pdf'),
], true);
@unlink($pdf);
if (!preg_match('#/pr/(\d+)\?review=1#', location($response), $m)) {
    fail('Draft tidak tersimpan (HTTP ' . $response['status'] . ')');
}
$id = (int) $m[1];
ok("draft PR #{$id} tersimpan, diarahkan ke halaman review");

$review = $browser->request('GET', "/pr/{$id}?review=1");
expect(str_contains($review['body'], 'Review sebelum submit') && str_contains($review['body'], 'Rp280.000'), 'halaman review menampilkan total Rp280.000 hasil hitung server');
expect(str_contains($review['body'], 'penawaran.pdf'), 'lampiran tersimpan');

// 2. Submit
$browser->request('POST', "/pr/{$id}/submit", ['_token' => $browser->token("/pr/{$id}")]);
$detail = $browser->request('GET', "/pr/{$id}");
preg_match('#<h1>(PR/PIK/[A-Z]+/\d{4}-PDPR\d{3})</h1>#', $detail['body'], $number);
expect(isset($number[1]) && str_contains($detail['body'], 'Submitted'), 'PR disubmit dengan nomor ' . ($number[1] ?? '?'));
$browser->logout();

// 3. Approval tahap 1 & 2
$browser->login('budi@pik.local', $password);
$queue = $browser->request('GET', '/approvals');
expect(str_contains($queue['body'], $number[1]), 'PR muncul di antrian approval Budi (Diketahui)');
$browser->request('POST', "/pr/{$id}/approve", ['_token' => $browser->token("/pr/{$id}"), 'comment' => 'Diketahui (smoke test)']);
expect(str_contains($browser->request('GET', "/pr/{$id}")['body'], 'In Review'), 'tahap Diketahui disetujui → In Review');
$browser->logout();

$browser->login('hendra@pik.local', $password);
$browser->request('POST', "/pr/{$id}/approve", ['_token' => $browser->token("/pr/{$id}")]);
expect(str_contains($browser->request('GET', "/pr/{$id}")['body'], 'Approved'), 'tahap Disetujui disetujui → Approved');
$browser->logout();

// 4. PDF & arsip
$browser->login('mitha@pik.local', $password);
$file = $browser->request('GET', "/pr/{$id}/pdf");
expect($file['status'] === 200 && str_starts_with($file['body'], '%PDF-'), 'PDF berhasil dibuat (' . number_format(strlen($file['body'])) . ' byte)');
$browser->request('POST', "/pr/{$id}/complete", ['_token' => $browser->token("/pr/{$id}")]);
expect(str_contains($browser->request('GET', "/pr/{$id}")['body'], 'Completed'), 'PR ditandai Completed / diarsipkan');

fwrite(STDOUT, "Semua langkah berhasil. PR #{$id} ({$number[1]}).\n");
