<?php
declare(strict_types=1);

/**
 * Antrean email (Admin): status, percobaan, error terakhir; kirim ulang / batalkan (PRD §7.3),
 * ditambah status tugas terjadwal (cron & backup) dari job_runs sebagai pemantauan dasar (NFR-11).
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Db;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Cron\JobStatus;
use App\Notification\MailQueue;

$user = require_permission('settings.manage');
$queue = new MailQueue();
$status = in_array(Request::query('status'), ['pending', 'sending', 'sent', 'failed', 'cancelled'], true) ? (string) Request::query('status') : null;
if (Request::isPost()) {
    require_post();
    $id = (int) Request::int('id');
    match ((string) Request::post('action')) {
        'retry' => $queue->retry($user, $id),
        'cancel' => $queue->cancel($user, $id),
        default => Response::error(400, I18n::t('validation.invalid')),
    };
    Session::flash('success', I18n::t('common.saved'));
    Response::redirect(url('settings/email-queue.php', array_filter(['status' => $status])));
}
$page = max(1, (int) Request::int('page', 1));
$where = $status ? 'WHERE d.status = ?' : '';
$params = $status ? [$status] : [];
$total = (int) Db::value("SELECT COUNT(*) FROM notification_deliveries d $where", $params);
$rows = Db::fetchAll("SELECT d.id, d.to_email, d.subject, d.status, d.attempts, d.max_attempts, d.last_error, d.next_attempt_at, d.sent_at, d.created_at, u.name AS user_name
    FROM notification_deliveries d LEFT JOIN users u ON u.id = d.user_id $where ORDER BY d.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params);
$pages = max(1, (int) ceil($total / 50));
$counts = $queue->counts();
$lastRun = Db::fetch("SELECT * FROM job_runs WHERE job = 'notifications' ORDER BY id DESC LIMIT 1");
$jobs = (new JobStatus())->all();
$tone = static fn (string $v): string => match ($v) { 'ok', 'success' => 'success', 'warning', 'failed' => 'danger', 'running' => 'accent', default => 'neutral' };

$pageTitle = I18n::t('nav.email_queue');
$activeNav = 'email_queue';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header"><div><h1><?= t('nav.email_queue') ?></h1>
  <p><?= t('equeue.subtitle') ?> <?= $lastRun ? t('equeue.last_run', ['date' => I18n::dateTime($lastRun['started_at']), 'status' => $lastRun['status']]) : t('equeue.never_run') ?></p></div></div>
<nav class="tabs" aria-label="<?= t('common.status') ?>">
  <a href="<?= e(url('settings/email-queue.php')) ?>"<?= $status === null ? ' class="active"' : '' ?>><?= t('common.all') ?></a>
  <?php foreach ($counts as $st => $n): ?><a href="<?= e(url('settings/email-queue.php', ['status' => $st])) ?>"<?= $status === $st ? ' class="active" aria-current="page"' : '' ?>><?= t('equeue.' . $st) ?> <span class="badge badge-neutral"><?= (int) $n ?></span></a><?php endforeach; ?>
</nav>
<div class="stack">
<div class="card"><div class="table-wrap"><table class="table">
  <thead><tr><th scope="col"><?= t('common.date') ?></th><th scope="col"><?= t('equeue.to') ?></th><th scope="col"><?= t('equeue.subject') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('equeue.attempts') ?></th><th scope="col"><?= t('equeue.error') ?></th><th scope="col"><span class="visually-hidden"><?= t('common.actions') ?></span></th></tr></thead>
  <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="table-empty"><?= t('common.empty') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="small nowrap"><?= fmt_datetime($r['created_at']) ?><?= $r['sent_at'] ? '<div class="muted">' . t('equeue.sent_at') . ' ' . fmt_datetime($r['sent_at']) . '</div>' : '' ?></td>
        <td class="small"><?= e($r['user_name'] ?? '') ?><div class="muted"><?= e($r['to_email']) ?></div></td>
        <td class="small"><?= e($r['subject']) ?></td>
        <td><?= status_badge((string) $r['status'], 'equeue') ?><?= $r['status'] === 'pending' && (int) $r['attempts'] > 0 ? '<div class="muted small">' . t('equeue.next_attempt') . ' ' . fmt_datetime($r['next_attempt_at']) . '</div>' : '' ?></td>
        <td class="small"><?= (int) $r['attempts'] ?>/<?= (int) $r['max_attempts'] ?></td>
        <td class="small"><?= e((string) $r['last_error']) ?></td>
        <td class="nowrap">
          <?php if (in_array($r['status'], ['failed', 'cancelled'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm" type="submit"><?= t('equeue.retry') ?></button></form><?php endif; ?>
          <?php if ($r['status'] === 'pending'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= t('common.cancel') ?></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><div class="pagination"><span><?= t('common.page_of', ['page' => $page, 'pages' => $pages]) ?></span><span class="pagination-links">
    <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(url('settings/email-queue.php', array_filter(['status' => $status, 'page' => $page - 1]))) ?>"><?= t('common.previous') ?></a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(url('settings/email-queue.php', array_filter(['status' => $status, 'page' => $page + 1]))) ?>"><?= t('common.next') ?></a><?php endif; ?>
  </span></div><?php endif; ?>
</div>

<section class="card" id="jobs" aria-labelledby="jobs-title">
  <div class="card-header"><div><h2 id="jobs-title"><?= t('jobs.title') ?></h2><p class="muted small"><?= t('jobs.subtitle') ?></p></div></div>
  <div class="table-wrap"><table class="table table-cards" data-jobs>
    <thead><tr><th scope="col"><?= t('jobs.job') ?></th><th scope="col"><?= t('common.status') ?></th><th scope="col"><?= t('jobs.last_run') ?></th><th scope="col"><?= t('jobs.last_success') ?></th><th scope="col"><?= t('jobs.message') ?></th></tr></thead>
    <tbody>
      <?php foreach ($jobs as $j): $last = $j['last']; ?>
        <tr data-job="<?= e($j['job']) ?>" data-state="<?= e($j['state']) ?>">
          <td><strong><?= t('jobs.name.' . $j['job']) ?></strong><div class="muted small mono"><?= e($j['job']) ?></div></td>
          <td><span class="badge badge-<?= $tone($j['state']) ?>"><?= t('jobs.state.' . $j['state']) ?></span></td>
          <td class="small nowrap"><?php if ($last): ?><?= fmt_datetime($last['started_at']) ?> <span class="badge badge-<?= $tone((string) $last['status']) ?>"><?= t('jobs.status.' . $last['status']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="small nowrap"><?= $j['last_success_at'] ? fmt_datetime($j['last_success_at']) : '—' ?></td>
          <td class="small"><?= $last ? e(mb_strimwidth((string) $last['message'], 0, 300, '…')) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
