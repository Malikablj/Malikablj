<?php
declare(strict_types=1);

/**
 * Helper render field form NPR. Warna penanda: .is-sales (biru) / .is-npd (pink).
 * Field yang tidak boleh diubah dirender `disabled` (tidak terkirim); server tetap menegakkan hak akses.
 */

use App\Core\I18n;
use App\Master\MasterService;

/** @var array<string,string> $GLOBALS['NPR_ERRORS'] */

function nf_err(string $key): string
{
    $errors = $GLOBALS['NPR_ERRORS'] ?? [];
    return isset($errors[$key]) ? '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>' : '';
}

function nf_has_err(string $key): bool
{
    return isset(($GLOBALS['NPR_ERRORS'] ?? [])[$key]);
}

function nf_id(string $name): string
{
    return 'f_' . trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_');
}

/** Label + kontrol dalam pembungkus berwarna. */
function nf_wrap(string $color, string $label, string $control, string $errKey, string $name, string $hint = '', string $extraClass = ''): string
{
    $err = nf_err($errKey);
    return '<div class="field npr-field is-' . e($color) . ($err !== '' ? ' has-error' : '') . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '">'
        . '<label for="' . e(nf_id($name)) . '">' . e($label) . '</label>' . $control
        . ($hint !== '' ? '<p class="field-hint">' . e($hint) . '</p>' : '') . $err . '</div>';
}

function nf_text(string $color, string $name, string $label, mixed $value, bool $editable, string $errKey, array $attrs = [], string $hint = ''): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . e($k) . '="' . e($v) . '"';
    }
    $control = '<input class="input" id="' . e(nf_id($name)) . '" name="' . e($name) . '" value="' . e($value) . '"' . ($editable ? '' : ' disabled') . $a . '>';
    return nf_wrap($color, $label, $control, $errKey, $name, $hint);
}

function nf_textarea(string $color, string $name, string $label, mixed $value, bool $editable, string $errKey, int $rows = 2, string $hint = ''): string
{
    $control = '<textarea class="input" rows="' . $rows . '" id="' . e(nf_id($name)) . '" name="' . e($name) . '" maxlength="5000"' . ($editable ? '' : ' disabled') . '>' . e($value) . '</textarea>';
    return nf_wrap($color, $label, $control, $errKey, $name, $hint);
}

/** @param list<array{code:string,label_id:string,label_en:string}>|array<string,string> $options */
function nf_select(string $color, string $name, string $label, mixed $value, array $options, bool $editable, string $errKey, string $hint = '', array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . e($k) . '="' . e($v) . '"';
    }
    $html = '<select class="input" id="' . e(nf_id($name)) . '" name="' . e($name) . '"' . ($editable ? '' : ' disabled') . $a . '>';
    $html .= '<option value="">' . t('npr.choose') . '</option>';
    $found = false;
    foreach (nf_normalize_options($options) as $code => $text) {
        $sel = (string) $value === (string) $code;
        $found = $found || $sel;
        $html .= '<option value="' . e($code) . '"' . ($sel ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    // nilai lama yang opsinya sudah dinonaktifkan tetap tampil (PRD §4.4)
    if (!$found && $value !== null && $value !== '') {
        $html .= '<option value="' . e($value) . '" selected>' . e($value) . '</option>';
    }
    return nf_wrap($color, $label, $html . '</select>', $errKey, $name, $hint);
}

/** Checkbox group (multi-pilih) + hidden "" agar "tidak ada yang dicentang" tetap terkirim. */
function nf_multi(string $color, string $name, string $label, array $values, array $options, bool $editable, string $errKey, string $hint = ''): string
{
    $html = '<div class="check-grid" role="group" aria-label="' . e($label) . '">';
    if ($editable) {
        $html .= '<input type="hidden" name="' . e($name) . '[]" value="">';
    }
    foreach (nf_normalize_options($options) as $code => $text) {
        $checked = in_array((string) $code, array_map('strval', $values), true);
        $html .= '<label class="check"><input type="checkbox" name="' . e($name) . '[]" value="' . e($code) . '"' . ($checked ? ' checked' : '') . ($editable ? '' : ' disabled') . '> <span>' . e($text) . '</span></label>';
    }
    $html .= '</div>';
    $err = nf_err($errKey);
    return '<fieldset class="field npr-field is-' . e($color) . ($err !== '' ? ' has-error' : '') . '"><legend class="field-label">' . e($label) . '</legend>' . $html
        . ($hint !== '' ? '<p class="field-hint">' . e($hint) . '</p>' : '') . $err . '</fieldset>';
}

/** Checkbox tunggal + hidden 0. */
function nf_bool(string $color, string $name, string $label, mixed $value, bool $editable, array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . e($k) . '="' . e($v) . '"';
    }
    return '<div class="field npr-field npr-bool is-' . e($color) . '">'
        . ($editable ? '<input type="hidden" name="' . e($name) . '" value="0">' : '')
        . '<label class="check"><input type="checkbox" id="' . e(nf_id($name)) . '" name="' . e($name) . '" value="1"' . ((int) $value === 1 ? ' checked' : '') . ($editable ? '' : ' disabled') . $a . '> <span>' . e($label) . '</span></label></div>';
}

/** Pilihan radio (mis. Ada / Tidak ada). @param array<string,string> $options */
function nf_radio(string $color, string $name, string $label, mixed $value, array $options, bool $editable, string $errKey): string
{
    $html = '<div class="radio-row" role="radiogroup" aria-label="' . e($label) . '">';
    foreach ($options as $code => $text) {
        $html .= '<label class="check"><input type="radio" name="' . e($name) . '" value="' . e($code) . '"' . ((string) $value === (string) $code ? ' checked' : '') . ($editable ? '' : ' disabled') . '> <span>' . e($text) . '</span></label>';
    }
    $html .= '</div>';
    $err = nf_err($errKey);
    return '<fieldset class="field npr-field is-' . e($color) . ($err !== '' ? ' has-error' : '') . '"><legend class="field-label">' . e($label) . '</legend>' . $html . $err . '</fieldset>';
}

/**
 * @param list<array<string,mixed>>|array<string,string> $options
 * @return array<string,string>
 */
function nf_normalize_options(array $options): array
{
    $out = [];
    foreach ($options as $k => $o) {
        if (is_array($o)) {
            $out[(string) $o['code']] = (string) (I18n::locale() === 'en' ? $o['label_en'] : $o['label_id']);
        } else {
            $out[(string) $k] = (string) $o;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function nf_options(string $category): array
{
    return MasterService::options($category);
}
