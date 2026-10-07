<?php
declare(strict_types=1);

/**
 * Halaman error aman (tanpa detail teknis kecuali mode development).
 * @var int $errorStatus
 * @var string $errorMessage
 */

use App\Core\Auth;
use App\Core\I18n;

$pageTitle = I18n::has('error.code_' . $errorStatus) ? I18n::t('error.code_' . $errorStatus) : I18n::t('error.title');
require __DIR__ . '/head.php';
?>
<body class="guest">
<main class="guest-main" id="main">
  <section class="guest-card error-card">
    <div class="error-code"><?= e((string) $errorStatus) ?></div>
    <h1><?= e($pageTitle) ?></h1>
    <p class="muted"><?= e($errorMessage) ?></p>
    <div class="actions">
      <?php if (Auth::check()): ?>
        <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>"><?= t('error.back_home') ?></a>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= e(url('login.php')) ?>"><?= t('auth.title') ?></a>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>
