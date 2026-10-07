<?php
declare(strict_types=1);

/**
 * <head> bersama (halaman aplikasi & halaman tamu).
 * Tema: data-theme dirender server untuk pilihan eksplisit; untuk "system" skrip ber-nonce
 * menerapkan tema sebelum paint sehingga tidak ada kilatan terang (PRD §11.3).
 *
 * @var string $pageTitle
 */

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Response;

$__user = Auth::user();
$__themePref = $__user?->theme ?? (string) ($_SESSION['guest_theme'] ?? 'system');
$__themeAttr = in_array($__themePref, ['light', 'dark'], true) ? ' data-theme="' . e($__themePref) . '"' : '';
?><!doctype html>
<html lang="<?= e(I18n::locale()) ?>"<?= $__themeAttr ?> data-theme-pref="<?= e($__themePref) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#F7F7F5" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(Response::basePath()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . I18n::t('app.name') : I18n::t('app.name')) ?></title>
<script nonce="<?= e(Response::nonce()) ?>">
(function () {
  var d = document.documentElement, p = d.getAttribute('data-theme-pref');
  var t = (p === 'light' || p === 'dark') ? p : (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  d.setAttribute('data-theme', t);
  document.querySelectorAll('meta[name="theme-color"]').forEach(function (m) { m.setAttribute('content', t === 'dark' ? '#000000' : '#F7F7F5'); m.removeAttribute('media'); });
})();
</script>
<link rel="preload" href="<?= e(asset('fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('images/mark-32.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('images/mark-180.png')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach (($pageScripts ?? []) as $__script): ?>
<script src="<?= e(asset($__script)) ?>" defer></script>
<?php endforeach; ?>
</head>
