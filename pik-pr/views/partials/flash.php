<?php
$messages = [
    'success' => [flash('success'), 'check-circle'],
    'error' => [flash('error'), 'alert'],
    'info' => [flash('info'), 'info'],
];
foreach ($messages as $type => [$message, $iconName]):
    if ($message === null || $message === '') {
        continue;
    }
?>
    <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= icon($iconName) ?>
        <p><?= e($message) ?></p>
        <button type="button" class="alert-close" data-dismiss aria-label="Tutup pesan"><?= icon('x') ?></button>
    </div>
<?php endforeach; ?>
