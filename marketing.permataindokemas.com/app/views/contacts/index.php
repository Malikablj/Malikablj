<?php

use App\Helpers\Form;
use App\Models\Contact;

/** @var App\Helpers\Paginator $contacts @var array<string,mixed> $filters */
$hasFilter = $filters['q'] !== '' || $filters['customer_id'] > 0 || $filters['status'] !== '';
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Customer &amp; CRM</div>
        <h1 class="page-title">Contacts</h1>
        <p class="page-subtitle">Semua orang yang dapat dihubungi di setiap customer.</p>
    </div>
    <?php if (can('contacts.create')): ?>
        <div class="page-actions"><a href="<?= e(url('/contacts/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah Kontak</a></div>
    <?php endif; ?>
</div>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/contacts')) ?>">
        <div class="filter-search"><i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Cari nama, jabatan, telepon, email, customer…" aria-label="Cari kontak"></div>
        <select class="form-select" name="customer_id" aria-label="Customer" data-autosubmit>
            <option value="">Semua customer</option><?= Form::options($customers, (string) $filters['customer_id']) ?>
        </select>
        <select class="form-select" name="status" aria-label="Status" data-autosubmit>
            <option value="">Semua status</option><option value="Active"<?= selected('Active', $filters['status']) ?>>Aktif</option><option value="Inactive"<?= selected('Inactive', $filters['status']) ?>>Nonaktif</option>
        </select>
        <button class="btn btn-light" type="submit">Cari</button>
        <?php if ($hasFilter): ?><a class="btn btn-link-plain small" href="<?= e(url('/contacts')) ?>">Reset</a><?php endif; ?>
    </form>
    <?php if ($contacts->isEmpty()): ?>
        <div class="empty-state"><i class="bi bi-person-lines-fill"></i><div class="empty-title"><?= $hasFilter ? 'Tidak ada kontak yang cocok' : 'Belum ada kontak' ?></div>
            <p>Kontak dapat ditambahkan dari sini atau dari halaman detail customer.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Nama</th><th>Customer</th><th class="d-none d-md-table-cell">Jabatan</th><th>Telepon / WA</th><th class="d-none d-lg-table-cell">Email</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($contacts->items as $ct): ?>
                    <tr class="<?= $ct['status'] === 'Inactive' ? 'row-muted' : '' ?>">
                        <td><div class="cell-title"><?= e($ct['name']) ?> <?= (int) $ct['is_primary'] === 1 ? '<span class="badge-soft badge-soft-accent no-dot">Utama</span>' : '' ?></div>
                            <?php if ($ct['status'] === 'Inactive'): ?><div class="cell-sub">Nonaktif</div><?php endif; ?></td>
                        <td><a href="<?= e(url('/customers/' . $ct['customer_id'])) ?>"><?= e($ct['customer_name']) ?></a></td>
                        <td class="d-none d-md-table-cell"><?= e($ct['position'] ?: '—') ?></td>
                        <td class="small"><div><?= e($ct['phone'] ?: '—') ?></div>
                            <?php if ($wa = Contact::waLink($ct['whatsapp'] ?: $ct['phone'])): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp"></i> WhatsApp</a><?php endif; ?></td>
                        <td class="d-none d-lg-table-cell small"><?= $ct['email'] ? '<a href="mailto:' . e($ct['email']) . '">' . e($ct['email']) . '</a>' : '—' ?></td>
                        <td class="col-actions"><?php if (can('contacts.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/contacts/' . $ct['id'] . '/edit', ['return' => '/contacts'])) ?>">Edit</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $contacts->footer('kontak') ?>
    <?php endif; ?>
</div>
