<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\I18n;
use App\Core\Settings;
use App\Core\User;
use App\Scheduling\WorkingCalendar;

/**
 * PDF Timeline (PRD §6.7): A4 landscape, kop (logo PIK + "Project Timeline"), No. Dokumen PIK-FORM-NPD-07
 * di pojok kanan atas setiap halaman (tanpa revisi), identitas project, dicetak oleh/tanggal, nomor halaman,
 * baris Overdue merah, dan Gantt (tabel minggu, vektor — bukan tangkapan layar) di halaman terpisah.
 */
final class TimelinePdf
{
    public function __construct(private TimelineExport $export = new TimelineExport())
    {
    }

    /** @return array{filename:string,content:string} */
    public function build(User $user, int $projectId, string $scope, ?int $partId = null): array
    {
        $x = $this->export->build($user, $projectId, $scope, $partId);
        $p = $x['project'];
        $mpdf = PdfFactory::make('L', ['margin_top' => 30, 'margin_bottom' => 14, 'default_font_size' => 8]);
        $mpdf->SetTitle('Project Timeline ' . $p['code']);
        $mpdf->SetHTMLHeader($this->header());
        $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7pt;color:#555"><tr><td>' . $this->h($p['code'] . ' · ' . $p['name']) . ' · '
            . $this->h(I18n::t('export.printed', ['date' => I18n::dateTime($x['printed_at']), 'user' => $x['printed_by']])) . '</td><td align="right">'
            . $this->h(I18n::t('npr.pdf_page')) . '</td></tr></table>');
        $mpdf->WriteHTML($this->css(), \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($this->info($x), \Mpdf\HTMLParserMode::HTML_BODY);
        foreach ($x['sections'] as $i => $s) {
            if ($i > 0) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML($s['level'] === 1 ? $this->level1($s) : $this->level2($s), \Mpdf\HTMLParserMode::HTML_BODY);
        }
        foreach ($x['sections'] as $s) {
            $mpdf->AddPage();
            $mpdf->WriteHTML($this->gantt($s), \Mpdf\HTMLParserMode::HTML_BODY);
        }
        return ['filename' => PdfFactory::safeFilename($x['filename_base']) . '.pdf', 'content' => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN)];
    }

    private function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function d(?string $date): string
    {
        return $date ? $this->h(I18n::date($date)) : '–';
    }

    private function header(): string
    {
        $company = $this->h((string) Settings::get('company.name', 'PT. Permata Indo Kemas'));
        return '<table class="kop" width="100%"><tr>'
            . '<td width="16%"><img src="' . $this->h(PdfFactory::logoPath()) . '" height="32"></td>'
            . '<td width="58%"><div class="co">' . $company . '</div><div class="title">PROJECT TIMELINE</div></td>'
            . '<td width="26%" align="right"><table class="docno" align="right"><tr><td>No. Dokumen</td><td>: ' . TimelineExport::DOC_NO . '</td></tr></table></td>'
            . '</tr></table>';
    }

    private function css(): string
    {
        return 'body{font-family:dejavusans;color:#111}
        .kop td{vertical-align:middle}.co{font-weight:bold;font-size:10pt}.title{font-weight:bold;font-size:12pt;letter-spacing:1px;margin-top:2px}
        .docno{font-size:8pt;border:0.6px solid #333}.docno td{padding:2px 4px}
        h2{font-size:10.5pt;margin:6px 0 4px}
        table.info{border-collapse:collapse;margin-bottom:6px}table.info td{padding:1.5px 8px 1.5px 0;font-size:8pt}
        table.t{width:100%;border-collapse:collapse}
        table.t th{background:#1d1d1f;color:#fff;font-weight:bold;font-size:7.3pt;padding:3px;border:0.5px solid #1d1d1f;text-align:left}
        table.t td{border:0.5px solid #999;padding:2.5px 3px;font-size:7.3pt;vertical-align:top}
        tr.late td{background:#fde7e9;color:#9b1020}tr.skip td{color:#888;text-decoration:line-through}
        tr.part td{background:#f2f2ef;font-weight:bold}
        .r{text-align:right}.c{text-align:center}.muted{color:#666}
        table.g{border-collapse:collapse;width:100%}table.g td,table.g th{border:0.3px solid #ccc;font-size:6.2pt;padding:1px;height:11px}
        table.g th{background:#f2f2ef;font-weight:normal}table.g td.lbl{width:24%;font-size:6.8pt;padding:1px 3px}
        td.b-done{background:#9bd7b4}td.b-run{background:#a9baf7}td.b-plan{background:#d9d9de}td.b-late{background:#f19aa1}td.today{background-color:#eef1ff}
        .legend span{padding:0 6px;margin-right:8px;font-size:7pt}';
    }

    /** @param array<string,mixed> $x */
    private function info(array $x): string
    {
        $p = $x['project'];
        $rows = [
            [I18n::t('project.project'), $p['code'] . ' — ' . $p['name'], I18n::t('npr.customer'), (string) $p['customer_name']],
            ['No. NPR', (string) $p['npr_number'], I18n::t('common.status'), TimelineExport::status((string) $p['status'])],
            [I18n::t('project.target_finish'), $p['target_finish'] ? I18n::date($p['target_finish']) : '–', I18n::t('project.forecast_finish'), ($p['forecast_finish'] ? I18n::date($p['forecast_finish']) : '–') . ($p['at_risk'] ? ' (' . I18n::t('project.past_target') . ')' : '')],
            [I18n::t('project.npd_pic'), (string) ($p['npd_pic_name'] ?? '–'), I18n::t('export.printed_by'), $x['printed_by'] . ' · ' . I18n::dateTime($x['printed_at'])],
        ];
        $html = '<table class="info">';
        foreach ($rows as $r) {
            $html .= '<tr><td class="muted">' . $this->h($r[0]) . '</td><td><b>' . $this->h($r[1]) . '</b></td><td width="30"></td><td class="muted">' . $this->h($r[2]) . '</td><td><b>' . $this->h($r[3]) . '</b></td></tr>';
        }
        return $html . '</table>';
    }

    /** @param array<string,mixed> $s */
    private function level1(array $s): string
    {
        $html = '<h2>' . $this->h($s['title']) . '</h2><table class="t"><thead><tr>'
            . '<th>No</th><th>' . $this->h(I18n::t('timeline.part_or_process')) . '</th><th>' . $this->h(I18n::t('timeline.type')) . '</th><th>' . $this->h(I18n::t('common.status')) . '</th>'
            . '<th class="r">' . $this->h(I18n::t('project.progress')) . '</th><th>PIC</th><th>' . $this->h(I18n::t('timeline.start')) . '</th><th>' . $this->h(I18n::t('timeline.planned_finish')) . '</th>'
            . '<th>' . $this->h(I18n::t('project.forecast_finish')) . '</th><th>' . $this->h(I18n::t('status.overdue')) . '</th><th>' . $this->h(I18n::t('timeline.remark')) . '</th></tr></thead><tbody>';
        foreach ($s['data']['rows'] as $r) {
            $cls = $r['overdue_days'] > 0 ? 'late' : ($r['kind'] === 'part' ? 'part' : '');
            $html .= '<tr class="' . $cls . '"><td>' . (int) $r['no'] . '</td><td>' . $this->h(trim($r['code'] . ' ' . $r['name'])) . '</td>'
                . '<td>' . $this->h($r['kind'] === 'part' ? I18n::t('part_type.' . $r['part_type']) : I18n::t('timeline.project_level')) . '</td>'
                . '<td>' . $this->h(TimelineExport::status((string) $r['status'])) . '</td><td class="r">' . ($r['kind'] === 'part' ? (int) $r['progress'] . '%' : '–') . '</td>'
                . '<td>' . $this->h($r['pic'] ?? '–') . '</td><td>' . $this->d($r['kind'] === 'part' ? $r['actual_start'] : ($r['actual_start'] ?? $r['planned_start'])) . '</td>'
                . '<td>' . $this->d($r['planned_finish']) . '</td><td>' . $this->d($r['kind'] === 'part' && $r['actual_finish'] ? $r['actual_finish'] : $r['forecast_finish']) . '</td>'
                . '<td>' . ($r['overdue_days'] > 0 ? $this->h(I18n::t('project.overdue_n', ['days' => $r['overdue_days']])) : '–') . '</td><td>' . $this->h($r['remark']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param array<string,mixed> $s */
    private function level2(array $s): string
    {
        $part = $s['data']['part'];
        $html = '<h2>' . $this->h($s['title'] . ' · ' . I18n::t('part_type.' . $part['part_type'])) . '</h2><table class="t"><thead><tr>'
            . '<th>No</th><th>' . $this->h(I18n::t('process.process')) . '</th><th>PIC</th><th>' . $this->h(I18n::t('process.dependencies')) . '</th><th class="r">' . $this->h(I18n::t('process.duration_short')) . '</th>'
            . '<th>' . $this->h(I18n::t('timeline.planned_start')) . '</th><th>' . $this->h(I18n::t('timeline.planned_finish')) . '</th><th>' . $this->h(I18n::t('timeline.actual_start')) . '</th>'
            . '<th>' . $this->h(I18n::t('timeline.actual_finish')) . '</th><th class="r">' . $this->h(I18n::t('process.deviation')) . '</th><th>' . $this->h(I18n::t('common.status')) . '</th><th>' . $this->h(I18n::t('timeline.remark')) . '</th></tr></thead><tbody>';
        foreach ($s['data']['rows'] as $r) {
            $cls = $r['overdue_days'] > 0 ? 'late' : ($r['skipped'] ? 'skip' : '');
            $dev = $r['deviation'] !== null ? sprintf('%+d', $r['deviation']) : ($r['overdue_days'] > 0 ? '+' . (int) $r['overdue_days'] : '–');
            $status = TimelineExport::status((string) $r['status']) . ($r['overdue_days'] > 0 ? ' · ' . I18n::t('project.overdue_n', ['days' => $r['overdue_days']]) : '');
            $html .= '<tr class="' . $cls . '"><td>' . (int) $r['no'] . '</td><td>' . $this->h($r['code'] . ' ' . $r['name']) . ($r['manual'] ? ' [M]' : '') . '</td>'
                . '<td>' . $this->h($r['pic'] ?? '–') . '</td><td>' . $this->h(TimelineExport::deps($r['deps']) ?: '–') . '</td><td class="r">' . (int) $r['duration'] . '</td>'
                . '<td>' . $this->d($r['planned_start']) . '</td><td>' . $this->d($r['planned_finish']) . '</td><td>' . $this->d($r['actual_start']) . '</td><td>' . $this->d($r['actual_finish']) . '</td>'
                . '<td class="r">' . $this->h($dev) . '</td><td>' . $this->h($status) . '</td><td>' . $this->h($r['remark']) . '</td></tr>';
        }
        return $html . '</tbody></table><p class="muted" style="font-size:6.8pt">' . $this->h(I18n::t('process.dates_note')) . ' [M] = ' . $this->h(I18n::t('gantt.manual_date')) . '</p>';
    }

    /** Gantt sebagai tabel minggu (vektor). @param array<string,mixed> $s */
    private function gantt(array $s): string
    {
        $data = $s['data'];
        $from = (string) $data['from'];
        $to = (string) $data['to'];
        $weeks = [];
        for ($d = $from; $d <= $to; $d = WorkingCalendar::shift($d, 7)) {
            $weeks[] = $d;
        }
        $step = (int) max(1, ceil(count($weeks) / 40)); // maksimal ±40 kolom agar terbaca di A4 landscape
        $cols = [];
        for ($i = 0; $i < count($weeks); $i += $step) {
            $cols[] = [$weeks[$i], WorkingCalendar::shift($weeks[$i], 7 * $step - 1)];
        }
        $today = (string) $data['today'];
        $html = '<h2>Gantt — ' . $this->h($s['title']) . '</h2><table class="g"><thead><tr><th class="lbl"></th>';
        foreach ($cols as [$a, $b]) {
            $isToday = $today >= $a && $today <= $b;
            $html .= '<th' . ($isToday ? ' style="background:#3b63f3;color:#fff;font-weight:bold"' : '') . '>' . $this->h(I18n::dateShort($a)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data['rows'] as $r) {
            $html .= '<tr><td class="lbl">' . $this->h(trim($r['code'] . ' ' . $r['name'])) . '</td>';
            foreach ($cols as [$a, $b]) {
                $cls = '';
                if ($r['bar_start'] && $r['bar_end'] && $r['bar_start'] <= $b && $r['bar_end'] >= $a) {
                    $late = $r['overdue_days'] > 0 && !empty($r['planned_finish']) && $b > $r['planned_finish'];
                    $cls = $late ? 'b-late' : ($r['status'] === 'completed' ? 'b-done' : (in_array($r['status'], ['not_started'], true) ? 'b-plan' : 'b-run'));
                }
                if ($cls === '' && $today >= $a && $today <= $b) {
                    $cls = 'today';
                }
                $html .= '<td class="' . trim($cls) . '"></td>';
            }
            $html .= '</tr>';
        }
        $legend = [['#d9d9de', I18n::t('status.not_started')], ['#a9baf7', I18n::t('gantt.legend_running')], ['#9bd7b4', I18n::t('status.completed')],
                   ['#f19aa1', I18n::t('status.overdue')], ['#3b63f3', I18n::t('gantt.today')]];
        $html .= '</tbody></table><table class="legend" style="margin-top:6px"><tr>';
        foreach ($legend as [$color, $label]) {
            $html .= '<td style="width:14px;background:' . $color . '"></td><td style="padding:0 12px 0 4px;font-size:7pt">' . $this->h($label) . '</td>';
        }
        if ($step > 1) {
            $html .= '<td style="font-size:7pt;color:#666">' . $this->h(I18n::t('export.gantt_bucket', ['weeks' => $step])) . '</td>';
        }
        $html .= '</tr></table>';
        return $html;
    }
}
