<?php
declare(strict_types=1);

namespace App\Npr;

use App\Core\I18n;
use App\Master\MasterService;

/**
 * Definisi field NPR (PIK-FORM-NPD-01 rev 00 — PRD Lampiran A) sebagai satu sumber kebenaran
 * untuk validasi, snapshot Revision History, form, dan PDF.
 *
 * Warna: SALES (biru) = npr + npr_parts; NPD (pink) = npr_feedback.
 */
final class NprFields
{
    public const OTHER_PART = '__other';
    public const PART_TYPES = ['new_mold', 'subcont'];
    public const DECISIONS = ['feasible', 'feasible_with_notes', 'needs_revision', 'not_feasible'];
    public const ATTACH_VALUES = ['ada', 'tidak_ada'];

    /**
     * Field header (biru) — key => [tipe, kategori master|null, label key]
     * tipe: text, textarea, multi, select, decimal, int, bool, date, enum
     */
    public const HEADER = [
        'product_name' => ['text', null, 'npr.f.product_name'],
        'request_types_json' => ['multi', 'request_type', 'npr.f.request_types'],
        'request_type_other' => ['text', null, 'npr.f.request_type_other'],
        'customer_id' => ['customer', null, 'npr.f.customer'],
        'invoice_address' => ['textarea', null, 'npr.f.invoice_address'],
        'shipping_address' => ['textarea', null, 'npr.f.shipping_address'],
        'phone' => ['text', null, 'npr.f.phone'],
        'product_applications_json' => ['multi', 'product_application', 'npr.f.product_applications'],
        'product_contents_json' => ['multi', 'product_content', 'npr.f.product_contents'],
        'product_content_other' => ['text', null, 'npr.f.product_content_other'],
        'net_volume_ml' => ['decimal', null, 'npr.f.net_volume_ml'],
        'net_weight_gr' => ['decimal', null, 'npr.f.net_weight_gr'],
        'deco_printing' => ['bool', null, 'npr.f.deco_printing'],
        'deco_printing_method' => ['select', 'printing_method', 'npr.f.deco_printing_method'],
        'deco_printing_components' => ['text', null, 'npr.f.deco_printing_components'],
        'deco_varnish' => ['select', 'varnish', 'npr.f.deco_varnish'],
        'deco_varnish_components' => ['text', null, 'npr.f.deco_varnish_components'],
        'deco_labelling' => ['bool', null, 'npr.f.deco_labelling'],
        'deco_labelling_side' => ['select', 'labelling_side', 'npr.f.deco_labelling_side'],
        'deco_labelling_components' => ['text', null, 'npr.f.deco_labelling_components'],
        'deco_shrink' => ['bool', null, 'npr.f.deco_shrink'],
        'deco_shrink_components' => ['text', null, 'npr.f.deco_shrink_components'],
        'qty_per_month' => ['int', null, 'npr.f.qty_per_month'],
        'qty_per_year' => ['int', null, 'npr.f.qty_per_year'],
        'packaging_json' => ['multi', 'packaging', 'npr.f.packaging'],
        'packaging_other' => ['text', null, 'npr.f.packaging_other'],
        'test_methods_json' => ['tests', 'test_method', 'npr.f.test_methods'],
        'test_refer_spec_doc' => ['bool', null, 'npr.f.test_refer_spec_doc'],
        'test_other' => ['text', null, 'npr.f.test_other'],
        'regulation_compliance' => ['enum', null, 'npr.f.regulation_compliance'],
        'regulation_note' => ['textarea', null, 'npr.f.regulation_note'],
        'attach_sample' => ['attach', null, 'npr.f.attach_sample'],
        'attach_technical_drawing' => ['attach', null, 'npr.f.attach_technical_drawing'],
        'attach_mockup' => ['attach', null, 'npr.f.attach_mockup'],
        'note' => ['textarea', null, 'npr.f.note'],
        'launching_target' => ['date', null, 'npr.f.launching_target'],
    ];

    /** Field part (biru) */
    public const PART = [
        'part_name_code' => ['part_name', 'part_name', 'npr.f.part_name'],
        'part_name_custom' => ['text', null, 'npr.f.part_name_custom'],
        'part_type' => ['part_type', null, 'npr.f.part_type'],
        'development_type' => ['select', 'development_type', 'npr.f.development_type'],
        'mold_supplier' => ['text', null, 'npr.f.mold_supplier'],
        'is_external_component' => ['bool', null, 'npr.f.is_external_component'],
        'external_note' => ['text', null, 'npr.f.external_note'],
        'resin_code' => ['select', 'resin', 'npr.f.resin'],
        'color_code' => ['select', 'color', 'npr.f.color'],
        'pantone' => ['text', null, 'npr.f.pantone'],
        'surface_code' => ['select', 'surface', 'npr.f.surface'],
        'neck_preform_code' => ['select', 'neck_preform', 'npr.f.neck_preform'],
    ];

    /** Field feedback per part (pink) */
    public const FEEDBACK = [
        'weight_gr' => ['decimal', null, 'npr.f.weight_gr'],
        'mould_method_code' => ['select', 'mould_method', 'npr.f.mould_method'],
        'mould_method_other' => ['text', null, 'npr.f.mould_method_other'],
        'cavity' => ['int', null, 'npr.f.cavity'],
        'mould_price_pik_pct' => ['decimal', null, 'npr.f.mould_price_pik_pct'],
        'mould_price_cust_pct' => ['decimal', null, 'npr.f.mould_price_cust_pct'],
        'mould_lead_time_days' => ['int', null, 'npr.f.mould_lead_time_days'],
        'mould_lead_time_note' => ['text', null, 'npr.f.mould_lead_time_note'],
        'needs_new_masterbatch' => ['nbool', null, 'npr.f.needs_new_masterbatch'],
        'feedback_text' => ['textarea', null, 'npr.f.feedback_text'],
        'decision' => ['decision', null, 'npr.f.decision'],
        'decision_reason' => ['textarea', null, 'npr.f.decision_reason'],
    ];

    private const MAX_TEXT = 255;
    private const MAX_TEXTAREA = 5000;

    /**
     * Normalisasi & validasi FORMAT (bukan kelengkapan) nilai header dari input pengguna.
     * Kunci yang tidak dikenal diabaikan. Mengembalikan [data, errors].
     *
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function cleanHeader(array $input): array
    {
        $data = [];
        $errors = [];
        foreach (self::HEADER as $key => [$type, $cat]) {
            $inKey = str_ends_with($key, '_json') ? substr($key, 0, -5) : $key;
            // Field yang tidak dikirim tidak diubah. Form mengirim hidden input untuk checkbox/multi
            // (nilai "0" / "") sehingga "tidak dicentang" tetap terkirim secara eksplisit.
            if (!array_key_exists($inKey, $input)) {
                continue;
            }
            $raw = $input[$inKey] ?? null;
            try {
                $data[$key] = self::cleanValue($type, $cat, $raw);
            } catch (\InvalidArgumentException) {
                $errors['npr.' . $inKey] = I18n::t('validation.invalid');
            }
        }
        return [$data, $errors];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function cleanPart(array $input, string $errorPrefix): array
    {
        $data = [];
        $errors = [];
        foreach (self::PART as $key => [$type, $cat]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            try {
                $data[$key] = self::cleanValue($type, $cat, $input[$key] ?? null);
            } catch (\InvalidArgumentException) {
                $errors[$errorPrefix . $key] = I18n::t('validation.invalid');
            }
        }
        return [$data, $errors];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function cleanFeedback(array $input, string $errorPrefix): array
    {
        $data = [];
        $errors = [];
        foreach (self::FEEDBACK as $key => [$type, $cat]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            try {
                $data[$key] = self::cleanValue($type, $cat, $input[$key]);
            } catch (\InvalidArgumentException) {
                $errors[$errorPrefix . $key] = I18n::t('validation.invalid');
            }
        }
        foreach (['mould_price_pik_pct', 'mould_price_cust_pct'] as $pct) {
            if (isset($data[$pct]) && $data[$pct] !== null && ((float) $data[$pct] < 0 || (float) $data[$pct] > 100)) {
                $errors[$errorPrefix . $pct] = I18n::t('npr.v.pct_range');
            }
        }
        return [$data, $errors];
    }

    private static function cleanValue(string $type, ?string $cat, mixed $raw): mixed
    {
        $str = is_scalar($raw) ? trim((string) $raw) : '';
        switch ($type) {
            case 'text':
                return $str === '' ? null : mb_substr($str, 0, self::MAX_TEXT);
            case 'textarea':
                return $str === '' ? null : mb_substr($str, 0, self::MAX_TEXTAREA);
            case 'bool':
                return in_array($str, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
            case 'nbool':
                if ($str === '') {
                    return null;
                }
                return in_array($str, ['1', 'yes', 'true'], true) ? 1 : 0;
            case 'int':
                if ($str === '') {
                    return null;
                }
                if (!preg_match('/^\d{1,9}$/', str_replace(['.', ','], '', $str))) {
                    throw new \InvalidArgumentException();
                }
                return (int) str_replace(['.', ','], '', $str);
            case 'decimal':
                if ($str === '') {
                    return null;
                }
                $norm = str_replace(',', '.', $str);
                if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $norm)) {
                    throw new \InvalidArgumentException();
                }
                return $norm;
            case 'date':
                if ($str === '') {
                    return null;
                }
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $str);
                if (!$d || $d->format('Y-m-d') !== $str) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'select':
                if ($str === '') {
                    return null;
                }
                if (!MasterService::isValid((string) $cat, $str, false)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'part_name':
                if ($str === '') {
                    return null;
                }
                if ($str !== self::OTHER_PART && !MasterService::isValid('part_name', $str, false)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'part_type':
                if ($str === '') {
                    return null;
                }
                if (!in_array($str, self::PART_TYPES, true)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'decision':
                if ($str === '') {
                    return null;
                }
                if (!in_array($str, self::DECISIONS, true)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'enum':
                if ($str === '') {
                    return null;
                }
                if (!in_array($str, ['no', 'yes'], true)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'attach':
                if ($str === '') {
                    return null;
                }
                if (!in_array($str, self::ATTACH_VALUES, true)) {
                    throw new \InvalidArgumentException();
                }
                return $str;
            case 'customer':
                if ($str === '') {
                    return null;
                }
                if (!preg_match('/^\d{1,10}$/', $str)) {
                    throw new \InvalidArgumentException();
                }
                return (int) $str;
            case 'multi':
                $vals = is_array($raw) ? $raw : [];
                $out = [];
                foreach ($vals as $v) {
                    $v = is_scalar($v) ? trim((string) $v) : '';
                    if ($v === '') {
                        continue;
                    }
                    if (!MasterService::isValid((string) $cat, $v, false)) {
                        throw new \InvalidArgumentException();
                    }
                    $out[$v] = true;
                }
                return json_encode(array_keys($out));
            case 'tests':
                // input: test_methods[code][checked]=1, test_methods[code][value]=.., test_methods[code][unit]=..
                $vals = is_array($raw) ? $raw : [];
                $out = [];
                foreach ($vals as $code => $item) {
                    if (!is_array($item) || empty($item['checked'])) {
                        continue;
                    }
                    $code = (string) $code;
                    if (!MasterService::isValid('test_method', $code, false)) {
                        throw new \InvalidArgumentException();
                    }
                    $units = MasterService::meta('test_method', $code)['units'] ?? [];
                    $unit = isset($item['unit']) && is_scalar($item['unit']) ? trim((string) $item['unit']) : '';
                    if ($unit !== '' && !in_array($unit, $units, true)) {
                        throw new \InvalidArgumentException();
                    }
                    $value = isset($item['value']) && is_scalar($item['value']) ? mb_substr(trim((string) $item['value']), 0, 60) : '';
                    $out[] = ['code' => $code, 'value' => $value, 'unit' => $unit !== '' ? $unit : ($units[0] ?? '')];
                }
                return json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        throw new \LogicException('Tipe field tidak dikenal: ' . $type);
    }

    /** Part adalah "Neck dan Preform" (pilihan neck menggantikan resin/warna/surface). */
    public static function isNeckPart(array $part): bool
    {
        $code = (string) ($part['part_name_code'] ?? '');
        return $code !== '' && $code !== self::OTHER_PART && !empty(MasterService::meta('part_name', $code)['neck_preform']);
    }

    /** Nama part tampil (master sesuai bahasa form = Indonesia, atau teks bebas). */
    public static function partDisplayName(array $part, string $locale = 'id'): string
    {
        $code = (string) ($part['part_name_code'] ?? '');
        if ($code === self::OTHER_PART || $code === '') {
            return (string) ($part['part_name_custom'] ?? '');
        }
        return MasterService::label('part_name', $code, $locale);
    }

    /** @return list<string> */
    public static function decode(?string $json): array
    {
        $v = $json ? json_decode($json, true) : null;
        return is_array($v) ? $v : [];
    }

    /**
     * Kelengkapan kolom biru saat Kirim (kolom "Wajib" Lampiran A).
     * @param array<string,mixed> $npr
     * @param list<array<string,mixed>> $parts  part aktif
     * @return array<string,string> field => pesan
     */
    public static function missingForSubmit(array $npr, array $parts): array
    {
        $req = I18n::t('validation.required');
        $e = [];
        foreach (['product_name', 'customer_id', 'invoice_address', 'shipping_address', 'phone', 'qty_per_month', 'qty_per_year',
                  'regulation_compliance', 'attach_sample', 'attach_technical_drawing', 'attach_mockup'] as $k) {
            if ($npr[$k] === null || $npr[$k] === '') {
                $e['npr.' . $k] = $req;
            }
        }
        foreach (['request_types' => 'request_type_other', 'product_applications' => null, 'product_contents' => 'product_content_other', 'packaging' => 'packaging_other'] as $multi => $other) {
            $vals = self::decode($npr[$multi . '_json'] ?? null);
            if ($vals === []) {
                $e['npr.' . $multi] = I18n::t('npr.v.choose_one');
            } elseif ($other !== null && in_array('lain_lain', $vals, true) && trim((string) ($npr[$other] ?? '')) === '') {
                $e['npr.' . $other] = I18n::t('npr.v.other_required');
            }
        }
        if (($npr['net_volume_ml'] === null || $npr['net_volume_ml'] === '') && ($npr['net_weight_gr'] === null || $npr['net_weight_gr'] === '')) {
            $e['npr.net_volume_ml'] = I18n::t('npr.v.net_one');
        }
        if ($npr['regulation_compliance'] === 'yes' && trim((string) $npr['regulation_note']) === '') {
            $e['npr.regulation_note'] = I18n::t('npr.v.regulation_note');
        }
        if ((int) $npr['deco_printing'] === 1) {
            if (!$npr['deco_printing_method']) {
                $e['npr.deco_printing_method'] = $req;
            }
            if (!$npr['deco_printing_components']) {
                $e['npr.deco_printing_components'] = $req;
            }
        }
        if ((int) $npr['deco_labelling'] === 1) {
            if (!$npr['deco_labelling_side']) {
                $e['npr.deco_labelling_side'] = $req;
            }
            if (!$npr['deco_labelling_components']) {
                $e['npr.deco_labelling_components'] = $req;
            }
        }
        if ((int) $npr['deco_shrink'] === 1 && !$npr['deco_shrink_components']) {
            $e['npr.deco_shrink_components'] = $req;
        }
        $tests = self::decode($npr['test_methods_json'] ?? null);
        foreach ($tests as $t) {
            if (($t['code'] ?? '') === 'lain_lain' && trim((string) ($npr['test_other'] ?? '')) === '') {
                $e['npr.test_other'] = I18n::t('npr.v.other_required');
            }
        }
        if ($parts === []) {
            $e['parts'] = I18n::t('npr.v.min_one_part');
        }
        foreach ($parts as $p) {
            $e += self::missingForPart($p, 'parts.' . $p['id'] . '.');
        }
        return $e;
    }

    /**
     * Kelengkapan kolom biru satu part.
     * @param array<string,mixed> $p
     * @return array<string,string>
     */
    public static function missingForPart(array $p, string $pre): array
    {
        $req = I18n::t('validation.required');
        $e = [];
        $code = (string) ($p['part_name_code'] ?? '');
        if ($code === '') {
            $e[$pre . 'part_name_code'] = $req;
        } elseif ($code === self::OTHER_PART && trim((string) ($p['part_name_custom'] ?? '')) === '') {
            $e[$pre . 'part_name_custom'] = $req;
        }
        if (empty($p['part_type'])) {
            $e[$pre . 'part_type'] = $req;
        }
        if (empty($p['development_type'])) {
            $e[$pre . 'development_type'] = $req;
        } elseif (!empty(MasterService::meta('development_type', (string) $p['development_type'])['requires_supplier']) && trim((string) ($p['mold_supplier'] ?? '')) === '') {
            $e[$pre . 'mold_supplier'] = I18n::t('npr.v.supplier_required');
        }
        if (self::isNeckPart($p)) {
            if (empty($p['neck_preform_code'])) {
                $e[$pre . 'neck_preform_code'] = $req;
            }
        } else {
            foreach (['resin_code', 'color_code', 'surface_code'] as $k) {
                if (empty($p[$k])) {
                    $e[$pre . $k] = $req;
                }
            }
        }
        return $e;
    }

    /**
     * Kelengkapan kolom pink per part sesuai keputusan (saat Selesaikan Feedback).
     * @param array<string,mixed> $part
     * @param array<string,mixed>|null $fb
     * @return array<string,string>
     */
    public static function missingFeedback(array $part, ?array $fb): array
    {
        $pre = 'feedback.' . $part['id'] . '.';
        $req = I18n::t('validation.required');
        $e = [];
        $decision = $fb['decision'] ?? null;
        if (!$decision) {
            return [$pre . 'decision' => I18n::t('npr.v.decision_required')];
        }
        if (trim((string) ($fb['feedback_text'] ?? '')) === '') {
            $e[$pre . 'feedback_text'] = $req;
        }
        if ($decision === 'not_feasible' && trim((string) ($fb['decision_reason'] ?? '')) === '') {
            $e[$pre . 'decision_reason'] = I18n::t('npr.v.reason_not_feasible');
        }
        if (in_array($decision, ['feasible', 'feasible_with_notes'], true)) {
            if ($fb['weight_gr'] === null || $fb['weight_gr'] === '') {
                $e[$pre . 'weight_gr'] = $req;
            }
            // Kebutuhan mould wajib bila part membutuhkan mould baru / modifikasi (OQ-22)
            if (in_array($part['development_type'], ['new_mould', 'modify_mould'], true)) {
                foreach (['mould_method_code', 'cavity', 'mould_price_pik_pct', 'mould_price_cust_pct', 'mould_lead_time_days'] as $k) {
                    if ($fb[$k] === null || $fb[$k] === '') {
                        $e[$pre . $k] = $req;
                    }
                }
                if (($fb['mould_method_code'] ?? '') === 'other' && trim((string) $fb['mould_method_other']) === '') {
                    $e[$pre . 'mould_method_other'] = I18n::t('npr.v.other_required');
                }
            }
            if ($part['part_type'] === 'new_mold' && $fb['needs_new_masterbatch'] === null) {
                $e[$pre . 'needs_new_masterbatch'] = I18n::t('npr.v.masterbatch_required');
            }
        }
        $pik = $fb['mould_price_pik_pct'] ?? null;
        $cust = $fb['mould_price_cust_pct'] ?? null;
        if (($pik !== null && $pik !== '') || ($cust !== null && $cust !== '')) {
            if (abs(((float) $pik + (float) $cust) - 100.0) > 0.001) {
                $e[$pre . 'mould_price_cust_pct'] = I18n::t('npr.v.pct_sum');
            }
        }
        return $e;
    }

    /**
     * Snapshot kolom biru (untuk Revision History & deteksi "Perlu ditinjau ulang").
     * @param array<string,mixed> $npr
     * @param list<array<string,mixed>> $parts
     * @return array{header:array<string,mixed>,parts:array<string,array<string,mixed>>}
     */
    public static function snapshot(array $npr, array $parts): array
    {
        $header = [];
        foreach (array_keys(self::HEADER) as $k) {
            $header[$k] = $npr[$k] ?? null;
        }
        $ps = [];
        foreach ($parts as $p) {
            if (($p['status'] ?? 'active') !== 'active') {
                continue;
            }
            $row = [];
            foreach (array_keys(self::PART) as $k) {
                $row[$k] = isset($p[$k]) ? (is_numeric($p[$k]) && !is_string($p[$k]) ? (string) $p[$k] : $p[$k]) : null;
            }
            $ps[(string) $p['id']] = $row;
        }
        return ['header' => $header, 'parts' => $ps];
    }

    /**
     * Perbedaan dua snapshot.
     * @param array{header:array<string,mixed>,parts:array<string,array<string,mixed>>} $old
     * @param array{header:array<string,mixed>,parts:array<string,array<string,mixed>>} $new
     * @return array{header:list<array{field:string,old:mixed,new:mixed}>,parts:array<string,array{status:string,fields:list<array{field:string,old:mixed,new:mixed}>}>}
     */
    public static function diff(array $old, array $new): array
    {
        $norm = static fn ($v) => $v === null ? '' : (string) $v;
        $h = [];
        foreach ($new['header'] as $k => $v) {
            if ($norm($old['header'][$k] ?? null) !== $norm($v)) {
                $h[] = ['field' => $k, 'old' => $old['header'][$k] ?? null, 'new' => $v];
            }
        }
        $p = [];
        foreach ($new['parts'] as $id => $fields) {
            if (!isset($old['parts'][$id])) {
                $p[$id] = ['status' => 'added', 'fields' => []];
                continue;
            }
            $changes = [];
            foreach ($fields as $k => $v) {
                if ($norm($old['parts'][$id][$k] ?? null) !== $norm($v)) {
                    $changes[] = ['field' => $k, 'old' => $old['parts'][$id][$k] ?? null, 'new' => $v];
                }
            }
            if ($changes) {
                $p[$id] = ['status' => 'changed', 'fields' => $changes];
            }
        }
        foreach ($old['parts'] as $id => $fields) {
            if (!isset($new['parts'][$id])) {
                $p[$id] = ['status' => 'removed', 'fields' => []];
            }
        }
        return ['header' => $h, 'parts' => $p];
    }

    /** Label field (untuk tampilan diff). */
    public static function label(string $field, ?string $locale = null): string
    {
        $def = self::HEADER[$field] ?? self::PART[$field] ?? self::FEEDBACK[$field] ?? null;
        return $def ? I18n::t($def[2], [], $locale) : $field;
    }

    /** Nilai tampil untuk diff/PDF (kode master → label). */
    public static function display(string $field, mixed $value, ?string $locale = null): string
    {
        if ($value === null || $value === '') {
            return '–';
        }
        $def = self::HEADER[$field] ?? self::PART[$field] ?? self::FEEDBACK[$field] ?? null;
        if (!$def) {
            return (string) $value;
        }
        [$type, $cat] = $def;
        return match ($type) {
            'select', 'part_name' => $value === self::OTHER_PART ? I18n::t('npr.other', [], $locale) : MasterService::label((string) $cat, (string) $value, $locale),
            'multi' => implode(', ', array_map(static fn ($c) => MasterService::label((string) $cat, (string) $c, $locale), self::decode((string) $value))),
            'tests' => implode(', ', array_map(static fn ($t) => MasterService::label('test_method', (string) $t['code'], $locale) . ($t['value'] !== '' ? ' ' . $t['value'] . ' ' . $t['unit'] : ''), self::decode((string) $value))),
            'bool', 'nbool' => (int) $value === 1 ? I18n::t('common.yes', [], $locale) : I18n::t('common.no', [], $locale),
            'part_type' => I18n::t('part_type.' . $value, [], $locale),
            'decision' => I18n::t('decision.' . $value, [], $locale),
            'enum' => I18n::t('npr.regulation.' . $value, [], $locale),
            'attach' => I18n::t('npr.attach.' . $value, [], $locale),
            'date' => I18n::date((string) $value, $locale),
            'customer' => (string) (\App\Core\Db::value('SELECT name FROM customers WHERE id = ?', [(int) $value]) ?? $value),
            default => (string) $value,
        };
    }
}
