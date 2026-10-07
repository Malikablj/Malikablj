<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Npr\NprService;

$user = require_permission('project.view');
$service = new NprService();

if (Request::isPost()) {
    require_post();
    if (Request::post('action') === 'create') {
        try {
            $id = $service->createDraft($user, Request::int('sales_pic_id'));
            Session::flash('success', I18n::t('npr.created'));
            Response::redirect(url('npr-edit.php', ['id' => $id]));
        } catch (ValidationException $e) {
            Session::flash('error', implode(' ', $e->errors()));
            Response::redirect('npr.php');
        }
    }
    Response::error(400, I18n::t('validation.invalid'));
}

$filters = [
    'status' => Request::query('status'),
    'customer_id' => Request::int('customer_id'),
    'mine' => Request::query('mine') === '1',
    'q' => Request::query('q'),
];
$rows = $service->list($user, $filters);
$customers = Db::fetchAll('SELECT id, name FROM customers ORDER BY name');
$canCreate = Gate::can($user, 'npr.create');
$needsSalesPic = $canCreate && $user->roleCode !== 'admin_sales';
$salesUsers = $needsSalesPic ? $service->salesUsers() : [];

$pageTitle = I18n::t('npr.list_title');
$activeNav = 'npr';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('npr.list_title') ?></h1>
    <p><?= t('npr.list_subtitle') ?></p>
  </div>
  <?php if ($canCreate): ?>
    <div class="page-actions">
      <?php if ($needsSalesPic): ?>
        <button type="button" class="btn btn-primary" data-open-dialog="dlg-new-npr"><?= icon('plus') ?> <?= t('npr.new') ?></button>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
          <button type="submit" class="btn btn-primary"><?= icon('plus') ?> <?= t('npr.new') ?></button></form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<form method="get" class="filter-bar" role="search">
  <div class="field">
    <label for="f-q"><?= t('common.search') ?></label>
    <input class="input" id="f-q" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= t('npr.number') ?> / <?= t('npr.product') ?>">
  </div>
  <div class="field">
    <label for="f-status"><?= t('common.status') ?></label>
    <select class="input" id="f-status" name="status">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach (['draft', 'submitted', 'returned', 'feedback_completed'] as $s): ?>
        <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= t('npr_status.' . $s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="f-customer"><?= t('npr.customer') ?></label>
    <select class="input" id="f-customer" name="customer_id">
      <option value=""><?= t('common.all') ?></option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= e((string) $c['id']) ?>"<?= $filters['customer_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <label class="check"><input type="checkbox" name="mine" value="1"<?= $filters['mine'] ? ' checked' : '' ?>> <span><?= t('npr.filter_mine') ?></span></label>
  <div class="filter-actions">
    <button type="submit" class="btn"><?= icon('filter') ?> <?= t('common.filter') ?></button>
    <a class="btn btn-ghost" href="<?= e(url('npr.php')) ?>"><?= t('common.reset') ?></a>
  </div>
</form>

<div class="card">
  <div class="table-wrap">
    <table class="table table-cards">
      <thead>
        <tr>
          <th><?= t('npr.number') ?></th>
          <th><?= t('npr.product') ?></th>
          <th><?= t('npr.customer') ?></th>
          <th><?= t('npr.sales_pic') ?></th>
          <th class="right"><?= t('npr.parts_count') ?></th>
          <th><?= t('common.status') ?></th>
          <th><?= t('npr.submitted_at') ?></th>
          <th><?= t('npr.project_code') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td data-label="<?= t('npr.number') ?>" class="nowrap"><a href="<?= e(url('npr-edit.php', ['id' => $r['id']])) ?>"><strong><?= $r['npr_number'] ? e($r['npr_number']) : '<span class="muted">' . t('npr_status.draft') . ' #' . e((string) $r['id']) . '</span>' ?></strong></a></td>
            <td data-label="<?= t('npr.product') ?>"><a href="<?= e(url('npr-edit.php', ['id' => $r['id']])) ?>"><?= e($r['product_name'] !== '' ? $r['product_name'] : '—') ?></a></td>
            <td data-label="<?= t('npr.customer') ?>"><?= e($r['customer_name'] ?? '—') ?></td>
            <td data-label="<?= t('npr.sales_pic') ?>"><?= e($r['sales_pic_name']) ?></td>
            <td data-label="<?= t('npr.parts_count') ?>" class="right"><?= e((string) $r['part_count']) ?></td>
            <td data-label="<?= t('common.status') ?>"><?= status_badge($r['status'], 'npr_status') ?></td>
            <td data-label="<?= t('npr.submitted_at') ?>" class="nowrap"><?= $r['requested_at'] ? fmt_date($r['requested_at']) : '—' ?></td>
            <td data-label="<?= t('npr.project_code') ?>" class="nowrap"><?php if (!empty($r['project_id'])): ?><a href="<?= e(url('project.php', ['id' => $r['project_id']])) ?>"><?= e($r['project_code']) ?></a><?php else: ?>—<?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination"><span><?= t('npr.count', ['count' => count($rows)]) ?></span></div>
</div>

<?php if ($needsSalesPic): ?>
  <dialog class="modal" id="dlg-new-npr" aria-labelledby="dlg-new-npr-title">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="modal-header">
        <h2 id="dlg-new-npr-title"><?= t('npr.create_title') ?></h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button>
      </div>
      <div class="modal-body">
        <div class="field">
          <label for="new-sales"><?= t('npr.create_for_sales') ?></label>
          <select class="input" id="new-sales" name="sales_pic_id" required>
            <option value=""><?= t('npr.choose') ?></option>
            <?php foreach ($salesUsers as $s): ?><option value="<?= e((string) $s['id']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
          </select>
          <p class="field-hint"><?= t('npr.create_for_sales_hint') ?></p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button>
        <button type="submit" class="btn btn-primary"><?= t('npr.new') ?></button>
      </div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
