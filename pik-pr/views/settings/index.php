<?php $user = auth_user(); ?>
<header class="page-header">
    <div>
        <p class="eyebrow">Sistem</p>
        <h1>Pengaturan</h1>
    </div>
</header>

<div class="grid-2">
    <section class="card">
        <div class="card-header">
            <div>
                <h2>Akun saya</h2>
                <p><?= e($user['name']) ?> · <?= e($user['email']) ?> · <?= e(role_label((string) $user['role'])) ?></p>
            </div>
        </div>
        <form method="post" action="<?= e(url('/settings/password')) ?>" class="card-body stack" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="current_password">Password saat ini</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" maxlength="72"<?= invalid('current_password') ?>>
                <?= error_for('current_password') ?>
            </div>
            <div class="field">
                <label for="password">Password baru</label>
                <input type="password" id="password" name="password" autocomplete="new-password" maxlength="72"<?= invalid('password') ?>>
                <p class="hint">Minimal 8 karakter, mengandung huruf dan angka.</p>
                <?= error_for('password') ?>
            </div>
            <div class="field">
                <label for="password_confirmation">Ulangi password baru</label>
                <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" maxlength="72"<?= invalid('password_confirmation') ?>>
                <?= error_for('password_confirmation') ?>
            </div>
            <div class="actions-row"><button type="submit" class="btn btn-primary">Ubah password</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2>Sistem</h2>
                <p><?= $canManageSystem ? 'Hanya dapat diubah oleh Super Admin.' : 'Hanya Super Admin yang dapat mengubah pengaturan ini.' ?></p>
            </div>
        </div>
        <form method="post" action="<?= e(url('/settings/system')) ?>" class="card-body stack" novalidate>
            <?= csrf_field() ?>
            <?php $disabled = $canManageSystem ? '' : ' disabled'; ?>
            <div class="field">
                <label for="company_name">Nama perusahaan (header PDF)</label>
                <input type="text" id="company_name" name="company_name" value="<?= e(has_old() ? old('company_name') : $settings['company_name']) ?>" maxlength="150"<?= $disabled ?><?= invalid('company_name') ?>>
                <?= error_for('company_name') ?>
            </div>
            <div class="field">
                <label for="company_address">Alamat perusahaan <span class="label-optional">(opsional)</span></label>
                <textarea id="company_address" name="company_address" maxlength="500"<?= $disabled ?><?= invalid('company_address') ?>><?= e(has_old() ? old('company_address') : $settings['company_address']) ?></textarea>
                <?= error_for('company_address') ?>
            </div>
            <div class="field">
                <label for="pr_prefix">Prefix nomor PR</label>
                <input type="text" id="pr_prefix" name="pr_prefix" value="<?= e(has_old() ? old('pr_prefix') : $settings['pr_prefix']) ?>" maxlength="20" class="mono"<?= $disabled ?><?= invalid('pr_prefix') ?>>
                <p class="hint">Contoh hasil: <?= e($settings['pr_prefix']) ?>/SEPT/2026-PDPR077</p>
                <?= error_for('pr_prefix') ?>
            </div>
            <div class="field">
                <label for="default_tax_rate">Pajak default PR baru (%)</label>
                <input type="number" id="default_tax_rate" name="default_tax_rate" value="<?= e(has_old() ? old('default_tax_rate') : $settings['default_tax_rate']) ?>" min="0" max="100" step="0.01" class="num"<?= $disabled ?><?= invalid('default_tax_rate') ?>>
                <?= error_for('default_tax_rate') ?>
            </div>
            <?php if ($canManageSystem): ?>
                <div class="actions-row"><button type="submit" class="btn btn-primary">Simpan pengaturan</button></div>
            <?php endif; ?>
        </form>
    </section>
</div>
