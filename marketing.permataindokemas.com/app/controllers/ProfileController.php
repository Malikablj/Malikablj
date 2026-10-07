<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Session;
use App\Helpers\Validator;
use App\Models\User;

final class ProfileController extends Controller
{
    public function show(): void
    {
        $user = $this->found(User::find((int) Auth::id()));
        $this->view('profile/index', ['title' => 'Profil Saya', 'user' => $user, 'errors' => []]);
    }

    public function update(): void
    {
        $user = $this->found(User::find((int) Auth::id()));
        $v = Validator::make($_POST, ['name' => 'required|string|max:120'], ['name' => 'Nama']);
        if ($v->fails()) {
            $this->invalid('profile/index', ['title' => 'Profil Saya', 'user' => $user], $v->errors(), ['name' => $_POST['name'] ?? '']);
            return;
        }
        User::update((int) $user['id'], ['name' => $v->validated()['name']], $user);
        Auth::refresh();
        $this->success('Profil berhasil diperbarui.', '/profile');
    }

    public function showPassword(): void
    {
        $this->view('profile/password', ['title' => 'Ganti Password', 'errors' => []]);
    }

    public function updatePassword(): void
    {
        $user = Database::fetch('SELECT id, password_hash FROM users WHERE id = :id', ['id' => Auth::id()]);
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $errors = [];
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Password saat ini salah.';
        }
        $policy = Auth::passwordPolicyError($new);
        if ($policy !== null) {
            $errors['password'] = $policy;
        } elseif ($new === $current) {
            $errors['password'] = 'Password baru harus berbeda dari password saat ini.';
        }
        if ($new !== $confirm) {
            $errors['password_confirmation'] = 'Konfirmasi password tidak sama.';
        }
        if ($errors !== []) {
            $this->invalid('profile/password', ['title' => 'Ganti Password'], $errors, []);
            return;
        }
        User::setPassword((int) $user['id'], $new, false);
        // ganti session id setelah perubahan kredensial
        Session::regenerate();
        Csrf::rotate();
        Auth::refresh();
        $this->success('Password berhasil diganti.', '/');
    }
}
