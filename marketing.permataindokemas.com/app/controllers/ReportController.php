<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Audit;
use App\Helpers\Auth;
use App\Helpers\HttpException;
use App\Helpers\Request;
use App\Helpers\Validator;
use App\Helpers\XlsxWriter;
use App\Models\Customer;
use App\Models\User;
use App\Services\ReportService;
use DateTimeImmutable;

final class ReportController extends Controller
{
    public function index(): void
    {
        $this->view('reports/index', [
            'title'   => 'Reports',
            'reports' => ReportService::available(),
        ]);
    }

    public function show(string $type): void
    {
        $meta = $this->authorizeReport($type);
        $filters = $this->filters();
        $result = ReportService::run($type, $filters, today());
        $this->view('reports/show', [
            'title'     => 'Laporan ' . $meta['title'],
            'type'      => $type,
            'meta'      => $meta,
            'filters'   => $filters,
            'result'    => $result,
            'presets'   => ReportService::presets(today()),
            'customers' => Customer::selectOptions(),
            'pics'      => User::picOptions(),
        ]);
    }

    /** Export CSV (UTF-8 + BOM) atau Excel (.xlsx). */
    public function export(string $type): void
    {
        $meta = $this->authorizeReport($type);
        $format = Request::queryString('format', 'xlsx');
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new HttpException(400, 'Format export tidak dikenal.');
        }
        $filters = $this->filters();
        $result = ReportService::run($type, $filters, today());
        Audit::log('export', 'report', null, $meta['title'] . ' (' . strtoupper($format) . ')', [
            'filters' => ['old' => null, 'new' => json_encode(array_filter($filters), JSON_UNESCAPED_UNICODE)],
            'rows'    => ['old' => null, 'new' => count($result['rows'])],
        ]);
        $base = 'Laporan-' . str_replace(' ', '-', $meta['title']) . '-' . ($filters['from'] ?: 'awal') . '_' . ($filters['to'] ?: today());
        if ($format === 'csv') {
            $this->csv($base . '.csv', $result);
        }
        $this->xlsx($base . '.xlsx', $meta, $filters, $result);
    }

    /** @return array<string,string> */
    private function authorizeReport(string $type): array
    {
        $meta = ReportService::REPORTS[$type] ?? null;
        if ($meta === null) {
            throw new HttpException(404);
        }
        if (!Auth::can($meta['perm'])) {
            throw new HttpException(403);
        }
        return $meta;
    }

    /** @return array{from:string,to:string,customer_id:int,pic:int} */
    private function filters(): array
    {
        $from = Validator::parseDate(Request::queryString('from')) ?? '';
        $to = Validator::parseDate(Request::queryString('to')) ?? '';
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to, 'customer_id' => Request::queryInt('customer_id'), 'pic' => Request::queryInt('pic')];
    }

    /** Nilai sel untuk export (tanpa format tampilan). */
    private static function cell(array $col, mixed $value, bool $forCsv): mixed
    {
        if ($value === null || $value === '') {
            return $forCsv ? '' : null;
        }
        switch ($col['type']) {
            case 'qty':
                return (int) $value;
            case 'money':
            case 'pct':
                return $forCsv ? (string) $value : (float) $value;
            case 'date':
            case 'datetime':
                $dt = DateTimeImmutable::createFromFormat($col['type'] === 'date' ? '!Y-m-d' : 'Y-m-d H:i:s', (string) $value);
                if ($dt === false) {
                    return (string) $value;
                }
                return $forCsv ? $dt->format($col['type'] === 'date' ? 'Y-m-d' : 'Y-m-d H:i') : $dt;
            default:
                $text = (string) $value;
                // Cegah formula injection saat CSV dibuka di Excel
                if ($forCsv && preg_match('/^[=+\-@\t\r]/', $text)) {
                    $text = "'" . $text;
                }
                return $text;
        }
    }

    /** @param array<string,mixed> $result */
    private function csv(string $filename, array $result): never
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', $filename) ?? 'laporan.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(static fn ($c) => $c['label'], $result['columns']), ',', '"', '');
        foreach ($result['rows'] as $row) {
            $line = [];
            foreach ($result['columns'] as $col) {
                $line[] = self::cell($col, $row[$col['key']] ?? null, true);
            }
            fputcsv($out, $line, ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /**
     * @param array<string,string> $meta
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $result
     */
    private function xlsx(string $filename, array $meta, array $filters, array $result): never
    {
        $customers = Customer::selectOptions();
        $pics = User::picOptions();
        $info = [
            ['Laporan', $meta['title']],
            ['Periode (' . $meta['date'] . ')', ($filters['from'] ? fmt_date($filters['from']) : 'awal') . ' – ' . ($filters['to'] ? fmt_date($filters['to']) : 'sekarang')],
            ['Customer', $filters['customer_id'] ? ($customers[$filters['customer_id']] ?? '#' . $filters['customer_id']) : 'Semua'],
            [$meta['pic'], $filters['pic'] ? ($pics[$filters['pic']] ?? '#' . $filters['pic']) : 'Semua'],
            ['Dibuat', date('d/m/Y H:i') . ' oleh ' . (Auth::user()['name'] ?? '')],
            ['', ''],
        ];
        foreach ($result['summary'] as $s) {
            $info[] = [$s['label'], $s['value'] . (isset($s['meta']) ? ' (' . $s['meta'] . ')' : '')];
        }
        foreach ($result['breakdowns'] as $b) {
            $info[] = ['', ''];
            $info[] = [$b['title'], ''];
            foreach ($b['items'] as $item) {
                $info[] = [$item['label'], $item['display']];
            }
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $line = [];
            foreach ($result['columns'] as $col) {
                $line[] = self::cell($col, $row[$col['key']] ?? null, false);
            }
            $rows[] = $line;
        }
        (new XlsxWriter())
            ->addSheet('Ringkasan', ['Keterangan', 'Nilai'], $info)
            ->addSheet('Data', array_map(static fn ($c) => $c['label'], $result['columns']), $rows)
            ->download($filename);
    }
}
