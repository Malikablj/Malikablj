<?php
/** @var int $page @var int $perPage @var int $total */
$pages = max(1, (int) ceil($total / max(1, $perPage)));
if ($pages <= 1) {
    if ($total > 0) {
        echo '<p class="pagination-info">' . e((string) $total) . ' data</p>';
    }

    return;
}
$from = ($page - 1) * $perPage + 1;
$to = min($total, $page * $perPage);
?>
<nav class="pagination" aria-label="Halaman">
    <p class="pagination-info"><?= e("{$from}–{$to} dari {$total}") ?></p>
    <div class="pagination-links">
        <?php if ($page > 1): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(query_url(['page' => $page - 1])) ?>"><?= icon('arrow-left') ?> Sebelumnya</a>
        <?php endif; ?>
        <span class="pagination-current">Halaman <?= e((string) $page) ?> / <?= e((string) $pages) ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(query_url(['page' => $page + 1])) ?>">Berikutnya <?= icon('arrow-right') ?></a>
        <?php endif; ?>
    </div>
</nav>
