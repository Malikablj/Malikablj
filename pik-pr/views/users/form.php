<?php
$isNew = $record['id'] === null;
$value = static fn (string $key) => has_old() ? old($key) : ($record[$key] ?? '');
?>
<a class="back-link" href="<?= e(url('/users')) ?>"><?= icon('arrow-left') ?> User</a>
<header class="page-header">
    <div>
        <h1><?= e($title) ?></h1>
        <?php if (!$isNew): ?><p class="subtitle"><?= e($record['email']) ?></p><?php endif; ?>
    </div>
</header>

<form method="post" action="<?= e(url($isNew ? '/users' : '/users/' . $record['id'])) ?>" class="card" novalidate autocomplete="off">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid">
            <div class="field">
                <label for="name">Nama lengkap</label>
                <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" maxlength="100" required<?= invalid('name') ?>>
                <?= error_for('name') ?>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($value('email')) ?>" maxlength="190" required<?= invalid('email') ?>>
                <?= error_for('email') ?>
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role"<?= invalid('role') ?><?= $isSelf ? ' aria-describedby="role-hint"' : '' ?>>
                    <?php foreach ($roles as $roleValue => $roleLabel): ?>
                        <option value="<?= e($roleValue) ?>"<?= selected($value('role'), $roleValue) ?>><?= e($roleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($isSelf): ?><p class="hint" id="role-hint">Anda tidak dapat mengubah role akun sendiri.</p><?php endif; ?>
                <?= error_for('role') ?>
            </div>
            <div class="field">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id"<?= invalid('department_id') ?>>
                    <option value="">— Tidak ada —</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"<?= selected($value('department_id'), $department['id']) ?>>
                            <?= e($department['name']) ?><?= $department['is_active'] ? '' : ' (nonaktif)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="hint">Wajib untuk Requester dan Approver.</p>
                <?= error_for('department_id') ?>
            </div>
            <div class="field">
                <label for="job_title">Jabatan <span class="label-optional">(opsional)</span></label>
                <input type="text" id="job_title" name="job_title" value="<?= e($value('job_title')) ?>" maxlength="100"<?= invalid('job_title') ?>>
                <p class="hint">Ditampilkan pada kolom tanda tangan PDF.</p>
                <?= error_for('job_title') ?>
            </div>
            <div class="field">
                <label for="password"><?= $isNew ? 'Password' : 'Password baru' ?><?php if (!$isNew): ?> <span class="label-optional">(kosongkan bila tidak diubah)</span><?php endif; ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password" maxlength="72"<?= invalid('password') ?>>
                <p class="hint">Minimal 8 karakter, mengandung huruf dan angka.</p>
                <?= error_for('password') ?>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <span class="muted small"><?= $isNew ? 'User baru langsung aktif.' : 'Perubahan dicatat pada audit trail.' ?></span>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= e(url('/users')) ?>">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </div>
</form>
