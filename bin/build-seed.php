<?php
declare(strict_types=1);

/**
 * Membangun database/seed.sql dari definisi PHP di database/seeds/*.php.
 * seed.sql idempoten: dijalankan ulang tidak menduplikasi data dan tidak menimpa
 * perubahan yang sudah dibuat Admin (pakai ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)).
 *
 *   php bin/build-seed.php            # tulis database/seed.sql
 *   php bin/build-seed.php --stdout   # cetak ke stdout
 */

$root = dirname(__DIR__);
$roles = require $root . '/database/seeds/roles.php';
$permissions = require $root . '/database/seeds/permissions.php';
$masters = require $root . '/database/seeds/masters.php';
$settings = require $root . '/database/seeds/settings.php';
$workflows = require $root . '/database/seeds/workflows.php';

function q(mixed $v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v)) {
        return (string) $v;
    }
    if (is_array($v)) {
        $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";
}

$out = [];
$out[] = '-- =====================================================================';
$out[] = '-- NPD Project Control v3.0 — data awal (GENERATED oleh bin/build-seed.php)';
$out[] = '-- Jangan edit manual: ubah database/seeds/*.php lalu jalankan php bin/build-seed.php';
$out[] = '-- Idempoten: aman dijalankan ulang; tidak menimpa perubahan Admin.';
$out[] = '-- Tidak membuat user. Buat Admin pertama dengan: php bin/create-admin.php';
$out[] = '-- =====================================================================';
$out[] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;';
$out[] = "SET time_zone = '+07:00';";
$out[] = '';

$out[] = '-- Roles';
foreach ($roles as $r) {
    $out[] = sprintf(
        'INSERT INTO roles (code, name_id, name_en, is_read_only, sort_order) VALUES (%s, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE id = id;',
        q($r['code']), q($r['name_id']), q($r['name_en']), $r['is_read_only'], $r['sort_order']
    );
}
$out[] = '';

$out[] = '-- Permissions & role_permissions (matriks PRD §2.3)';
foreach ($permissions as $code => [$module, $desc, $map]) {
    $out[] = sprintf(
        'INSERT INTO permissions (code, module, description) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description);',
        q($code), q($module), q($desc)
    );
    foreach ($map as $role => $scope) {
        $out[] = sprintf(
            'INSERT INTO role_permissions (role_id, permission_id, scope) SELECT r.id, p.id, %s FROM roles r, permissions p WHERE r.code = %s AND p.code = %s ON DUPLICATE KEY UPDATE scope = VALUES(scope);',
            q($scope), q($role), q($code)
        );
    }
}
$out[] = '';

$out[] = '-- Master NPR & tipe dokumen';
foreach ($masters as $category => $items) {
    foreach ($items as $i => [$code, $labelId, $labelEn, $meta]) {
        $out[] = sprintf(
            'INSERT INTO master_options (category, code, label_id, label_en, meta_json, sort_order, is_active, is_builtin) VALUES (%s, %s, %s, %s, %s, %d, 1, 1) ON DUPLICATE KEY UPDATE id = id;',
            q($category), q($code), q($labelId), q($labelEn), q($meta), ($i + 1) * 10
        );
    }
}
$out[] = '';

$out[] = '-- Pengaturan aplikasi';
foreach ($settings as $key => [$value, $type, $desc]) {
    $out[] = sprintf(
        'INSERT INTO application_settings (setting_key, setting_value, value_type, description) VALUES (%s, %s, %s, %s) ON DUPLICATE KEY UPDATE description = VALUES(description);',
        q($key), q($value), q($type), q($desc)
    );
}
$out[] = '';

$out[] = '-- Kalender kerja: Senin–Jumat hari kerja, Sabtu–Minggu libur';
for ($d = 1; $d <= 7; $d++) {
    $out[] = sprintf('INSERT INTO working_calendar (weekday, is_working) VALUES (%d, %d) ON DUPLICATE KEY UPDATE weekday = weekday;', $d, $d <= 5 ? 1 : 0);
}
$out[] = '';

$out[] = '-- Template workflow versi 1 (PRD §5.1)';
foreach ($workflows as $tplCode => $tpl) {
    $out[] = "-- Template: {$tplCode}";
    $out[] = sprintf(
        'INSERT INTO workflow_templates (code, name, scope, part_type, gate_enabled, is_active) VALUES (%s, %s, %s, %s, 1, 1) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);',
        q($tplCode), q($tpl['name']), q($tpl['scope']), q($tpl['part_type'])
    );
    $out[] = 'SET @tpl_id := LAST_INSERT_ID();';
    $out[] = "INSERT INTO workflow_template_versions (template_id, version_no, status, notes, published_at) VALUES (@tpl_id, 1, 'published', 'Template bawaan PRD v3.0', NOW()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);";
    $out[] = 'SET @ver_id := LAST_INSERT_ID();';
    $out[] = 'SET @ver_is_new := (SELECT COUNT(*) = 0 FROM workflow_steps WHERE template_version_id = @ver_id);';
    foreach ($tpl['steps'] as $i => $s) {
        $cols = [
            'template_version_id' => '@ver_id',
            'code' => q($s['code']),
            'name' => q($s['name']),
            'name_en' => q($s['name_en'] ?? null),
            'short_name' => q($s['short_name'] ?? null),
            'description' => q($s['description'] ?? null),
            'step_type' => q($s['step_type'] ?? 'task'),
            'pic_role_id' => '(SELECT id FROM roles WHERE code = ' . q($s['pic_role']) . ')',
            'default_duration' => (string) (int) $s['duration'],
            'is_mandatory' => (string) (int) ($s['is_mandatory'] ?? 1),
            'is_skippable' => (string) (int) ($s['is_skippable'] ?? 0),
            'skip_group' => q($s['skip_group'] ?? null),
            'is_external' => (string) (int) ($s['is_external'] ?? 0),
            'is_customer_approval' => (string) (int) ($s['is_customer_approval'] ?? 0),
            'approval_type' => q($s['approval_type'] ?? null),
            'approval_giver' => q($s['approval_giver'] ?? null),
            'activation' => q($s['activation'] ?? 'auto'),
            'on_complete_reopen_code' => q($s['on_complete_reopen'] ?? null),
            'decision_options_json' => q($s['decisions'] ?? null),
            'required_doc_types_json' => q($s['required_docs'] ?? []),
            'suggested_doc_types_json' => q($s['suggested_docs'] ?? []),
            'record_type' => q($s['record_type'] ?? null),
            'calendar_category' => q($s['calendar_category'] ?? null),
            'is_gate_milestone' => (string) (int) ($s['is_gate_milestone'] ?? 0),
            'sort_order' => (string) (($i + 1) * 10),
            'is_active' => '1',
            'is_builtin' => '1',
        ];
        $out[] = sprintf(
            'INSERT INTO workflow_steps (%s) VALUES (%s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);',
            implode(', ', array_keys($cols)),
            implode(', ', array_values($cols))
        );
        $out[] = 'SET @step_id := LAST_INSERT_ID();';
        foreach ($s['deps'] as $dep) {
            [$pred, $type, $lag] = $dep;
            $onlyGate = (int) ($dep[3] ?? 0);
            // Dependency hanya ditambahkan pada versi yang baru dibuat agar perubahan Admin tidak ditimpa.
            $out[] = sprintf(
                'INSERT INTO workflow_step_dependencies (step_id, predecessor_code, dep_type, lag_days, only_when_gate) SELECT @step_id, %s, %s, %d, %d FROM DUAL WHERE @ver_is_new = 1 ON DUPLICATE KEY UPDATE id = id;',
                q($pred), q($type), $lag, $onlyGate
            );
        }
    }
    $out[] = 'UPDATE workflow_templates SET current_version_id = COALESCE(current_version_id, @ver_id) WHERE id = @tpl_id;';
    $out[] = '';
}

$sql = implode("\n", $out) . "\n";
if (in_array('--stdout', $argv, true)) {
    echo $sql;
} else {
    file_put_contents($root . '/database/seed.sql', $sql);
    fwrite(STDOUT, "database/seed.sql ditulis (" . count($out) . " baris)\n");
}
