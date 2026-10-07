<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Session;
use App\Helpers\View;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        if ((int) Database::fetchValue('SELECT COUNT(*) FROM users') === 0) {
            redirect('/setup');
        }
        $this->view('auth/login', ['title' => 'Masuk', 'errors' => [], 'email' => ''], 200, 'layouts/auth');
    }

    public function login(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $errors = [];
        if ($email === '' || mb_strlen($email) > 190) {
            $errors['email'] = 'Email wajib diisi.';
        }
        if ($password === '' || mb_strlen($password) > 200) {
            $errors['password'] = 'Password wajib diisi.';
        }
        if ($errors === []) {
            $result = Auth::attempt($email, $password);
            if ($result['ok']) {
                $intended = Session::get('intended_url');
                Session::forget('intended_url');
                if (!is_string($intended) || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
                    $intended = '/';
                }
                redirect($intended);
            }
            $errors['login'] = $result['error'] ?? 'Login gagal.';
        }
        View::$shared['__old'] = ['email' => $email];
        $this->view('auth/login', ['title' => 'Masuk', 'errors' => $errors, 'email' => $email], 422, 'layouts/auth');
    }

    public function logout(): void
    {
        Auth::logout();
        Session::start();
        Session::flash('success', 'Anda telah keluar.');
        redirect('/login');
    }
}
