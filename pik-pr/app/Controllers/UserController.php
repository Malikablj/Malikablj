<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Role;
use App\Repositories\MasterDataRepository;
use App\Repositories\UserRepository;
use App\Services\UserService;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->queryString('q'), 0, 100),
            'role' => Role::tryFrom($request->queryString('role')) !== null ? $request->queryString('role') : '',
            'status' => in_array($request->queryString('status'), ['active', 'inactive'], true) ? $request->queryString('status') : '',
        ];
        $page = $this->page($request);
        $result = (new UserRepository())->paginate($filters, $page);

        return $this->view('users/index', [
            'title' => 'User',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 20,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(['id' => null, 'name' => '', 'email' => '', 'role' => Role::Requester->value, 'department_id' => null, 'job_title' => '', 'is_active' => 1]);
    }

    public function store(Request $request): Response
    {
        (new UserService())->create($this->user(), $request->all());

        return $this->redirect('/users', 'User berhasil dibuat.');
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form((new UserService())->findManageable($this->user(), $id));
    }

    public function update(Request $request, int $id): Response
    {
        (new UserService())->update($this->user(), $id, $request->all());

        return $this->redirect('/users', 'User berhasil diperbarui.');
    }

    public function toggle(Request $request, int $id): Response
    {
        $active = (new UserService())->toggleActive($this->user(), $id);

        return $this->redirect('/users', $active ? 'User diaktifkan.' : 'User dinonaktifkan dan tidak dapat login lagi.');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function form(array $record): Response
    {
        return $this->view('users/form', [
            'title' => $record['id'] === null ? 'Tambah User' : 'Ubah User',
            'record' => $record,
            'roles' => UserService::assignableRoles($this->user()),
            'departments' => MasterDataRepository::departments()->all(),
            'isSelf' => $record['id'] !== null && (int) $record['id'] === (int) $this->user()['id'],
        ]);
    }
}
