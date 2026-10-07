<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

use App\Core\Auth;
use App\Core\I18n;
use App\Core\Response;
use App\Core\Session;

// Logout hanya lewat POST + CSRF (mencegah logout paksa via tautan).
require_post();
Auth::logout();
// Sesi baru untuk pesan konfirmasi
Session::start();
Session::flash('info', I18n::t('auth.logged_out'));
Response::redirect('login.php');
