<?php
declare(strict_types=1);

/**
 * Form NPR digital (PIK-FORM-NPD-01 rev 00). Biru = Sales, pink = Admin NPD.
 * Semua aksi divalidasi & diotorisasi di service (NprService / NprFeedbackService).
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/npr_form.php';

use App\Core\AppException;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\User;
use App\Core\ValidationException;
use App\Document\DocumentService;
use App\Document\UploadValidator;
use App\Master\MasterService;
use App\Npr\NprFeedbackService;
use App\Npr\NprFields;
use App\Npr\NprService;
use App\Project\RevisionHistory;

$user = require_permission('project.view');
$svc = new NprService();
$fbSvc = new NprFeedbackService($svc);
$id = (int) Request::int('id', 0);
$svc->assertView($user, $svc->find($id));

/** Simpan isian form utama (biru & pink yang terkirim). Mengembalikan lock_version terbaru. */
$persist = static function (User $user, array $post, ?int $lock, ?string $reason) use ($svc, $fbSvc, $id): ?int {
    $blue = array_intersect_key($post, ['npr' => 1, 'parts' => 1]);
    if ($blue) {
        $lock = $svc->saveSales($user, $id, $blue, $lock, $reason);
    }
    if (isset($post['feedback']) && is_array($post['feedback'])) {
        $lock = $fbSvc->save($user, $id, $post['feedback'], $blue ? null : $lock, $reason);
    }
    return $lock;
};

$errors = [];
$override = null;
$failedAction = null;
if (Request::isPost()) {
    require_post();
    $action = (string) Request::post('action', 'save');
    $lock = Request::int('lock_version');
    $reason = Request::post('reason');
    [$verb, $arg] = array_pad(explode(':', $action, 2), 2, null);
    $redirect = url('npr-edit.php', ['id' => $id]);
    try {
        switch ($verb) {
            case 'save':
            case 'submit':
            case 'complete':
            case 'add_part':
            case 'remove_part':
            case 'remove_attachment':
            case 'upload':
                $persist($user, $_POST, $lock, $reason);
                $docs = new DocumentService();
                foreach (DocumentService::NPR_CATEGORIES as $cat) {
                    $f = $_FILES['attachment_' . $cat] ?? null;
                    if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $docs->addNprAttachment($user, $id, $cat, $f);
                        Session::flash('success', I18n::t('npr.attachment_added'));
                    }
                }
                if ($verb === 'submit') {
                    $r = $svc->submit($user, $id);
                    Session::flash('success', I18n::t($r['resubmitted'] ? 'npr.resubmitted_msg' : 'npr.submitted_msg', ['number' => $r['npr_number'], 'project' => $r['project_code']]));
                } elseif ($verb === 'complete') {
                    $r = $fbSvc->complete($user, $id);
                    Session::flash($r['result'] === 'returned' ? 'warning' : 'success', $r['result'] === 'returned'
                        ? I18n::t('npr.completed_returned_msg')
                        : I18n::t('npr.completed_msg', ['accepted' => count($r['accepted']), 'cancelled' => count($r['cancelled'])]));
                } elseif ($verb === 'add_part') {
                    $pid = $svc->addPart($user, $id);
                    Session::flash('success', I18n::t('npr.part_added'));
                    $redirect .= '#part-' . $pid;
                } elseif ($verb === 'remove_part') {
                    $svc->removePart($user, $id, (int) $arg, $reason);
                    Session::flash('success', I18n::t('npr.part_removed'));
                } elseif ($verb === 'remove_attachment') {
                    $docs->removeNprAttachment($user, $id, (int) $arg);
                    Session::flash('success', I18n::t('npr.attachment_removed'));
                } elseif ($verb === 'save') {
                    Session::flash('success', isset($_POST['feedback']) && !isset($_POST['npr']) ? I18n::t('npr.feedback_saved') : I18n::t('npr.saved'));
                }
                break;
            case 'return':
                $fbSvc->returnToSales($user, $id, (string) $reason, $lock);
                Session::flash('success', I18n::t('npr.returned_msg'));
                break;
            case 'discard':
                $svc->discard($user, $id);
                Session::flash('success', I18n::t('npr.discarded_msg'));
                $redirect = url('npr.php');
                break;
            case 'cancel_part':
                $svc->cancelPart($user, $id, (int) Request::int('part_id'), (string) $reason);
                Session::flash('success', I18n::t('npr.part_cancelled_msg'));
                break;
            case 'add_part_npd':
                $pid = $svc->addPart($user, $id, is_array($_POST['new_part'] ?? null) ? $_POST['new_part'] : [], $reason);
                Session::flash('success', I18n::t('npr.part_added'));
                $redirect .= '#part-' . $pid;
                break;
            default:
                Response::error(400, I18n::t('validation.invalid'));
        }
        Response::redirect($redirect);
    } catch (ValidationException $e) {
        // Render ulang (tanpa redirect) agar isian tidak hilang; error ditampilkan di samping field.
        $errors = $e->errors();
        $override = $_POST;
        $failedAction = $verb;
        http_response_code(422);
        $msg = $e->getMessage();
        Session::flash('error', in_array($msg, [I18n::t('npr.v.incomplete'), I18n::t('npr.v.feedback_incomplete')], true) ? $msg : I18n::t('validation.form_errors'));
    } catch (AppException $e) {
        if ($e->httpStatus() === 403) {
            throw $e;
        }
        Session::flash('error', $e->getMessage());
        Response::redirect($redirect);
    }
}

$GLOBALS['NPR_ERRORS'] = $errors;
$npr = $svc->load($id);
$can = $svc->abilities($user, $npr);
$seeDraftFeedback = Gate::can($user, 'npr.edit_npd_fields');
$status = (string) $npr['status'];

// Nilai tampil: data DB ditimpa input terakhir bila validasi gagal (agar isian tidak hilang)
$h = $npr;
if (is_array($override['npr'] ?? null)) {
    foreach ($override['npr'] as $k => $v) {
        $key = array_key_exists($k . '_json', NprFields::HEADER) ? $k . '_json' : $k;
        $h[$key] = is_array($v) ? json_encode(array_values(array_filter($v, static fn ($x) => $x !== ''))) : $v;
    }
}
$parts = $npr['parts'];
foreach ($parts as &$p) {
    foreach ([is_array($override['parts'][$p['id']] ?? null) ? $override['parts'][$p['id']] : [], is_array($override['feedback'][$p['id']] ?? null) ? $override['feedback'][$p['id']] : []] as $ov) {
        foreach ($ov as $k => $v) {
            $p[$k] = $v;
        }
    }
}
unset($p);
$activeParts = array_values(array_filter($parts, static fn ($p) => $p['status'] === 'active'));
$publishedParts = array_values(array_filter($parts, static fn ($p) => $p['published_at'] !== null));
$customers = $svc->formOptions()['customers'];
$history = RevisionHistory::forNpr($id);
$blue = $can['edit_blue'];
$pink = $can['edit_pink'];
$tests = [];
foreach (NprFields::decode($h['test_methods_json'] ?? null) as $tm) {
    $tests[$tm['code']] = $tm;
}
$m = static fn (string $cat): array => MasterService::options($cat);
$neckCodesJson = (string) json_encode(array_values(array_map(static fn ($o) => $o['code'], array_filter(MasterService::options('part_name', true), static fn ($o) => !empty($o['meta']['neck_preform'])))));

$pageTitle = ($npr['npr_number'] ?: I18n::t('npr_status.draft')) . ' · ' . ($npr['product_name'] ?: I18n::t('npr.title'));
$activeNav = 'npr';
$breadcrumbs = [[I18n::t('nav.npr'), url('npr.php')], [$npr['npr_number'] ?: I18n::t('npr_status.draft') . ' #' . $id, null]];
$pageScripts = ['js/npr-form.js'];
$bodyClass = 'page-npr';
require APP_ROOT . '/includes/layout/header.php';
?>
<div class="page-header npr-header">
  <div>
    <p class="eyebrow">PIK-FORM-NPD-01 · Rev 00</p>
    <h1><?= e($npr['product_name'] !== '' ? $npr['product_name'] : I18n::t('npr.title')) ?></h1>
    <p class="row">
      <?= status_badge($status, 'npr_status') ?>
      <span class="mono"><?= $npr['npr_number'] ? e($npr['npr_number']) : t('npr.number_auto') ?></span>
      <?php if ($npr['project_code']): ?><span class="muted">· <?= t('npr.project_code') ?>: <strong><?= e($npr['project_code']) ?></strong></span><?php endif; ?>
      <span class="muted">· <?= t('npr.sales_pic') ?>: <?= e($npr['sales_pic_name']) ?></span>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($can['pdf_final']): ?>
      <a class="btn" href="<?= e(url('export.php', ['type' => 'npr_pdf', 'id' => $id])) ?>"><?= icon('download') ?> <?= t('npr.pdf') ?></a>
    <?php elseif ($can['pdf_preview']): ?>
      <a class="btn" href="<?= e(url('export.php', ['type' => 'npr_pdf', 'id' => $id])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> <?= t('npr.pdf_preview') ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($status === 'returned' && $npr['return_reason']): ?>
  <div class="alert alert-warning" role="status"><?= icon('alert') ?><div><strong><?= t('npr.return_reason_label') ?>:</strong> <span class="pre"><?= e($npr['return_reason']) ?></span></div></div>
<?php endif; ?>
<?php if ($status === 'submitted' && !$blue): ?>
  <div class="alert alert-info" role="status"><?= icon('lock') ?><div><?= t('npr.locked_blue') ?></div></div>
<?php endif; ?>

<div class="npr-legend" aria-label="<?= t('npr.legend') ?>">
  <span class="legend-item is-sales"><span class="swatch"></span><?= t('npr.legend_sales') ?></span>
  <span class="legend-item is-npd"><span class="swatch"></span><?= t('npr.legend_npd') ?></span>
  <span class="legend-item is-auto"><span class="swatch"></span><?= t('npr.legend_auto') ?></span>
</div>

<div class="npr-layout">
  <nav class="npr-toc" aria-label="<?= t('npr.title') ?>">
    <ol>
      <?php foreach (['header', 'request_type', 'customer', 'application', 'parts', 'decoration', 'quantity', 'packaging', 'test', 'regulation', 'attachments', 'closing', 'signatures', 'feedback', 'history'] as $i => $sec): ?>
        <li><a href="#sec-<?= e($sec) ?>"><?= t('npr.s.' . $sec) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <div class="npr-main">
  <form method="post" enctype="multipart/form-data" id="npr-form" class="npr-form" novalidate
        data-npr-id="<?= e((string) $id) ?>" <?= ($blue || $pink) && $status !== 'feedback_completed' ? 'data-autosave="1"' : '' ?>
        data-msg-saving="<?= t('npr.autosave_saving') ?>" data-msg-saved="<?= t('npr.autosave_saved', ['time' => '{time}']) ?>"
        data-msg-error="<?= t('npr.autosave_error') ?>" data-msg-conflict="<?= t('npr.autosave_conflict') ?>"
        data-step-label="<?= t('npr.step', ['n' => '{n}', 'total' => '{total}']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="lock_version" value="<?= e((string) $npr['lock_version']) ?>" data-lock>

    <!-- 1. Header -->
    <section class="card npr-section" id="sec-header" data-step>
      <div class="card-header"><h2><?= t('npr.s.header') ?></h2><span class="muted small"><?= t('npr.filled_by_sales') ?></span></div>
      <div class="card-body form-grid">
        <div class="field npr-field is-auto"><label><?= t('npr.number') ?></label><div class="input input-static mono"><?= $npr['npr_number'] ? e($npr['npr_number']) : '<span class="muted">' . t('npr.number_auto') . '</span>' ?></div></div>
        <?= nf_text('sales', 'npr[product_name]', I18n::t('npr.f.product_name'), $h['product_name'], $blue, 'npr.product_name', ['maxlength' => '190', 'required' => 'required']) ?>
      </div>
    </section>

    <!-- 2. Jenis permintaan -->
    <section class="card npr-section" id="sec-request_type" data-step>
      <div class="card-header"><h2><?= t('npr.s.request_type') ?></h2></div>
      <div class="card-body stack">
        <?= nf_multi('sales', 'npr[request_types]', I18n::t('npr.f.request_types'), NprFields::decode($h['request_types_json']), $m('request_type'), $blue, 'npr.request_types', I18n::t('npr.s.request_type_hint')) ?>
        <?= nf_text('sales', 'npr[request_type_other]', I18n::t('npr.f.request_type_other'), $h['request_type_other'], $blue, 'npr.request_type_other', ['maxlength' => '255']) ?>
      </div>
    </section>

    <!-- 3. Data customer -->
    <section class="card npr-section" id="sec-customer" data-step>
      <div class="card-header"><h2><?= t('npr.s.customer') ?></h2></div>
      <div class="card-body form-grid">
        <?php
        $custOptions = [];
        foreach ($customers as $c) {
            $custOptions[(string) $c['id']] = $c['name'] . ' (' . $c['code'] . ')';
        }
        if ($h['customer_id'] && !isset($custOptions[(string) $h['customer_id']])) {
            $custOptions[(string) $h['customer_id']] = (string) $npr['customer_name'];
        }
        echo nf_select('sales', 'npr[customer_id]', I18n::t('npr.f.customer'), (string) $h['customer_id'], $custOptions, $blue, 'npr.customer_id', '', ['data-customer-select' => '1', 'data-customers' => json_encode(array_column($customers, null, 'id'), JSON_UNESCAPED_UNICODE)]);
        ?>
        <?= nf_text('sales', 'npr[phone]', I18n::t('npr.f.phone'), $h['phone'], $blue, 'npr.phone', ['maxlength' => '60', 'data-customer-field' => 'phone']) ?>
        <?= nf_textarea('sales', 'npr[invoice_address]', I18n::t('npr.f.invoice_address'), $h['invoice_address'], $blue, 'npr.invoice_address') ?>
        <?= nf_textarea('sales', 'npr[shipping_address]', I18n::t('npr.f.shipping_address'), $h['shipping_address'], $blue, 'npr.shipping_address') ?>
      </div>
    </section>

    <!-- 4. Aplikasi, isi, berat bersih -->
    <section class="card npr-section" id="sec-application" data-step>
      <div class="card-header"><h2><?= t('npr.s.application') ?></h2></div>
      <div class="card-body stack">
        <?= nf_multi('sales', 'npr[product_applications]', I18n::t('npr.f.product_applications'), NprFields::decode($h['product_applications_json']), $m('product_application'), $blue, 'npr.product_applications') ?>
        <?= nf_multi('sales', 'npr[product_contents]', I18n::t('npr.f.product_contents'), NprFields::decode($h['product_contents_json']), $m('product_content'), $blue, 'npr.product_contents') ?>
        <?= nf_text('sales', 'npr[product_content_other]', I18n::t('npr.f.product_content_other'), $h['product_content_other'], $blue, 'npr.product_content_other', ['maxlength' => '255']) ?>
        <div class="form-grid">
          <?= nf_text('sales', 'npr[net_volume_ml]', I18n::t('npr.f.net_volume_ml'), $h['net_volume_ml'], $blue, 'npr.net_volume_ml', ['inputmode' => 'decimal']) ?>
          <?= nf_text('sales', 'npr[net_weight_gr]', I18n::t('npr.f.net_weight_gr'), $h['net_weight_gr'], $blue, 'npr.net_weight_gr', ['inputmode' => 'decimal']) ?>
        </div>
      </div>
    </section>

    <!-- 5. Komponen produk (multi-part) -->
    <section class="card npr-section" id="sec-parts" data-step>
      <div class="card-header">
        <div><h2><?= t('npr.s.parts') ?></h2><p class="muted small"><?= t('npr.s.parts_hint') ?></p></div>
        <?php if ($can['add_part'] && in_array($status, ['draft', 'returned'], true)): ?>
          <button type="submit" name="action" value="add_part" class="btn btn-sm"><?= icon('plus', 'icon icon-sm') ?> <?= t('npr.add_part') ?></button>
        <?php elseif ($can['add_part']): ?>
          <button type="button" class="btn btn-sm" data-open-dialog="dlg-add-part"><?= icon('plus', 'icon icon-sm') ?> <?= t('npr.add_part') ?></button>
        <?php endif; ?>
      </div>
      <div class="card-body stack">
        <?= nf_err('parts') ?>
        <?php foreach ($parts as $n => $p):
            $pid = (int) $p['id'];
            $pre = 'parts[' . $pid . ']';
            $ek = 'parts.' . $pid . '.';
            $isCancelled = $p['status'] === 'cancelled';
            $partBlue = $blue && !$isCancelled;
            $showPink = $p['published_at'] !== null || $seeDraftFeedback;
            $partPink = $pink && !$isCancelled;
            $fk = 'feedback.' . $pid . '.';
            $fpre = 'feedback[' . $pid . ']';
            $neck = NprFields::isNeckPart($p);
        ?>
          <article class="npr-part<?= $isCancelled ? ' is-cancelled' : '' ?>" id="part-<?= e((string) $pid) ?>" data-part>
            <header class="npr-part-header">
              <span class="npr-part-no"><?= e((string) ($n + 1)) ?></span>
              <h3><?= e(NprFields::partDisplayName($p) !== '' ? NprFields::partDisplayName($p) : I18n::t('npr.part_n', ['n' => $n + 1])) ?></h3>
              <?php if ($p['part_type']): ?><span class="badge badge-neutral"><?= t('part_type.' . $p['part_type']) ?></span><?php endif; ?>
              <?php if ((int) $p['needs_review'] === 1): ?><span class="badge badge-warning"><?= icon('alert', 'icon icon-sm') ?> <?= t('npr.needs_review') ?></span><?php endif; ?>
              <?php if ($isCancelled): ?><span class="badge badge-danger"><?= t('npr.part_cancelled') ?></span><?php endif; ?>
              <span class="spacer"></span>
              <?php if (!$isCancelled && $can['add_part'] && count($activeParts) > 1 && in_array($status, ['draft', 'returned'], true) && $p['published_at'] === null && $p['feedback_id'] === null): ?>
                <button type="submit" name="action" value="remove_part:<?= e((string) $pid) ?>" class="btn btn-sm btn-ghost" formnovalidate><?= icon('trash', 'icon icon-sm') ?> <?= t('npr.remove_part') ?></button>
              <?php endif; ?>
              <?php if (!$isCancelled && $can['cancel_part']): ?>
                <button type="button" class="btn btn-sm btn-ghost" data-open-dialog="dlg-cancel-part" data-part-id="<?= e((string) $pid) ?>"><?= icon('x', 'icon icon-sm') ?> <?= t('npr.cancel_part') ?></button>
              <?php endif; ?>
            </header>
            <?php if ($isCancelled && $p['cancel_reason']): ?><p class="muted small"><?= t('common.reason') ?>: <?= e($p['cancel_reason']) ?></p><?php endif; ?>
            <div class="npr-part-grid">
              <?php
              $partNameOptions = nf_normalize_options($m('part_name')) + [NprFields::OTHER_PART => I18n::t('npr.other')];
              echo nf_select('sales', $pre . '[part_name_code]', I18n::t('npr.f.part_name'), $p['part_name_code'], $partNameOptions, $partBlue, $ek . 'part_name_code', '', ['data-part-name' => '1', 'data-neck-codes' => $neckCodesJson]);
              echo '<div data-show-when-other>' . nf_text('sales', $pre . '[part_name_custom]', I18n::t('npr.f.part_name_custom'), $p['part_name_custom'], $partBlue, $ek . 'part_name_custom', ['maxlength' => '120']) . '</div>';
              echo nf_radio('sales', $pre . '[part_type]', I18n::t('npr.f.part_type'), $p['part_type'], ['new_mold' => I18n::t('part_type.new_mold'), 'subcont' => I18n::t('part_type.subcont')], $partBlue, $ek . 'part_type');
              echo nf_select('sales', $pre . '[development_type]', I18n::t('npr.f.development_type'), $p['development_type'], $m('development_type'), $partBlue, $ek . 'development_type');
              echo nf_text('sales', $pre . '[mold_supplier]', I18n::t('npr.f.mold_supplier'), $p['mold_supplier'], $partBlue, $ek . 'mold_supplier', ['maxlength' => '190'], I18n::t('npr.f.mold_supplier_hint'));
              echo nf_bool('sales', $pre . '[is_external_component]', I18n::t('npr.f.is_external_component'), $p['is_external_component'], $partBlue);
              echo nf_text('sales', $pre . '[external_note]', I18n::t('npr.f.external_note'), $p['external_note'], $partBlue, $ek . 'external_note', ['maxlength' => '255'], I18n::t('npr.f.external_note_hint'));
              echo '<div data-hide-when-neck' . ($neck ? ' hidden' : '') . ' class="npr-subgrid">'
                  . nf_select('sales', $pre . '[resin_code]', I18n::t('npr.f.resin'), $p['resin_code'], $m('resin'), $partBlue, $ek . 'resin_code')
                  . nf_select('sales', $pre . '[color_code]', I18n::t('npr.f.color'), $p['color_code'], $m('color'), $partBlue, $ek . 'color_code')
                  . nf_text('sales', $pre . '[pantone]', I18n::t('npr.f.pantone'), $p['pantone'], $partBlue, $ek . 'pantone', ['maxlength' => '60'])
                  . nf_select('sales', $pre . '[surface_code]', I18n::t('npr.f.surface'), $p['surface_code'], $m('surface'), $partBlue, $ek . 'surface_code')
                  . '</div>';
              echo '<div data-show-when-neck' . ($neck ? '' : ' hidden') . '>' . nf_select('sales', $pre . '[neck_preform_code]', I18n::t('npr.f.neck_preform'), $p['neck_preform_code'], $m('neck_preform'), $partBlue, $ek . 'neck_preform_code') . '</div>';
              ?>
            </div>

            <?php if ($showPink): ?>
              <div class="npr-feedback is-npd">
                <div class="npr-feedback-title">
                  <strong><?= t('npr.legend_npd') ?></strong>
                  <?= $p['published_at'] !== null ? '<span class="badge badge-success">' . t('npr.feedback_published') . '</span>' : '<span class="badge badge-neutral">' . t('npr.feedback_draft') . '</span>' ?>
                </div>
                <div class="npr-part-grid">
                  <?php
                  $fbEditable = $partPink;
                  echo nf_text('npd', $fpre . '[weight_gr]', I18n::t('npr.f.weight_gr'), $p['weight_gr'], $fbEditable, $fk . 'weight_gr', ['inputmode' => 'decimal']);
                  echo nf_select('npd', $fpre . '[mould_method_code]', I18n::t('npr.f.mould_method'), $p['mould_method_code'], $m('mould_method'), $fbEditable, $fk . 'mould_method_code');
                  echo nf_text('npd', $fpre . '[mould_method_other]', I18n::t('npr.f.mould_method_other'), $p['mould_method_other'], $fbEditable, $fk . 'mould_method_other', ['maxlength' => '120']);
                  echo nf_text('npd', $fpre . '[cavity]', I18n::t('npr.f.cavity'), $p['cavity'], $fbEditable, $fk . 'cavity', ['inputmode' => 'numeric']);
                  echo nf_text('npd', $fpre . '[mould_price_pik_pct]', I18n::t('npr.f.mould_price_pik_pct'), $p['mould_price_pik_pct'], $fbEditable, $fk . 'mould_price_pik_pct', ['inputmode' => 'decimal', 'data-pct' => 'pik']);
                  echo nf_text('npd', $fpre . '[mould_price_cust_pct]', I18n::t('npr.f.mould_price_cust_pct'), $p['mould_price_cust_pct'], $fbEditable, $fk . 'mould_price_cust_pct', ['inputmode' => 'decimal', 'data-pct' => 'cust']);
                  echo nf_text('npd', $fpre . '[mould_lead_time_days]', I18n::t('npr.f.mould_lead_time_days'), $p['mould_lead_time_days'], $fbEditable, $fk . 'mould_lead_time_days', ['inputmode' => 'numeric']);
                  echo nf_text('npd', $fpre . '[mould_lead_time_note]', I18n::t('npr.f.mould_lead_time_note'), $p['mould_lead_time_note'], $fbEditable, $fk . 'mould_lead_time_note', ['maxlength' => '120']);
                  echo nf_select('npd', $fpre . '[needs_new_masterbatch]', I18n::t('npr.f.needs_new_masterbatch'), $p['needs_new_masterbatch'] === null ? '' : (string) (int) $p['needs_new_masterbatch'], ['1' => I18n::t('common.yes'), '0' => I18n::t('common.no')], $fbEditable, $fk . 'needs_new_masterbatch', I18n::t('npr.f.needs_new_masterbatch_hint'));
                  $decEditable = $fbEditable && $p['published_at'] === null;
                  $decOptions = [];
                  foreach (NprFields::DECISIONS as $d) {
                      $decOptions[$d] = I18n::t('decision.' . $d);
                  }
                  echo nf_select('npd', $fpre . '[decision]', I18n::t('npr.f.decision'), $p['decision'], $decOptions, $decEditable, $fk . 'decision');
                  ?>
                  <div class="span-all">
                    <?= nf_textarea('npd', $fpre . '[feedback_text]', I18n::t('npr.f.feedback_text'), $p['feedback_text'], $fbEditable, $fk . 'feedback_text', 3) ?>
                  </div>
                  <div class="span-all">
                    <?= nf_textarea('npd', $fpre . '[decision_reason]', I18n::t('npr.f.decision_reason'), $p['decision_reason'], $fbEditable, $fk . 'decision_reason', 2) ?>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 6. Decoration -->
    <section class="card npr-section" id="sec-decoration" data-step>
      <div class="card-header"><h2><?= t('npr.s.decoration') ?></h2></div>
      <div class="card-body stack">
        <div class="deco-block">
          <?= nf_bool('sales', 'npr[deco_printing]', I18n::t('npr.f.deco_printing'), $h['deco_printing'], $blue, ['data-toggle-target' => 'deco-printing']) ?>
          <div class="form-grid" id="deco-printing"<?= (int) $h['deco_printing'] === 1 ? '' : ' data-collapsed' ?>>
            <?= nf_select('sales', 'npr[deco_printing_method]', I18n::t('npr.f.deco_printing_method'), $h['deco_printing_method'], $m('printing_method'), $blue, 'npr.deco_printing_method') ?>
            <?= nf_text('sales', 'npr[deco_printing_components]', I18n::t('npr.f.deco_printing_components'), $h['deco_printing_components'], $blue, 'npr.deco_printing_components', ['maxlength' => '255']) ?>
            <?= nf_select('sales', 'npr[deco_varnish]', I18n::t('npr.f.deco_varnish'), $h['deco_varnish'], $m('varnish'), $blue, 'npr.deco_varnish') ?>
            <?= nf_text('sales', 'npr[deco_varnish_components]', I18n::t('npr.f.deco_varnish_components'), $h['deco_varnish_components'], $blue, 'npr.deco_varnish_components', ['maxlength' => '255']) ?>
          </div>
        </div>
        <div class="deco-block">
          <?= nf_bool('sales', 'npr[deco_labelling]', I18n::t('npr.f.deco_labelling'), $h['deco_labelling'], $blue, ['data-toggle-target' => 'deco-labelling']) ?>
          <div class="form-grid" id="deco-labelling"<?= (int) $h['deco_labelling'] === 1 ? '' : ' data-collapsed' ?>>
            <?= nf_select('sales', 'npr[deco_labelling_side]', I18n::t('npr.f.deco_labelling_side'), $h['deco_labelling_side'], $m('labelling_side'), $blue, 'npr.deco_labelling_side') ?>
            <?= nf_text('sales', 'npr[deco_labelling_components]', I18n::t('npr.f.deco_labelling_components'), $h['deco_labelling_components'], $blue, 'npr.deco_labelling_components', ['maxlength' => '255']) ?>
          </div>
        </div>
        <div class="deco-block">
          <?= nf_bool('sales', 'npr[deco_shrink]', I18n::t('npr.f.deco_shrink'), $h['deco_shrink'], $blue, ['data-toggle-target' => 'deco-shrink']) ?>
          <div class="form-grid" id="deco-shrink"<?= (int) $h['deco_shrink'] === 1 ? '' : ' data-collapsed' ?>>
            <?= nf_text('sales', 'npr[deco_shrink_components]', I18n::t('npr.f.deco_shrink_components'), $h['deco_shrink_components'], $blue, 'npr.deco_shrink_components', ['maxlength' => '255']) ?>
          </div>
        </div>
      </div>
    </section>

    <!-- 7. Kebutuhan -->
    <section class="card npr-section" id="sec-quantity" data-step>
      <div class="card-header"><h2><?= t('npr.s.quantity') ?></h2></div>
      <div class="card-body form-grid">
        <?= nf_text('sales', 'npr[qty_per_month]', I18n::t('npr.f.qty_per_month'), $h['qty_per_month'], $blue, 'npr.qty_per_month', ['inputmode' => 'numeric']) ?>
        <?= nf_text('sales', 'npr[qty_per_year]', I18n::t('npr.f.qty_per_year'), $h['qty_per_year'], $blue, 'npr.qty_per_year', ['inputmode' => 'numeric']) ?>
      </div>
    </section>

    <!-- 8. Kemasan -->
    <section class="card npr-section" id="sec-packaging" data-step>
      <div class="card-header"><h2><?= t('npr.s.packaging') ?></h2></div>
      <div class="card-body stack">
        <?= nf_multi('sales', 'npr[packaging]', I18n::t('npr.f.packaging'), NprFields::decode($h['packaging_json']), $m('packaging'), $blue, 'npr.packaging') ?>
        <?= nf_text('sales', 'npr[packaging_other]', I18n::t('npr.f.packaging_other'), $h['packaging_other'], $blue, 'npr.packaging_other', ['maxlength' => '255']) ?>
      </div>
    </section>

    <!-- 9. Metode test -->
    <section class="card npr-section" id="sec-test" data-step>
      <div class="card-header"><h2><?= t('npr.s.test') ?></h2></div>
      <div class="card-body stack">
        <div class="npr-field is-sales test-table" role="group" aria-label="<?= t('npr.f.test_methods') ?>">
          <?php foreach ($m('test_method') as $tm):
              $code = (string) $tm['code'];
              $sel = $tests[$code] ?? null;
              $units = $tm['meta']['units'] ?? [];
              $base = 'npr[test_methods][' . $code . ']';
          ?>
            <div class="test-row">
              <?php if ($blue): ?><input type="hidden" name="<?= e($base) ?>[checked]" value="0"><?php endif; ?>
              <label class="check"><input type="checkbox" name="<?= e($base) ?>[checked]" value="1"<?= $sel ? ' checked' : '' ?><?= $blue ? '' : ' disabled' ?>> <span><?= e(I18n::locale() === 'en' ? $tm['label_en'] : $tm['label_id']) ?></span></label>
              <?php if ($units): ?>
                <input class="input input-sm" name="<?= e($base) ?>[value]" value="<?= e($sel['value'] ?? '') ?>" placeholder="<?= t('npr.test_value') ?>" aria-label="<?= t('npr.test_value') ?>" maxlength="60"<?= $blue ? '' : ' disabled' ?>>
                <select class="input input-sm" name="<?= e($base) ?>[unit]" aria-label="<?= t('npr.test_unit') ?>"<?= $blue ? '' : ' disabled' ?>>
                  <?php foreach ($units as $u): ?><option value="<?= e($u) ?>"<?= ($sel['unit'] ?? '') === $u ? ' selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?= nf_bool('sales', 'npr[test_refer_spec_doc]', I18n::t('npr.f.test_refer_spec_doc'), $h['test_refer_spec_doc'], $blue) ?>
        <?= nf_text('sales', 'npr[test_other]', I18n::t('npr.f.test_other'), $h['test_other'], $blue, 'npr.test_other', ['maxlength' => '255']) ?>
      </div>
    </section>

    <!-- 10. Regulasi -->
    <section class="card npr-section" id="sec-regulation" data-step>
      <div class="card-header"><h2><?= t('npr.s.regulation') ?></h2></div>
      <div class="card-body stack">
        <?= nf_radio('sales', 'npr[regulation_compliance]', I18n::t('npr.f.regulation_compliance'), $h['regulation_compliance'], ['no' => I18n::t('npr.regulation.no'), 'yes' => I18n::t('npr.regulation.yes')], $blue, 'npr.regulation_compliance') ?>
        <?= nf_textarea('sales', 'npr[regulation_note]', I18n::t('npr.f.regulation_note'), $h['regulation_note'], $blue, 'npr.regulation_note') ?>
      </div>
    </section>

    <!-- 11. Lampiran -->
    <section class="card npr-section" id="sec-attachments" data-step>
      <div class="card-header"><h2><?= t('npr.s.attachments') ?></h2></div>
      <div class="card-body stack">
        <div class="form-grid">
          <?php foreach (['attach_sample', 'attach_technical_drawing', 'attach_mockup'] as $ak): ?>
            <?= nf_radio('sales', 'npr[' . $ak . ']', I18n::t('npr.f.' . $ak), $h[$ak], ['ada' => I18n::t('npr.attach.ada'), 'tidak_ada' => I18n::t('npr.attach.tidak_ada')], $blue, 'npr.' . $ak) ?>
          <?php endforeach; ?>
        </div>
        <div class="grid grid-2">
          <?php foreach (DocumentService::NPR_CATEGORIES as $cat):
              $files = array_filter($npr['attachments'], static fn ($a) => $a['npr_category'] === $cat);
          ?>
            <div class="attach-box npr-field is-sales">
              <h3><?= t('npr.attach_' . $cat) ?></h3>
              <?php if (!$files): ?><p class="muted small"><?= t('npr.attach_none') ?></p><?php endif; ?>
              <ul class="attach-list">
                <?php foreach ($files as $f): $isImg = in_array($f['extension'], UploadValidator::IMAGE_EXT, true); ?>
                  <li>
                    <?php if ($isImg): ?><img src="<?= e(url('download.php', ['v' => $f['version_id'], 'inline' => 1])) ?>" alt="" class="attach-thumb" loading="lazy"><?php else: ?><span class="attach-icon"><?= icon('file') ?></span><?php endif; ?>
                    <a href="<?= e(url('download.php', ['v' => $f['version_id'], 'inline' => ($isImg || $f['extension'] === 'pdf') ? 1 : 0])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a>
                    <span class="muted small"><?= fmt_number(round($f['size_bytes'] / 1024)) ?> KB</span>
                    <?php if ($can['upload']): ?>
                      <button type="submit" name="action" value="remove_attachment:<?= e((string) $f['id']) ?>" class="btn btn-sm btn-ghost" formnovalidate><?= t('npr.attach_remove') ?></button>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php if ($can['upload']): ?>
                <div class="field">
                  <label for="att-<?= e($cat) ?>"><?= t('npr.attach_upload') ?></label>
                  <input class="input" type="file" id="att-<?= e($cat) ?>" name="attachment_<?= e($cat) ?>" accept="<?= e('.' . implode(',.', UploadValidator::allowedExtensions())) ?>">
                  <p class="field-hint"><?= t('npr.attach_hint', ['mb' => (int) (UploadValidator::maxBytes() / 1048576)]) ?></p>
                  <?= nf_err('attachment') ?>
                </div>
                <button type="submit" name="action" value="upload" class="btn btn-sm"><?= icon('upload', 'icon icon-sm') ?> <?= t('npr.attach_upload') ?></button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- 12. Note & launching target -->
    <section class="card npr-section" id="sec-closing" data-step>
      <div class="card-header"><h2><?= t('npr.s.closing') ?></h2></div>
      <div class="card-body form-grid">
        <div class="span-2"><?= nf_textarea('sales', 'npr[note]', I18n::t('npr.f.note'), $h['note'], $blue, 'npr.note', 3) ?></div>
        <?= nf_text('sales', 'npr[launching_target]', I18n::t('npr.f.launching_target'), $h['launching_target'], $blue, 'npr.launching_target', ['type' => 'date']) ?>
      </div>
    </section>

    <!-- 13. Requested by / Received by (otomatis, tanpa blok tanda tangan) -->
    <section class="card npr-section" id="sec-signatures" data-step>
      <div class="card-header"><h2><?= t('npr.s.signatures') ?></h2></div>
      <div class="card-body grid grid-2">
        <?php foreach (['requested' => ['requested_by_name', 'requested_by_title', 'requested_at'], 'received' => ['received_by_name', 'received_by_title', 'received_at']] as $kind => [$n1, $n2, $n3]): ?>
          <div class="sign-box npr-field is-auto">
            <h3><?= t('npr.' . $kind . '_by') ?></h3>
            <dl class="kv">
              <dt><?= t('common.name') ?></dt><dd><?= e($npr[$n1] ?? '–') ?></dd>
              <dt><?= t('npr.job_title') ?></dt><dd><?= e($npr[$n2] ?? '–') ?></dd>
              <dt><?= t('common.date') ?></dt><dd><?= $npr[$n3] ? fmt_date($npr[$n3]) : '–' ?></dd>
            </dl>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <?php if ($can['reason_required'] && ($blue || $pink)): ?>
      <section class="card npr-section">
        <div class="card-body">
          <div class="field">
            <label for="reason"><?= t('npr.correction_reason') ?></label>
            <input class="input" id="reason" name="reason" maxlength="500" value="<?= e((string) ($override['reason'] ?? '')) ?>">
            <?= nf_err('reason') ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($blue || $pink || $can['submit'] || $can['return'] || $can['complete'] || $can['discard']): ?>
    <div class="npr-actionbar" role="toolbar">
      <span class="autosave-status muted small" data-autosave-status aria-live="polite"></span>
      <span class="spacer"></span>
      <?php if ($blue): ?>
        <button type="submit" name="action" value="save" class="btn"><?= icon('check') ?> <?= $status === 'feedback_completed' ? t('npr.save') : t('npr.save_draft') ?></button>
      <?php elseif ($pink): ?>
        <button type="submit" name="action" value="save" class="btn"><?= icon('check') ?> <?= t('npr.save_feedback') ?></button>
      <?php endif; ?>
      <?php if ($can['submit']): ?>
        <button type="submit" name="action" value="submit" class="btn btn-primary" data-confirm-submit="<?= t('npr.submit_confirm') ?>" data-ok="<?= t('npr.submit') ?>" data-cancel="<?= t('common.cancel') ?>"><?= icon('arrow-right') ?> <?= t('npr.submit') ?></button>
      <?php endif; ?>
      <?php if ($can['return']): ?>
        <button type="button" class="btn" data-open-dialog="dlg-return"><?= icon('arrow-left') ?> <?= t('npr.return') ?></button>
      <?php endif; ?>
      <?php if ($can['complete']): ?>
        <button type="submit" name="action" value="complete" class="btn btn-primary" data-confirm-submit="<?= t('npr.complete_confirm') ?>" data-ok="<?= t('npr.complete') ?>" data-cancel="<?= t('common.cancel') ?>"><?= icon('check-circle') ?> <?= t('npr.complete') ?></button>
      <?php endif; ?>
      <?php if ($can['discard']): ?>
        <button type="button" class="btn btn-ghost" data-open-dialog="dlg-discard"><?= t('npr.discard') ?></button>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </form>

  <!-- 14. Feedback NPR (dibangun otomatis dari isian NPD yang sudah dipublikasikan) -->
  <section class="card npr-section section" id="sec-feedback">
    <div class="card-header"><div><h2><?= t('npr.feedback_summary') ?></h2><p class="muted small"><?= t('npr.feedback_summary_hint') ?></p></div></div>
    <div class="card-body">
      <?php if (!$publishedParts): ?>
        <p class="muted"><?= t('npr.feedback_none') ?></p>
      <?php else:
          $acc = count(array_filter($publishedParts, static fn ($p) => $p['decision'] !== 'not_feasible'));
      ?>
        <p class="muted"><?= t('npr.project_summary', ['accepted' => $acc, 'cancelled' => count($publishedParts) - $acc, 'total' => count($publishedParts)]) ?></p>
        <div class="table-wrap">
          <table class="table table-cards npr-fb-table">
            <thead><tr>
              <th><?= t('npr.part') ?></th><th><?= t('npr.f.decision') ?></th><th class="right"><?= t('npr.f.weight_gr') ?></th>
              <th><?= t('npr.f.mould_method') ?></th><th><?= t('npr.f.cavity') ?></th><th><?= t('npr.f.mould_lead_time_days') ?></th><th><?= t('npr.f.feedback_text') ?></th>
            </tr></thead>
            <tbody>
              <?php foreach ($publishedParts as $p): ?>
                <tr>
                  <td data-label="<?= t('npr.part') ?>"><strong><?= e(NprFields::partDisplayName($p)) ?></strong><br><span class="muted small"><?= t('part_type.' . $p['part_type']) ?></span></td>
                  <td data-label="<?= t('npr.f.decision') ?>"><?= status_badge((string) $p['decision'], 'decision') ?></td>
                  <td data-label="<?= t('npr.f.weight_gr') ?>" class="right"><?= e($p['weight_gr'] ?? '–') ?></td>
                  <td data-label="<?= t('npr.f.mould_method') ?>"><?= e(MasterService::label('mould_method', $p['mould_method_code']) ?: '–') ?></td>
                  <td data-label="<?= t('npr.f.cavity') ?>"><?= e($p['cavity'] ?? '–') ?></td>
                  <td data-label="<?= t('npr.f.mould_lead_time_days') ?>"><?= e($p['mould_lead_time_days'] ?? '–') ?></td>
                  <td data-label="<?= t('npr.f.feedback_text') ?>" class="pre"><?= e($p['feedback_text']) ?><?= $p['decision_reason'] ? '<br><span class="muted">' . e($p['decision_reason']) . '</span>' : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted small"><?= t('npr.received_by') ?>: <?= e($npr['received_by_name'] ?? '–') ?> · <?= $npr['received_at'] ? fmt_date($npr['received_at']) : '' ?></p>
      <?php endif; ?>
    </div>
  </section>

  <!-- 15. Revision History -->
  <section class="card npr-section section" id="sec-history">
    <div class="card-header"><h2><?= t('npr.s.history') ?></h2></div>
    <div class="card-body">
      <?php if (!$history): ?><p class="muted"><?= t('npr.history_empty') ?></p><?php endif; ?>
      <ol class="timeline-list">
        <?php foreach ($history as $rev):
            $d = $rev['details_json'] ? json_decode((string) $rev['details_json'], true) : [];
        ?>
          <li>
            <div class="timeline-meta"><span class="badge badge-neutral"><?= I18n::has('revtype.' . $rev['revision_type']) ? t('revtype.' . $rev['revision_type']) : e($rev['revision_type']) ?></span>
              <span class="muted small"><?= fmt_datetime($rev['created_at']) ?> · <?= e($rev['user_name'] ?? tr('common.system')) ?></span></div>
            <div><?= e($rev['summary']) ?></div>
            <?php if (!empty($d['reason'])): ?><div class="muted small pre"><?= t('common.reason') ?>: <?= e($d['reason']) ?></div><?php endif; ?>
            <?php if (!empty($d['diff']) && (count($d['diff']['header']) + count($d['diff']['parts'])) > 0): ?>
              <details class="diff"><summary><?= t('npr.history_changes') ?></summary>
                <table class="table"><tbody>
                  <?php foreach ($d['diff']['header'] as $c): ?>
                    <tr><td><?= e(NprFields::label($c['field'])) ?></td><td class="muted"><?= e(NprFields::display($c['field'], $c['old'])) ?></td><td><?= e(NprFields::display($c['field'], $c['new'])) ?></td></tr>
                  <?php endforeach; ?>
                  <?php foreach ($d['diff']['parts'] as $pid => $pc): ?>
                    <?php if ($pc['status'] !== 'changed'): ?>
                      <tr><td><?= t('npr.part') ?> #<?= e((string) $pid) ?></td><td colspan="2"><?= e($pc['status']) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($pc['fields'] as $c): ?>
                      <tr><td><?= t('npr.part') ?> #<?= e((string) $pid) ?> · <?= e(NprFields::label($c['field'])) ?></td><td class="muted"><?= e(NprFields::display($c['field'], $c['old'])) ?></td><td><?= e(NprFields::display($c['field'], $c['new'])) ?></td></tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tbody></table>
              </details>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </section>
  </div>
</div>

<?php if ($can['return']): ?>
  <dialog class="modal" id="dlg-return" aria-labelledby="dlg-return-title"<?= $failedAction === 'return' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="return">
      <input type="hidden" name="lock_version" value="<?= e((string) $npr['lock_version']) ?>" data-lock>
      <div class="modal-header"><h2 id="dlg-return-title"><?= t('npr.return') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body"><div class="field"><label for="ret-reason"><?= t('npr.return_reason') ?></label><textarea class="input" id="ret-reason" name="reason" rows="4" required maxlength="2000"></textarea><?= nf_err('reason') ?></div></div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('npr.return') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if ($can['discard']): ?>
  <dialog class="modal" id="dlg-discard">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="discard">
      <div class="modal-body"><p><?= t('npr.discard_confirm') ?></p></div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-danger"><?= t('npr.discard') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if ($can['cancel_part']): ?>
  <dialog class="modal" id="dlg-cancel-part" aria-labelledby="dlg-cancel-title"<?= $failedAction === 'cancel_part' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel_part"><input type="hidden" name="part_id" value="" data-part-id-input>
      <div class="modal-header"><h2 id="dlg-cancel-title"><?= t('npr.cancel_part') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body"><div class="field"><label for="cp-reason"><?= t('common.reason_required') ?></label><textarea class="input" id="cp-reason" name="reason" rows="3" required maxlength="2000"></textarea></div></div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-danger"><?= t('npr.cancel_part') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php if ($can['add_part'] && !in_array($status, ['draft', 'returned'], true)): ?>
  <dialog class="modal" id="dlg-add-part" aria-labelledby="dlg-add-title"<?= $failedAction === 'add_part_npd' ? ' data-autoopen' : '' ?>>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_part_npd">
      <div class="modal-header"><h2 id="dlg-add-title"><?= t('npr.add_part') ?></h2><button type="button" class="icon-btn" data-close-dialog aria-label="<?= t('common.close') ?>"><?= icon('x') ?></button></div>
      <div class="modal-body">
        <p class="muted small"><?= t('npr.add_part_reason_hint') ?></p>
        <?php $np = is_array($override['new_part'] ?? null) ? $override['new_part'] : []; ?>
        <div class="form-grid">
          <?= nf_select('sales', 'new_part[part_name_code]', I18n::t('npr.f.part_name'), $np['part_name_code'] ?? '', nf_normalize_options($m('part_name')) + [NprFields::OTHER_PART => I18n::t('npr.other')], true, 'new_part.part_name_code') ?>
          <?= nf_text('sales', 'new_part[part_name_custom]', I18n::t('npr.f.part_name_custom'), $np['part_name_custom'] ?? '', true, 'new_part.part_name_custom', ['maxlength' => '120']) ?>
          <?= nf_radio('sales', 'new_part[part_type]', I18n::t('npr.f.part_type'), $np['part_type'] ?? '', ['new_mold' => I18n::t('part_type.new_mold'), 'subcont' => I18n::t('part_type.subcont')], true, 'new_part.part_type') ?>
          <?= nf_select('sales', 'new_part[development_type]', I18n::t('npr.f.development_type'), $np['development_type'] ?? '', $m('development_type'), true, 'new_part.development_type') ?>
          <?= nf_text('sales', 'new_part[mold_supplier]', I18n::t('npr.f.mold_supplier'), $np['mold_supplier'] ?? '', true, 'new_part.mold_supplier', ['maxlength' => '190']) ?>
          <?= nf_select('sales', 'new_part[resin_code]', I18n::t('npr.f.resin'), $np['resin_code'] ?? '', $m('resin'), true, 'new_part.resin_code') ?>
          <?= nf_select('sales', 'new_part[color_code]', I18n::t('npr.f.color'), $np['color_code'] ?? '', $m('color'), true, 'new_part.color_code') ?>
          <?= nf_text('sales', 'new_part[pantone]', I18n::t('npr.f.pantone'), $np['pantone'] ?? '', true, 'new_part.pantone', ['maxlength' => '60']) ?>
          <?= nf_select('sales', 'new_part[surface_code]', I18n::t('npr.f.surface'), $np['surface_code'] ?? '', $m('surface'), true, 'new_part.surface_code') ?>
          <?= nf_select('sales', 'new_part[neck_preform_code]', I18n::t('npr.f.neck_preform'), $np['neck_preform_code'] ?? '', $m('neck_preform'), true, 'new_part.neck_preform_code') ?>
          <div class="field span-2"><label for="np-reason"><?= t('common.reason_required') ?></label><input class="input" id="np-reason" name="reason" maxlength="500" required><?= nf_err('reason') ?></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn" data-close-dialog><?= t('common.cancel') ?></button><button type="submit" class="btn btn-primary"><?= t('npr.add_part') ?></button></div>
    </form>
  </dialog>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout/footer.php'; ?>
