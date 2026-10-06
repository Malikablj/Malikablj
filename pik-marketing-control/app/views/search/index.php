<?php /** @var string $q @var array<string,list<array{url:string,title:string,sub:string,badge:?string,icon:string}>> $sections */ ?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Pencarian</div>
        <h1 class="page-title"><?= $q !== '' ? 'Hasil untuk “' . e($q) . '”' : 'Cari data' ?></h1>
        <p class="page-subtitle">Mencari customer, kontak, lead, OEF, delivery, produk, dan inbound sesuai hak akses Anda.</p>
    </div>
</div>

<form class="surface surface-pad section-gap" method="get" action="<?= e(url('/search')) ?>" role="search">
    <div class="d-flex gap-2">
        <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Ketik minimal 2 karakter…" aria-label="Kata kunci" autofocus maxlength="100">
        <button class="btn btn-primary" type="submit">Cari</button>
    </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
    <div class="callout">Ketik minimal 2 karakter.</div>
<?php elseif ($q !== '' && !$sections): ?>
    <div class="surface"><div class="empty-state"><i class="bi bi-search"></i><div class="empty-title">Tidak ditemukan</div><p>Coba kata kunci lain, misalnya nomor PO, nomor surat jalan, atau nama customer.</p></div></div>
<?php endif; ?>

<div class="row g-4">
    <?php foreach ($sections as $label => $items): ?>
        <div class="col-lg-6">
            <section class="surface">
                <div class="surface-header"><h2 class="surface-title"><?= e($label) ?></h2><span class="tab-count"><?= count($items) ?></span></div>
                <ul class="list-lite">
                    <?php foreach ($items as $it): ?>
                        <li>
                            <span class="kpi-icon"><i class="bi <?= e($it['icon']) ?>"></i></span>
                            <div class="li-main">
                                <a class="li-title" href="<?= e(to($it['url'])) ?>"><?= e($it['title']) ?></a>
                                <div class="li-sub"><?= e($it['sub']) ?></div>
                            </div>
                            <?php if ($it['badge']): ?><div class="li-end"><?= status_badge($it['badge']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    <?php endforeach; ?>
</div>
