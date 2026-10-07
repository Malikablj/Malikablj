<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Permission;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\User;
use DomainException;

/**
 * Manajemen user (khusus Admin): tambah, edit, reset password, buka blokir login, dan hapus.
 * User yang hanya sementara tidak dipakai sebaiknya dinonaktifkan; hapus bila memang tidak dipakai lagi.
 */
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
        $blocked = Auth::blockedAccounts();
        $users = User::paginate($search, $role, $status, $this->page(), array_keys($blocked));
        $this->view('users/index', [
            'title'      => 'Users',
            'users'      => $users,
            'search'     => $search,
            'role'       => $role,
            'status'     => $status,
            'blocked'    => $blocked,
            'returnPath' => Request::fullPath(),
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
        $this->view('users/form', ['title' => 'Edit User', 'user' => $user, 'errors' => []] + $this->editData($user));
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
            $this->invalid('users/form', ['title' => 'Edit User', 'user' => $user] + $this->editData($user), $v->errors(), $this->oldInput());
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

    /** Buka blokir login user yang terkunci karena salah password berulang kali. */
    public function unblock(int $id): void
    {
        $user = $this->found(User::find($id));
        $back = $this->returnTo('/users');
        if (Auth::blockedUntil((string) $user['email']) === null) {
            $this->failure($user['name'] . ' tidak sedang terblokir (blokir sementara sudah berakhir otomatis).', $back);
        }
        Auth::unblock((string) $user['email']);
        // notifikasi "user terblokir" untuk user ini dianggap selesai bagi semua Admin
        Database::update('notifications', ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            "type = 'user_blocked' AND entity_id = :id AND is_read = 0", ['id' => $id]);
        Audit::log('user_unblock', User::ENTITY, $id, (string) $user['email']);
        $this->success('Blokir login ' . $user['name'] . ' sudah dibuka. User dapat login kembali sekarang.', $back);
    }

    /** Hapus user permanen (tidak bisa menghapus akun sendiri atau Admin aktif terakhir). */
    public function destroy(int $id): void
    {
        $user = $this->found(User::find($id));
        if ($id === Auth::id()) {
            $this->failure('Anda tidak dapat menghapus akun Anda sendiri.', '/users/' . $id . '/edit');
        }
        if ($user['role'] === 'Admin' && (int) $user['is_active'] === 1 && User::activeAdminCount() <= 1) {
            $this->failure('Harus ada minimal satu Admin aktif. User ini tidak dapat dihapus.', '/users/' . $id . '/edit');
        }
        try {
            User::deleteUser($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/users/' . $id . '/edit');
        }
        $this->success('User ' . $user['name'] . ' (' . $user['email'] . ') sudah dihapus.', '/users');
    }

    /**
     * Data tambahan halaman edit: status blokir login & data yang ditangani user (info sebelum menghapus).
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function editData(array $user): array
    {
        return [
            'blockedUntil' => Auth::blockedUntil((string) $user['email']),
            'assignments'  => User::assignments((int) $user['id']),
            'isSelf'       => (int) $user['id'] === Auth::id(),
        ];
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
