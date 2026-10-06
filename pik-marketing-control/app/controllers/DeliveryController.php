<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use DomainException;

final class DeliveryController extends Controller
{
    private const FIELDS = ['po_line_id', 'delivery_date', 'sj_number', 'destination', 'delivered_qty', 'status', 'note', 'attachment', 'confirm_over_delivery'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'status'      => Request::queryString('status'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'        => Request::queryString('link'),
            'sj'          => Request::queryString('sj') === 'missing' ? 'missing' : '',
        ];
        $this->view('deliveries/index', [
            'title'      => 'Deliveries',
            'deliveries' => Delivery::paginate($filters, $this->page()),
            'summary'    => Delivery::summary($filters),
            'filters'    => $filters,
            'customers'  => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $lineId = Request::queryInt('po_line_id') ?: null;
        $poId = Request::queryInt('po_id') ?: null;
        $line = $lineId ? PoLine::findFull($lineId) : null;
        if ($line !== null) {
            $poId = (int) $line['po_id'];
        }
        $destination = null;
        if ($poId) {
            $row = Database::fetch('SELECT p.delivery_address, c.name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = :id', ['id' => $poId]);
            $destination = $row !== null ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($row['delivery_address'] ?? $row['name'] ?? ''))), 0, 255) : null;
        }
        $preset = ['po_line_id' => $lineId, 'delivery_date' => today(), 'status' => 'Scheduled', 'destination' => $destination ?: null];
        $this->view('deliveries/form', $this->formData(null, $poId, $lineId) + ['errors' => [], 'preset' => $preset, 'line' => $line]);
    }

    public function store(): void
    {
        $v = $this->validate(null);
        if ($v->fails()) {
            $this->invalid('deliveries/form', $this->formData(null, null, (int) ($_POST['po_line_id'] ?? 0) ?: null) + ['preset' => [], 'line' => null], $v->errors(), $this->old());
            return;
        }
        $data = $this->withSjRule($v->validated(), null);
        unset($data['confirm_over_delivery']);
        $id = Delivery::saveDelivery(null, $data);
        $po = Database::fetchValue('SELECT po_id FROM deliveries WHERE id = :id', ['id' => $id]);
        $this->success('Delivery ' . ($data['sj_number'] ?? '') . ' tersimpan. Outstanding OEF diperbarui otomatis.', $this->returnTo('/purchase-orders/' . $po));
    }

    public function show(int $id): void
    {
        $d = $this->found(Delivery::findFull($id));
        $this->view('deliveries/show', [
            'title'     => 'Delivery ' . ($d['sj_number'] ?? $d['code']),
            'delivery'  => $d,
            'line'      => $d['po_line_id'] ? PoLine::findFull((int) $d['po_line_id']) : null,
            'returns'   => Database::fetchAll('SELECT * FROM returns WHERE delivery_id = :id', ['id' => $id]),
            'issues'    => Auth::can('migration.view') || Auth::can('deliveries.edit') ? MigrationIssue::openForRecord('DELIVERIES', $id) : [],
            'lineOptions' => $d['po_line_id'] === null && Auth::can('deliveries.edit') ? $this->linkOptions($d) : [],
        ]);
    }

    public function edit(int $id): void
    {
        $d = $this->found(Delivery::findFull($id));
        $this->view('deliveries/form', $this->formData($d, $d['po_id'] ? (int) $d['po_id'] : null, $d['po_line_id'] ? (int) $d['po_line_id'] : null) + [
            'errors' => [], 'preset' => [], 'line' => $d['po_line_id'] ? PoLine::findFull((int) $d['po_line_id']) : null,
        ]);
    }

    public function update(int $id): void
    {
        $d = $this->found(Delivery::findFull($id));
        $v = $this->validate($d);
        if ($v->fails()) {
            $this->invalid('deliveries/form', $this->formData($d, $d['po_id'] ? (int) $d['po_id'] : null, $d['po_line_id'] ? (int) $d['po_line_id'] : null) + ['preset' => [], 'line' => null], $v->errors(), $this->old());
            return;
        }
        $data = $this->withSjRule($v->validated(), $d);
        unset($data['confirm_over_delivery']);
        if ($d['schedule_source'] === Delivery::SOURCE_OEF) {
            // jadwal otomatis yang sudah diubah user tidak lagi disesuaikan otomatis oleh OEF
            $data['schedule_source'] = Delivery::SOURCE_OEF_EDITED;
        }
        Delivery::saveDelivery($id, $data);
        $this->success('Delivery diperbarui.', $this->returnTo('/deliveries/' . $id));
    }

    /** Hubungkan delivery legacy ke baris PO (menyelesaikan migration issue terkait). */
    public function link(int $id): void
    {
        $d = $this->found(Delivery::find($id));
        $lineId = (int) ($_POST['po_line_id'] ?? 0);
        $allowed = $this->linkOptions($d);
        $valid = false;
        foreach ($allowed as $group) {
            if (isset($group[$lineId])) {
                $valid = true;
            }
        }
        if (!$valid) {
            $this->failure('Pilih produk OEF yang valid' . ($d['po_id'] ? ' dari OEF delivery ini.' : '.'), '/deliveries/' . $id);
        }
        Delivery::saveDelivery($id, [
            'po_line_id' => $lineId, 'delivery_date' => $d['delivery_date'], 'sj_number' => $d['sj_number'], 'destination' => $d['destination'],
            'delivered_qty' => $d['delivered_qty'], 'status' => $d['status'], 'note' => $d['note'], 'attachment' => $d['attachment'],
        ]);
        $this->success('Delivery dihubungkan ke produk OEF. Outstanding & migration issue diperbarui.', $this->returnTo('/deliveries/' . $id));
    }

    public function destroy(int $id): void
    {
        $d = $this->found(Delivery::find($id));
        try {
            Delivery::remove($id);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/deliveries/' . $id);
        }
        $this->success('Delivery ' . ($d['sj_number'] ?? $d['code']) . ' dihapus.', $d['po_id'] ? '/purchase-orders/' . $d['po_id'] : '/deliveries');
    }

    /**
     * Nomor Surat Jalan hanya boleh diisi/diubah role dengan deliveries.sj (PPIC).
     * Untuk role lain nilai SJ yang tersimpan dipertahankan (atau kosong untuk data baru).
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function withSjRule(array $data, ?array $existing): array
    {
        if (!Auth::can('deliveries.sj')) {
            $data['sj_number'] = $existing['sj_number'] ?? null;
        }
        return $data;
    }

    /** @return array<string,array<int,string>> baris PO yang boleh dipilih untuk delivery legacy */
    private function linkOptions(array $d): array
    {
        return PurchaseOrder::lineOptions(false, null, $d['po_id'] ? (int) $d['po_id'] : null);
    }

    private function validate(?array $existing): Validator
    {
        $v = Validator::make($_POST, [
            'po_line_id'            => ($existing !== null && $existing['po_line_id'] === null ? 'nullable' : 'required') . '|integer|exists:po_lines,id',
            'delivery_date'         => 'required|date',
            'sj_number'             => 'nullable|string|max:60',
            'destination'           => 'nullable|string|max:255',
            'delivered_qty'         => 'required|integer|min:1',
            'status'                => ['required', ['in', Delivery::STATUSES]],
            'note'                  => 'nullable|string|max:2000',
            'attachment'            => 'nullable|url|max:500',
            'confirm_over_delivery' => 'boolean',
        ], [
            'po_line_id' => 'Produk OEF', 'delivery_date' => 'Tanggal delivery', 'sj_number' => 'Nomor surat jalan', 'destination' => 'Tujuan',
            'delivered_qty' => 'Qty', 'status' => 'Status', 'note' => 'Catatan', 'attachment' => 'Link lampiran',
        ]);
        $data = $v->validated();
        if (!$v->fails() && $data['po_line_id'] !== null && in_array($data['status'], Delivery::COUNTED_STATUSES, true) && (int) $data['confirm_over_delivery'] !== 1) {
            $totals = PoLine::totals((int) $data['po_line_id']);
            $available = (int) ($totals['outstanding_qty'] ?? 0);
            // saat edit, qty lama milik delivery ini dikembalikan dulu ke outstanding
            if ($existing !== null && (int) $existing['po_line_id'] === (int) $data['po_line_id'] && in_array($existing['status'], Delivery::COUNTED_STATUSES, true)) {
                $available += (int) $existing['delivered_qty'];
            }
            if ((int) $data['delivered_qty'] > $available) {
                $v->addError('delivered_qty', 'Qty melebihi outstanding produk OEF (' . number_format(max(0, $available), 0, ',', '.') . ' pcs). Centang "Konfirmasi kelebihan kirim" bila memang over delivery.');
            }
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
    private function formData(?array $delivery, ?int $poId, ?int $lineId): array
    {
        return [
            'title'       => $delivery ? 'Edit Delivery' : 'Catat Delivery',
            'delivery'    => $delivery,
            'lineOptions' => PurchaseOrder::lineOptions(true, $lineId, $poId),
            'poId'        => $poId,
            'return'      => $this->returnTo(''),
        ];
    }
}
