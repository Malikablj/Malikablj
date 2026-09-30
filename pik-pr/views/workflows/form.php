<?php
$isNew = $workflow['id'] === null;
$value = static fn (string $key) => has_old() ? old($key) : ($workflow[$key] ?? '');
$stepRows = has_old() ? array_values((array) old('steps', [])) : $steps;
if ($stepRows === []) {
    $stepRows = [['label' => '', 'approver_type' => 'role', 'approver_user_id' => null, 'approver_role' => 'approver', 'same_department' => 1, 'is_required' => 1, 'min_amount' => null]];
}
$active = has_old() ? old('is_active') === '1' : (bool) $workflow['is_active'];

$renderStep = static function (int|string $index, array $step) use ($approverUsers, $approverRoles, $used): string {
    ob_start();
    $prefix = "steps[{$index}]";
    $key = "steps.{$index}";
    $type = (string) ($step['approver_type'] ?? 'role');
    $required = (string) ($step['is_required'] ?? '1') !== '0';
    ?>
    <div class="step-card" data-step>
        <div class="step-card-header">
            <strong>Tahap <span data-step-number><?= is_int($index) ? $index + 1 : '' ?></span></strong>
            <?php if (!$used): ?>
                <button type="button" class="icon-button" data-remove-step aria-label="Hapus tahap"><?= icon('trash') ?></button>
            <?php endif; ?>
        </div>
        <div class="step-grid">
            <div class="field">
                <label>Label tahap</label>
                <input type="text" name="<?= e($prefix) ?>[label]" value="<?= e($step['label'] ?? '') ?>" maxlength="50" placeholder="mis. Diketahui" data-name="label"<?= invalid("{$key}.label") ?>>
                <?= error_for("{$key}.label") ?>
            </div>
            <div class="field">
                <label>Jenis approver</label>
                <select name="<?= e($prefix) ?>[approver_type]" data-approver-type data-name="approver_type"<?= invalid("{$key}.approver_type") ?>>
                    <option value="role"<?= selected($type, 'role') ?>>Berdasarkan role</option>
                    <option value="user"<?= selected($type, 'user') ?>>User tertentu</option>
                </select>
                <?= error_for("{$key}.approver_type") ?>
            </div>
            <div class="field<?= $type === 'user' ? '' : ' hidden' ?>" data-when="user">
                <label>User approver</label>
                <select name="<?= e($prefix) ?>[approver_user_id]" data-name="approver_user_id"<?= invalid("{$key}.approver_user_id") ?>>
                    <option value="">— Pilih user —</option>
                    <?php foreach ($approverUsers as $candidate): ?>
                        <option value="<?= e((string) $candidate['id']) ?>"<?= selected($step['approver_user_id'] ?? '', $candidate['id']) ?>>
                            <?= e($candidate['name']) ?> — <?= e(role_label((string) $candidate['role'])) ?><?= $candidate['department_name'] ? ', ' . e($candidate['department_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= error_for("{$key}.approver_user_id") ?>
            </div>
            <div class="field<?= $type === 'role' ? '' : ' hidden' ?>" data-when="role">
                <label>Role approver</label>
                <select name="<?= e($prefix) ?>[approver_role]" data-name="approver_role"<?= invalid("{$key}.approver_role") ?>>
                    <?php foreach ($approverRoles as $roleValue => $roleLabel): ?>
                        <option value="<?= e($roleValue) ?>"<?= selected($step['approver_role'] ?? 'approver', $roleValue) ?>><?= e($roleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="checkbox small">
                    <input type="checkbox" name="<?= e($prefix) ?>[same_department]" value="1" data-name="same_department"<?= checked((string) ($step['same_department'] ?? '0') === '1') ?>>
                    Hanya dari department PR
                </label>
                <?= error_for("{$key}.approver_role") ?>
            </div>
            <div class="field">
                <label>Keberlakuan</label>
                <select name="<?= e($prefix) ?>[is_required]" data-required-select data-name="is_required"<?= invalid("{$key}.is_required") ?>>
                    <option value="1"<?= selected($required ? '1' : '0', '1') ?>>Selalu (wajib)</option>
                    <option value="0"<?= selected($required ? '1' : '0', '0') ?>>Kondisional (nilai PR)</option>
                </select>
                <?= error_for("{$key}.is_required") ?>
            </div>
            <div class="field<?= $required ? ' hidden' : '' ?>" data-when-conditional>
                <label>Berlaku jika total ≥ (Rp)</label>
                <input type="number" name="<?= e($prefix) ?>[min_amount]" value="<?= e($step['min_amount'] ?? '') ?>" min="0" step="0.01" class="num" data-name="min_amount"<?= invalid("{$key}.min_amount") ?>>
                <?= error_for("{$key}.min_amount") ?>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<a class="back-link" href="<?= e(url('/approval-workflows')) ?>"><?= icon('arrow-left') ?> Approval Workflow</a>
<header class="page-header">
    <div>
        <h1><?= e($title) ?></h1>
        <p class="subtitle">Approver ditentukan oleh aturan (user tertentu atau role), bukan nama yang ditulis di kode.</p>
    </div>
</header>

<?php if ($used): ?>
    <div class="banner banner-warning">
        <?= icon('info') ?>
        <div>
            <strong>Workflow ini sudah dipakai PR.</strong>
            Jumlah dan urutan tahap dikunci agar riwayat approval tetap konsisten. Label dan approver tetap dapat diubah
            (mis. mengganti approver yang sedang cuti). Untuk struktur baru, buat workflow baru lalu nonaktifkan yang ini.
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isNew ? '/approval-workflows' : '/approval-workflows/' . $workflow['id'])) ?>" class="stack-lg" novalidate>
    <?= csrf_field() ?>
    <section class="card">
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label for="name">Nama workflow</label>
                    <input type="text" id="name" name="name" value="<?= e($value('name')) ?>" maxlength="100" required<?= invalid('name') ?>>
                    <?= error_for('name') ?>
                </div>
                <div class="field">
                    <label for="department_id">Berlaku untuk department</label>
                    <select id="department_id" name="department_id"<?= invalid('department_id') ?>>
                        <option value="">Default (semua department tanpa workflow khusus)</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>"<?= selected($value('department_id'), $department['id']) ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= error_for('department_id') ?>
                </div>
                <div class="field span-2">
                    <label for="description">Deskripsi <span class="label-optional">(opsional)</span></label>
                    <input type="text" id="description" name="description" value="<?= e($value('description')) ?>" maxlength="255"<?= invalid('description') ?>>
                    <?= error_for('description') ?>
                </div>
                <div class="field span-2">
                    <input type="hidden" name="is_active" value="0">
                    <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= checked($active) ?>> Workflow aktif</label>
                    <p class="hint">Hanya satu workflow aktif per department (dan satu workflow default).</p>
                    <?= error_for('is_active') ?>
                </div>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2>Tahap approval</h2>
                <p>Dijalankan berurutan dari atas. Satu user tidak dapat menyetujui dua tahap pada PR yang sama.</p>
            </div>
        </div>
        <div class="card-body">
            <?= error_for('steps') ?>
            <div data-steps>
                <?php foreach ($stepRows as $index => $step): ?>
                    <?= $renderStep((int) $index, (array) $step) ?>
                <?php endforeach; ?>
            </div>
            <?php if (!$used): ?>
                <template id="step-template"><?= $renderStep('__INDEX__', ['label' => '', 'approver_type' => 'role', 'approver_role' => 'approver', 'same_department' => 1, 'is_required' => 1]) ?></template>
                <button type="button" class="btn btn-secondary btn-sm" data-add-step><?= icon('plus') ?> Tambah tahap</button>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <span class="muted small">Perubahan dicatat pada audit trail.</span>
            <div class="actions-row">
                <a class="btn btn-secondary" href="<?= e(url('/approval-workflows')) ?>">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan workflow</button>
            </div>
        </div>
    </section>
</form>
<script src="<?= e(asset('js/workflow-form.js')) ?>" defer></script>
