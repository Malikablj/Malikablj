<?php
declare(strict_types=1);

/**
 * Simpan preferensi bahasa/tema.
 *  - Pengguna login: disimpan di profil (users.language / users.theme).
 *  - Tamu (halaman login): disimpan di sesi.
 * Menerima form POST (redirect kembali) atau JSON (fetch).
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\User\UserService;

if (!Request::isPost()) {
    Response::error(405, I18n::t('error.method_not_allowed'));
}
Csrf::verify();

$isJson = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
$input = $isJson ? Request::json() : $_POST;
$language = isset($input['language']) && is_string($input['language']) ? $input['language'] : null;
$theme = isset($input['theme']) && is_string($input['theme']) ? $input['theme'] : null;

if ($language !== null && !in_array($language, I18n::SUPPORTED, true)) {
    throw new ValidationException(['language' => I18n::t('validation.invalid')]);
}
if ($theme !== null && !in_array($theme, UserService::THEMES, true)) {
    throw new ValidationException(['theme' => I18n::t('validation.invalid')]);
}

$user = Auth::user();
if ($user !== null) {
    (new UserService())->updatePreferences($user, $language, $theme);
} else {
    if ($language !== null) {
        $_SESSION['guest_language'] = $language;
    }
    if ($theme !== null) {
        $_SESSION['guest_theme'] = $theme;
    }
}

if ($isJson || Request::acceptsJson()) {
    Response::json(['ok' => true, 'language' => $language, 'theme' => $theme]);
}
$return = Request::safeReturnPath(Request::post('return'), url('dashboard.php'));
Response::redirect($return);
