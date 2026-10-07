<?php

use App\Models\Contact;

/** @var array<string,mixed> $customer @var array<string,mixed> $data @var string $base */
$ret = $base . '?tab=contacts';
?>
<section class="surface">
    <div class="surface-header">
        <div><h2 class="surface-title">Kontak customer</h2><p class="surface-subtitle">Orang yang dapat dihubungi di <?= e($customer['name']) ?></p></div>
        <?php if (can('contacts.create')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/contacts/create', ['customer_id' => $customer['id'], 'return' => $ret])) ?>"><i class="bi bi-plus-lg"></i> Tambah kontak</a>
        <?php endif; ?>
    </div>
    <?php if (!$data['contacts']): ?>
        <div class="empty-state"><i class="bi bi-person-lines-fill"></i><div class="empty-title">Belum ada kontak</div><p>Simpan nama, jabatan, telepon, dan WhatsApp orang yang Anda hubungi.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead><tr><th>Nama</th><th class="d-none d-md-table-cell">Jabatan</th><th>Telepon / WhatsApp</th><th class="d-none d-lg-table-cell">Email</th><th>Status</th><th class="col-actions"></th></tr></thead>
                <tbody>
                <?php foreach ($data['contacts'] as $ct): ?>
                    <tr class="<?= $ct['status'] === 'Inactive' ? 'row-muted' : '' ?>">
                        <td>
                            <div class="cell-title"><?= e($ct['name']) ?> <?= (int) $ct['is_primary'] === 1 ? '<span class="badge-soft badge-soft-accent no-dot">Utama</span>' : '' ?></div>
                            <div class="cell-sub"><span class="code-chip"><?= e($ct['code']) ?></span></div>
                        </td>
                        <td class="d-none d-md-table-cell"><?= e($ct['position'] ?: '—') ?></td>
                        <td class="small">
                            <div><?= e($ct['phone'] ?: '—') ?></div>
                            <?php $waNumber = $ct['whatsapp'] ?: $ct['phone']; if ($wa = Contact::waLink($waNumber)): ?>
                                <a href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp"></i> <?= e($ct['whatsapp'] ?: 'WhatsApp') ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="d-none d-lg-table-cell small"><?= $ct['email'] ? '<a href="mailto:' . e($ct['email']) . '">' . e($ct['email']) . '</a>' : '—' ?></td>
                        <td><?= status_badge($ct['status'], $ct['status'] === 'Active' ? 'Aktif' : 'Nonaktif') ?></td>
                        <td class="col-actions">
                            <?php if (can('contacts.edit')): ?><a class="btn btn-light btn-sm" href="<?= e(url('/contacts/' . $ct['id'] . '/edit', ['return' => $ret])) ?>">Edit</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
