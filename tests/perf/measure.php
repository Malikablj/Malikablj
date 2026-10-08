<?php
declare(strict_types=1);

/**
 * Pengukuran kinerja terhadap data volume (PRD §13.3 / NFR-08). Menjalankan server PHP sendiri terhadap
 * database *_perf, login sebagai Admin demo, lalu mengukur:
 *   - halaman utama (≤ 2 dtk): 1 pemanasan + N ulangan, dicatat median & maksimum,
 *   - API umum (p95 ≤ 500 ms): pratinjau jadwal (plan & dependency) dan preferensi,
 *   - hitung ulang jadwal satu project terbesar (≤ 1 dtk, hingga 10 part × 20 proses),
 *   - export PDF/Excel (≤ 15 dtk).
 * Keluar dengan kode 1 bila ada target yang terlampaui.
 *
 *   DB_NAME=npd_perf NPD_DEMO_PASSWORD='Rahasia123' php tests/perf/measure.php [--runs=5] [--api-runs=60] [--no-opcache] [--out=hasil.json]
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Scheduling\ScheduleService;

$opts = getopt('', ['runs::', 'api-runs::', 'no-opcache', 'out::']);
$runs = max(1, (int) ($opts['runs'] ?? 5));
$apiRuns = max(10, (int) ($opts['api-runs'] ?? 60));
$opcache = !isset($opts['no-opcache']);
$dbName = (string) (Config::get('db')['name'] ?? '');
$password = (string) getenv('NPD_DEMO_PASSWORD');
if (!str_ends_with($dbName, '_perf') || $password === '') {
    fwrite(STDERR, "Butuh DB_NAME=*_perf dan NPD_DEMO_PASSWORD (sekarang: {$dbName})\n");
    exit(1);
}

// ---------------------------------------------------------------- data acuan
$big = (int) Db::value('SELECT project_id FROM project_parts GROUP BY project_id ORDER BY COUNT(*) DESC, project_id DESC LIMIT 1');
$bigParts = (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ?', [$big]);
$bigProcs = (int) Db::value('SELECT COUNT(*) FROM processes WHERE project_id = ?', [$big]);
$bigNpr = (int) Db::value('SELECT npr_id FROM projects WHERE id = ?', [$big]);
$current = (int) Db::value("SELECT id FROM processes WHERE project_id = ? AND status = 'current' ORDER BY id LIMIT 1", [$big]);
$pending = (int) Db::value("SELECT id FROM processes WHERE project_id = ? AND status = 'not_started' AND part_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$big]);
$pendingDeps = Db::fetchAll('SELECT predecessor_id, dep_type, lag_days FROM process_dependencies WHERE process_id = ?', [$pending]);
$volume = Db::fetch("SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM projects) projects,
    (SELECT COUNT(*) FROM projects WHERE finished_at IS NULL AND cancelled_at IS NULL) active_projects,
    (SELECT COUNT(*) FROM project_parts) parts, (SELECT COUNT(*) FROM processes) processes, (SELECT COUNT(*) FROM process_runs) runs,
    (SELECT COUNT(*) FROM notifications) notifications, (SELECT COUNT(*) FROM audit_logs) audit_logs, (SELECT COUNT(*) FROM revision_history) revisions");

// ---------------------------------------------------------------- server
$root = dirname(__DIR__, 2);
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = 'http://127.0.0.1:' . $port;
$env = getenv() + ['APP_DEBUG' => '0'];
$env['APP_DEBUG'] = '0';
$cmd = [PHP_BINARY, '-d', 'opcache.enable_cli=' . ($opcache ? '1' : '0'), '-S', '127.0.0.1:' . $port, '-t', $root . '/public'];
$log = sys_get_temp_dir() . '/npd_perf_server_' . $port . '.log';
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); $i++) {
    usleep(100_000);
}
register_shutdown_function(static function () use ($proc): void {
    proc_terminate($proc);
});

$jar = tempnam(sys_get_temp_dir(), 'perfjar');
$csrf = '';
/** @return array{status:int,ms:float,bytes:int,body:string} */
$req = static function (string $method, string $path, array|string|null $data = null, array $headers = []) use ($base, $jar, &$csrf): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    $body = (string) curl_exec($ch);
    $r = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 'bytes' => strlen($body), 'body' => $body];
    curl_close($ch);
    if (preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $body, $m)) {
        $csrf = $m[1];
    }
    return $r;
};
$req('GET', '/login.php');
$login = $req('POST', '/login.php', ['email' => 'andi@pik.local', 'password' => $password, '_csrf' => $csrf]);
if ($login['status'] !== 303) {
    fwrite(STDERR, "Login gagal ({$login['status']})\n");
    exit(1);
}
$req('GET', '/dashboard.php');

$pct = static function (array $xs, float $p): float {
    sort($xs);
    return $xs[(int) max(0, min(count($xs) - 1, (int) ceil($p * count($xs)) - 1))];
};
$results = ['pages' => [], 'api' => [], 'recalc' => [], 'exports' => []];
$fail = [];

// ---------------------------------------------------------------- halaman (≤ 2000 ms)
$range = 'period=range&from=2026-01-01&to=2026-10-07';
$pages = [
    'Dashboard' => '/dashboard.php',
    'Dashboard (filter New Mold)' => '/dashboard.php?part_type=new_mold',
    'Daftar project' => '/projects.php',
    'Daftar project hal. 10' => '/projects.php?page=10',
    'Daftar project cari "Botol"' => '/projects.php?q=Botol',
    'Daftar project overdue' => '/projects.php?overdue=1',
    'Detail project besar' => '/project.php?id=' . $big,
    'Detail project — Proses' => '/project.php?id=' . $big . '&tab=processes',
    'Detail project — Riwayat' => '/project.php?id=' . $big . '&tab=history',
    'Detail project — Aktivitas' => '/project.php?id=' . $big . '&tab=activity',
    'Detail proses' => '/process.php?id=' . $current,
    'Timeline project besar' => '/timeline.php?project=' . $big,
    'Gantt lintas project' => '/gantt.php',
    'Gantt per part' => '/gantt.php?level=part',
    'Tracker' => '/tracker.php',
    'Kalender' => '/calendar.php?month=2026-10',
    'Daftar NPR' => '/npr.php',
    'Detail NPR' => '/npr-edit.php?id=' . $bigNpr,
    'Approval' => '/approvals.php',
    'Dokumen' => '/documents.php',
    'Notifikasi' => '/notifications.php',
    'Laporan mingguan' => '/reports.php',
    'Analitik Jan–Okt' => '/reports.php?tab=analytics&' . $range,
    'KPI per PIC Jan–Okt' => '/reports.php?tab=kpi&' . $range,
    'Audit log' => '/settings/audit.php',
    'Pengguna' => '/settings/users.php',
];
foreach ($pages as $label => $path) {
    $warm = $req('GET', $path);
    if ($warm['status'] !== 200) {
        $fail[] = "{$label}: HTTP {$warm['status']}";
    }
    $ms = [];
    for ($i = 0; $i < $runs; $i++) {
        $ms[] = $req('GET', $path)['ms'];
    }
    $results['pages'][] = ['label' => $label, 'path' => $path, 'status' => $warm['status'], 'kb' => round($warm['bytes'] / 1024),
        'median_ms' => round($pct($ms, 0.5)), 'max_ms' => round(max($ms))];
    if (max($ms) > 2000) {
        $fail[] = "{$label}: maks " . round(max($ms)) . ' ms > 2000 ms';
    }
}

// ---------------------------------------------------------------- API (p95 ≤ 500 ms)
$apis = [
    'Pratinjau jadwal (planning)' => fn (int $i) => $req('POST', '/api/schedule-preview.php',
        ['kind' => 'plan', 'process_id' => $pending, 'plan' => ['duration' => (string) (2 + $i % 8)], '_csrf' => $csrf], ['Accept: application/json']),
    'Pratinjau jadwal (dependency)' => fn (int $i) => $req('POST', '/api/schedule-preview.php',
        ['kind' => 'dependency', 'process_id' => $pending, 'deps' => array_map(static fn ($d) => ['predecessor_id' => $d['predecessor_id'], 'type' => $d['dep_type'], 'lag' => (string) ($i % 3)], $pendingDeps), '_csrf' => $csrf], ['Accept: application/json']),
    'Simpan preferensi tema' => fn (int $i) => $req('POST', '/api/preferences.php', json_encode(['theme' => $i % 2 ? 'dark' : 'light']),
        ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $csrf]),
];
foreach ($apis as $label => $call) {
    $first = $call(0);
    if ($first['status'] !== 200) {
        $fail[] = "{$label}: HTTP {$first['status']} " . substr($first['body'], 0, 200);
    }
    $ms = [];
    for ($i = 1; $i <= $apiRuns; $i++) {
        $ms[] = $call($i)['ms'];
    }
    $results['api'][] = ['label' => $label, 'status' => $first['status'], 'n' => $apiRuns, 'p50_ms' => round($pct($ms, 0.5)), 'p95_ms' => round($pct($ms, 0.95)), 'max_ms' => round(max($ms))];
    if ($pct($ms, 0.95) > 500) {
        $fail[] = "{$label}: p95 " . round($pct($ms, 0.95)) . ' ms > 500 ms';
    }
}

// kembalikan preferensi tema user uji ke bawaan (uji API di atas mengubahnya bolak-balik)
$req('POST', '/api/preferences.php', json_encode(['theme' => 'system']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $csrf]);

// ---------------------------------------------------------------- hitung ulang jadwal (≤ 1000 ms)
$svc = new ScheduleService();
$ms = [];
for ($i = 0; $i < max(5, $runs); $i++) {
    $t = microtime(true);
    $svc->recalculate($big, 'auto_shift', null, 'uji kinerja', null, 'plan');
    $ms[] = (microtime(true) - $t) * 1000;
}
$results['recalc'] = ['project_id' => $big, 'parts' => $bigParts, 'processes' => $bigProcs, 'median_ms' => round($pct($ms, 0.5)), 'max_ms' => round(max($ms))];
if (max($ms) > 1000) {
    $fail[] = 'Hitung ulang jadwal: maks ' . round(max($ms)) . ' ms > 1000 ms';
}

// ---------------------------------------------------------------- export (≤ 15000 ms)
$exports = [
    'Timeline PDF project besar' => '/export.php?type=timeline_pdf&project=' . $big,
    'Timeline Excel project besar' => '/export.php?type=timeline_xlsx&project=' . $big,
    'NPR PDF' => '/export.php?type=npr_pdf&id=' . $bigNpr,
    'Daftar project Excel' => '/export.php?type=projects_xlsx',
    'Laporan mingguan Excel' => '/export.php?type=weekly_xlsx',
    'Analitik Excel Jan–Okt' => '/export.php?type=analytics_xlsx&' . $range,
    'KPI Excel Jan–Okt' => '/export.php?type=kpi_xlsx&' . $range,
    'KPI PDF Jan–Okt' => '/export.php?type=kpi_pdf&' . $range,
];
foreach ($exports as $label => $path) {
    $ms = [];
    $last = null;
    for ($i = 0; $i < 2; $i++) {
        $last = $req('GET', $path);
        $ms[] = $last['ms'];
    }
    $results['exports'][] = ['label' => $label, 'status' => $last['status'], 'kb' => round($last['bytes'] / 1024), 'max_ms' => round(max($ms))];
    if ($last['status'] !== 200) {
        $fail[] = "{$label}: HTTP {$last['status']}";
    }
    if (max($ms) > 15000) {
        $fail[] = "{$label}: " . round(max($ms)) . ' ms > 15000 ms';
    }
}
@unlink($jar);

// ---------------------------------------------------------------- laporan
$results['volume'] = $volume;
$results['env'] = ['php' => PHP_VERSION, 'mysql' => (string) Db::value('SELECT VERSION()'), 'server' => 'php -S (satu proses)', 'opcache' => $opcache,
    'cpu' => trim((string) @shell_exec("nproc")) . ' vCPU', 'runs' => $runs, 'api_runs' => $apiRuns, 'measured_at' => date('Y-m-d H:i')];
$results['failures'] = $fail;
if (isset($opts['out'])) {
    file_put_contents((string) $opts['out'], json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
$out = "Volume: " . json_encode($volume) . "\n\n| Halaman | HTTP | KB | Median (ms) | Maks (ms) |\n|---|---|---|---|---|\n";
foreach ($results['pages'] as $r) {
    $out .= "| {$r['label']} | {$r['status']} | {$r['kb']} | {$r['median_ms']} | {$r['max_ms']} |\n";
}
$out .= "\n| API | n | p50 (ms) | p95 (ms) | Maks (ms) |\n|---|---|---|---|---|\n";
foreach ($results['api'] as $r) {
    $out .= "| {$r['label']} | {$r['n']} | {$r['p50_ms']} | {$r['p95_ms']} | {$r['max_ms']} |\n";
}
$rc = $results['recalc'];
$out .= "\nHitung ulang jadwal project #{$rc['project_id']} ({$rc['parts']} part, {$rc['processes']} proses): median {$rc['median_ms']} ms, maks {$rc['max_ms']} ms\n";
$out .= "\n| Export | HTTP | KB | Maks (ms) |\n|---|---|---|---|\n";
foreach ($results['exports'] as $r) {
    $out .= "| {$r['label']} | {$r['status']} | {$r['kb']} | {$r['max_ms']} |\n";
}
$out .= "\n" . ($fail ? "GAGAL:\n - " . implode("\n - ", $fail) : 'Semua target terpenuhi.') . "\n";
echo $out;
exit($fail ? 1 : 0);
