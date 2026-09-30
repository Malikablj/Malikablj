<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Request;
use App\Helpers\Validator;
use App\Models\Delivery;
use App\Models\MigrationIssue;
use App\Models\PoLine;
use DomainException;

/** Tinjauan data migrasi: selesaikan, abaikan, atau pilih nilai master / legacy. */
final class MigrationIssueController extends Controller
{
    public function index(): void
    {
        $filters = [
            'q'      => Request::queryString('q'),
            'table'  => Request::queryString('table'),
            'type'   => Request::queryString('type'),
            'status' => Request::queryString('status', 'Needs Review'),
        ];
        $this->view('migration_issues/index', [
            'title'      => 'Migration Issues',
            'issues'     => MigrationIssue::paginate($filters, $this->page()),
            'filters'    => $filters,
            'counts'     => MigrationIssue::statusCounts(),
            'summary'    => MigrationIssue::typeSummary($filters['status']),
            'tables'     => MigrationIssue::tables(),
            'types'      => MigrationIssue::types(),
            'candidates' => MigrationIssue::singleLineDeliveryCount(),
        ]);
    }

    public function show(int $id): void
    {
        $issue = $this->found(MigrationIssue::find($id));
        $this->view('migration_issues/show', [
            'title'  => 'Issue ' . $issue['code'],
            'issue'  => $issue,
            'link'   => MigrationIssue::recordLink($issue),
            'target' => MigrationIssue::valueTarget($issue),
            'return' => $this->returnTo(''),
        ]);
    }

    /** Ubah status: Resolved / Ignored / Needs Review (buka kembali). */
    public function status(int $id): void
    {
        $this->found(MigrationIssue::find($id));
        $v = $this->validateNote(['required', ['in', ['Resolved', 'Ignored', 'Needs Review']]]);
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/migration-issues/' . $id);
        }
        $d = $v->validated();
        MigrationIssue::setStatus($id, (string) $d['status'], $d['note']);
        $label = ['Resolved' => 'ditandai selesai', 'Ignored' => 'diabaikan', 'Needs Review' => 'dibuka kembali'][$d['status']];
        $this->success('Issue ' . $label . '.', $this->returnTo('/migration-issues/' . $id));
    }

    /** Pakai nilai master atau nilai spreadsheet legacy untuk kolom yang dipermasalahkan. */
    public function apply(int $id): void
    {
        $this->found(MigrationIssue::find($id));
        $v = Validator::make($_POST, [
            'which' => ['required', ['in', ['master', 'suggested']]],
            'note'  => 'nullable|string|max:1000',
        ], ['which' => 'Pilihan nilai', 'note' => 'Catatan']);
        if ($v->fails()) {
            $this->failure(implode(' ', $v->errors()), '/migration-issues/' . $id);
        }
        try {
            MigrationIssue::applyValue($id, (string) $v->validated()['which'], $v->validated()['note']);
        } catch (DomainException $e) {
            $this->failure($e->getMessage(), '/migration-issues/' . $id);
        }
        $this->success('Nilai diterapkan dan issue ditandai selesai.', $this->returnTo('/migration-issues/' . $id));
    }

    /** Tandai beberapa issue sekaligus (dari daftar). */
    public function bulk(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
        $v = $this->validateNote(['required', ['in', ['Resolved', 'Ignored']]]);
        if ($ids === [] || $v->fails()) {
            $this->failure($ids === [] ? 'Pilih minimal satu issue.' : implode(' ', $v->errors()), $this->returnTo('/migration-issues'));
        }
        $d = $v->validated();
        $done = 0;
        foreach (array_slice($ids, 0, 200) as $id) {
            if (MigrationIssue::find($id) !== null) {
                MigrationIssue::setStatus($id, (string) $d['status'], $d['note']);
                $done++;
            }
        }
        $this->success($done . ' issue ' . ($d['status'] === 'Resolved' ? 'ditandai selesai.' : 'diabaikan.'), $this->returnTo('/migration-issues'));
    }

    /** Tinjau delivery legacy yang PO-nya hanya punya satu baris (konfirmasi manual per baris). */
    public function deliveries(): void
    {
        $page = $this->page();
        $perPage = 50;
        $total = MigrationIssue::singleLineDeliveryCount();
        $this->view('migration_issues/deliveries', [
            'title'      => 'Tinjau Delivery Legacy',
            'rows'       => MigrationIssue::singleLineDeliveryCandidates($perPage, ($page - 1) * $perPage),
            'total'      => $total,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function linkDeliveries(): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['delivery_ids'] ?? [])))));
        if ($ids === []) {
            $this->failure('Centang delivery yang ingin dihubungkan terlebih dahulu.', '/migration-issues/deliveries');
        }
        $linked = 0;
        $skipped = 0;
        foreach (array_slice($ids, 0, 100) as $id) {
            $d = Delivery::find($id);
            if ($d === null || $d['po_line_id'] !== null || $d['po_id'] === null) {
                $skipped++;
                continue;
            }
            // Hanya PO dengan tepat satu baris yang boleh dihubungkan dari halaman ini
            $lines = PoLine::linesOfPo((int) $d['po_id']);
            if (count($lines) !== 1) {
                $skipped++;
                continue;
            }
            Delivery::saveDelivery($id, [
                'po_line_id'    => (int) $lines[0]['id'],
                'delivery_date' => $d['delivery_date'],
                'sj_number'     => $d['sj_number'],
                'delivered_qty' => (int) $d['delivered_qty'],
                'status'        => $d['status'],
            ]);
            $linked++;
        }
        $this->success($linked . ' delivery dihubungkan ke baris PO' . ($skipped ? ', ' . $skipped . ' dilewati (sudah terhubung / PO tidak lagi satu baris)' : '') . '. Outstanding & status PO dihitung ulang.', '/migration-issues/deliveries');
    }

    private function validateNote(array $statusRule): Validator
    {
        return Validator::make($_POST, [
            'status' => $statusRule,
            'note'   => 'nullable|string|max:1000',
        ], ['status' => 'Status', 'note' => 'Catatan']);
    }
}
