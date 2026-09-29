<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Komponen field form (Bootstrap) yang konsisten dan sudah di-escape.
 *
 *   <?= Form::input('name', 'Nama customer', old('name', $customer), $errors, ['required' => true, 'maxlength' => 190]) ?>
 *   <?= Form::select('status', 'Status', $statusOptions, old('status', $customer, 'Active'), $errors) ?>
 */
final class Form
{
    /** @param array<string,string> $errors @param array<string,mixed> $opts */
    public static function input(string $name, string $label, ?string $value, array $errors, array $opts = []): string
    {
        $type = (string) ($opts['type'] ?? 'text');
        $id = (string) ($opts['id'] ?? 'f_' . $name);
        $attrs = self::attrs($opts, ['required', 'maxlength', 'min', 'max', 'step', 'placeholder', 'autocomplete', 'pattern', 'inputmode', 'readonly', 'disabled', 'autofocus', 'list']);
        $html = self::open($opts) . self::label($id, $label, $opts);
        $input = '<input type="' . e($type) . '" class="form-control' . invalid($errors, $name) . (isset($opts['class']) ? ' ' . e($opts['class']) : '') . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"' . $attrs . '>';
        if (isset($opts['prefix']) || isset($opts['suffix'])) {
            $input = '<div class="input-group">'
                . (isset($opts['prefix']) ? '<span class="input-group-text">' . e($opts['prefix']) . '</span>' : '')
                . $input
                . (isset($opts['suffix']) ? '<span class="input-group-text">' . e($opts['suffix']) . '</span>' : '')
                . '</div>';
        }
        return $html . $input . self::help($opts) . field_error($errors, $name) . self::close($opts);
    }

    /** @param array<string,string> $errors @param array<string,mixed> $opts */
    public static function textarea(string $name, string $label, ?string $value, array $errors, array $opts = []): string
    {
        $id = (string) ($opts['id'] ?? 'f_' . $name);
        $rows = (int) ($opts['rows'] ?? 3);
        $attrs = self::attrs($opts, ['required', 'maxlength', 'placeholder', 'readonly']);
        return self::open($opts) . self::label($id, $label, $opts)
            . '<textarea class="form-control' . invalid($errors, $name) . '" id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '"' . $attrs . '>' . e($value) . '</textarea>'
            . self::help($opts) . field_error($errors, $name) . self::close($opts);
    }

    /**
     * @param array<string|int,string>|array<string,array<string|int,string>> $options value => label (atau grup => [value => label])
     * @param array<string,string> $errors
     * @param array<string,mixed> $opts
     */
    public static function select(string $name, string $label, array $options, ?string $value, array $errors, array $opts = []): string
    {
        $id = (string) ($opts['id'] ?? 'f_' . $name);
        $attrs = self::attrs($opts, ['required', 'disabled']);
        if (!empty($opts['searchable'])) {
            $attrs .= ' data-searchable="' . e(is_string($opts['searchable']) ? $opts['searchable'] : 'Ketik untuk mencari…') . '"';
        }
        if (!empty($opts['data'])) {
            foreach ((array) $opts['data'] as $k => $v) {
                $attrs .= ' data-' . e($k) . '="' . e($v) . '"';
            }
        }
        $html = self::open($opts) . self::label($id, $label, $opts)
            . '<select class="form-select' . invalid($errors, $name) . '" id="' . e($id) . '" name="' . e($name) . '"' . $attrs . '>';
        if (array_key_exists('placeholder', $opts)) {
            $html .= '<option value="">' . e($opts['placeholder']) . '</option>';
        }
        $html .= self::options($options, $value);
        $html .= '</select>';
        return $html . self::help($opts) . field_error($errors, $name) . self::close($opts);
    }

    /** @param array<string|int,mixed> $options */
    public static function options(array $options, ?string $value): string
    {
        $html = '';
        foreach ($options as $optValue => $optLabel) {
            if (is_array($optLabel)) {
                $html .= '<optgroup label="' . e($optValue) . '">' . self::options($optLabel, $value) . '</optgroup>';
                continue;
            }
            $html .= '<option value="' . e($optValue) . '"' . selected($optValue, $value) . '>' . e($optLabel) . '</option>';
        }
        return $html;
    }

    /** @param array<string,string> $errors @param array<string,mixed> $opts */
    public static function checkbox(string $name, string $label, bool $checked, array $errors, array $opts = []): string
    {
        $id = (string) ($opts['id'] ?? 'f_' . $name);
        return self::open($opts)
            . '<div class="form-check form-switch">'
            . '<input type="hidden" name="' . e($name) . '" value="0">'
            . '<input class="form-check-input' . invalid($errors, $name) . '" type="checkbox" role="switch" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . e($id) . '">' . e($label) . '</label>'
            . '</div>' . self::help($opts) . field_error($errors, $name) . self::close($opts);
    }

    /** Array value=>label dari list nilai (label = nilai). @param list<string> $values @return array<string,string> */
    public static function list(array $values): array
    {
        return array_combine($values, $values) ?: [];
    }

    private static function open(array $opts): string
    {
        $col = $opts['col'] ?? 'col-12';
        return '<div class="' . e($col) . '">';
    }

    private static function close(array $opts): string
    {
        return '</div>';
    }

    private static function label(string $id, string $label, array $opts): string
    {
        if ($label === '') {
            return '';
        }
        return '<label class="form-label" for="' . e($id) . '">' . e($label) . (!empty($opts['required']) ? '<span class="req">*</span>' : '') . '</label>';
    }

    private static function help(array $opts): string
    {
        return isset($opts['help']) ? '<div class="form-text">' . e($opts['help']) . '</div>' : '';
    }

    /** @param list<string> $allowed */
    private static function attrs(array $opts, array $allowed): string
    {
        $html = '';
        foreach ($allowed as $attr) {
            if (!array_key_exists($attr, $opts) || $opts[$attr] === false || $opts[$attr] === null) {
                continue;
            }
            $html .= $opts[$attr] === true ? ' ' . $attr : ' ' . $attr . '="' . e($opts[$attr]) . '"';
        }
        return $html;
    }
}
