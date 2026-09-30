<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Number;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MigrationIssue;
use App\Models\Setting;
use DomainException;

final class InvoiceController extends Controller
{
    private const FIELDS = ['customer_id', 'po_id', 'invoice_number', 'invoice_date', 'due_date', 'invoice_amount', 'paid_amount', 'invoice_attachment', 'notes'];

    public function index(): void
    {
        Invoice::refreshStatuses(today());
        $filters = [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'due'         => Request::queryString('due'),
            'link'        => Request::queryString('link'),
        ];
        $sort = Request::queryString('sort', 'date');
        $dir = Request::queryString('dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $this->view('invoices/index', [
            'title'     => 'Invoice & Payment',
            'invoices'  => Invoice::paginate($filters, $sort, $dir, today(), $this->page()),
            'summary'   => Invoice::summary($filters, today()),
            'filters'   => $filters,
            'sort'      => $sort,
            'dir'       => $dir,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function show(int $id): void
    {
        Invoice::refreshStatuses(today());
        $invoice = $this->found(Invoice::findFull($id));
        $this->view('invoices/show', [
            'title'    => 'Invoice ' . ($invoice['invoice_number'] ?? $invoice['code']),
            'invoice'  => $invoice,
            'payments' => Invoice::paymentHistory($id),
            'issues'   => Auth::can('migration.view') ? MigrationIssue::openForRecord('INVOICES_PAYMENTS', $id) : [],
            'history'  => AuditLog::forEntity('invoice', $id, 8),
            'errors'   => [],
        ]);
    }

    public function create(): void
    {
        $poId = Request::queryInt('po_id') ?: null;
        $customerId = Request::queryInt('customer_id') ?: null;
        if ($poId !== null) {
            $customerId = (int) (Database::fetchValue('SELECT customer_id FROM purchase_orders WHERE id = :id', ['id' => $poId]) ?? 0) ?: $customerId;
        }
        $preset = ['customer_id' => $customerId, 'po_id' => $poId, 'invoice_date' => today()];
        $this->view('invoices/form', $this->formData(null, $customerId, $poId) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate(null);
        if ($v->fails()) {
            $cid = (int) ($_POST['customer_id'] ?? 0) ?: null;
            $this->invalid('invoices/form', $this->formData(null, $cid, null) + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = $this->withDueDate($v->validated());
        unset($data['paid_amount']);
        $id = Invoice::saveInvoice(null, $data, today());
        $this->success('Invoice ' . $data['invoice_number'] . ' tersimpan. Jatuh tempo ' . fmt_date($data['due_date']) . '.', '/invoices/' . $id);
    }

    public function edit(int $id): void
    {
        $invoice = $this->found(Invoice::find($id));
        $this->view('invoices/form', $this->formData($invoice, $invoice['customer_id'] !== null ? (int) $invoice['customer_id'] : null, $invoice['po_id'] !== null ? (int) $invoice['po_id'] : null)
            + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $invoice = $this->found(Invoice::find($id));
        $v = $this->validate($invoice);
        if ($v->fails()) {
            $this->invalid('invoices/form', $this->formData($invoice, $invoice['customer_id'] !== null ? (int) $invoice['customer_id'] : null, $invoice['po_id'] !== null ? (int) $invoice['po_id'] : null)
                + ['preset' => []], $v->errors(), $this->old());
            return;
        }
        $data = $this->withDueDate($v->validated());
        try {
            Invoice::saveInvoice($id, $data, today());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/invoices/' . $id . '/edit');
        }
        $this->success('Invoice diperbarui.', '/invoices/' . $id);
    }

    /** Catat pembayaran (menambah total dibayar; tidak boleh melebihi sisa tagihan). */
    public function pay(int $id): void
    {
        $invoice = $this->found(Invoice::findFull($id));
        $v = Validator::make($_POST, [
            'amount'                 => 'required|numeric|min:0.01',
            'payment_date'           => 'required|date',
            'payment_receipt_number' => 'nullable|string|max:80',
            'payment_attachment'     => 'nullable|url|max:500',
            'note'                   => 'nullable|string|max:500',
        ], [
            'amount' => 'Jumlah pembayaran', 'payment_date' => 'Tanggal pembayaran', 'payment_receipt_number' => 'Nomor bukti bayar',
            'payment_attachment' => 'Link bukti bayar', 'note' => 'Catatan',
        ]);
        if (!$v->fails() && $v->validated()['payment_date'] > today()) {
            $v->addError('payment_date', 'Tanggal pembayaran tidak boleh di masa depan.');
        }
        if ($v->fails()) {
            $this->invalid('invoices/show', [
                'title'    => 'Invoice ' . ($invoice['invoice_number'] ?? $invoice['code']),
                'invoice'  => $invoice,
                'payments' => Invoice::paymentHistory($id),
                'issues'   => Auth::can('migration.view') ? MigrationIssue::openForRecord('INVOICES_PAYMENTS', $id) : [],
                'history'  => AuditLog::forEntity('invoice', $id, 8),
            ], $v->errors(), [
                'amount' => $_POST['amount'] ?? '', 'payment_date' => $_POST['payment_date'] ?? '', 'payment_receipt_number' => $_POST['payment_receipt_number'] ?? '',
                'payment_attachment' => $_POST['payment_attachment'] ?? '', 'note' => $_POST['note'] ?? '',
            ]);
            return;
        }
        try {
            $result = Invoice::recordPayment($id, $v->validated(), today());
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/invoices/' . $id);
        }
        $message = $result['status'] === 'Paid'
            ? 'Pembayaran dicatat. Invoice LUNAS.'
            : 'Pembayaran dicatat. Sisa tagihan ' . fmt_money($result['outstanding']) . '.';
        $this->success($message, '/invoices/' . $id);
    }

    public function destroy(int $id): void
    {
        $invoice = $this->found(Invoice::find($id));
        try {
            Invoice::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/invoices/' . $id);
        }
        $this->success('Invoice ' . ($invoice['invoice_number'] ?? $invoice['code']) . ' dihapus.', '/invoices');
    }

    /** @param array<string,mixed>|null $current invoice yang diedit (null = baru) */
    private function validate(?array $current): Validator
    {
        $legacyNoCustomer = $current !== null && $current['customer_id'] === null;
        $rules = [
            'customer_id'        => ($legacyNoCustomer ? 'nullable' : 'required') . '|integer|exists:customers,id',
            'po_id'              => 'nullable|integer|exists:purchase_orders,id',
            'invoice_number'     => 'required|string|max:80',
            'invoice_date'       => 'required|date',
            'due_date'           => 'nullable|date|after_or_equal:invoice_date',
            'invoice_amount'     => 'required|numeric|min:0.01',
            'invoice_attachment' => 'nullable|url|max:500',
            'notes'              => 'nullable|string|max:5000',
        ];
        if ($current !== null) {
            // Koreksi total dibayar hanya lewat form edit (pembayaran normal lewat "Catat pembayaran")
            $rules['paid_amount'] = 'required|numeric|min:0';
        }
        $v = Validator::make($_POST, $rules, [
            'customer_id' => 'Customer', 'po_id' => 'PO', 'invoice_number' => 'Nomor invoice', 'invoice_date' => 'Tanggal invoice',
            'due_date' => 'Jatuh tempo', 'invoice_amount' => 'Nilai invoice', 'paid_amount' => 'Total dibayar',
            'invoice_attachment' => 'Link invoice', 'notes' => 'Catatan',
        ]);
        if (!$v->fails()) {
            $d = $v->validated();
            if (($taken = Invoice::numberTaken((string) $d['invoice_number'], $current !== null ? (int) $current['id'] : null)) !== null) {
                $v->addError('invoice_number', 'Nomor invoice sudah dipakai (' . $taken['code'] . ').');
            }
            if ($d['po_id'] !== null && $d['customer_id'] !== null) {
                $poCustomer = Database::fetchValue('SELECT customer_id FROM purchase_orders WHERE id = :id', ['id' => $d['po_id']]);
                if ($poCustomer !== null && (int) $poCustomer !== (int) $d['customer_id']) {
                    $v->addError('po_id', 'PO ini milik customer lain.');
                }
            }
            if (isset($d['paid_amount']) && Number::toCents($d['paid_amount']) > Number::toCents($d['invoice_amount'])) {
                $v->addError('paid_amount', 'Total dibayar tidak boleh melebihi nilai invoice.');
            }
        }
        return $v;
    }

    /** Isi jatuh tempo default bila dikosongkan. @param array<string,mixed> $data @return array<string,mixed> */
    private function withDueDate(array $data): array
    {
        if ($data['due_date'] === null) {
            $data['due_date'] = Invoice::defaultDueDate((string) $data['invoice_date'], $data['po_id'] !== null ? (int) $data['po_id'] : null);
        }
        return $data;
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
    private function formData(?array $invoice, ?int $customerId, ?int $poId): array
    {
        return [
            'title'     => $invoice ? 'Edit Invoice' : 'Buat Invoice',
            'invoice'   => $invoice,
            'customers' => Customer::selectOptions(),
            'pos'       => Invoice::poOptions($customerId, $poId),
            'dueDays'   => Setting::int('invoice_default_due_days', 30),
        ];
    }
}
