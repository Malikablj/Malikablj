<?php
declare(strict_types=1);

/**
 * Helper global untuk view. Semua output HTML WAJIB lewat e() (htmlspecialchars, ENT_QUOTES, UTF-8).
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Response;
use App\Core\User;

/** Escape untuk konteks HTML (teks & atribut). */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Teks terjemahan yang SUDAH di-escape untuk HTML. */
function t(string $key, array $params = []): string
{
    return e(I18n::t($key, $params));
}

/** Teks terjemahan mentah (untuk JSON / atribut yang di-escape terpisah). */
function tr(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

/** JSON aman untuk disisipkan di atribut data-* atau <script type="application/json">. */
function json_attr(mixed $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
}

function url(string $path = '', array $query = []): string
{
    $u = Response::url($path);
    if ($query !== []) {
        $u .= (str_contains($u, '?') ? '&' : '?') . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return Response::url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function current_user(): ?User
{
    return Auth::user();
}

function fmt_date(?string $date): string
{
    return e(I18n::date($date));
}

function fmt_datetime(?string $date): string
{
    return e(I18n::dateTime($date));
}

function fmt_number(mixed $n, int $decimals = 0): string
{
    return e(I18n::number($n, $decimals));
}

/** Nilai form sebelumnya (setelah validasi gagal) atau nilai bawaan. */
function old(string $key, mixed $default = ''): string
{
    $old = $_SESSION['_old_input'] ?? [];
    $v = is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    return is_scalar($v) || $v === null ? (string) $v : '';
}

/** Pesan error validasi per field (setelah redirect). */
function field_error(string $key): string
{
    $errors = $_SESSION['_errors'] ?? [];
    if (!is_array($errors) || empty($errors[$key])) {
        return '';
    }
    return '<p class="field-error" role="alert">' . e($errors[$key]) . '</p>';
}

/** Simpan input + error ke sesi untuk ditampilkan ulang (PRG). */
function remember_form(array $input, array $errors): void
{
    unset($input['_csrf'], $input['password'], $input['password_confirmation'], $input['current_password'], $input['new_password']);
    $_SESSION['_old_input'] = $input;
    $_SESSION['_errors'] = $errors;
}

function clear_form_state(): void
{
    unset($_SESSION['_old_input'], $_SESSION['_errors']);
}

/** Ikon SVG dari sprite (assets/images/icons.svg). */
function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(Response::url('assets/images/icons.svg')) . '#i-' . e($name) . '"></use></svg>';
}

/** Lencana status dengan teks (tidak hanya warna — PRD §11.8). */
function status_badge(string $status, string $domain = 'status'): string
{
    $tone = match ($status) {
        'completed', 'approved', 'pass', 'feedback_completed', 'active', 'current_ok' => 'success',
        'overdue', 'problem', 'rejected', 'fail', 'cancelled', 'not_feasible' => 'danger',
        'waiting', 'waiting_approval', 'waiting_external', 'due_soon', 'pending', 'returned', 'revision', 'needs_revision', 'submitted' => 'warning',
        'current', 'on_progress', 'in_progress' => 'accent',
        'hold', 'skipped', 'inactive', 'draft', 'not_started', 'archived' => 'neutral',
        default => 'neutral',
    };
    $label = I18n::has($domain . '.' . $status) ? I18n::t($domain . '.' . $status) : $status;
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

function role_label(string $roleCode): string
{
    return e(I18n::t('role.' . $roleCode));
}

/** Atribut aria-current untuk navigasi aktif. */
function nav_active(string $current, string $item): string
{
    return $current === $item ? ' aria-current="page" class="active"' : '';
}
