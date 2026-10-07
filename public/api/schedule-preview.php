<?php
declare(strict_types=1);

/**
 * Pratinjau jadwal (tanpa menyimpan) untuk perubahan planning atau dependency (PRD §5.5, §6.1).
 * POST: kind=plan|dependency, process_id, plan[...] / deps[...]. Respons JSON: perubahan + perkiraan project.
 * Lingkaran dependency → 422 dengan pesan yang aman ditampilkan.
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Project\ProjectQuery;
use App\Workflow\DependencyService;
use App\Workflow\WorkflowEngine;

$user = require_login();
require_post();

$kind = (string) Request::post('kind');
$processId = (int) Request::int('process_id', 0);
if ($kind === 'plan') {
    $preview = (new WorkflowEngine())->previewPlan($user, $processId, is_array($_POST['plan'] ?? null) ? $_POST['plan'] : []);
} elseif ($kind === 'dependency') {
    $preview = (new DependencyService())->preview($user, $processId, is_array($_POST['deps'] ?? null) ? array_values($_POST['deps']) : []);
} else {
    Response::error(400, I18n::t('validation.invalid'));
}

$names = [];
foreach ($preview['changes'] as $c) {
    $names[] = $c['process_id'];
}
$parts = [];
if ($names) {
    foreach (\App\Core\Db::fetchAll('SELECT pr.id, pr.name, pr.name_en, pp.name AS part_name FROM processes pr LEFT JOIN project_parts pp ON pp.id = pr.part_id WHERE pr.id IN ' . \App\Core\Db::in($names), $names) as $r) {
        $parts[(int) $r['id']] = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . ProjectQuery::processName($r);
    }
}
$fmt = static fn (?string $d): string => $d ? I18n::date($d) : '–';
$target = $preview['target_finish'];
Response::json([
    'ok' => true,
    'changes' => array_map(static fn ($c) => [
        'process' => $c['code'] . ' ' . ($parts[$c['process_id']] ?? $c['name']),
        'old' => $fmt($c['old_start']) . ' – ' . $fmt($c['old_finish']),
        'new' => $fmt($c['new_start']) . ' – ' . $fmt($c['new_finish']),
        'shift' => $c['shift'] === null ? '' : sprintf('%+d', $c['shift']),
    ], $preview['changes']),
    'forecast_old' => $fmt($preview['project_forecast_old']),
    'forecast_new' => $fmt($preview['project_forecast_new']),
    'target' => $fmt($target),
    'past_target' => $target !== null && $preview['project_forecast_new'] !== null && $preview['project_forecast_new'] > $target,
]);
