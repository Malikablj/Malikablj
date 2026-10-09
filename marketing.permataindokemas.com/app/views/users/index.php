<?php

use App\Helpers\Form;
use App\Helpers\Permission;

/** @var App\Helpers\Paginator $users @var array<string,string> $blocked email => blokir berakhir @var string $returnPath */
$selfId = App\Helpers\Auth::id();
?>
<div class="page-header">
    <div>
        <div class="page-eyebrow">Settings</div>
        <h1 class="page-title">Users</h1>
        <p class="page-subtitle">Kelola akun, role akses, blokir login, dan hapus user.</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(url('/users/create')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Tambah User</a>
    </div>
</div>

<?php if ($blocked !== [] && $status !== 'blocked'): ?>
    <a class="callout callout-warning section-gap d-flex align-items-center gap-2 text-decoration-none" href="<?= e(url('/users', ['status' => 'blocked'])) ?>">
        <i class="bi bi-shield-lock"></i>
        <span><strong><?= count($blocked) ?> user</strong> sedang terblokir karena salah password berulang kali — klik untuk melihat dan membuka blokir.</span>
    </a>
<?php endif; ?>

<div class="surface">
    <form class="filter-bar" method="get" action="<?= e(url('/users')) ?>">
        <div class="filter-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Cari nama atau email…" aria-label="Cari user">
        </div>
        <select class="form-select" name="role" aria-label="Filter role" data-autosubmit>
            <option value="">Semua role</option>
            <?= Form::options(Form::list(Permission::ROLES), $role) ?>
        </select>
        <select class="form-select" name="status" aria-label="Filter status" data-autosubmit>
            <option value="">Semua status</option>
            <option value="active"<?= selected('active', $status) ?>>Aktif</option>
            <option value="inactive"<?= selected('inactive', $status) ?>>Nonaktif</option>
            <option value="blocked"<?= selected('blocked', $status) ?>>Terblokir<?= $blocked !== [] ? ' (' . count($blocked) . ')' : '' ?></option>
        </select>
        <button class="btn btn-light" type="submit">Terapkan</button>
    </form>

    <?php if ($users->isEmpty()): ?>
        <?php if ($status === 'blocked'): ?>
            <div class="empty-state"><i class="bi bi-shield-check"></i><div class="empty-title">Tidak ada user yang terblokir</div><p>Blokir login terjadi otomatis bila password salah berulang kali, dan berakhir sendiri setelah beberapa menit.</p></div>
        <?php else: ?>
            <div class="empty-state"><i class="bi bi-people"></i><div class="empty-title">Tidak ada user</div><p>Coba ubah filter pencarian.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table-pik">
                <thead>
                <tr>
                    <th>Nama</th>
                    <th class="d-none d-sm-table-cell">Role</th>
                    <th>Status</th>
                    <th class="d-none d-md-table-cell">Login terakhir</th>
                    <th class="col-actions"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users->items as $u): $blockedUntil = $blocked[$u['email']] ?? null; ?>
                    <tr class="<?= (int) $u['is_active'] === 1 ? '' : 'row-muted' ?>">
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <span class="avatar avatar-sm"><?= e(initials($u['name'])) ?></span>
                                <div>
                                    <div class="cell-title"><?= e($u['name']) ?></div>
                                    <div class="cell-sub"><?= e($u['email']) ?></div>
                                    <div class="cell-sub d-sm-none"><?= e($u['role']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="d-none d-sm-table-cell"><span class="chip"><?= e($u['role']) ?></span></td>
                        <td>
                            <?= (int) $u['is_active'] === 1 ? status_badge('Active', 'Aktif') : status_badge('Inactive', 'Nonaktif') ?>
                            <?php if ($blockedUntil !== null): ?><span class="badge-soft badge-soft-danger no-dot ms-1" title="Terbuka otomatis pukul <?= e(date('H:i', (int) strtotime($blockedUntil))) ?>"><i class="bi bi-shield-lock"></i> Terblokir s/d <?= e(date('H:i', (int) strtotime($blockedUntil))) ?></span><?php endif; ?>
                            <?php if ((int) $u['must_change_password'] === 1): ?><span class="badge-soft badge-soft-warning no-dot ms-1">Wajib ganti password</span><?php endif; ?>
                        </td>
                        <td class="d-none d-md-table-cell text-secondary"><?= e(fmt_datetime($u['last_login_at'], 'Belum pernah')) ?></td>
                        <td class="col-actions">
                            <div class="d-inline-flex gap-1">
                                <?php if ($blockedUntil !== null): ?>
                                    <form method="post" action="<?= e(url('/users/' . $u['id'] . '/unblock')) ?>" data-confirm="Buka blokir login <?= e($u['name']) ?> sekarang?">
                                        <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnPath) ?>">
                                        <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-unlock"></i> Buka blokir</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-light btn-sm" href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>">Edit</a>
                                <?php if ((int) $u['id'] !== $selfId): ?>
                                    <form method="post" action="<?= e(url('/users/' . $u['id'] . '/delete')) ?>" data-confirm="Hapus user <?= e($u['name']) ?> (<?= e($u['email']) ?>) secara permanen? Data yang pernah dibuatnya tetap ada, tetapi user ini tidak bisa login lagi dan dilepas dari customer/lead/order yang ditanganinya.">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-light btn-sm text-danger" type="submit" aria-label="Hapus user <?= e($u['name']) ?>" title="Hapus user"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $users->footer('user') ?>
    <?php endif; ?>
</div>

<div class="surface surface-pad mt-4">
    <h2 class="surface-title mb-2">Hak akses per role</h2>
    <p class="text-secondary small mb-3">Diatur di <span class="code-chip">config/permissions.php</span> dan diverifikasi di backend untuk setiap halaman &amp; aksi.</p>
    <div class="row g-3 small">
        <div class="col-md-6 col-xl-4"><strong>Admin</strong><div class="text-secondary">Akses penuh ke semua modul, user, audit log, import &amp; migration issues.</div></div>
        <div class="col-md-6 col-xl-4"><strong>Marketing</strong><div class="text-secondary">Customers, Contacts, Leads, Activities, Follow Up, Order Entry Form, Complaint &amp; Return, Lead Time, Products. Deliveries hanya lihat.</div></div>
        <div class="col-md-6 col-xl-4"><strong>Sales</strong><div class="text-secondary">Customers, Contacts, Leads, Activities, Follow Up, input Order Entry Form &amp; complaint.</div></div>
        <div class="col-md-6 col-xl-4"><strong>PPIC</strong><div class="text-secondary">Meninjau Order Entry Form (Bisa / Tidak bisa diproses) dan mengisi Surat Jalan di menu Deliveries.</div></div>
        <div class="col-md-6 col-xl-4"><strong>Produksi</strong><div class="text-secondary">Mengisi Stock (nama produk diketik manual, otomatis dikelompokkan per produk), Inbound Maklon, dan Inbound Supplier — termasuk tim gudang.</div></div>
        <div class="col-md-6 col-xl-4"><strong>Management</strong><div class="text-secondary">Dashboard, Reports + export, Customers, Order Entry Form, Complaint &amp; Return, Lead Time; Deliveries, Stock &amp; Inbound hanya lihat.</div></div>
        <div class="col-md-6 col-xl-4"><strong>Viewer</strong><div class="text-secondary">Read-only untuk modul operasional &amp; laporan, tanpa export.</div></div>
    </div>
</div>
