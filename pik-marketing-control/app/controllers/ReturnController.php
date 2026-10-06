<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\HttpException;
use App\Helpers\Mailer;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use App\Models\ReturnAttachment;
use App\Models\Setting;
use App\Services\ComplaintMailer;
use DomainException;
use Throwable;

/**
 * Retur & Komplain (satu menu): bukti gambar/PDF, email QC, dan hasil
 * penyelesaian "Selesai" (hijau) / "Tidak selesai" + alasan (merah).
 */
final class ReturnController extends Controller
{
    private const FIELDS = ['case_type', 'po_line_id', 'delivery_id', 'return_date', 'sj_number', 'destination', 'qty', 'reason', 'attachment', 'note', 'qc_email', 'send_email'];

    public function index(): void
    {
        $filters = [
            'q'           => Request::queryString('q'),
            'type'        => Request::queryString('type'),
            'resolution'  => Request::queryString('resolution'),
            'reason'      => Request::queryString('reason'),
            'customer_id' => Request::queryInt('customer_id'),
            'from'        => Validator::parseDate(Request::queryString('from')) ?? '',
            'to'          => Validator::parseDate(Request::queryString('to')) ?? '',
            'link'        => Request::queryString('link'),
        ];
        $this->view('returns/index', [
            'title'     => 'Retur & Komplain',
            'returns'   => ProductReturn::paginate($filters, $this->page()),
            'summary'   => ProductReturn::summary($filters),
            'filters'   => $filters,
            'customers' => Customer::selectOptions(),
        ]);
    }

    public function create(): void
    {
        $lineId = Request::queryInt('po_line_id') ?: null;
        $poId = Request::queryInt('po_id') ?: null;
        if ($lineId) {
            $poId = (int) (PoLine::find($lineId)['po_id'] ?? 0) ?: null;
        }
        $preset = [
            'case_type' => 'Komplain', 'po_line_id' => $lineId, 'delivery_id' => Request::queryInt('delivery_id') ?: null, 'return_date' => today(),
            'qc_email' => Setting::get('qc_email'), 'send_email' => '1',
        ];
        $this->view('returns/form', $this->formData(null, $poId, $lineId) + ['errors' => [], 'preset' => $preset]);
    }

    public function store(): void
    {
        $v = $this->validate(null);
        [$files, $fileErrors] = ReturnAttachment::fromRequest('evidence');
        $errors = $v->errors();
        if ($fileErrors !== []) {
            $errors['evidence'] = implode(' ', $fileErrors);
        }
        if ($errors !== []) {
            $lineId = (int) ($_POST['po_line_id'] ?? 0) ?: null;
            $this->invalid('returns/form', $this->formData(null, null, $lineId) + ['preset' => []], $errors, $this->old());
            return;
        }
        $data = $this->data($v->validated());
        $moved = [];
        try {
            $id = Database::transaction(function () use ($data, $files, &$moved): int {
                $id = ProductReturn::saveReturn(null, $data);
                $moved = ReturnAttachment::store($id, $files);
                return $id;
            });
        } catch (Throwable $e) {
            ReturnAttachment::discard($moved);
            if (!$e instanceof DomainException) {
                throw $e;
            }
            $this->invalid('returns/form', $this->formData(null, null, (int) $data['po_line_id']) + ['preset' => []], ['po_line_id' => $e->getMessage()], $this->old());
            return;
        }
        $msg = ($data['case_type'] === 'Retur' ? 'Retur tersimpan. Outstanding OEF bertambah sesuai qty retur.' : 'Komplain tersimpan.')
            . (count($files) > 0 ? ' ' . count($files) . ' file bukti diunggah.' : '');
        if (($_POST['send_email'] ?? '') === '1' && $data['qc_email'] !== null) {
            $result = ComplaintMailer::send($id);
            $msg .= ' ' . ComplaintMailer::describe($result);
            if ($result !== null && $result['status'] === Mailer::FAILED) {
                $this->failure($msg, '/returns/' . $id);
            }
        }
        $this->success($msg, '/returns/' . $id);
    }

    public function show(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $this->view('returns/show', [
            'title'  => $r['case_type'] . ' ' . $r['code'],
            'ret'    => $r,
            'files'  => ReturnAttachment::forReturn($id),
            'emails' => EmailLog::forEntity('return', $id),
            'line'   => $r['po_line_id'] ? PoLine::findFull((int) $r['po_line_id']) : null,
            'issues' => Auth::can('migration.view') ? MigrationIssue::openForRecord('RETURNS', $id) : [],
        ]);
    }

    public function edit(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $r['qty'] = $r['return_qty'] ?? $r['affected_qty'];
        $this->view('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null) + ['errors' => [], 'preset' => []]);
    }

    public function update(int $id): void
    {
        $r = $this->found(ProductReturn::findFull($id));
        $v = $this->validate($r);
        [$files, $fileErrors] = ReturnAttachment::fromRequest('evidence', ReturnAttachment::countFor($id));
        $errors = $v->errors();
        if ($fileErrors !== []) {
            $errors['evidence'] = implode(' ', $fileErrors);
        }
        if ($errors !== []) {
            $r['qty'] = $r['return_qty'] ?? $r['affected_qty'];
            $this->invalid('returns/form', $this->formData($r, $r['po_id'] ? (int) $r['po_id'] : null, $r['po_line_id'] ? (int) $r['po_line_id'] : null) + ['preset' => []], $errors, $this->old());
            return;
        }
        $data = $this->data($v->validated());
        $moved = [];
        try {
            Database::transaction(function () use ($id, $data, $files, &$moved): void {
                ProductReturn::saveReturn($id, $data);
                $moved = ReturnAttachment::store($id, $files);
            });
        } catch (Throwable $e) {
            ReturnAttachment::discard($moved);
            if (!$e instanceof DomainException) {
                throw $e;
            }
            $this->failure($e->getMessage(), '/returns/' . $id . '/edit');
        }
        $msg = 'Data ' . strtolower($data['case_type']) . ' diperbarui.' . (count($files) > 0 ? ' ' . count($files) . ' file bukti ditambahkan.' : '');
        if (($_POST['send_email'] ?? '') === '1' && $data['qc_email'] !== null) {
            $result = ComplaintMailer::send($id);
            $msg .= ' ' . ComplaintMailer::describe($result);
        }
        $this->success($msg, '/returns/' . $id);
    }

    public function destroy(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        ProductReturn::remove($id);
        $this->success($r['case_type'] . ' ' . $r['code'] . ' dihapus beserta file buktinya.', $r['po_id'] && Auth::can('purchase_orders.view') ? '/purchase-orders/' . $r['po_id'] : '/returns');
    }

    /** Tombol hijau "Selesai" / merah "Tidak selesai" (+ alasan). Hasil dikirim ke email QC. */
    public function resolve(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        $status = (string) ($_POST['resolution_status'] ?? '');
        $note = (string) ($_POST['resolution_note'] ?? '');
        if (mb_strlen($note) > 2000) {
            $this->failure('Catatan maksimal 2000 karakter.', '/returns/' . $id);
        }
        if ($r['resolution_status'] !== 'Open') {
            $this->failure('Kasus ini sudah diberi hasil. Buka kembali dulu bila ingin mengubah.', '/returns/' . $id);
        }
        try {
            ProductReturn::resolve($id, $status, $note);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/returns/' . $id);
        }
        $msg = $r['case_type'] . ' ' . $r['code'] . ' ditandai ' . strtoupper($status) . '.';
        $result = ComplaintMailer::send($id);
        $msg .= ' ' . ComplaintMailer::describe($result);
        if ($result !== null && $result['status'] === Mailer::FAILED) {
            $this->failure($msg, '/returns/' . $id);
        }
        $this->success($msg, '/returns/' . $id);
    }

    public function reopen(int $id): void
    {
        $r = $this->found(ProductReturn::find($id));
        if ($r['resolution_status'] === 'Open') {
            $this->failure('Kasus ini masih terbuka.', '/returns/' . $id);
        }
        ProductReturn::reopen($id);
        $this->success($r['case_type'] . ' ' . $r['code'] . ' dibuka kembali.', '/returns/' . $id);
    }

    /** Kirim ulang hasil ke email QC. */
    public function email(int $id): void
    {
        $this->found(ProductReturn::find($id));
        $result = ComplaintMailer::send($id);
        $msg = ComplaintMailer::describe($result);
        if ($result === null || $result['status'] === Mailer::FAILED) {
            $this->failure($msg, '/returns/' . $id);
        }
        $this->success($msg, '/returns/' . $id);
    }

    /** Tambah bukti dari halaman detail. */
    public function upload(int $id): void
    {
        $this->found(ProductReturn::find($id));
        [$files, $fileErrors] = ReturnAttachment::fromRequest('evidence', ReturnAttachment::countFor($id));
        if ($fileErrors !== []) {
            $this->failure('Bukti gagal diunggah: ' . implode(' ', $fileErrors), '/returns/' . $id);
        }
        if ($files === []) {
            $this->failure('Pilih file gambar atau PDF terlebih dahulu.', '/returns/' . $id);
        }
        $moved = [];
        try {
            Database::transaction(function () use ($id, $files, &$moved): void {
                $moved = ReturnAttachment::store($id, $files);
            });
        } catch (Throwable $e) {
            ReturnAttachment::discard($moved);
            if (!$e instanceof DomainException) {
                throw $e;
            }
            $this->failure($e->getMessage(), '/returns/' . $id);
        }
        $this->success(count($files) . ' file bukti diunggah.', '/returns/' . $id);
    }

    /** Tampilkan / unduh file bukti (hanya user yang berhak melihat retur). */
    public function file(int $id, int $file_id): void
    {
        $att = $this->found(ReturnAttachment::findFor($id, $file_id));
        $path = ReturnAttachment::path($att);
        if ($path === null || !isset(ReturnAttachment::TYPES[$att['mime_type']])) {
            throw new HttpException(404, 'File bukti tidak ditemukan di server.');
        }
        $download = Request::queryString('download') === '1';
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $att['original_name']) ?: 'bukti';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $att['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode((string) $att['original_name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        if ($att['mime_type'] !== 'application/pdf') {
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        readfile($path);
        exit;
    }

    public function deleteFile(int $id, int $file_id): void
    {
        $att = $this->found(ReturnAttachment::findFor($id, $file_id));
        ReturnAttachment::remove($att);
        $this->success('File bukti "' . $att['original_name'] . '" dihapus.', '/returns/' . $id);
    }

    private function validate(?array $existing): Validator
    {
        $legacyUnlinked = $existing !== null && $existing['po_line_id'] === null;
        $type = (string) ($_POST['case_type'] ?? '');
        $v = Validator::make($_POST, [
            'case_type'   => ['required', ['in', ProductReturn::CASE_TYPES]],
            'po_line_id'  => ($legacyUnlinked ? 'nullable' : 'required') . '|integer|exists:po_lines,id',
            'delivery_id' => 'nullable|integer|exists:deliveries,id',
            'return_date' => 'required|date',
            'sj_number'   => 'nullable|string|max:60',
            'destination' => 'nullable|string|max:255',
            'qty'         => ($type === 'Komplain' ? 'nullable' : 'required') . '|integer|min:1',
            'reason'      => ['required', ['in', ProductReturn::REASONS]],
            'attachment'  => 'nullable|url|max:500',
            'note'        => ($type === 'Komplain' ? 'required' : 'nullable') . '|string|max:5000',
            'qc_email'    => 'nullable|string|max:500',
        ], [
            'case_type' => 'Jenis', 'po_line_id' => 'Produk OEF', 'delivery_id' => 'Surat jalan asal', 'return_date' => 'Tanggal',
            'sj_number' => 'Nomor dokumen retur', 'destination' => 'Asal/lokasi', 'qty' => $type === 'Komplain' ? 'Qty bermasalah' : 'Qty retur',
            'reason' => 'Alasan', 'attachment' => 'Link lampiran', 'note' => 'Detail masalah', 'qc_email' => 'Email QC',
        ]);
        $qc = $v->validated()['qc_email'] ?? null;
        if ($qc !== null && Mailer::parseList((string) $qc) === null) {
            $v->addError('qc_email', 'Email QC tidak valid. Pisahkan beberapa email dengan koma (maks. 5).');
        }
        return $v;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function data(array $d): array
    {
        $qty = $d['qty'];
        unset($d['qty']);
        $d['return_qty'] = $d['case_type'] === 'Retur' ? $qty : null;
        $d['affected_qty'] = $d['case_type'] === 'Komplain' ? $qty : null;
        $d['qc_email'] = $d['qc_email'] !== null ? (implode(', ', Mailer::parseList((string) $d['qc_email']) ?? []) ?: null) : null;
        return $d;
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
            'title'       => $return ? 'Edit ' . $return['case_type'] : 'Catat Retur / Komplain',
            'ret'         => $return,
            'lineOptions' => PurchaseOrder::lineOptions(false, $lineId, $poId),
            'deliveries'  => ProductReturn::deliveryOptions($lineId),
            'reasons'     => $reasons,
            'files'       => $return ? ReturnAttachment::forReturn((int) $return['id']) : [],
            'return'      => $this->returnTo(''),
        ];
    }
}
