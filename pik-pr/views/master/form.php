<?php
/** @var array $config @var array $record */
$base = $config['baseUrl'];
$isNew = $record['id'] === null;
$action = $isNew ? url($base) : url($base . '/' . $record['id']);
?>
<a class="back-link" href="<?= e(url($base)) ?>"><?= icon('arrow-left') ?> <?= e($config['title']) ?></a>
<header class="page-header">
    <div>
        <h1><?= e($title) ?></h1>
        <?php if (!$isNew): ?><p class="subtitle">Perubahan dicatat pada audit trail.</p><?php endif; ?>
    </div>
</header>

<form method="post" action="<?= e($action) ?>" class="card" novalidate>
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="form-grid">
            <?php foreach ($config['fields'] as $name => $field):
                $value = has_old() ? old($name) : ($record[$name] ?? '');
                $id = 'f-' . $name;
                $required = $field['required'] ?? false;
                $wide = $field['type'] === 'textarea';
            ?>
                <div class="field<?= $wide ? ' span-2' : '' ?>">
                    <label for="<?= e($id) ?>"><?= e($field['label']) ?><?php if (!$required): ?> <span class="label-optional">(opsional)</span><?php endif; ?></label>
                    <?php if ($field['type'] === 'textarea'): ?>
                        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" maxlength="<?= e((string) ($field['max'] ?? 1000)) ?>"<?= invalid($name) ?>><?= e($value) ?></textarea>
                    <?php elseif ($field['type'] === 'money'): ?>
                        <input type="number" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" min="0" step="0.01" inputmode="decimal" class="num"<?= invalid($name) ?>>
                    <?php else: ?>
                        <input type="text" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
                               maxlength="<?= e((string) ($field['max'] ?? 255)) ?>"<?= $required ? ' required' : '' ?><?= $field['type'] === 'code' ? ' class="mono" autocapitalize="characters"' : '' ?><?= invalid($name) ?>>
                    <?php endif; ?>
                    <?php if (isset($field['hint'])): ?><p class="hint"><?= e($field['hint']) ?></p><?php endif; ?>
                    <?= error_for($name) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer">
        <?php if (!$isNew): ?>
            <span class="badge <?= $record['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><span class="badge-dot"></span><?= $record['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <div class="actions-row">
            <a class="btn btn-secondary" href="<?= e(url($base)) ?>">Batal</a>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </div>
</form>
