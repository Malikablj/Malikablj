<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerTabs;
use DomainException;

final class CustomerController extends Controller
{
    private const FIELDS = ['name', 'company', 'pic', 'phone', 'email', 'address', 'industry', 'status', 'source', 'marketing_pic_id', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'        => Request::queryString('q'),
            'status'   => Request::queryString('status'),
            'pic'      => Request::queryInt('pic'),
            'industry' => Request::queryString('industry'),
        ];
        $sort = Request::queryString('sort', 'name');
        $dir = Request::queryString('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $this->view('customers/index', [
            'title'      => 'Customers',
            'customers'  => Customer::paginate($filters, $sort, $dir, $this->page()),
            'filters'    => $filters,
            'sort'       => $sort,
            'dir'        => $dir,
            'pics'       => User::picOptions(),
            'industries' => Customer::industries(),
        ]);
    }

    public function create(): void
    {
        $this->view('customers/form', $this->formData(null) + ['errors' => []]);
    }

    public function store(): void
    {
        $v = $this->validate();
        $similar = [];
        if (!$v->fails() && (int) ($_POST['confirm_not_duplicate'] ?? 0) !== 1) {
            $similar = Customer::similarByName((string) $v->validated()['name']);
            if ($similar !== []) {
                $v->addError('name', 'Customer dengan nama serupa sudah ada. Periksa daftar di bawah atau centang "bukan duplikat".');
            }
        }
        if ($v->fails()) {
            $this->invalid('customers/form', array_merge($this->formData(null), ['similar' => $similar]), $v->errors(), $this->old());
            return;
        }
        $id = Customer::create($v->validated());
        $this->success('Customer berhasil ditambahkan.', '/customers/' . $id);
    }

    public function show(int $id): void
    {
        $customer = $this->found(Customer::findWithPic($id));
        $counts = Customer::relatedCounts($id);
        $tabs = CustomerTabs::available($counts);
        $tab = Request::queryString('tab', 'overview');
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $this->view('customers/show', [
            'title'    => $customer['name'],
            'customer' => $customer,
            'counts'   => $counts,
            'summary'  => Customer::summary($id, today()),
            'tabs'     => $tabs,
            'tab'      => $tab,
            'tabData'  => CustomerTabs::load($tab, $id, $this->page()),
            'history'  => $tab === 'overview' ? AuditLog::forEntity('customer', $id, 5) : [],
        ]);
    }

    public function edit(int $id): void
    {
        $customer = $this->found(Customer::find($id));
        $this->view('customers/form', $this->formData($customer) + ['errors' => []]);
    }

    public function update(int $id): void
    {
        $customer = $this->found(Customer::find($id));
        $v = $this->validate();
        $similar = [];
        $newName = (string) ($v->validated()['name'] ?? '');
        if (!$v->fails() && Customer::normalizeName($newName) !== Customer::normalizeName((string) $customer['name'])
            && (int) ($_POST['confirm_not_duplicate'] ?? 0) !== 1) {
            $similar = Customer::similarByName($newName, $id);
            if ($similar !== []) {
                $v->addError('name', 'Customer dengan nama serupa sudah ada. Centang "bukan duplikat" bila memang berbeda.');
            }
        }
        if ($v->fails()) {
            $this->invalid('customers/form', array_merge($this->formData($customer), ['similar' => $similar]), $v->errors(), $this->old());
            return;
        }
        Customer::update($id, $v->validated(), $customer);
        $this->success('Perubahan customer disimpan.', '/customers/' . $id);
    }

    public function destroy(int $id): void
    {
        $customer = $this->found(Customer::find($id));
        try {
            Customer::deleteSafely($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/customers/' . $id);
        }
        $this->success('Customer "' . $customer['name'] . '" dihapus.', '/customers');
    }

    private function validate(): Validator
    {
        return Validator::make($_POST, [
            'name'             => 'required|string|max:190',
            'company'          => 'nullable|string|max:190',
            'pic'              => 'nullable|string|max:120',
            'phone'            => 'nullable|phone|max:40',
            'email'            => 'nullable|email|max:190',
            'address'          => 'nullable|string|max:1000',
            'industry'         => 'nullable|string|max:100',
            'status'           => ['required', ['in', Customer::STATUSES]],
            'source'           => 'nullable|string|max:100',
            'marketing_pic_id' => 'nullable|integer|exists:users,id',
            'notes'            => 'nullable|string|max:5000',
        ], [
            'name' => 'Nama customer', 'company' => 'Perusahaan', 'pic' => 'Contact person', 'phone' => 'Telepon',
            'email' => 'Email', 'address' => 'Alamat', 'industry' => 'Industri', 'status' => 'Status',
            'source' => 'Sumber', 'marketing_pic_id' => 'PIC Marketing', 'notes' => 'Catatan',
        ]);
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = $_POST[$f] ?? '';
        }
        $old['confirm_not_duplicate'] = $_POST['confirm_not_duplicate'] ?? '0';
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $customer): array
    {
        return [
            'title'      => $customer ? 'Edit ' . $customer['name'] : 'Tambah Customer',
            'customer'   => $customer,
            'pics'       => User::picOptions(),
            'industries' => Customer::industries(),
            'sources'    => Customer::sources(),
            'similar'    => [],
            'me'         => Auth::id(),
        ];
    }
}
