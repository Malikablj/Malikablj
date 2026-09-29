<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Session;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use DomainException;

final class LeadController extends Controller
{
    private const FIELDS = ['lead_name', 'customer_id', 'company_name', 'contact_name', 'phone', 'email', 'source', 'product_interest',
        'estimated_qty', 'potential_value', 'expected_close_date', 'status', 'priority', 'pic_user_id', 'notes'];

    /** @return array<string,mixed> */
    private function filters(): array
    {
        return [
            'q'           => Request::queryString('q'),
            'pic'         => Request::queryInt('pic'),
            'priority'    => Request::queryString('priority'),
            'customer_id' => Request::queryInt('customer_id'),
            'status'      => Request::queryString('status'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
        ];
    }

    /** Kanban board. */
    public function index(): void
    {
        $filters = $this->filters();
        $filters['status'] = '';
        $this->view('leads/board', [
            'title'   => 'Leads',
            'board'   => Lead::board($filters),
            'filters' => $filters,
            'pics'    => User::picOptions(),
            'today'   => today(),
        ]);
    }

    public function list(): void
    {
        $filters = $this->filters();
        $sort = Request::queryString('sort', 'created');
        $dir = Request::queryString('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->view('leads/list', [
            'title'   => 'Leads',
            'leads'   => Lead::paginate($filters, $sort, $dir, $this->page()),
            'filters' => $filters,
            'sort'    => $sort,
            'dir'     => $dir,
            'pics'    => User::picOptions(),
            'today'   => today(),
        ]);
    }

    public function create(): void
    {
        $customerId = Request::queryInt('customer_id');
        $preset = ['status' => 'New', 'priority' => 'Medium', 'pic_user_id' => Auth::id(), 'customer_id' => $customerId ?: null];
        $this->view('leads/form', $this->formData(null) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('leads/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $id = Lead::saveLead(null, $v->validated());
        $this->success('Lead berhasil dibuat.', '/leads/' . $id);
    }

    public function show(int $id): void
    {
        $lead = $this->found(Lead::findFull($id));
        $today = today();
        $this->view('leads/show', [
            'title'      => $lead['lead_name'],
            'lead'       => $lead,
            'activities' => Database::fetchAll(
                'SELECT a.*, u.name AS pic_name FROM activities a LEFT JOIN users u ON u.id = a.pic_user_id WHERE a.lead_id = :id ORDER BY a.activity_date DESC, a.id DESC LIMIT 50',
                ['id' => $id]
            ),
            'followups'  => Database::fetchAll(
                "SELECT f.*, u.name AS pic_name, (f.follow_up_date < :t AND f.status NOT IN ('Done','Cancelled')) AS is_overdue
                 FROM follow_up f LEFT JOIN users u ON u.id = f.pic_user_id WHERE f.lead_id = :id
                 ORDER BY (f.status IN ('Done','Cancelled')) ASC, f.follow_up_date ASC LIMIT 50",
                ['id' => $id, 't' => $today]
            ),
            'history'    => AuditLog::forEntity('lead', $id, 15),
            'today'      => $today,
        ]);
    }

    public function edit(int $id): void
    {
        $lead = $this->found(Lead::find($id));
        $this->view('leads/form', $this->formData($lead) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $lead = $this->found(Lead::find($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('leads/form', $this->formData($lead) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        Lead::saveLead($id, $v->validated());
        $this->success('Lead diperbarui.', '/leads/' . $id);
    }

    /** Ubah status dari Kanban (AJAX drag & drop) atau form biasa. */
    public function status(int $id): void
    {
        $lead = Lead::find($id);
        $status = (string) ($_POST['status'] ?? '');
        if ($lead === null) {
            throw new HttpException(404);
        }
        try {
            $old = Lead::changeStatus($id, $status);
        } catch (DomainException $e) {
            if (Request::isAjax()) {
                $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }
            $this->failure($e->getMessage(), '/leads');
        }
        $message = $old === $status ? 'Status tidak berubah.' : "Lead \"{$lead['lead_name']}\" dipindah ke {$status}.";
        if (Request::isAjax()) {
            $this->json(['ok' => true, 'message' => $message, 'status' => $status, 'old' => $old]);
        }
        Session::flash('success', $message);
        redirect($this->returnTo('/leads'));
    }

    public function destroy(int $id): void
    {
        $lead = $this->found(Lead::find($id));
        try {
            Lead::deleteSafely($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/leads/' . $id);
        }
        $this->success('Lead "' . $lead['lead_name'] . '" dihapus.', '/leads');
    }

    public function convert(int $id): void
    {
        if (!Auth::can('customers.create')) {
            throw new HttpException(403);
        }
        $this->found(Lead::find($id));
        try {
            $customerId = Lead::convertToCustomer($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/leads/' . $id);
        }
        $this->success('Customer baru dibuat dari lead ini dan sudah terhubung.', '/customers/' . $customerId);
    }

    private function validate(): Validator
    {
        $v = Validator::make($_POST, [
            'lead_name'           => 'required|string|max:190',
            'customer_id'         => 'nullable|integer|exists:customers,id',
            'company_name'        => 'nullable|string|max:190',
            'contact_name'        => 'nullable|string|max:120',
            'phone'               => 'nullable|phone|max:40',
            'email'               => 'nullable|email|max:190',
            'source'              => 'nullable|string|max:100',
            'product_interest'    => 'nullable|string|max:255',
            'estimated_qty'       => 'nullable|integer|min:0',
            'potential_value'     => 'nullable|numeric|min:0',
            'expected_close_date' => 'nullable|date',
            'status'              => ['required', ['in', Lead::STATUSES]],
            'priority'            => ['required', ['in', Lead::PRIORITIES]],
            'pic_user_id'         => 'nullable|integer|exists:users,id',
            'notes'               => 'nullable|string|max:5000',
        ], [
            'lead_name' => 'Nama lead', 'customer_id' => 'Customer', 'company_name' => 'Nama perusahaan prospek', 'contact_name' => 'Contact person',
            'phone' => 'Telepon', 'email' => 'Email', 'source' => 'Sumber', 'product_interest' => 'Produk diminati', 'estimated_qty' => 'Estimasi qty',
            'potential_value' => 'Potential value', 'expected_close_date' => 'Target closing', 'status' => 'Status', 'priority' => 'Prioritas',
            'pic_user_id' => 'PIC', 'notes' => 'Catatan',
        ]);
        $data = $v->validated();
        if (!$v->fails() && empty($data['customer_id']) && empty($data['company_name'])) {
            $v->addError('customer_id', 'Pilih customer, atau isi nama perusahaan prospek bila belum menjadi customer.');
        }
        return $v;
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = $_POST[$f] ?? '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $lead): array
    {
        return [
            'title'     => $lead ? 'Edit Lead' : 'Buat Lead',
            'lead'      => $lead,
            'customers' => Customer::selectOptions(),
            'pics'      => User::picOptions(),
        ];
    }
}
