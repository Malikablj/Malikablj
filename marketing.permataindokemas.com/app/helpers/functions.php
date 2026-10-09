<?php

declare(strict_types=1);

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Number;
use App\Helpers\Request;
use App\Helpers\View;

/*
 * Fungsi global untuk view & controller.
 * Semua output ke HTML WAJIB melalui e() (escape) kecuali HTML yang dibangun
 * oleh fungsi helper di file ini (yang sudah meng-escape nilainya sendiri).
 */

function config(string $key, mixed $default = null): mixed
{
    static $cache = [];
    $parts = explode('.', $key);
    $file = array_shift($parts);
    if (!array_key_exists($file, $cache)) {
        $path = APP_ROOT . '/config/' . basename($file) . '.php';
        $cache[$file] = is_file($path) ? require $path : [];
    }
    $value = $cache[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Seperti e(), ditambah titik potong baris (<wbr>) setelah "/" — untuk nomor dokumen panjang
 * (mis. OEF/PIK/2026/10/0001) agar turun baris di tempat yang wajar di layar sempit.
 */
function e_wrap(mixed $value): string
{
    return str_replace('/', '/<wbr>', e($value));
}

/** URL internal aplikasi. @param array<string,mixed> $query */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $prefix = Request::basePath();
    if (!config('app.pretty_urls', true)) {
        $prefix .= '/index.php';
    }
    $url = $prefix . ($path === '/' && $prefix !== '' ? '/' : $path);
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    return $url === '' ? '/' : $url;
}

/**
 * Redirect ke path internal aplikasi. Hanya path relatif aplikasi yang
 * diterima (mencegah open redirect ke domain lain).
 */
function redirect(string $path, int $status = 302): never
{
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
        $path = '/';
    }
    $parts = explode('?', $path, 2);
    $target = url($parts[0]) . (isset($parts[1]) && $parts[1] !== '' ? '?' . $parts[1] : '');
    header('Location: ' . $target, true, $status);
    exit;
}

/** URL aset statis di public/ dengan cache-busting berdasarkan waktu file. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = APP_ROOT . '/public/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return Request::basePath() . '/' . $path . '?v=' . $version;
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_token(): string
{
    return Csrf::token();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

/** @return array<string,mixed>|null */
function auth_user(): ?array
{
    return Auth::user();
}

function today(): string
{
    return date('Y-m-d');
}

/**
 * Nilai lama form (setelah validasi gagal) atau nilai record.
 * @param array<string,mixed>|null $record
 */
function old(string $key, ?array $record = null, mixed $default = ''): string
{
    $old = View::$shared['__old'] ?? null;
    if (is_array($old) && array_key_exists($key, $old)) {
        $value = $old[$key];
        return is_scalar($value) ? (string) $value : '';
    }
    if ($record !== null && array_key_exists($key, $record) && $record[$key] !== null) {
        return (string) $record[$key];
    }
    return is_scalar($default) ? (string) $default : '';
}

/** @param array<string,string> $errors */
function field_error(array $errors, string $key): string
{
    if (!isset($errors[$key])) {
        return '';
    }
    return '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>';
}

/** @param array<string,string> $errors */
function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $value): string
{
    return ($value === true || $value === 1 || $value === '1' || $value === 'on') ? ' checked' : '';
}

const ID_MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const ID_MONTHS_LONG = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const ID_DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/** 2026-09-29 => "29 Sep 2026" */
function fmt_date(?string $date, string $empty = '—', bool $long = false): string
{
    if ($date === null || $date === '' || str_starts_with($date, '0000')) {
        return $empty;
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return e($date);
    }
    $months = $long ? ID_MONTHS_LONG : ID_MONTHS;
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function fmt_datetime(?string $datetime, string $empty = '—'): string
{
    if ($datetime === null || $datetime === '') {
        return $empty;
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return e($datetime);
    }
    return fmt_date(date('Y-m-d', $ts)) . ', ' . date('H:i', $ts);
}

function fmt_day(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts === false ? '' : ID_DAYS[(int) date('w', $ts)];
}

/** Keterangan relatif: "Hari ini", "Besok", "3 hari lagi", "2 hari lalu". */
function relative_day(?string $date): string
{
    if (!$date) {
        return '';
    }
    $diff = (int) round((strtotime(substr($date, 0, 10)) - strtotime(today())) / 86400);
    return match (true) {
        $diff === 0 => 'Hari ini',
        $diff === 1 => 'Besok',
        $diff === -1 => 'Kemarin',
        $diff > 1 => $diff . ' hari lagi',
        default => abs($diff) . ' hari lalu',
    };
}

function fmt_qty(mixed $value, string $empty = '—'): string
{
    return Number::qty($value, $empty);
}

function fmt_money(mixed $value, string $empty = '—'): string
{
    return Number::money($value, $empty);
}

/** Warna badge status (sesuai palet PRD). */
function status_tone(?string $status): string
{
    return match ((string) $status) {
        'Active', 'Won', 'Done', 'Closed', 'Delivered', 'Paid', 'Resolved', 'OK', 'Approved' => 'success',
        'Potential', 'Quotation', 'Negotiation', 'Partial', 'Reschedule', 'On Process', 'On Delivery',
        'Needs Review', 'High', 'Delayed', 'Pending' => 'warning',
        'Lost', 'Overdue', 'Cancelled', 'Critical', 'Unpaid', 'Rejected', 'Unresolved' => 'danger',
        'New', 'Contacted', 'Qualified', 'Scheduled', 'Open', 'Planned', 'Auto-Corrected', 'Medium' => 'info',
        default => 'neutral',
    };
}

function status_badge(?string $status, ?string $label = null): string
{
    if ($status === null || $status === '') {
        return '<span class="badge-soft badge-soft-neutral">—</span>';
    }
    return '<span class="badge-soft badge-soft-' . status_tone($status) . '">' . e($label ?? $status) . '</span>';
}

/** Badge konfirmasi PPIC Order Entry Form (NULL = PO lama tanpa konfirmasi). */
function ppic_badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '<span class="badge-soft badge-soft-neutral no-dot" title="Data PO lama, tanpa konfirmasi PPIC">PO lama</span>';
    }
    return status_badge($status, App\Models\PurchaseOrder::PPIC_LABELS[$status] ?? $status);
}

/** Badge status complaint: Open / Selesai / Tidak selesai. */
function complaint_badge(?string $status): string
{
    return status_badge($status === 'Open' ? 'Pending' : $status, App\Models\ProductReturn::STATUS_LABELS[$status] ?? $status);
}

/**
 * Query string halaman saat ini dengan beberapa nilai diganti.
 * @param array<string,mixed> $overrides
 */
function query_with(array $overrides, ?string $path = null): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return url($path ?? Request::path(), $query);
}

/** Link header kolom yang bisa diurutkan. */
function sort_link(string $key, string $label, string $currentSort, string $currentDir): string
{
    $active = $currentSort === $key;
    $dir = $active && $currentDir === 'asc' ? 'desc' : 'asc';
    $icon = $active ? ($currentDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up opacity-25';
    return '<a class="sort-link' . ($active ? ' active' : '') . '" href="' . e(query_with(['sort' => $key, 'dir' => $dir, 'page' => null])) . '">'
        . e($label) . ' <i class="bi ' . $icon . '"></i></a>';
}

/** Inisial nama untuk avatar: "Budi Santoso" => "BS" */
function initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '?';
    }
    $words = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $letters;
}

/** Link eksternal (attachment) yang aman: hanya http/https. */
function external_link(?string $url, string $label = 'Buka link'): string
{
    if ($url === null || $url === '') {
        return '—';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return e($url);
    }
    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . e($label) . ' <i class="bi bi-box-arrow-up-right small"></i></a>';
}

/** Potong teks panjang untuk tabel. */
function excerpt(?string $text, int $length = 80): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

/** Ikon Bootstrap Icons untuk tipe aktivitas / follow up. */
function activity_icon(?string $type): string
{
    return match ((string) $type) {
        'WhatsApp' => 'bi-whatsapp',
        'Phone Call' => 'bi-telephone',
        'Email' => 'bi-envelope',
        'Meeting' => 'bi-people',
        'Visit' => 'bi-geo-alt',
        'Quotation' => 'bi-file-earmark-text',
        'Sample' => 'bi-box2',
        'Presentation' => 'bi-easel',
        'Follow Up' => 'bi-arrow-repeat',
        'Complaint' => 'bi-exclamation-octagon',
        default => 'bi-three-dots',
    };
}

/** Persentase aman untuk progress bar (0–100). */
function pct(float|int $part, float|int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    return (int) max(0, min(100, round($part / $total * 100)));
}

/** URL internal dari path yang boleh berisi query string, mis. "/customers/5?tab=contacts". */
function to(string $pathWithQuery): string
{
    [$path, $query] = array_pad(explode('?', $pathWithQuery, 2), 2, '');
    return url($path) . ($query !== '' ? '?' . $query : '');
}

/** URL lengkap (https://domain/...) untuk link di email. */
function absolute_url(string $path = '/'): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    if ($host === '') {
        $configured = rtrim((string) (App\Models\Setting::get('app_url') ?? ''), '/');
        return $configured !== '' ? $configured . '/' . ltrim($path, '/') : url($path);
    }
    $scheme = (config('app.force_https') || Request::isHttps()) ? 'https' : 'http';
    return $scheme . '://' . $host . url($path);
}
