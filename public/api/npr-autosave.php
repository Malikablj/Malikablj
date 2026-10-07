<?php
declare(strict_types=1);

/**
 * Autosave draft NPR (kolom biru) dan draft feedback NPD (kolom pink).
 * POST multipart/form-data dari form NPR. Respons JSON: {ok, lock_version}.
 * Hak akses & validasi format sama dengan simpan biasa (service), tanpa entri audit per ketikan.
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Npr\NprFeedbackService;
use App\Npr\NprService;

$user = require_login();
require_post();

$id = (int) Request::int('npr_id', 0);
$svc = new NprService();
$npr = $svc->find($id);
$svc->assertView($user, $npr);
$lock = Request::int('lock_version');

$blue = array_intersect_key($_POST, ['npr' => 1, 'parts' => 1]);
$pink = isset($_POST['feedback']) && is_array($_POST['feedback']) ? $_POST['feedback'] : null;
if (!$blue && $pink === null) {
    Response::json(['ok' => true, 'lock_version' => (int) $npr['lock_version']]);
}
if ($blue) {
    $lock = $svc->saveSales($user, $id, $blue, $lock, null, true);
}
if ($pink !== null) {
    $lock = (new NprFeedbackService($svc))->save($user, $id, $pink, $blue ? null : $lock, null, true);
}
Response::json(['ok' => true, 'lock_version' => $lock, 'message' => I18n::t('common.saved')]);
