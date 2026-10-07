<?php
declare(strict_types=1);

/**
 * Guard untuk halaman & API. Otorisasi SELALU di server — menyembunyikan tombol hanyalah kenyamanan.
 */

use App\Core\Auth;
use App\Core\AuthenticationException;
use App\Core\Csrf;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\User;

/** Wajib login. Halaman → redirect login; API → 401 JSON. */
function require_login(): User
{
    $user = Auth::user();
    if ($user === null) {
        if (Request::wantsJson()) {
            throw new AuthenticationException(I18n::t('error.unauthenticated'));
        }
        if (!empty($_SESSION['_expired'])) {
            unset($_SESSION['_expired']);
            Session::flash('warning', I18n::t('auth.session_expired'));
        }
        $return = Request::safeReturnPath((string) ($_SERVER['REQUEST_URI'] ?? ''), '');
        if ($return !== '' && Request::method() === 'GET') {
            $_SESSION['_return_to'] = $return;
        }
        Response::redirect('login.php');
    }
    // Wajib ganti password (akun baru / direset Admin) sebelum memakai aplikasi
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($user->mustChangePassword && !in_array($script, ['profile.php', 'logout.php', 'preferences.php'], true)) {
        if (Request::wantsJson()) {
            Response::json(['error' => I18n::t('auth.must_change_password')], 403);
        }
        Session::flash('warning', I18n::t('auth.must_change_password'));
        Response::redirect('profile.php');
    }
    return $user;
}

/** Wajib izin tertentu → 403 bila tidak berhak. */
function require_permission(string $ability, array $context = []): User
{
    $user = require_login();
    Gate::authorize($user, $ability, $context);
    return $user;
}

/** Cek izin untuk tampilan (menu/tombol). */
function can(string $ability, array $context = []): bool
{
    return Gate::can(Auth::user(), $ability, $context);
}

/** Request yang mengubah data wajib POST + token CSRF. */
function require_post(): void
{
    if (!Request::isPost()) {
        Response::error(405, I18n::t('error.method_not_allowed'));
    }
    Csrf::verify();
}

/** Untuk halaman yang menerima GET & POST: verifikasi CSRF bila POST. */
function verify_csrf_if_post(): void
{
    if (Request::isMutating()) {
        Csrf::verify();
    }
}
