<?php
declare(strict_types=1);

/**
 * Pusat dokumen (PRD §9.1): pencarian & filter (project, part, tipe, status, pengunggah, tanggal),
 * versi (revisi tidak menimpa), pratinjau gambar & PDF, unduhan aman lewat download.php.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\AppException;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Document\DocumentService;
use App\Document\UploadValidator;
use App\Master\MasterService;
use App\Project\ProjectQuery;

$user = require_permission('document.view');
$svc = new DocumentService();
$filters = [
    'q' => Request::query('q'),
    'project_id' => Request::int('project_id'),
    'part_id' => Request::int('part_id'),
    'doc_type' => Request::query('doc_type'),
    'status' => Request::query('status'),
    'uploader_id' => Request::int('uploader_id'),
    'from' => Request::query('from'),
    'to' => Request::query('to'),
    'removed' => Request::query('removed') === '1',
];
$qs = array_filter(array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : null) : $v, $filters), static fn ($v) => $v !== null && $v !== '');

if (Request::isPost()) {
    require_post();
    try {
        if (Request::post('action') !== 'remove') {
            Response::error(400, I18n::t('validation.invalid'));
        }
        $svc->removeDocument($user, (int) Request::int('document_id'), (string) Request::post('reason'));
        Session::flash('success', I18n::t('doc.removed_msg'));
    } catch (ValidationException $e) {
        Session::flash('error', $e->getMessage());
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
    }
    Response::redirect(url('documents.php', $qs));
}

$page = max(1, (int) Request::int('page', 1));
$result = $svc->search($user, $filters, $page, 30);
$pages = max(1, (int) ceil($result['total'] / 30));
$projects = Db::fetchAll('SELECT id, code, name FROM projects ORDER BY code DESC LIMIT 500');
$parts = $filters['project_id'] ? Db::fetchAll('SELECT id, name FROM project_parts WHERE project_id = ? ORDER BY sort_order, id', [(int) $filters['project_id']]) : [];
$uploaders = Db::fetchAll('SELECT DISTINCT u.id, u.name FROM document_versions v JOIN users u ON u.id = v.uploaded_by ORDER BY u.name');
$versionsFor = static fn (int $docId): array => $svc->versions($docId);
$fmtSize = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';

$pageTitle = I18n::t('doc.center_title');
$activeNav = 'documents';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('doc.center_title') ?></h1>
    <p><?= t('doc.center_subtitle', ['mb' => (int) (UploadValidator::maxBytes() / 1048576)]) ?></p>
  </div>
</div>

<form method="get" class="filter-bar" role="search">
  <div class="field"><label for="d-q"><?= t('common.search') ?></label><input class="input" id="d-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('doc.search_ph') ?>"></div>
  <div class="field"><label for="d-proj"><?= t('project.project') ?></label>
    <select class="input" id="d-proj" name="project_id" data-autosubmit><option value=""><?= t('common.all') ?></option>
      <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $filters['project_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
    </select></div>
  <?php if ($parts): ?>
    <div class="field"><label for="d-part"><?= t('project.parts') ?></label>
      <select class="input" id="d-part" name="part_id"><option value=""><?= t('common.all') ?></option>
        <?php foreach ($parts as $pt): ?><option value="<?= (int) $pt['id'] ?>"<?= (int) $filters['part_id'] === (int) $pt['id'] ? ' selected' : '' ?>><?= e($pt['name']) ?></option><?php endforeach; ?>
      </select></div>
  <?php endif; ?>
  <div class="field"><label for="d-type"><?= t('process.doc_type') ?></label>
    <select class="input" id="d-type" name="doc_type"><option value=""><?= t('common.all') ?></option>
      <?php foreach (MasterService::options('document_type', true) as $o): ?><option value="<?= e($o['code']) ?>"<?= $filters['doc_type'] === $o['code'] ? ' selected' : '' ?>><?= e(MasterService::label('document_type', (string) $o['code'])) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="d-status"><?= t('common.status') ?></label>
    <select class="input" id="d-status" name="status"><option value=""><?= t('common.all') ?></option>
      <?php foreach (DocumentService::VERSION_STATUSES as $st): ?><option value="<?= $st ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= t('docstatus.' . $st) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="d-up"><?= t('doc.uploader') ?></label>
    <select class="input" id="d-up" name="uploader_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($uploaders as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $filters['uploader_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="d-from"><?= t('common.from') ?></label><input class="input" type="date" id="d-from" name="from" value="<?= e($filters['from']) ?>"></div>
  <div class="field"><label for="d-to"><?= t('common.to') ?></label><input class="input" type="date" id="d-to" name="to" value="<?= e($filters['to']) ?>"></div>
  <?php if ($user->isAdmin()): ?><label class="check"><input type="checkbox" name="removed" value="1"<?= $filters['removed'] ? ' checked' : '' ?>> <span><?= t('doc.show_removed') ?></span></label><?php endif; ?>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th scope="col"><?= t('process.file') ?></th><th scope="col"><?= t('process.doc_type') ?></th><th scope="col"><?= t('project.project') ?></th>
        <th scope="col"><?= t('doc.location') ?></th><th scope="col"><?= t('project.version') ?></th><th scope="col"><?= t('common.status') ?></th>
        <th scope="col"><?= t('process.uploaded') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th>
      </tr></thead>
      <tbody>
        <?php if (!$result['rows']): ?><tr><td colspan="8" class="table-empty"><?= t('doc.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($result['rows'] as $d): ?>
          <tr>
            <td>
              <a href="<?= e(url('download.php', ['v' => $d['version_id']])) ?>"><?= icon('download', 'icon icon-sm') ?> <?= e($d['original_name']) ?></a>
              <?php if (in_array($d['extension'], array_merge(UploadValidator::IMAGE_EXT, ['pdf']), true)): ?> · <a href="<?= e(url('download.php', ['v' => $d['version_id'], 'inline' => 1])) ?>" target="_blank" rel="noopener"><?= t('doc.preview') ?></a><?php endif; ?>
              <div class="muted small"><?= e(strtoupper((string) $d['extension'])) ?> · <?= e($fmtSize((int) $d['size_bytes'])) ?><?= $d['notes'] ? ' · ' . e($d['notes']) : '' ?></div>
            </td>
            <td class="small"><?= e(MasterService::label('document_type', (string) $d['doc_type_code'])) ?></td>
            <td class="small"><a class="mono" href="<?= e(url('project.php', ['id' => $d['project_id'], 'tab' => 'documents'])) ?>"><?= e($d['project_code']) ?></a><div class="muted"><?= e($d['project_name']) ?></div></td>
            <td class="small"><?php if ($d['process_id']): ?><a href="<?= e(url('process.php', ['id' => $d['process_id']])) ?>"><?= e(($d['part_name'] ? $d['part_name'] . ' › ' : '') . $d['process_code'] . ' ' . ProjectQuery::processName(['name' => $d['process_name'], 'name_en' => $d['process_name_en']])) ?></a><?php elseif ($d['npr_id']): ?><a href="<?= e(url('npr-edit.php', ['id' => $d['npr_id']])) ?>">NPR</a><?php else: ?>–<?php endif; ?></td>
            <td class="small">
              <?php if ((int) $d['version_no'] > 1): ?>
                <details><summary class="small">v<?= (int) $d['version_no'] ?> · <?= t('doc.history') ?></summary>
                  <ul class="plain-list small">
                    <?php foreach ($versionsFor((int) $d['id']) as $v): ?>
                      <li><a href="<?= e(url('download.php', ['v' => $v['id']])) ?>">v<?= (int) $v['version_no'] ?> · <?= e($v['original_name']) ?></a> <?= status_badge((string) $v['status'], 'docstatus') ?><div class="muted"><?= fmt_datetime($v['uploaded_at']) ?> · <?= e($v['uploader_name']) ?></div></li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php else: ?>v1<?php endif; ?>
            </td>
            <td><?= status_badge((string) $d['version_status'], 'docstatus') ?><?= (int) $d['is_removed'] === 1 ? ' <span class="badge badge-neutral">' . t('doc.removed') . '</span>' : '' ?></td>
            <td class="small nowrap"><?= fmt_datetime($d['uploaded_at']) ?><div class="muted"><?= e($d['uploader_name']) ?></div></td>
            <td>
              <?php if ($user->isAdmin() && (int) $d['is_removed'] === 0): ?>
                <button type="button" class="icon-btn icon-btn-sm" data-open-dialog="dlg-remove-<?= (int) $d['id'] ?>" aria-label="<?= t('doc.remove') ?>"><?= icon('trash', 'icon icon-sm') ?></button>
                <dialog class="modal" id="dlg-remove-<?= (int) $d['id'] ?>" aria-labelledby="dlg-remove-title-<?= (int) $d['id'] ?>">
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                    <div class="modal-header"><h2 id="dlg-remove-title-<?= (int) $d['id'] ?>"><?= t('doc.remove') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
                    <div class="modal-body stack"><p class="small"><?= t('doc.remove_hint') ?></p>
                      <div class="field"><label for="rm-reason-<?= (int) $d['id'] ?>"><?= t('common.reason_required') ?></label><textarea class="input" id="rm-reason-<?= (int) $d['id'] ?>" name="reason" rows="3" required maxlength="1000"></textarea></div></div>
                    <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-danger"><?= t('doc.remove') ?></button></div>
                  </form>
                </dialog>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <span><?= t('doc.count', ['count' => $result['total']]) ?></span>
    <?php if ($pages > 1): ?>
      <span class="pagination-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('documents.php', $qs + ['page' => $page - 1])) ?>"><?= icon('chevron-left', 'icon icon-sm') ?> <?= t('common.previous') ?></a><?php endif; ?>
        <span class="muted"><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('documents.php', $qs + ['page' => $page + 1])) ?>"><?= t('common.next') ?> <?= icon('chevron-right', 'icon icon-sm') ?></a><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
