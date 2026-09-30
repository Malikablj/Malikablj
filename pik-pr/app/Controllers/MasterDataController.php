<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BusinessRuleException;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\MasterDataRepository;
use App\Services\AuditService;
use App\Services\PrCalculator;
use App\Support\Decimal;

/**
 * CRUD generik untuk master data sederhana. Subclass hanya mendefinisikan
 * field; seluruh query tetap memakai prepared statement.
 */
abstract class MasterDataController extends Controller
{
    abstract protected function repository(): MasterDataRepository;

    /** Nama entity untuk audit log, mis. "supplier". */
    abstract protected function entity(): string;

    abstract protected function baseUrl(): string;

    abstract protected function title(): string;

    /**
     * Definisi field form.
     * type: text | textarea | code | money
     *
     * @return array<string, array{label: string, type: string, required?: bool, max?: int, unique?: bool, pattern?: string, pattern_message?: string, hint?: string, default?: string}>
     */
    abstract protected function fields(): array;

    /**
     * Kolom yang ditampilkan di tabel daftar.
     *
     * @return array<string, string>
     */
    abstract protected function columns(): array;

    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->queryString('q'), 0, 100),
            'status' => in_array($request->queryString('status'), ['active', 'inactive'], true) ? $request->queryString('status') : '',
        ];
        $page = $this->page($request);
        $result = $this->repository()->paginate($filters, $page);

        return $this->view('master/index', [
            'title' => $this->title(),
            'config' => $this->config(),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 20,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $record = ['id' => null, 'is_active' => 1];
        foreach ($this->fields() as $name => $field) {
            $record[$name] = $field['default'] ?? '';
        }

        return $this->view('master/form', [
            'title' => 'Tambah ' . $this->title(),
            'config' => $this->config(),
            'record' => $record,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request->all(), null);
        $user = $this->user();

        Database::transaction(function () use ($data, $user): void {
            $id = $this->repository()->create($data + ['is_active' => 1]);
            (new AuditService())->log((int) $user['id'], $this->entity() . '.create', $this->entity(), $id, null, $data);
        });

        return $this->redirect($this->baseUrl(), $this->title() . ' berhasil ditambahkan.');
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->view('master/form', [
            'title' => 'Ubah ' . $this->title(),
            'config' => $this->config(),
            'record' => $this->findOr404($this->repository()->find($id)),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $record = $this->findOr404($this->repository()->find($id));
        $data = $this->validate($request->all(), $record);
        $user = $this->user();

        Database::transaction(function () use ($record, $data, $user, $id): void {
            $this->repository()->update($id, $data);
            [$old, $new] = AuditService::diff($record, $data);
            if ($new !== []) {
                (new AuditService())->log((int) $user['id'], $this->entity() . '.update', $this->entity(), $id, $old, $new);
            }
        });

        return $this->redirect($this->baseUrl(), $this->title() . ' berhasil diperbarui.');
    }

    public function toggle(Request $request, int $id): Response
    {
        $record = $this->findOr404($this->repository()->find($id));
        $active = !(bool) $record['is_active'];
        $user = $this->user();

        Database::transaction(function () use ($id, $record, $active, $user): void {
            $this->repository()->update($id, ['is_active' => $active ? 1 : 0]);
            (new AuditService())->log(
                (int) $user['id'],
                $this->entity() . ($active ? '.activate' : '.deactivate'),
                $this->entity(),
                $id,
                ['is_active' => (int) $record['is_active']],
                ['is_active' => $active ? 1 : 0],
            );
        });

        return $this->redirect($this->baseUrl(), $this->title() . ' ' . ($active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    public function delete(Request $request, int $id): Response
    {
        $record = $this->findOr404($this->repository()->find($id));
        $user = $this->user();

        Database::transaction(function () use ($id, $record, $user): void {
            if (!$this->repository()->delete($id)) {
                throw new BusinessRuleException(
                    $this->title() . ' "' . $record['name'] . '" sudah dipakai oleh data lain sehingga tidak dapat dihapus. Nonaktifkan saja.',
                );
            }
            (new AuditService())->log((int) $user['id'], $this->entity() . '.delete', $this->entity(), $id, $record, null);
        });

        return $this->redirect($this->baseUrl(), $this->title() . ' dihapus.');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    protected function validate(array $input, ?array $record): array
    {
        foreach ($this->fields() as $name => $field) {
            if ($field['type'] === 'code' && is_scalar($input[$name] ?? null)) {
                $input[$name] = strtoupper(trim((string) $input[$name]));
            }
        }

        $v = new Validator($input);
        $data = [];
        foreach ($this->fields() as $name => $field) {
            if ($field['required'] ?? false) {
                $v->required($name, $field['label']);
            }
            if (isset($field['max'])) {
                $v->maxLength($name, $field['max'], $field['label']);
            }
            if (isset($field['pattern'])) {
                $v->pattern($name, $field['pattern'], $field['pattern_message'] ?? $field['label'] . ' tidak valid.');
            }
            $value = $v->value($name);

            if ($field['type'] === 'money') {
                $v->decimal($name, $field['label'], '0', PrCalculator::MAX_AMOUNT);
                $value = Decimal::parse($value === '' ? '0' : $value) ?? '0.00';
            }
            if (($field['unique'] ?? false) && $value !== '' && $this->repository()->valueExists($name, $value, $record !== null ? (int) $record['id'] : null)) {
                $v->add($name, $field['label'] . ' "' . $value . '" sudah digunakan.');
            }

            $data[$name] = $value === '' && !($field['required'] ?? false) ? ($field['default'] ?? null) : $value;
        }
        $v->throwIfFailed();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'title' => $this->title(),
            'baseUrl' => $this->baseUrl(),
            'fields' => $this->fields(),
            'columns' => $this->columns(),
            'deletable' => true,
        ];
    }
}
