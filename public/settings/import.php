<?php
declare(strict_types=1);

/**
 * Impor data project lama dari Excel — hanya Admin (izin project.import).
 *   GET  ?action=template  unduh template (dropdown dari data aplikasi saat ini)
 *   POST action=analyze    unggah .xlsx → disimpan sementara di storage/imports (di luar web root) → diperiksa
 *   POST action=commit     impor file yang sudah diperiksa (diperiksa ulang di server, satu transaksi)
 *   POST action=cancel     buang file pemeriksaan
 * Tidak ada data yang disimpan sebelum commit. Aturan: docs/IMPORT_DATA_LAMA.md.
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Config;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Document\UploadValidator;
use App\Import\LegacyFormat;
use App\Import\LegacyImportService;
use App\Import\LegacyTemplate;

$actor = require_permission('project.import');
$svc = new LegacyImportService();
$dir = rtrim((string) Config::get('storage.root'), '/') . '/imports';
$fileOf = static fn (array $s): string => $dir . '/' . $s['token'] . '.xlsx';
$state = Session::get('legacy_import');
$state = is_array($state) && isset($state['token']) && preg_match('/^[a-f0-9]{32}$/', (string) $state['token']) ? $state : null;

// file pemeriksaan yang ditinggalkan > 1 hari dibersihkan
foreach (glob($dir . '/*.xlsx') ?: [] as $f) {
    if (@filemtime($f) < time() - 86400) {
        @unlink($f);
    }
}

if (Request::query('action') === 'template') {
    $content = LegacyTemplate::build();
    $name = LegacyTemplate::filename();
    AuditLogger::log('import.template', 'import', null, null, ['filename' => $name], null, null, $actor);
    session_write_close();
    header_remove('Content-Security-Policy');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $content;
    exit;
}

$topErrors = [];
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action');
    $discard = static function () use (&$state, $fileOf): void {
        if ($state !== null) {
            @unlink($fileOf($state));
        }
        Session::forget('legacy_import');
        $state = null;
    };
    try {
        switch ($action) {
            case 'analyze':
                $file = $_FILES['file'] ?? ['error' => UPLOAD_ERR_NO_FILE];
                $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
                $mb = (int) (LegacyFormat::MAX_BYTES / 1048576);
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new ValidationException(['file' => I18n::t('upload.too_large', ['mb' => $mb])]);
                }
                if ($err === UPLOAD_ERR_NO_FILE) {
                    throw new ValidationException(['file' => I18n::t('upload.none')]);
                }
                $tmp = (string) ($file['tmp_name'] ?? '');
                if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                    throw new ValidationException(['file' => I18n::t('upload.failed')]);
                }
                if ((int) filesize($tmp) > LegacyFormat::MAX_BYTES) {
                    throw new ValidationException(['file' => I18n::t('upload.too_large', ['mb' => $mb])]);
                }
                $original = UploadValidator::sanitizeName((string) ($file['name'] ?? 'import.xlsx'));
                $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
                if (UploadValidator::extension($original) !== 'xlsx' || !in_array($mime, UploadValidator::MIME_MAP['xlsx'], true)) {
                    throw new ValidationException(['file' => I18n::t('import.only_xlsx')]);
                }
                $discard();
                if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new RuntimeException('Folder impor tidak dapat dibuat: ' . $dir);
                }
                $token = bin2hex(random_bytes(16));
                $state = ['token' => $token, 'name' => $original, 'sha256' => (string) hash_file('sha256', $tmp)];
                if (!move_uploaded_file($tmp, $fileOf($state))) {
                    throw new RuntimeException('File impor tidak dapat disimpan');
                }
                @chmod($fileOf($state), 0600);
                Session::set('legacy_import', $state);
                Response::redirect(url('settings/import.php'));
                // no break
            case 'commit':
                $path = $state !== null ? $fileOf($state) : '';
                if ($state === null || !hash_equals((string) $state['token'], (string) Request::post('token', '')) || !is_file($path)
                    || !hash_equals((string) $state['sha256'], (string) hash_file('sha256', $path))) {
                    $discard();
                    Session::flash('error', I18n::t('import.expired'));
                    Response::redirect(url('settings/import.php'));
                }
                @set_time_limit(900);
                $res = $svc->commit($actor, $path, (string) $state['name']);
                $discard();
                Session::set('legacy_import_result', ['projects' => array_slice($res['projects'], 0, 300), 'total' => count($res['projects'])]);
                Session::flash('success', I18n::t('import.done', ['count' => count($res['projects'])]));
                Response::redirect(url('settings/import.php'));
                // no break
            case 'cancel':
                $discard();
                Response::redirect(url('settings/import.php'));
                // no break
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
    } catch (ValidationException $e) {
        $topErrors = array_values($e->errors());
        http_response_code(422);
    } catch (BusinessRuleException $e) {
        $topErrors = [$e->getMessage()];
        http_response_code(409);
    }
}

$analysis = null;
if ($state !== null) {
    if (is_file($fileOf($state))) {
        @set_time_limit(300);
        $analysis = $svc->analyze($fileOf($state));
    } else {
        Session::forget('legacy_import');
        $state = null;
        $topErrors[] = I18n::t('import.expired');
    }
}
$result = Session::pull('legacy_import_result');

$pageTitle = I18n::t('nav.import');
$activeNav = 'import';
require APP_ROOT . '/includes/layout/header.php';
$maxShown = 300;
?>
<div class="page-header">
  <div>
    <h1><?= t('nav.import') ?></h1>
    <p><?= t('import.subtitle') ?></p>
  </div>
</div>
<?php if ($topErrors): ?><div class="flash flash-error" role="alert"><?= icon('alert') ?><span><?= e(implode(' ', $topErrors)) ?></span></div><?php endif; ?>

<?php if (is_array($result) && !empty($result['projects'])): ?>
  <section class="card section" aria-labelledby="h-done">
    <div class="card-header"><h2 id="h-done"><?= t('import.done_title') ?></h2><span class="badge badge-success"><?= (int) $result['total'] ?></span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><?= t('import.col_ref') ?></th><th scope="col"><?= t('import.col_code') ?></th><th scope="col"><?= t('import.col_number') ?></th><th scope="col"><?= t('import.col_status') ?></th></tr></thead>
        <tbody>
          <?php foreach ($result['projects'] as $p): ?>
            <tr>
              <td><?= e($p['ref']) ?></td>
              <td class="nowrap"><a href="<?= e(url('project.php', ['id' => $p['id']])) ?>"><?= e($p['code']) ?></a></td>
              <td class="mono small nowrap"><?= e($p['npr_number']) ?></td>
              <td><?= status_badge((string) $p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($analysis !== null): ?>
  <?php $sum = $analysis['summary']; $errs = $analysis['errors']; $warns = $analysis['warnings']; ?>
  <section class="card section" aria-labelledby="h-result">
    <div class="card-header"><h2 id="h-result"><?= t('import.result_title', ['file' => $state['name']]) ?></h2></div>
    <div class="card-body stack">
      <div class="stat-grid stat-grid-compact">
        <div class="card stat"><div class="stat-label"><?= t('import.sum_projects') ?></div><div class="stat-value"><?= (int) $sum['projects'] ?></div>
          <div class="stat-sub"><?= t('import.sum_status', ['running' => $sum['status_running'], 'hold' => $sum['status_hold'], 'completed' => $sum['status_completed'], 'cancelled' => $sum['status_cancelled']]) ?></div></div>
        <div class="card stat"><div class="stat-label"><?= t('import.sum_parts') ?></div><div class="stat-value"><?= (int) $sum['parts'] ?></div></div>
        <div class="card stat"><div class="stat-label"><?= t('import.sum_completed') ?></div><div class="stat-value"><?= (int) $sum['processes_completed'] ?></div></div>
        <div class="card stat"><div class="stat-label"><?= t('import.sum_current') ?></div><div class="stat-value"><?= (int) $sum['processes_current'] ?></div></div>
        <div class="card stat"><div class="stat-label"><?= t('import.sum_skipped') ?></div><div class="stat-value"><?= (int) $sum['processes_skipped'] ?></div></div>
      </div>
      <?php if ($errs): ?>
        <div class="alert alert-danger" role="alert"><?= icon('alert') ?><div><?= t('import.errors_found', ['count' => count($errs)]) ?><?php if (count($errs) > $maxShown): ?> <?= t('import.errors_more', ['shown' => $maxShown, 'total' => count($errs)]) ?><?php endif; ?></div></div>
        <div class="table-wrap">
          <table class="table" data-import-errors>
            <thead><tr><th scope="col"><?= t('import.col_sheet') ?></th><th scope="col" class="right"><?= t('import.col_row') ?></th><th scope="col"><?= t('import.col_column') ?></th><th scope="col"><?= t('import.col_message') ?></th></tr></thead>
            <tbody>
              <?php foreach (array_slice($errs, 0, $maxShown) as $er): ?>
                <tr class="row-danger"><td class="nowrap"><?= e($er['sheet'] !== '' ? $er['sheet'] : '–') ?></td><td class="right mono"><?= $er['row'] > 0 ? (int) $er['row'] : '–' ?></td><td class="small"><?= e($er['column'] !== '' ? $er['column'] : '–') ?></td><td><?= e($er['message']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="alert alert-info" role="status"><?= icon('check-circle') ?><div><?= t('import.no_errors') ?></div></div>
      <?php endif; ?>
      <?php if ($warns): ?>
        <details<?= $errs ? '' : ' open' ?>>
          <summary class="small"><?= t('import.warnings', ['count' => count($warns)]) ?></summary>
          <div class="table-wrap">
            <table class="table">
              <thead><tr><th scope="col"><?= t('import.col_sheet') ?></th><th scope="col" class="right"><?= t('import.col_row') ?></th><th scope="col"><?= t('import.col_column') ?></th><th scope="col"><?= t('import.col_message') ?></th></tr></thead>
              <tbody>
                <?php foreach (array_slice($warns, 0, $maxShown) as $w): ?>
                  <tr><td class="nowrap"><?= e($w['sheet']) ?></td><td class="right mono"><?= $w['row'] > 0 ? (int) $w['row'] : '–' ?></td><td class="small"><?= e($w['column']) ?></td><td><?= e($w['message']) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
      <?php if (!$errs && $analysis['plan']): ?>
        <h3 class="section-title"><?= t('import.preview') ?></h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th scope="col"><?= t('import.col_ref') ?></th><th scope="col"><?= t('import.col_name') ?></th><th scope="col"><?= t('import.col_customer') ?></th><th scope="col"><?= t('import.col_status') ?></th><th scope="col" class="right"><?= t('import.col_parts') ?></th><th scope="col"><?= t('import.col_number') ?></th></tr></thead>
            <tbody>
              <?php foreach (array_slice($analysis['plan'], 0, $maxShown) as $p): ?>
                <tr>
                  <td class="nowrap"><?= e($p['ref']) ?></td>
                  <td><?= e($p['name']) ?></td>
                  <td class="small"><?= e((string) ($p['customer']['code'] ?? '')) ?> · <?= e((string) ($p['customer']['name'] ?? '')) ?></td>
                  <td><span class="badge badge-neutral"><?= t('import.status.' . $p['status']) ?></span></td>
                  <td class="right"><?= count($p['parts']) ?></td>
                  <td class="mono small"><?= e($p['npr_number'] ?? t('import.auto_number')) ?> / <?= e($p['code'] ?? t('import.auto_number')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="form-actions">
        <form method="post" class="inline-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn"><?= t('import.cancel') ?></button>
        </form>
        <?php if (!$errs && $analysis['plan']): ?>
          <form method="post" class="inline-form" data-confirm="<?= t('import.commit_confirm', ['count' => count($analysis['plan'])]) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="commit"><input type="hidden" name="token" value="<?= e($state['token']) ?>">
            <button type="submit" class="btn btn-primary"><?= icon('upload') ?> <?= t('import.commit', ['count' => count($analysis['plan'])]) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<div class="grid grid-2 section">
  <div class="stack">
    <section class="card" aria-labelledby="h-step1">
      <div class="card-header"><h2 id="h-step1"><?= t('import.step1') ?></h2></div>
      <div class="card-body stack">
        <p class="small muted"><?= t('import.step1_body') ?></p>
        <div><a class="btn" href="<?= e(url('settings/import.php', ['action' => 'template'])) ?>"><?= icon('download') ?> <?= t('import.download') ?></a></div>
      </div>
    </section>
    <section class="card" aria-labelledby="h-step2">
      <div class="card-header"><h2 id="h-step2"><?= t('import.step2') ?></h2></div>
      <div class="card-body"><p class="small muted"><?= t('import.step2_body') ?></p></div>
    </section>
    <section class="card" aria-labelledby="h-step3">
      <div class="card-header"><h2 id="h-step3"><?= t('import.step3') ?></h2></div>
      <form method="post" enctype="multipart/form-data" class="card-body form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="analyze">
        <div class="field span-2"><label for="imp-file"><?= t('import.file', ['mb' => (int) (LegacyFormat::MAX_BYTES / 1048576)]) ?></label>
          <input class="input" type="file" id="imp-file" name="file" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" data-max-bytes="<?= (int) LegacyFormat::MAX_BYTES ?>" data-max-message="<?= e(I18n::t('upload.file_too_large', ['name' => ':name', 'mb' => (int) (LegacyFormat::MAX_BYTES / 1048576)])) ?>"></div>
        <div class="span-2 form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= t('import.check') ?></button></div>
      </form>
    </section>
  </div>
  <section class="card" aria-labelledby="h-rules">
    <div class="card-header"><h2 id="h-rules"><?= t('import.rules_title') ?></h2></div>
    <div class="card-body">
      <ul class="plain-list small stack">
        <li><?= t('import.rule_atomic') ?></li>
        <li><?= t('import.rule_kpi') ?></li>
        <li><?= t('import.rule_schedule') ?></li>
        <li><?= t('import.rule_notif') ?></li>
        <li><?= t('import.rule_docs') ?></li>
      </ul>
    </div>
  </section>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
