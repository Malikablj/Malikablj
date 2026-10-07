<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\I18n;

$user = require_permission('report.view');

$pageTitle = I18n::t('dashboard.title');
$activeNav = 'dashboard';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header">
  <div>
    <h1><?= t('dashboard.greeting', ['name' => $user->name]) ?></h1>
    <p><?= t('dashboard.role_info', ['role' => I18n::t('role.' . $user->roleCode)]) ?></p>
  </div>
</div>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
