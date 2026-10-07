<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\HttpException;
use App\Helpers\Mailer;
use App\Helpers\Request;
use App\Helpers\Session;
use App\Helpers\Validator;
use App\Models\ComplaintAttachment;
use App\Models\Customer;
use App\Models\PoLine;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Services\ComplaintMailer;
use DomainException;
use RuntimeException;

/** Complaint & Return (menu gabungan). URL tetap /returns agar link lama tetap berlaku. */
final class ReturnController extends Controller
{
    private const FIELDS = ['record_type', 'customer_id', 'po_line_id', 'delivery_id', 'return_date', 'sj_number', 'destination', 'return_qty', 'reason',
        'complaint_detail', 'qc_email', 'attachment', 'note'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'type'        => Request::queryString('type'),
            'status'      => Request::queryString('status'),
            'reason'      => Request::queryString('reason'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'        => Request::queryString('link'),
        ];
        $this->view('returns/index', [
            'title'     => 'Complaint & Return',
            'returns'   => ProductReturn::paginate($filters, $this->page()),
            'counts'    => ProductReturn::statusCounts(),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function show(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $this->view('returns/show', [
            'title'       => 'Complaint ' . $r['code'],
            'ret'         => $r,
            'attachments' => ComplaintAttachment::forReturn($id),
            'mailReady'   => Mailer::configured(),
        ]);
    }

    public function create(): void
    {
        $lineId = Request::queryInt('po_line_id') ?: null;
        $poId = Request::queryInt('po_id') ?: null;
        if ($lineId) {
            $poId = (int) (PoLine::find($lineId)['po_id'] ?? 0) ?: null;
        }
        $customerId = Request::queryInt('customer_id') ?: null;
        if ($poId && !$customerId) {
            $customerId = (int) (PurchaseOrder::find($poId)['customer_id'] ?? 0) ?: null;
        }
        if (!$lineId && $poId) {
            $lines = PoLine::linesOfPo($poId);
            if (count($lines) === 1) {
                $lineId = (int) $lines[0]['id'];
            }
        }
        $preset = [
            'record_type' => Request::queryString('type') === 'Return' || Request::queryInt('delivery_id') ? 'Return' : 'Complaint',
            'customer_id' => $customerId,
            'po_line_id'  => $lineId,
            'delivery_id' => Request::queryInt('delivery_id') ?: null,
            'return_date' => today(),
            'qc_email'    => Setting::get('qc_default_email', ''),
        ];
        $this->view('returns/form', $this->formData(null, $poId, $lineId) + ['errors' => [], 'preset' => $preset, 'attachments' => []]);
    }

    public function store(): void
    {
        [$v, $files] = $this->validate(null);
        if ($v->fails()) {
            $lineId = (int) ($_POST['po_line_id'] ?? 0) ?: null;
            $this->invalid('returns/form', $this->formData(null, null, $lineId) + ['preset' => [], 'attachments' => []], $v->errors(), $this->old());
            return;
        }
        try {
            $id = ProductReturn::saveReturn(null, $this->payload($v->validated()), $files);
        } catch (DomainException | RuntimeException $e) {
            $this->failure($e->getMessage(), '/returns/create');
        }
        $msg = 'Complaint tersimpan' . ($files ? ' dengan ' . count($files) . ' file bukti' : '') . '.';
        $r = ProductReturn::find($id);
        if (($r['record_type'] ?? '') === 'Return') {
            $msg .= ' Outstanding order bertambah sesuai qty retur.';
        }
        $msg .= $this->mailMessage(ComplaintMailer::send($id, 'new'));
        $this->success($msg, '/returns/' . $id);
    }

    public function edit(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $this->view('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null)
            + ['errors' => [], 'preset' => [], 'attachments' => ComplaintAttachment::forReturn($id)]);
    }

    public function update(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        [$v, $files] = $this->validate($r);
        if ($v->fails()) {
            $this->invalid('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null)
                + ['preset' => [], 'attachments' => ComplaintAttachment::forReturn($id)], $v->errors(), $this->old());
            return;
        }
        try {
            ProductReturn::saveReturn($id, $this->payload($v->validated()), $files);
        } catch (DomainException | RuntimeException $e) {
            $this->failure($e->getMessage(), '/returns/' . $id . '/edit');
        }
        $this->success('Complaint diperbarui' . ($files ? ', ' . count($files) . ' file bukti ditambahkan' : '') . '.', $this->returnTo('/returns/' . $id));
    }

    /** Tombol Selesai (hijau) / Tidak selesai (merah, wajib alasan) / Buka kembali. */
    public function resolve(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        $outcome = (string) ($_POST['outcome'] ?? '');
        $note = is_string($_POST['resolution_note'] ?? null) ? mb_substr(trim($_POST['resolution_note']), 0, 5000) : null;
        try {
            ProductReturn::resolve($id, $outcome, $note);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/returns/' . $id);
        }
        if ($outcome === 'reopen') {
            $this->success('Complaint ' . $r['code'] . ' dibuka kembali.', '/returns/' . $id);
        }
        $msg = 'Complaint ' . $r['code'] . ' ditandai ' . ($outcome === 'resolved' ? 'SELESAI' : 'TIDAK SELESAI') . '. Hasil tercatat di laporan Complaint.';
        $msg .= $this->mailMessage(ComplaintMailer::send($id, 'result'));
        $this->success($msg, '/returns/' . $id);
    }

    /** Kirim ulang email ke QC. */
    public function email(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        $result = ComplaintMailer::send($id, 'manual');
        if ($result['status'] === 'skipped') {
            $this->failure('Isi kolom Email QC terlebih dahulu (Edit complaint).', '/returns/' . $id);
        }
        if ($result['status'] === 'failed') {
            $this->failure('Email ke QC gagal dikirim: ' . $result['error'], '/returns/' . $id);
        }
        $this->success('Email complaint ' . $r['code'] . ' terkirim ke ' . implode(', ', $result['to']) . '.', '/returns/' . $id);
    }

    /** Tampilkan / unduh file bukti (hanya lewat aplikasi, cek hak akses di route). */
    public function attachment(int $id): void
    {
        $att = $this->found(ComplaintAttachment::find($id));
        $path = ComplaintAttachment::path($att);
        if (!is_file($path)) {
            throw new HttpException(404, 'File bukti tidak ditemukan di server.');
        }
        $mime = in_array($att['mime_type'], ComplaintAttachment::TYPES, true) ? (string) $att['mime_type'] : 'application/octet-stream';
        $name = preg_replace('/[^A-Za-z0-9 ._\-()]+/', '_', (string) $att['original_name']) ?: 'bukti';
        $disposition = Request::queryString('download') === '1' ? 'attachment' : 'inline';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Content-Security-Policy');
        if (str_starts_with($mime, 'image/')) {
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . $disposition . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $att['original_name']));
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;
    }

    public function deleteAttachment(int $id): void
    {
        $att = $this->found(ComplaintAttachment::find($id));
        ComplaintAttachment::remove($att);
        $this->success('File bukti "' . $att['original_name'] . '" dihapus.', $this->returnTo('/returns/' . $att['return_id']));
    }

    public function destroy(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        ProductReturn::remove($id);
        $this->success('Complaint ' . $r['code'] . ' dihapus.', $r['po_id'] ? '/purchase-orders/' . $r['po_id'] : '/returns');
    }

    // ------------------------------------------------------------------ helpers

    /** @param array{status:string,error:?string,to:list<string>} $result */
    private function mailMessage(array $result): string
    {
        if ($result['status'] === 'sent') {
            return ' Email terkirim ke QC (' . implode(', ', $result['to']) . ').';
        }
        if ($result['status'] === 'failed') {
            Session::flash('warning', 'Email ke QC gagal dikirim: ' . $result['error'] . ' — periksa Settings › Email lalu klik "Kirim ulang email".');
        }
        return '';
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array{0:Validator,1:list<array{tmp:string,name:string,mime:string,size:int,ext:string}>}
     */
    private function validate(?array $existing): array
    {
        $type = (string) ($_POST['record_type'] ?? '');
        $isReturn = $type === 'Return';
        $legacyUnlinked = $existing !== null && $existing['po_line_id'] === null && $existing['record_type'] === 'Return';
        $v = Validator::make($_POST, [
            'record_type'      => ['required', ['in', ProductReturn::TYPES]],
            'customer_id'      => 'nullable|integer|exists:customers,id',
            'po_line_id'       => ($isReturn && !$legacyUnlinked ? 'required' : 'nullable') . '|integer|exists:po_lines,id',
            'delivery_id'      => 'nullable|integer|exists:deliveries,id',
            'return_date'      => 'required|date',
            'sj_number'        => 'nullable|string|max:60',
            'destination'      => 'nullable|string|max:255',
            'return_qty'       => ($isReturn ? 'required' : 'nullable') . '|integer|min:1',
            'reason'           => ['required', ['in', ProductReturn::REASONS]],
            'complaint_detail' => ($existing === null ? 'required' : 'nullable') . '|string|max:5000',
            'qc_email'         => 'nullable|string|max:500',
            'attachment'       => 'nullable|url|max:500',
            'note'             => 'nullable|string|max:2000',
        ], [
            'record_type' => 'Jenis', 'customer_id' => 'Customer', 'po_line_id' => 'Order & produk', 'delivery_id' => 'Surat jalan asal',
            'return_date' => 'Tanggal complaint', 'sj_number' => 'Nomor dokumen', 'destination' => 'Asal / lokasi', 'return_qty' => 'Qty retur',
            'reason' => 'Alasan', 'complaint_detail' => 'Detail complaint', 'qc_email' => 'Email QC', 'attachment' => 'Link lampiran', 'note' => 'Catatan',
        ]);
        $data = $v->validated();
        if (!$v->fails() && empty($data['po_line_id']) && empty($data['customer_id']) && !$legacyUnlinked) {
            $v->addError('customer_id', 'Pilih customer, atau pilih order & produk yang dikomplain.');
        }
        if (!empty($data['qc_email'])) {
            $list = Mailer::parseList((string) $data['qc_email']);
            if ($list['invalid'] !== []) {
                $v->addError('qc_email', 'Email tidak valid: ' . implode(', ', $list['invalid']));
            }
        }
        $existingCount = $existing !== null ? count(ComplaintAttachment::forReturn((int) $existing['id'])) : 0;
        $upload = ComplaintAttachment::fromUpload('evidence', $existingCount);
        if ($upload['errors'] !== []) {
            $v->addError('evidence', implode(' ', $upload['errors']));
        }
        return [$v, $upload['files']];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function payload(array $data): array
    {
        if (!empty($data['qc_email'])) {
            $data['qc_email'] = implode(', ', Mailer::parseList((string) $data['qc_email'])['valid']);
        }
        if ($data['record_type'] === 'Complaint') {
            $data['return_qty'] = null;
        }
        if ($data['complaint_detail'] === null) {
            unset($data['complaint_detail']);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private function old(): array
    {
        $old = [];
        foreach (self::FIELDS as $f) {
            $old[$f] = is_scalar($_POST[$f] ?? null) ? (string) $_POST[$f] : '';
        }
        return $old;
    }

    /** @return array<string,mixed> */
    private function formData(?array $return, ?int $poId, ?int $lineId): array
    {
        $reasons = [];
        foreach (ProductReturn::REASONS as $r) {
            $reasons[$r] = ProductReturn::REASON_LABELS[$r] . ' (' . $r . ')';
        }
        return [
            'title'       => $return ? 'Edit Complaint ' . $return['code'] : 'Catat Complaint',
            'ret'         => $return,
            'customers'   => Customer::selectOptions(),
            'lineOptions' => PurchaseOrder::lineOptions(false, $lineId, $poId),
            'deliveries'  => ProductReturn::deliveryOptions($lineId),
            'reasons'     => $reasons,
            'maxBytes'    => ComplaintAttachment::maxBytes(),
            'return'      => $this->returnTo(''),
        ];
    }
}
