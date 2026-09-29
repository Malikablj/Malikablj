<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Permission;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\User;

/** Manajemen user (khusus Admin). User tidak dihapus, hanya dinonaktifkan. */
final class UserController extends Controller
{
    public function index(): void
    {
        $search = Request::queryString('q');
        $role = Request::queryString('role');
        $status = Request::queryString('status');
        if (!in_array($role, Permission::ROLES, true)) {
            $role = '';
        }
        $users = User::paginate($search, $role, $status, $this->page());
        $this->view('users/index', [
            'title'  => 'Users',
            'users'  => $users,
            'search' => $search,
            'role'   => $role,
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        $this->view('users/form', ['title' => 'Tambah User', 'user' => null, 'errors' => []]);
    }

    public function store(): void
    {
        $v = $this->validate(null);
        $password = (string) ($_POST['password'] ?? '');
        if (!$v->fails()) {
            $policy = Auth::passwordPolicyError($password);
            if ($policy !== null) {
                $v->addError('password', $policy);
            }
        }
        if ($v->fails()) {
            $this->invalid('users/form', ['title' => 'Tambah User', 'user' => null], $v->errors(), $this->oldInput());
            return;
        }
        $data = $v->validated();
        User::createWithPassword([
            'name'                 => (string) $data['name'],
            'email'                => (string) $data['email'],
            'role'                 => (string) $data['role'],
            'password'             => $password,
            'is_active'            => (int) $data['is_active'],
            'must_change_password' => (int) ($_POST['must_change_password'] ?? 1) === 1 ? 1 : 0,
        ]);
        $this->success('User berhasil dibuat. Berikan password sementara kepada user secara langsung (bukan lewat grup chat).', '/users');
    }

    public function edit(int $id): void
    {
        $user = $this->found(User::find($id));
        $this->view('users/form', ['title' => 'Edit User', 'user' => $user, 'errors' => []]);
    }

    public function update(int $id): void
    {
        $user = $this->found(User::find($id));
        $v = $this->validate($id);
        $data = $v->validated();
        if (!$v->fails()) {
            $isSelf = $id === Auth::id();
            $removesAdmin = $user['role'] === 'Admin' && (int) $user['is_active'] === 1
                && ($data['role'] !== 'Admin' || (int) $data['is_active'] === 0);
            if ($isSelf && (int) $data['is_active'] === 0) {
                $v->addError('is_active', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            } elseif ($removesAdmin && User::activeAdminCount() <= 1) {
                $v->addError('role', 'Harus ada minimal satu Admin aktif.');
            }
        }
        if ($v->fails()) {
            $this->invalid('users/form', ['title' => 'Edit User', 'user' => $user], $v->errors(), $this->oldInput());
            return;
        }
        User::update($id, [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'is_active' => $data['is_active'],
        ], $user);
        $this->success('Data user berhasil diperbarui.', '/users');
    }

    public function resetPassword(int $id): void
    {
        $user = $this->found(User::find($id));
        $password = (string) ($_POST['new_password'] ?? '');
        $policy = Auth::passwordPolicyError($password);
        if ($policy !== null) {
            $this->failure('Reset password gagal: ' . $policy, '/users/' . $id . '/edit');
        }
        // must_change_password = 1: sesi user yang masih aktif langsung diarahkan ke halaman ganti password
        User::setPassword($id, $password, true);
        // buka kunci login (bila user sempat terkunci karena salah password berulang kali)
        Database::delete('login_attempts', 'email = :email', ['email' => $user['email']]);
        $this->success('Password sementara untuk ' . $user['email'] . ' berhasil diset. User wajib menggantinya saat login.', '/users');
    }

    private function validate(?int $ignoreId): Validator
    {
        return Validator::make($_POST, [
            'name'      => 'required|string|max:120',
            'email'     => 'required|email|max:190|unique:users,email,' . ($ignoreId ?? 0),
            'role'      => ['required', ['in', Permission::ROLES]],
            'is_active' => 'boolean',
        ], ['name' => 'Nama', 'email' => 'Email', 'role' => 'Role', 'is_active' => 'Status aktif']);
    }

    /** @return array<string,mixed> */
    private function oldInput(): array
    {
        return [
            'name'      => $_POST['name'] ?? '',
            'email'     => $_POST['email'] ?? '',
            'role'      => $_POST['role'] ?? '',
            'is_active' => $_POST['is_active'] ?? '0',
        ];
    }
}
