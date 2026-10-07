<?php

use App\Helpers\Form;
use App\Models\Lead;

/** @var array<string,mixed> $filters @var array<int,string> $pics @var string $action @var bool $showStatus */
$hasFilter = $filters['q'] !== '' || $filters['pic'] > 0 || $filters['priority'] !== '' || ($showStatus && $filters['status'] !== '') || $filters['from'] !== '' || $filters['to'] !== '';
?>
<form class="filter-bar" method="get" action="<?= e(url($action)) ?>">
    <div class="filter-search"><i class="bi bi-search"></i>
        <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari lead, customer, produk, kode…" aria-label="Cari lead"></div>
    <?php if ($showStatus): ?>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="open"<?= selected('open', $filters['status']) ?>>Aktif (belum closing)</option>
            <?= Form::options(Form::list(Lead::STATUSES), $filters['status']) ?>
        </select>
    <?php endif; ?>
    <select class="form-select" name="pic" aria-label="PIC" data-autosubmit>
        <option value="">Semua PIC</option><?= Form::options($pics, (string) $filters['pic']) ?>
    </select>
    <select class="form-select" name="priority" aria-label="Prioritas" data-autosubmit>
        <option value="">Semua prioritas</option><?= Form::options(Form::list(Lead::PRIORITIES), $filters['priority']) ?>
    </select>
    <input type="date" class="form-control filter-date" name="from" value="<?= e($filters['from']) ?>" aria-label="Dibuat dari tanggal" title="Dibuat dari">
    <input type="date" class="form-control filter-date" name="to" value="<?= e($filters['to']) ?>" aria-label="Dibuat sampai tanggal" title="Dibuat sampai">
    <button class="btn btn-light" type="submit">Terapkan</button>
    <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url($action)) ?>">Reset</a><?php endif; ?>
</form>
