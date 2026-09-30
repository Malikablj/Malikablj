<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Models\PrStatus;
use App\Models\Role;

/**
 * Escape output HTML. Semua data dinamis di view wajib melewati fungsi ini.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @param array<string, mixed> $query
 */
function url(string $path = '/', array $query = []): string
{
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    $path = '/' . ltrim($path, '/');

    return Request::basePath() . $path . ($query ? '?' . http_build_query($query) : '');
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';

    return url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

/**
 * @return array<string, mixed>|null
 */
function auth_user(): ?array
{
    return Auth::user();
}

function is_admin(?array $user = null): bool
{
    $user ??= Auth::user();

    return $user !== null && Role::isAdmin((string) $user['role']);
}

function flash(string $key): mixed
{
    return Session::getFlash($key);
}

/**
 * Input lama setelah validasi gagal. Mendukung dot notation, mis. old('items.0.name').
 */
function old(string $key, mixed $default = ''): mixed
{
    $value = Session::getFlash('old', []);
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function has_old(): bool
{
    return Session::getFlash('old') !== null;
}

/**
 * @return array<string, string>
 */
function errors(): array
{
    return (array) Session::getFlash('errors', []);
}

function error_for(string $field): string
{
    $message = errors()[$field] ?? null;
    if ($message === null) {
        return '';
    }

    return '<p class="field-error" id="' . e('err-' . str_replace('.', '-', $field)) . '">' . e($message) . '</p>';
}

function invalid(string $field): string
{
    return isset(errors()[$field]) ? ' aria-invalid="true"' : '';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

/**
 * Format Rupiah dari string DECIMAL tanpa konversi float: "280000.00" => "Rp280.000".
 */
function money(mixed $value, bool $prefix = true): string
{
    $value = trim((string) ($value ?? '0'));
    $negative = str_starts_with($value, '-');
    [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
    $integer = ltrim($integer, '0') ?: '0';
    $grouped = strrev(implode('.', str_split(strrev($integer), 3)));
    $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
    $formatted = $grouped . ($fraction !== '00' ? ',' . $fraction : '');

    return ($negative ? '-' : '') . ($prefix ? 'Rp' : '') . $formatted;
}

/**
 * Format angka quantity/persen: "2.00" => "2", "1.50" => "1,5".
 */
function number_id(mixed $value): string
{
    $value = trim((string) ($value ?? '0'));
    [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $integer = ltrim($integer, '0') ?: '0';
    $grouped = strrev(implode('.', str_split(strrev($integer), 3)));
    $fraction = rtrim($fraction, '0');

    return $grouped . ($fraction !== '' ? ',' . $fraction : '');
}

function tanggal(?string $value, bool $withTime = false): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '-';
    }
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $text = date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);

    return $withTime ? $text . ', ' . date('H:i', $timestamp) : $text;
}

function tanggal_panjang(?string $value): string
{
    if ($value === null || $value === '' || ($timestamp = strtotime($value)) === false) {
        return '-';
    }
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    return date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}

function time_ago(?string $value): string
{
    if ($value === null || ($timestamp = strtotime($value)) === false) {
        return '-';
    }
    $diff = time() - $timestamp;

    return match (true) {
        $diff < 60 => 'baru saja',
        $diff < 3600 => intdiv($diff, 60) . ' menit lalu',
        $diff < 86400 => intdiv($diff, 3600) . ' jam lalu',
        $diff < 7 * 86400 => intdiv($diff, 86400) . ' hari lalu',
        default => tanggal($value, true),
    };
}

function status_label(string $status): string
{
    return PrStatus::tryFrom($status)?->label() ?? $status;
}

function status_badge(string $status): string
{
    $case = PrStatus::tryFrom($status);
    $label = $case?->label() ?? $status;
    $title = $case?->description() ?? '';

    return '<span class="badge badge-' . e(str_replace('_', '-', $status)) . '" title="' . e($title) . '">'
        . '<span class="badge-dot" aria-hidden="true"></span>' . e($label) . '</span>';
}

function role_label(string $role): string
{
    return Role::tryFrom($role)?->label() ?? $role;
}

function pr_label(array $pr): string
{
    return (string) ($pr['pr_number'] ?? '') !== '' ? (string) $pr['pr_number'] : 'Draft #' . $pr['id'];
}

function current_path(): string
{
    return Request::current()?->path ?? '/';
}

function nav_active(string $prefix): string
{
    $path = current_path();
    $active = $prefix === '/' ? $path === '/' : ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/'));

    return $active ? ' is-active' : '';
}

/**
 * URL halaman saat ini dengan parameter query yang diganti (untuk filter & pagination).
 *
 * @param array<string, mixed> $overrides
 */
function query_url(array $overrides): string
{
    $request = Request::current();
    $query = array_merge($request?->query ?? [], $overrides);

    return url($request?->path ?? '/', $query);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : '?';
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_id(number_format($bytes / 1048576, 1, '.', '')) . ' MB';
    }

    return number_id((string) max(1, (int) round($bytes / 1024))) . ' KB';
}

/**
 * Ikon garis sederhana (SVG inline, tanpa dependency eksternal).
 */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
        'document' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'rotate' => '<path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5"/><path d="M20 4v4.5h-4.5"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 15.5"/><path d="M4 20v-4.5h4.5"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 20a6.5 6.5 0 0 0-3-5.5"/>',
        'building' => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16"/><path d="M15 9h4a1 1 0 0 1 1 1v11"/><path d="M3 21h18"/><path d="M8 8h3M8 12h3M8 16h3"/>',
        'truck' => '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/>',
        'box' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'flow' => '<rect x="3" y="4" width="6" height="5" rx="1"/><rect x="15" y="4" width="6" height="5" rx="1"/><rect x="9" y="15" width="6" height="5" rx="1"/><path d="M6 9v2.5h12V9M12 11.5V15"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 6.2l2.1 2.1M17.7 15.7l2.1 2.1M2.5 12h3M18.5 12h3M4.2 17.8l2.1-2.1M17.7 8.3l2.1-2.1"/>',
        'logout' => '<path d="M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'download' => '<path d="M12 4v11M7 10.5l5 4.5 5-4.5"/><path d="M5 20h14"/>',
        'upload' => '<path d="M12 16V5M7 9.5 12 5l5 4.5"/><path d="M5 20h14"/>',
        'paperclip' => '<path d="m20 11.5-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.4 8.4a1.7 1.7 0 0 1-2.4-2.4l7.6-7.6"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4"/>',
        'send' => '<path d="M21 3 10 14"/><path d="m21 3-7 18-4-7-7-4z"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>',
        'inbox' => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5 5h14l2 8v6H3v-6z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'filter' => '<path d="M4 5h16l-6 7.5V19l-4 1v-7.5z"/>',
        'printer' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1.5"/><path d="M7 14h10v7H7z"/>',
        'toggle' => '<rect x="2" y="7" width="20" height="10" rx="5"/><circle cx="16" cy="12" r="3"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    ];
    $body = $paths[$name] ?? $paths['document'];

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/**
 * Jumlah notifikasi belum dibaca & approval tertunda untuk badge navigasi.
 *
 * @return array{notifications: int, approvals: int}
 */
function nav_counts(): array
{
    $user = auth_user();
    if ($user === null) {
        return ['notifications' => 0, 'approvals' => 0];
    }

    return [
        'notifications' => (new \App\Repositories\NotificationRepository())->unreadCount((int) $user['id']),
        'approvals' => $user['role'] === 'requester' ? 0 : (new \App\Repositories\PrRepository())->countPendingApprovals($user),
    ];
}
