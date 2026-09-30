<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Role;
use App\Repositories\MasterDataRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkflowRepository;
use App\Services\WorkflowService;

final class WorkflowController extends Controller
{
    public function index(Request $request): Response
    {
        $repo = new WorkflowRepository();
        $workflows = $repo->all();
        foreach ($workflows as &$workflow) {
            $workflow['steps'] = $repo->steps((int) $workflow['id']);
        }
        unset($workflow);

        return $this->view('workflows/index', [
            'title' => 'Approval Workflow',
            'workflows' => $workflows,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(
            ['id' => null, 'name' => '', 'department_id' => null, 'description' => '', 'is_active' => 1],
            [
                ['label' => 'Diketahui', 'approver_type' => 'role', 'approver_user_id' => null, 'approver_role' => 'approver', 'same_department' => 1, 'is_required' => 1, 'min_amount' => null],
                ['label' => 'Disetujui', 'approver_type' => 'user', 'approver_user_id' => null, 'approver_role' => null, 'same_department' => 0, 'is_required' => 1, 'min_amount' => null],
            ],
            false,
        );
    }

    public function store(Request $request): Response
    {
        (new WorkflowService())->create($this->user(), $request->all());

        return $this->redirect('/approval-workflows', 'Workflow berhasil dibuat.');
    }

    public function edit(Request $request, int $id): Response
    {
        $repo = new WorkflowRepository();
        $workflow = $this->findOr404($repo->find($id));

        return $this->form($workflow, $repo->steps($id), $repo->isUsed($id));
    }

    public function update(Request $request, int $id): Response
    {
        (new WorkflowService())->update($this->user(), $id, $request->all());

        return $this->redirect('/approval-workflows', 'Workflow berhasil diperbarui.');
    }

    public function delete(Request $request, int $id): Response
    {
        (new WorkflowService())->delete($this->user(), $id);

        return $this->redirect('/approval-workflows', 'Workflow dihapus.');
    }

    /**
     * @param array<string, mixed> $workflow
     * @param list<array<string, mixed>> $steps
     */
    private function form(array $workflow, array $steps, bool $used): Response
    {
        return $this->view('workflows/form', [
            'title' => $workflow['id'] === null ? 'Tambah Workflow' : 'Ubah Workflow',
            'workflow' => $workflow,
            'steps' => $steps,
            'used' => $used,
            'departments' => MasterDataRepository::departments()->all(),
            'approverUsers' => (new UserRepository())->activeApproverCandidates(),
            'approverRoles' => array_intersect_key(Role::labels(), array_flip(Role::approverRoles())),
        ]);
    }
}
