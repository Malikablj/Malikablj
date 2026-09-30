<?php
/**
 * @var array $pr @var list<array> $items @var list<array> $attachments @var bool $locked
 */
$isNew = $pr['id'] === null;
$value = static fn (string $key) => has_old() ? old($key) : ($pr[$key] ?? '');

if (has_old()) {
    $rows = is_array(old('items', [])) ? old('items', []) : [];
} else {
    $rows = [];
    foreach ($items as $index => $item) {
        $rows[$index] = [
            'item_id' => (string) ($item['item_id'] ?? ''),
            'name' => $item['item_name_snapshot'],
            'description' => $item['description'] ?? '',
            'quantity' => rtrim(rtrim((string) $item['quantity'], '0'), '.'),
            'unit' => $item['unit'],
            'unit_price' => rtrim(rtrim((string) $item['unit_price'], '0'), '.'),
        ];
    }
}
if ($rows === []) {
    $rows = [0 => ['item_id' => '', 'name' => '', 'description' => '', 'quantity' => '', 'unit' => 'pcs', 'unit_price' => '']];
}

$masterData = array_map(static fn (array $item): array => [
    'id' => (int) $item['id'],
    'code' => $item['code'],
    'name' => $item['name'],
    'unit' => $item['unit'],
    'price' => $item['default_price'],
], $masterItems);

$renderRow = static function (int|string $key, array $row, int $number): string {
    $field = static fn (string $name): string => is_scalar($row[$name] ?? null) ? (string) $row[$name] : '';
    $prefix = "items[{$key}]";
    $err = "items.{$key}";
    ob_start(); ?>
    <tr class="item-row" data-item-row>
        <td class="col-no" data-row-number><?= $number ?></td>
        <td class="col-name" data-label="Nama / deskripsi item">
            <input type="hidden" name="<?= e($prefix) ?>[item_id]" value="<?= e($field('item_id')) ?>" data-field="item_id">
            <input type="text" name="<?= e($prefix) ?>[name]" value="<?= e($field('name')) ?>" list="master-items" maxlength="150"
                   placeholder="Nama item (ketik atau pilih dari master)" aria-label="Nama item" data-field="name"<?= invalid("{$err}.name") ?>>
            <input type="text" name="<?= e($prefix) ?>[description]" value="<?= e($field('description')) ?>" maxlength="500"
                   placeholder="Keterangan (opsional)" aria-label="Keterangan item" class="input-desc" data-field="description"<?= invalid("{$err}.description") ?>>
            <?= error_for("{$err}.name") ?><?= error_for("{$err}.description") ?>
        </td>
        <td class="col-qty" data-label="Qty">
            <input type="number" name="<?= e($prefix) ?>[quantity]" value="<?= e($field('quantity')) ?>" min="0.01" step="0.01" inputmode="decimal"
                   class="num" aria-label="Quantity" data-field="quantity"<?= invalid("{$err}.quantity") ?>>
            <?= error_for("{$err}.quantity") ?>
        </td>
        <td class="col-unit" data-label="Satuan">
            <input type="text" name="<?= e($prefix) ?>[unit]" value="<?= e($field('unit') !== '' ? $field('unit') : 'pcs') ?>" maxlength="20"
                   aria-label="Satuan" data-field="unit"<?= invalid("{$err}.unit") ?>>
            <?= error_for("{$err}.unit") ?>
        </td>
        <td class="col-price" data-label="Harga satuan (Rp)">
            <input type="number" name="<?= e($prefix) ?>[unit_price]" value="<?= e($field('unit_price')) ?>" min="0" step="0.01" inputmode="decimal"
                   class="num" aria-label="Harga satuan" data-field="unit_price"<?= invalid("{$err}.unit_price") ?>>
            <?= error_for("{$err}.unit_price") ?>
        </td>
        <td class="col-total" data-label="" data-line-total>Rp0</td>
        <td class="col-remove">
            <button type="button" class="icon-button" data-remove-row aria-label="Hapus baris"><?= icon('trash') ?></button>
        </td>
    </tr>
    <?php
    return (string) ob_get_clean();
};
?>
<a class="back-link" href="<?= e(url($isNew ? '/pr' : '/pr/' . $pr['id'])) ?>"><?= icon('arrow-left') ?> <?= $isNew ? 'Purchase Requisition' : e(pr_label($pr)) ?></a>
<header class="page-header">
    <div>
        <h1><?= $isNew ? 'Buat Purchase Requisition' : e('Ubah ' . pr_label($pr)) ?></h1>
        <p class="subtitle">Isi informasi, tambahkan item, lalu simpan untuk direview sebelum diajukan.</p>
    </div>
    <?php if (!$isNew): ?><div><?= status_badge((string) $pr['status']) ?></div><?php endif; ?>
</header>

<ol class="stepper" aria-label="Tahapan pengajuan">
    <li class="is-current">Informasi</li>
    <li class="is-current">Item</li>
    <li>Review</li>
    <li>Submit</li>
</ol>

<?php if ($revisionNote !== null): ?>
    <div class="banner banner-warning" role="note">
        <?= icon('rotate') ?>
        <div>
            <strong>Revisi diminta oleh <?= e($revisionNote['approver_name']) ?> (tahap <?= e($revisionNote['step_label']) ?>)</strong>
            <?= e($revisionNote['comment']) ?>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url($isNew ? '/pr' : '/pr/' . $pr['id'])) ?>" enctype="multipart/form-data" id="pr-form" class="card" novalidate data-pr-form>
    <?= csrf_field() ?>
    <div class="card-body">
        <section class="form-section" aria-labelledby="sec-info">
            <div class="form-section-title"><span class="step-number">1</span><h2 id="sec-info">Informasi PR</h2></div>
            <div class="form-grid">
                <div class="field">
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id" data-department<?= $locked ? ' disabled' : '' ?><?= invalid('department_id') ?>>
                        <option value="">— Pilih department —</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>" data-code="<?= e($department['code']) ?>"<?= selected($value('department_id'), $department['id']) ?>><?= e($department['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if ($locked && !in_array((int) $pr['department_id'], array_map('intval', array_column($departments, 'id')), true)): ?>
                            <option value="<?= e((string) $pr['department_id']) ?>" selected><?= e($pr['department_name'] ?? '') ?></option>
                        <?php endif; ?>
                    </select>
                    <?php if ($locked): ?><p class="hint">Terkunci karena nomor PR sudah terbit.</p><?php endif; ?>
                    <?= error_for('department_id') ?>
                </div>
                <div class="field">
                    <label for="pr_number">Nomor PR</label>
                    <?php if ($locked): ?>
                        <input type="text" id="pr_number" value="<?= e($pr['pr_number']) ?>" readonly class="mono">
                    <?php else: ?>
                        <input type="text" id="pr_number" value="<?= e($numberPreview) ?>" readonly class="mono" aria-describedby="pr-number-hint"
                               data-number-preview data-prefix="<?= e($settings['pr_prefix']) ?>"
                               data-months="<?= e(json_encode(config('app.pr.months'))) ?>">
                        <p class="hint" id="pr-number-hint">Diterbitkan otomatis oleh sistem saat PR disubmit.</p>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="pr_date">Tanggal</label>
                    <input type="date" id="pr_date" name="pr_date" value="<?= e($value('pr_date')) ?>" data-pr-date<?= $locked ? ' disabled' : '' ?><?= invalid('pr_date') ?>>
                    <?= error_for('pr_date') ?>
                </div>
                <div class="field">
                    <label for="requester">Nama pemohon</label>
                    <input type="text" id="requester" value="<?= e($pr['requester_name']) ?>" readonly>
                </div>
                <div class="field span-2">
                    <label for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id"<?= invalid('supplier_id') ?>>
                        <option value="">— Pilih supplier —</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= e((string) $supplier['id']) ?>"<?= selected($value('supplier_id'), $supplier['id']) ?>>
                                <?= e($supplier['name']) ?><?= $supplier['is_active'] ? '' : ' (nonaktif)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">Boleh dikosongkan saat menyimpan draft, wajib saat submit.</p>
                    <?= error_for('supplier_id') ?>
                </div>
            </div>
        </section>

        <section class="form-section" aria-labelledby="sec-items">
            <div class="form-section-title"><span class="step-number">2</span><h2 id="sec-items">Item</h2></div>
            <?= error_for('items') ?>
            <div class="table-wrap">
                <table class="items-editor">
                    <thead>
                    <tr>
                        <th scope="col" class="col-no">No</th>
                        <th scope="col">Nama / deskripsi</th>
                        <th scope="col" class="col-qty">Qty</th>
                        <th scope="col" class="col-unit">Satuan</th>
                        <th scope="col" class="col-price">Harga satuan</th>
                        <th scope="col" class="col-total">Jumlah</th>
                        <th scope="col" class="col-remove"><span class="visually-hidden">Hapus</span></th>
                    </tr>
                    </thead>
                    <tbody data-item-rows>
                    <?php $number = 1; foreach ($rows as $key => $row): ?>
                        <?= $renderRow($key, is_array($row) ? $row : [], $number++) ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <template id="item-row-template"><?= $renderRow('__KEY__', ['unit' => 'pcs'], 0) ?></template>
            <button type="button" class="btn btn-secondary btn-sm" data-add-row><?= icon('plus') ?> Tambah item</button>

            <dl class="totals" aria-live="polite">
                <dt>Subtotal</dt>
                <dd data-subtotal><?= e(money($pr['subtotal'] ?? '0')) ?></dd>
                <dt>
                    <label class="tax-input" for="tax_rate">Pajak
                        <input type="number" id="tax_rate" name="tax_rate" value="<?= e($value('tax_rate')) ?>" min="0" max="100" step="0.01" inputmode="decimal" data-tax-rate<?= invalid('tax_rate') ?>> %
                    </label>
                </dt>
                <dd data-tax-amount><?= e(money($pr['tax_amount'] ?? '0')) ?></dd>
                <dt class="grand">Total</dt>
                <dd class="grand" data-grand-total><?= e(money($pr['grand_total'] ?? '0')) ?></dd>
            </dl>
            <?= error_for('tax_rate') ?>
            <p class="hint">Perhitungan di layar hanya pratinjau — nilai final dihitung ulang oleh server saat disimpan.</p>
        </section>

        <section class="form-section" aria-labelledby="sec-notes">
            <div class="form-section-title"><span class="step-number">3</span><h2 id="sec-notes">Catatan &amp; lampiran</h2></div>
            <div class="form-grid">
                <div class="field span-2">
                    <label for="notes">Catatan <span class="label-optional">(opsional)</span></label>
                    <textarea id="notes" name="notes" maxlength="2000" placeholder="Tujuan pembelian, urgensi, atau informasi tambahan"<?= invalid('notes') ?>><?= e($value('notes')) ?></textarea>
                    <?= error_for('notes') ?>
                </div>
                <div class="field span-2">
                    <label for="attachments">Lampiran <span class="label-optional">(opsional)</span></label>
                    <?php if ($attachments !== []): ?>
                        <ul class="file-list">
                            <?php foreach ($attachments as $attachment): ?>
                                <li>
                                    <?= icon('paperclip') ?>
                                    <span class="file-info">
                                        <a class="file-name" href="<?= e(url('/attachments/' . $attachment['id'] . '/download')) ?>"><?= e($attachment['original_name']) ?></a>
                                        <span class="file-meta"><?= e(format_bytes((int) $attachment['size_bytes'])) ?></span>
                                    </span>
                                    <button type="submit" form="delete-attachment-<?= e((string) $attachment['id']) ?>" class="icon-button" aria-label="Hapus lampiran <?= e($attachment['original_name']) ?>"><?= icon('trash') ?></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <input type="file" id="attachments" name="attachments[]" multiple
                           accept="<?= e(implode(',', array_map(static fn (string $ext): string => '.' . $ext, $upload['extensions']))) ?>"<?= invalid('attachments') ?>>
                    <p class="hint">Format: <?= e(implode(', ', $upload['extensions'])) ?>. Maksimal <?= e((string) $upload['maxMb']) ?> MB per file.</p>
                    <?= error_for('attachments') ?>
                </div>
            </div>
        </section>
    </div>

    <div class="card-footer">
        <a class="btn btn-secondary" href="<?= e(url($isNew ? '/pr' : '/pr/' . $pr['id'])) ?>">Batal</a>
        <div class="actions-row">
            <button type="submit" name="action" value="draft" class="btn btn-secondary"><?= icon('archive') ?> Simpan draft</button>
            <button type="submit" name="action" value="review" class="btn btn-primary">Simpan &amp; review <?= icon('arrow-right') ?></button>
        </div>
    </div>
</form>

<?php foreach ($attachments as $attachment): ?>
    <form method="post" action="<?= e(url('/attachments/' . $attachment['id'] . '/delete')) ?>" id="delete-attachment-<?= e((string) $attachment['id']) ?>" data-confirm="Hapus lampiran <?= e($attachment['original_name']) ?>?" class="hidden">
        <?= csrf_field() ?>
    </form>
<?php endforeach; ?>

<datalist id="master-items">
    <?php foreach ($masterItems as $item): ?>
        <option value="<?= e($item['name']) ?>"><?= e($item['code']) ?> · <?= e(money($item['default_price'])) ?>/<?= e($item['unit']) ?></option>
    <?php endforeach; ?>
</datalist>
<script type="application/json" id="master-items-data"><?= json_encode($masterData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/pr-form.js')) ?>" defer></script>
