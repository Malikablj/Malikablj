<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\HttpException;
use App\Helpers\Session;
use App\Helpers\Validator;
use App\Models\User;

/**
 * Pembuatan akun Admin pertama (hanya bisa dipakai saat tabel users kosong).
 * Menghindari kebutuhan menyimpan password default di repository.
 */
final class SetupController extends Controller
{
    private function ensureNoUsers(): void
    {
        if ((int) Database::fetchValue('SELECT COUNT(*) FROM users') > 0) {
            throw new HttpException(404);
        }
    }

    public function show(): void
    {
        $this->ensureNoUsers();
        $this->view('auth/setup', ['title' => 'Setup Admin', 'errors' => []], 200, 'layouts/auth');
    }

    public function store(): void
    {
        $this->ensureNoUsers();
        $v = Validator::make($_POST, [
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ], ['name' => 'Nama', 'email' => 'Email', 'password' => 'Password']);
        $data = $v->validated();
        if (!$v->fails()) {
            $policy = Auth::passwordPolicyError((string) $_POST['password']);
            if ($policy !== null) {
                $v->addError('password', $policy);
            } elseif (($_POST['password'] ?? '') !== ($_POST['password_confirmation'] ?? '')) {
                $v->addError('password_confirmation', 'Konfirmasi password tidak sama.');
            }
        }
        if ($v->fails()) {
            $this->invalid('auth/setup', ['title' => 'Setup Admin'], $v->errors(), ['name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? ''], 'layouts/auth');
            return;
        }

        $id = Database::transaction(function () use ($data): int {
            // kunci baris agar dua request setup bersamaan tidak membuat 2 admin
            if ((int) Database::fetchValue('SELECT COUNT(*) FROM users FOR UPDATE') > 0) {
                throw new HttpException(404);
            }
            return User::createWithPassword([
                'name'     => (string) $data['name'],
                'email'    => (string) $data['email'],
                'role'     => 'Admin',
                'password' => (string) $_POST['password'],
            ]);
        });
        Auth::loginUsingId($id);
        Session::flash('success', 'Akun Admin berhasil dibuat. Selamat datang!');
        redirect('/');
    }
}
