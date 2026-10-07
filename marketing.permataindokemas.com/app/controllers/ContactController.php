<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Contact;
use App\Models\Customer;
use DomainException;

final class ContactController extends Controller
{
    private const FIELDS = ['customer_id', 'name', 'position', 'phone', 'whatsapp', 'email', 'is_primary', 'status', 'notes'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'customer_id' => Request::queryInt('customer_id'),
            'status'      => Request::queryString('status'),
        ];
        $this->view('contacts/index', [
            'title'     => 'Contacts',
            'contacts'  => Contact::paginate($filters, $this->page()),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $customerId = Request::queryInt('customer_id');
        $this->view('contacts/form', $this->formData(null) + [
            'errors'  => [],
            'preset'  => ['customer_id' => $customerId ?: null, 'status' => 'Active'],
        ]);
    }

    public function store(): void
    {
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('contacts/form', $this->formData(null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = $v->validated();
        Contact::save(null, $data);
        $this->success('Kontak berhasil ditambahkan.', $this->returnTo('/customers/' . $data['customer_id'] . '?tab=contacts'));
    }

    public function edit(int $id): void
    {
        $contact = $this->found(Contact::findWithCustomer($id));
        $this->view('contacts/form', $this->formData($contact) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $contact = $this->found(Contact::findWithCustomer($id));
        $v = $this->validate();
        if ($v->fails()) {
            $this->invalid('contacts/form', $this->formData($contact) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = $v->validated();
        Contact::save($id, $data);
        $this->success('Kontak diperbarui.', $this->returnTo('/customers/' . $data['customer_id'] . '?tab=contacts'));
    }

    public function destroy(int $id): void
    {
        $contact = $this->found(Contact::find($id));
        try {
            Contact::delete($id, $contact);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/contacts/' . $id . '/edit');
        }
        $this->success('Kontak "' . $contact['name'] . '" dihapus.', $this->returnTo('/customers/' . $contact['customer_id'] . '?tab=contacts'));
    }

    private function validate(): Validator
    {
        return Validator::make($_POST, [
            'customer_id' => 'required|integer|exists:customers,id',
            'name'        => 'required|string|max:150',
            'position'    => 'nullable|string|max:100',
            'phone'       => 'nullable|phone|max:40',
            'whatsapp'    => 'nullable|phone|max:40',
            'email'       => 'nullable|email|max:190',
            'is_primary'  => 'boolean',
            'status'      => ['required', ['in', Contact::STATUSES]],
            'notes'       => 'nullable|string|max:2000',
        ], [
            'customer_id' => 'Customer', 'name' => 'Nama', 'position' => 'Jabatan', 'phone' => 'Telepon',
            'whatsapp' => 'WhatsApp', 'email' => 'Email', 'status' => 'Status', 'notes' => 'Catatan',
        ]);
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
    private function formData(?array $contact): array
    {
        return [
            'title'     => $contact ? 'Edit Kontak' : 'Tambah Kontak',
            'contact'   => $contact,
            'customers' => Customer::selectOptions(),
            'return'    => $this->returnTo(''),
        ];
    }
}
