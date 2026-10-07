<?php
declare(strict_types=1);

/**
 * Approval (PRD §9.2): antrean (Pending) & riwayat dengan filter. Keputusan dicatat dari halaman proses
 * (terhubung ke workflow & loop); role tanpa hak keputusan hanya melihat.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Approval\ApprovalService;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Project\ProjectQuery;

$user = require_permission('project.view');
$svc = new ApprovalService();
$view = Request::query('view') === 'history' ? 'history' : 'queue';
$filters = [
    'status' => $view === 'queue' ? 'pending' : Request::query('status'),
    'type' => Request::query('type'),
    'giver' => Request::query('giver'),
    'project_id' => Request::int('project_id'),
    'from' => Request::query('from'),
    'to' => Request::query('to'),
    'q' => Request::query('q'),
    'mine' => Request::query('mine') === '1',
];
$page = max(1, (int) Request::int('page', 1));
$result = $svc->search($user, $filters, $page, 30);
$pages = max(1, (int) ceil($result['total'] / 30));
$projects = Db::fetchAll('SELECT id, code, name FROM projects ORDER BY code DESC LIMIT 500');
$qs = array_filter(['view' => $view] + array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : null) : $v, array_diff_key($filters, $view === 'queue' ? ['status' => 1] : [])), static fn ($v) => $v !== null && $v !== '');
$pendingTotal = $svc->pendingCount();

$pageTitle = I18n::t('approval.title');
$activeNav = 'approvals';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('approval.title') ?></h1>
    <p><?= t('approval.subtitle') ?></p>
  </div>
</div>
<nav class="tabs" aria-label="<?= t('approval.title') ?>">
  <a href="<?= e(url('approvals.php')) ?>"<?= $view === 'queue' ? ' class="active" aria-current="page"' : '' ?>><?= t('approval.queue') ?> <span class="badge badge-warning"><?= (int) $pendingTotal ?></span></a>
  <a href="<?= e(url('approvals.php', ['view' => 'history'])) ?>"<?= $view === 'history' ? ' class="active" aria-current="page"' : '' ?>><?= t('approval.history') ?></a>
</nav>

<form method="get" class="filter-bar" role="search">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="field"><label for="a-q"><?= t('common.search') ?></label><input class="input" id="a-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('approval.search_ph') ?>"></div>
  <div class="field"><label for="a-type"><?= t('approval.type') ?></label>
    <select class="input" id="a-type" name="type"><option value=""><?= t('common.all') ?></option>
      <?php foreach (ApprovalService::TYPES as $tp): ?><option value="<?= e($tp) ?>"<?= $filters['type'] === $tp ? ' selected' : '' ?>><?= t('approval.type.' . $tp) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="a-giver"><?= t('approval.giver') ?></label>
    <select class="input" id="a-giver" name="giver"><option value=""><?= t('common.all') ?></option>
      <?php foreach (['customer', 'internal'] as $g): ?><option value="<?= $g ?>"<?= $filters['giver'] === $g ? ' selected' : '' ?>><?= t('approval.giver.' . $g) ?></option><?php endforeach; ?>
    </select></div>
  <?php if ($view === 'history'): ?>
    <div class="field"><label for="a-status"><?= t('common.status') ?></label>
      <select class="input" id="a-status" name="status"><option value=""><?= t('common.all') ?></option>
        <?php foreach (ApprovalService::STATUSES as $st): ?><option value="<?= $st ?>"<?= $filters['status'] === $st ? ' selected' : '' ?>><?= t('approval.' . $st) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="a-from"><?= t('common.from') ?></label><input class="input" type="date" id="a-from" name="from" value="<?= e($filters['from']) ?>"></div>
    <div class="field"><label for="a-to"><?= t('common.to') ?></label><input class="input" type="date" id="a-to" name="to" value="<?= e($filters['to']) ?>"></div>
  <?php endif; ?>
  <div class="field"><label for="a-proj"><?= t('project.project') ?></label>
    <select class="input" id="a-proj" name="project_id"><option value=""><?= t('common.all') ?></option>
      <?php foreach ($projects as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $filters['project_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
    </select></div>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> <span><?= t('project.mine') ?></span></label>
  <div class="field"><button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button></div>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th scope="col"><?= t('approval.code') ?></th><th scope="col"><?= t('approval.type') ?></th><th scope="col"><?= t('project.project') ?> / <?= t('process.process') ?></th>
        <th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('approval.document') ?></th><th scope="col"><?= t('approval.requested') ?></th>
        <th scope="col"><?= t('approval.decided') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th>
      </tr></thead>
      <tbody>
        <?php if (!$result['rows']): ?><tr><td colspan="8" class="table-empty"><?= $view === 'queue' ? t('approval.queue_empty') : t('common.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($result['rows'] as $a): ?>
          <tr>
            <td class="mono small"><?= e($a['code']) ?><div class="muted"><?= t('process.iteration_n', ['n' => (int) $a['iteration']]) ?></div></td>
            <td class="small"><?= t('approval.type.' . $a['approval_type']) ?><div class="muted"><?= t('approval.giver.' . $a['giver']) ?></div></td>
            <td class="small"><a class="mono" href="<?= e(url('project.php', ['id' => $a['project_id'], 'tab' => 'approvals'])) ?>"><?= e($a['project_code']) ?></a> · <?= e($a['project_name']) ?>
              <?php if ($a['process_id']): ?><div><a href="<?= e(url('process.php', ['id' => $a['process_id']])) ?>"><?= e(($a['part_name'] ? $a['part_name'] . ' › ' : '') . $a['process_code'] . ' ' . ProjectQuery::processName(['name' => $a['process_name'], 'name_en' => $a['process_name_en']])) ?></a></div><?php endif; ?></td>
            <td><?= status_badge((string) $a['status'], 'approval') ?></td>
            <td class="small">
              <?php if ($a['doc_version_id']): ?><a href="<?= e(url('download.php', ['v' => $a['doc_version_id']])) ?>"><?= e($a['doc_name']) ?></a> <span class="muted">v<?= (int) $a['doc_version'] ?></span><?php else: ?>–<?php endif; ?>
              <?php if ($a['evidence_version_id']): ?><div><?= t('approval.evidence') ?>: <a href="<?= e(url('download.php', ['v' => $a['evidence_version_id']])) ?>"><?= e($a['evidence_name']) ?></a></div><?php endif; ?>
            </td>
            <td class="small nowrap"><?= fmt_datetime($a['requested_at']) ?><div class="muted"><?= e($a['requested_by_name'] ?? I18n::t('approval.system')) ?></div></td>
            <td class="small"><?php if ($a['decided_at']): ?><span class="nowrap"><?= fmt_datetime($a['decided_at']) ?></span><div class="muted"><?= e($a['decided_by_name']) ?><?= $a['decision_maker_name'] ? ' · ' . e($a['decision_maker_name']) : '' ?></div><?= $a['comment'] ? '<div>' . e($a['comment']) . '</div>' : '' ?><?php else: ?>–<?php endif; ?></td>
            <td><?php if ($a['can_decide'] && $a['process_id'] && in_array($a['process_status'], ['current', 'revision', 'problem'], true)): ?><a class="btn btn-sm btn-primary" href="<?= e(url('process.php', ['id' => $a['process_id']])) ?>#sec-complete"><?= t('approval.decide') ?></a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <span><?= t('approval.count', ['count' => $result['total']]) ?></span>
    <?php if ($pages > 1): ?>
      <span class="pagination-links">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('approvals.php', $qs + ['page' => $page - 1])) ?>"><?= icon('chevron-left', 'icon icon-sm') ?> <?= t('common.previous') ?></a><?php endif; ?>
        <span class="muted"><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('approvals.php', $qs + ['page' => $page + 1])) ?>"><?= t('common.next') ?> <?= icon('chevron-right', 'icon icon-sm') ?></a><?php endif; ?>
      </span>
    <?php endif; ?>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
