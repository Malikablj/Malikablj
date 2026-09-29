<?php
/** @var int $status @var string $heading @var string $message @var string|null $trace @var bool $loggedIn */
$icon = match ($status) {
    403 => 'bi-shield-lock',
    404 => 'bi-compass',
    419 => 'bi-hourglass-bottom',
    503 => 'bi-database-exclamation',
    default => 'bi-exclamation-octagon',
};
?>
<section class="auth-card text-center">
    <div class="error-icon"><i class="bi <?= e($icon) ?>"></i></div>
    <div class="error-code"><?= (int) $status ?></div>
    <h1 class="auth-title"><?= e($heading) ?></h1>
    <p class="text-secondary mb-4"><?= e($message) ?></p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
        <?php if ($loggedIn): ?>
            <a href="<?= e(url('/')) ?>" class="btn btn-primary">Kembali ke Dashboard</a>
        <?php else: ?>
            <a href="<?= e(url('/login')) ?>" class="btn btn-primary">Ke halaman login</a>
        <?php endif; ?>
        <button type="button" class="btn btn-light" data-history-back>Kembali</button>
    </div>
    <?php if (!empty($trace)): ?>
        <pre class="error-trace text-start mt-4"><?= e($trace) ?></pre>
    <?php endif; ?>
</section>
