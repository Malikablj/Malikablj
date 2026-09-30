<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Models\Role;
use App\Repositories\MasterDataRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkflowRepository;
use App\Support\Decimal;

/**
 * Konfigurasi approval workflow.
 *
 * Workflow yang sudah dipakai PR tidak boleh diubah struktur tahapnya
 * (jumlah/urutan), agar riwayat approval tetap konsisten. Label dan aturan
 * approver tetap dapat diubah (mis. mengganti approver yang cuti).
 */
final class WorkflowService
{
    private WorkflowRepository $workflows;
    private UserRepository $users;
    private AuditService $audit;

    public function __construct()
    {
        $this->workflows = new WorkflowRepository();
        $this->users = new UserRepository();
        $this->audit = new AuditService();
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function create(array $actor, array $input): int
    {
        [$header, $steps] = $this->validate($input, null);

        return Database::transaction(function () use ($actor, $header, $steps): int {
            $id = $this->workflows->create($header);
            foreach ($steps as $step) {
                $this->workflows->createStep($step + ['workflow_id' => $id]);
            }
            $this->audit->log((int) $actor['id'], 'workflow.create', 'approval_workflow', $id, null, $header + ['steps' => $steps]);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public function update(array $actor, int $id, array $input): void
    {
        $workflow = $this->workflows->find($id);
        if ($workflow === null) {
            throw new HttpException(404);
        }
        [$header, $steps] = $this->validate($input, $workflow);
        $existingSteps = $this->workflows->steps($id);
        $used = $this->workflows->isUsed($id);

        if ($used && count($steps) !== count($existingSteps)) {
            throw new BusinessRuleException(
                'Workflow ini sudah dipakai PR sehingga jumlah tahap tidak dapat diubah. '
                . 'Buat workflow baru lalu nonaktifkan workflow ini.',
            );
        }

        Database::transaction(function () use ($actor, $id, $workflow, $header, $steps, $existingSteps, $used): void {
            $this->workflows->update($id, $header);
            if ($used) {
                foreach ($steps as $index => $step) {
                    $this->workflows->updateStep((int) $existingSteps[$index]['id'], $step);
                }
            } else {
                $this->workflows->deleteSteps($id);
                foreach ($steps as $step) {
                    $this->workflows->createStep($step + ['workflow_id' => $id]);
                }
            }
            $this->audit->log((int) $actor['id'], 'workflow.update', 'approval_workflow', $id, [
                'name' => $workflow['name'],
                'department_id' => $workflow['department_id'],
                'is_active' => $workflow['is_active'],
                'steps' => array_map([self::class, 'stepSummary'], $existingSteps),
            ], $header + ['steps' => $steps]);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function delete(array $actor, int $id): void
    {
        $workflow = $this->workflows->find($id);
        if ($workflow === null) {
            throw new HttpException(404);
        }
        if ($this->workflows->isUsed($id)) {
            throw new BusinessRuleException('Workflow sudah dipakai PR sehingga tidak dapat dihapus. Nonaktifkan saja.');
        }
        Database::transaction(function () use ($actor, $id, $workflow): void {
            $steps = $this->workflows->steps($id);
            $this->workflows->delete($id);
            $this->audit->log((int) $actor['id'], 'workflow.delete', 'approval_workflow', $id, [
                'name' => $workflow['name'],
                'steps' => array_map([self::class, 'stepSummary'], $steps),
            ], null);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validate(array $input, ?array $existing): array
    {
        $v = new Validator($input);
        $v->required('name', 'Nama workflow')->maxLength('name', 100, 'Nama workflow')
            ->maxLength('description', 255, 'Deskripsi');

        $departmentId = (int) $v->value('department_id');
        if ($departmentId > 0 && MasterDataRepository::departments()->find($departmentId) === null) {
            $v->add('department_id', 'Department tidak valid.');
        }
        $active = ($input['is_active'] ?? '') === '1';
        if ($active && $this->workflows->activeExistsForScope($departmentId > 0 ? $departmentId : null, $existing !== null ? (int) $existing['id'] : null)) {
            $v->add('is_active', $departmentId > 0
                ? 'Department ini sudah memiliki workflow aktif. Nonaktifkan workflow tersebut terlebih dahulu.'
                : 'Sudah ada workflow default yang aktif. Nonaktifkan workflow tersebut terlebih dahulu.');
        }

        $rows = is_array($input['steps'] ?? null) ? array_values(array_filter($input['steps'], 'is_array')) : [];
        $steps = [];
        $usersUsed = [];
        foreach ($rows as $index => $row) {
            $field = static fn (string $name): string => is_scalar($row[$name] ?? null) ? trim((string) $row[$name]) : '';
            $key = 'steps.' . $index;
            $label = $field('label');
            $type = $field('approver_type');
            if ($label === '') {
                $v->add("{$key}.label", 'Label tahap wajib diisi.');
            } elseif (mb_strlen($label) > 50) {
                $v->add("{$key}.label", 'Label maksimal 50 karakter.');
            }

            $step = [
                'step_order' => $index + 1,
                'label' => $label,
                'approver_type' => $type,
                'approver_user_id' => null,
                'approver_role' => null,
                'same_department' => $field('same_department') === '1' ? 1 : 0,
                'is_required' => $field('is_required') === '0' ? 0 : 1,
                'min_amount' => null,
            ];

            if ($type === 'user') {
                $userId = (int) $field('approver_user_id');
                $user = $userId > 0 ? $this->users->find($userId) : null;
                if ($user === null || !in_array($user['role'], Role::approverRoles(), true)) {
                    $v->add("{$key}.approver_user_id", 'Pilih user approver yang valid.');
                } elseif (isset($usersUsed[$userId])) {
                    $v->add("{$key}.approver_user_id", 'User yang sama tidak boleh menjadi approver di dua tahap.');
                } else {
                    $usersUsed[$userId] = true;
                    $step['approver_user_id'] = $userId;
                }
                $step['same_department'] = 0;
            } elseif ($type === 'role') {
                $role = $field('approver_role');
                if (!in_array($role, Role::approverRoles(), true)) {
                    $v->add("{$key}.approver_role", 'Pilih role approver yang valid.');
                } else {
                    $step['approver_role'] = $role;
                }
            } else {
                $v->add("{$key}.approver_type", 'Pilih jenis approver.');
            }

            if ($step['is_required'] === 0) {
                $amount = Decimal::parse($field('min_amount'));
                if ($amount === null) {
                    $v->add("{$key}.min_amount", 'Tahap kondisional wajib memiliki nilai minimum PR.');
                } else {
                    $step['min_amount'] = $amount;
                }
            }
            $steps[] = $step;
        }

        if ($steps === []) {
            $v->add('steps', 'Workflow minimal memiliki satu tahap approval.');
        } elseif (count($steps) > 10) {
            $v->add('steps', 'Workflow maksimal memiliki 10 tahap.');
        } elseif ((int) $steps[0]['is_required'] === 0) {
            $v->add('steps.0.is_required', 'Tahap pertama harus wajib.');
        }
        $v->throwIfFailed();

        return [[
            'name' => $v->value('name'),
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'description' => $v->value('description') !== '' ? $v->value('description') : null,
            'is_active' => $active ? 1 : 0,
        ], $steps];
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    public static function stepSummary(array $step): array
    {
        return [
            'step_order' => (int) $step['step_order'],
            'label' => $step['label'],
            'approver_type' => $step['approver_type'],
            'approver_user_id' => $step['approver_user_id'],
            'approver_role' => $step['approver_role'],
            'same_department' => (int) $step['same_department'],
            'is_required' => (int) $step['is_required'],
            'min_amount' => $step['min_amount'],
        ];
    }
}
