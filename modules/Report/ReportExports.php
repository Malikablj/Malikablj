<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\User;
use App\Project\ProjectQuery;

/**
 * Export laporan (PRD §10.2–10.3, FR-RPT-05): Weekly Report, Analytics, daftar project → Excel;
 * KPI per PIC → Excel dan PDF (mPDF, bukan screenshot). Data dari service yang sama dengan halaman.
 */
final class ReportExports
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @param array<string,mixed> $f @return list<array{0:string,1:string}> */
    private function info(User $user, ?ReportPeriod $period, array $f): array
    {
        $out = [];
        if ($period) {
            $out[] = [I18n::t('report.period'), $period->label()];
        }
        $parts = [];
        if (!empty($f['part_type'])) {
            $parts[] = I18n::t('part_type.' . $f['part_type']);
        }
        if (!empty($f['customer_id'])) {
            $parts[] = (string) Db::value('SELECT name FROM customers WHERE id = ?', [(int) $f['customer_id']]);
        }
        if (!empty($f['npd_pic_id'])) {
            $parts[] = I18n::t('project.npd_pic') . ': ' . (string) Db::value('SELECT name FROM users WHERE id = ?', [(int) $f['npd_pic_id']]);
        }
        if (!empty($f['role'])) {
            $parts[] = I18n::t('role.' . $f['role']);
        }
        if (!empty($f['process'])) {
            $parts[] = I18n::t('process.process') . ' ' . $f['process'];
        }
        if (!empty($f['priority'])) {
            $parts[] = I18n::t('project.priority.' . $f['priority']);
        }
        $out[] = [I18n::t('report.filters'), $parts ? implode(' · ', $parts) : I18n::t('common.all')];
        $out[] = [I18n::t('export.printed_by'), $user->name . ' · ' . I18n::dateTime(Clock::nowString())];
        return $out;
    }

    /** @param array<string,mixed> $f @return array{filename:string,content:string,mime:string} */
    public function weekly(User $user, ReportPeriod $period, array $f): array
    {
        $svc = new WeeklyReport();
        $r = $svc->build($period, $f);
        $wb = new ReportWorkbook('Weekly NPD Report', $this->info($user, $period, $f));
        $wb->table(I18n::t('report.summary'), [I18n::t('report.metric'), I18n::t('report.value')],
            array_map(static fn ($k, $v) => [I18n::t('report.s.' . $k), $v], array_keys($r['summary']), array_values($r['summary'])), ['text', 'int'], [40, 14]);
        $wb->table(I18n::t('report.overdue_list'),
            [I18n::t('project.project'), I18n::t('project.part'), I18n::t('process.process'), 'PIC', I18n::t('timeline.planned_finish'), I18n::t('report.days_late'), I18n::t('report.waiting_for')],
            array_map(static fn ($o) => [$o['project_code'], $o['part_name'] ?? '–', $o['process_label'], $o['pic_name'] ?? '–', $o['planned_finish'], $o['overdue_days'], I18n::t('report.waiting.' . $o['waiting'])], $r['overdue']),
            ['text', 'text', 'text', 'text', 'date', 'int', 'text'], [16, 14, 34, 20, 14, 12, 18], static fn () => true);
        $problemRows = array_merge(
            array_map(static fn ($s) => [null, $s['project_code'], $s['label'], $s['pic_name'] ?? '–', I18n::t('status.problem'), ''], $r['stuck']),
            array_map(static fn ($p) => [substr((string) $p['created_at'], 0, 10), $p['project_code'], $p['label'] !== '' ? $p['label'] : $p['summary'], $p['pic_name'] ?? '–', $p['summary'], $p['comment']], $r['problems'])
        );
        $wb->table(I18n::t('report.problem_list'), [I18n::t('common.date'), I18n::t('project.project'), I18n::t('process.process'), 'PIC', I18n::t('report.event'), I18n::t('report.comment')],
            $problemRows, ['date', 'text', 'text', 'text', 'text', 'text'], [13, 16, 34, 20, 34, 40]);
        $rejRows = array_merge(
            array_map(static fn ($x) => [substr((string) $x['decided_at'], 0, 10), $x['project_code'], $x['label'], I18n::t('approval.type.' . $x['approval_type']), $x['pic_name'] ?? '–', (string) $x['comment']], $r['rejections']),
            array_map(static fn ($n) => [substr((string) $n['created_at'], 0, 10), $n['project_code'], 'NPR ' . $n['npr_number'], I18n::t('report.npr_returned'), $n['by_name'] ?? '–', $n['reason']], $r['npr_returns'])
        );
        $wb->table(I18n::t('report.rejection_list'), [I18n::t('common.date'), I18n::t('project.project'), I18n::t('process.process'), I18n::t('approval.type'), 'PIC', I18n::t('report.comment')],
            $rejRows, ['date', 'text', 'text', 'text', 'text', 'text'], [13, 16, 34, 26, 20, 40]);
        $actRows = array_merge(
            array_map(static fn ($a) => [$a['due_date'], $a['project_code'], $a['part_name'] ?? '–', $a['description'], $a['owner_name'] ?? '–', I18n::t('next.waiting.' . $a['waiting_for']), $a['late'] ? I18n::t('next.late') : ''], $r['actions']),
            array_map(static fn ($d) => [$d['planned_finish'], $d['project_code'], $d['part_name'] ?? '–', $d['label'], $d['pic_name'] ?? '–', '', I18n::t('report.process_due')], $r['due'])
        );
        $wb->table(I18n::t('report.action_required'), [I18n::t('next.due'), I18n::t('project.project'), I18n::t('project.part'), I18n::t('report.action'), 'PIC', I18n::t('next.waiting_for'), I18n::t('common.status')],
            $actRows, ['date', 'text', 'text', 'text', 'text', 'text', 'text'], [13, 16, 14, 44, 20, 18, 16], static fn ($row) => $row[6] === I18n::t('next.late'));
        $file = $wb->output('Weekly_NPD_Report_' . $period->from . '_' . $period->to);
        return $file + ['mime' => self::XLSX];
    }

    /** @param array<string,mixed> $f @return array{filename:string,content:string,mime:string} */
    public function analytics(User $user, ReportPeriod $period, array $f): array
    {
        $a = (new AnalyticsService())->build($period, $f);
        $wb = new ReportWorkbook('NPD Analytics', $this->info($user, $period, $f));
        $wb->table(I18n::t('report.process_stats'),
            [I18n::t('process.process'), I18n::t('report.part_type'), I18n::t('report.samples'), I18n::t('report.avg_actual'), I18n::t('report.avg_planned'), I18n::t('report.avg_diff'), I18n::t('report.on_time_rate'), I18n::t('report.rejections')],
            array_map(static fn ($s) => [$s['code'] . ' ' . $s['name'], $s['part_type'] ? I18n::t('part_type.' . $s['part_type']) : I18n::t('project.project_level'), $s['count'], $s['avg_actual'], $s['avg_planned'], $s['avg_diff'], $s['on_time_rate'], $s['rejections']], $a['stats']),
            ['text', 'text', 'int', 'dec', 'dec', 'dec', 'pct', 'int'], [36, 16, 10, 14, 14, 12, 12, 12], static fn ($row) => $row[5] > 0);
        $loopRows = [];
        foreach ($a['loops'] as $key => $l) {
            foreach ($l['items'] as $it) {
                $loopRows[] = [I18n::t('report.loop.' . $key), $it['actual_finish'], $it['project_code'], $it['label'], (int) $it['iteration'], $it['pic_name']];
            }
            if (!$l['items']) {
                $loopRows[] = [I18n::t('report.loop.' . $key), null, '–', '–', null, ''];
            }
        }
        $wb->table(I18n::t('report.loops'), [I18n::t('report.loop_type'), I18n::t('common.date'), I18n::t('project.project'), I18n::t('process.process'), I18n::t('report.iteration'), 'PIC'],
            $loopRows, ['text', 'date', 'text', 'text', 'int', 'text'], [26, 13, 16, 36, 10, 20]);
        return $wb->output('NPD_Analytics_' . $period->from . '_' . $period->to) + ['mime' => self::XLSX];
    }

    /** @param array<string,mixed> $f @return array{filename:string,content:string,mime:string} */
    public function kpiXlsx(User $user, ReportPeriod $period, array $f): array
    {
        $k = (new KpiService())->build($user, $period, $f);
        $wb = new ReportWorkbook('KPI per PIC', $this->info($user, $period, $f));
        $wb->table(I18n::t('kpi.ranking'), $this->kpiHeaders(), array_map(fn ($r, $i) => $this->kpiRow($r, $i + 1), $k['rows'], array_keys($k['rows'])),
            ['int', 'text', 'text', 'int', 'pct', 'dec', 'dec', 'dec', 'pct', 'int'], [6, 24, 18, 12, 12, 14, 14, 12, 12, 12]);
        $wb->table(I18n::t('kpi.trend'), [I18n::t('kpi.month'), I18n::t('kpi.completed'), I18n::t('kpi.on_time_count'), I18n::t('kpi.on_time_rate')],
            array_map(static fn ($t) => [I18n::t('report.month.' . (int) substr($t['month'], 5)) . ' ' . substr($t['month'], 0, 4), $t['completed'], $t['on_time'], $t['rate']], $k['trend']),
            ['text', 'int', 'int', 'pct'], [20, 14, 14, 14]);
        $runs = (new KpiService())->completedRuns($period->from, $period->to, $f);
        $wb->table(I18n::t('kpi.detail'),
            ['PIC', I18n::t('project.project'), I18n::t('process.process'), I18n::t('report.iteration'), I18n::t('kpi.planned_finish_activation'), I18n::t('process.actual_finish'),
             I18n::t('kpi.actual_days'), I18n::t('kpi.planned_days'), I18n::t('kpi.hold_days'), I18n::t('kpi.on_time')],
            array_map(static fn ($r) => [$r['pic_name'], $r['project_code'], $r['label'], (int) $r['iteration'], $r['planned_finish_at_activation'], $r['actual_finish'],
                $r['actual_days'], $r['planned_days'], (int) $r['hold_working_days'], $r['on_time'] === null ? '–' : ($r['on_time'] ? I18n::t('common.yes') : I18n::t('common.no'))], $runs),
            ['text', 'text', 'text', 'int', 'date', 'date', 'int', 'int', 'int', 'text'], [20, 16, 36, 10, 16, 14, 12, 12, 10, 10], static fn ($row) => $row[9] === I18n::t('common.no'));
        return $wb->output('KPI_PIC_' . $period->from . '_' . $period->to) + ['mime' => self::XLSX];
    }

    /** @param array<string,mixed> $f @return array{filename:string,content:string,mime:string} */
    public function kpiPdf(User $user, ReportPeriod $period, array $f): array
    {
        $k = (new KpiService())->build($user, $period, $f);
        $mpdf = PdfFactory::make('L', ['margin_top' => 28, 'default_font_size' => 8]);
        $logo = PdfFactory::logoPath();
        $mpdf->SetHTMLHeader('<table width="100%" style="border-bottom:0.3mm solid #1D1D1F"><tr><td width="30%">' . (is_file($logo) ? '<img src="' . htmlspecialchars($logo) . '" height="12mm">' : '')
            . '</td><td align="center" style="font-size:13pt;font-weight:bold">KPI PER PIC</td><td width="30%" align="right" style="font-size:7.5pt;color:#6E6E73">PT. Permata Indo Kemas</td></tr></table>');
        $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7pt;color:#6E6E73"><tr><td>NPD Project Control</td><td align="right">{PAGENO}/{nbpg}</td></tr></table>');
        $mpdf->WriteHTML('table.t{border-collapse:collapse;width:100%} .t th{background:#1D1D1F;color:#fff;padding:4px;font-size:7.5pt;text-align:left} .t td{border-bottom:0.2mm solid #E5E5E7;padding:3px 4px;font-size:7.5pt}
            .r{text-align:right} .bad{color:#C62828;font-weight:bold} h2{font-size:10pt;margin:10px 0 4px} .info td{font-size:8pt;padding:1px 6px 1px 0}', \Mpdf\HTMLParserMode::HEADER_CSS);
        $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<table class="info">';
        foreach ($this->info($user, $period, $f) as [$l, $v]) {
            $html .= '<tr><td style="color:#6E6E73">' . $h($l) . '</td><td><b>' . $h($v) . '</b></td></tr>';
        }
        $t = $k['totals'];
        $html .= '<tr><td style="color:#6E6E73">' . $h(I18n::t('kpi.overall')) . '</td><td><b>' . $h(I18n::t('kpi.overall_line', [
            'completed' => $t['completed'], 'rate' => $t['on_time_rate'] === null ? '–' : $t['on_time_rate'] . '%', 'overdue' => $t['overdue']])) . '</b></td></tr></table>';
        $html .= '<h2>' . $h(I18n::t('kpi.ranking')) . '</h2><table class="t"><thead><tr>';
        foreach ($this->kpiHeaders() as $i => $hd) {
            $html .= '<th' . ($i >= 3 ? ' class="r"' : '') . '>' . $h($hd) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($k['rows'] as $i => $r) {
            $row = $this->kpiRow($r, $i + 1);
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $h($row[1]) . '</td><td>' . $h($row[2]) . '</td><td class="r">' . $row[3] . '</td><td class="r">'
                . ($row[4] === null ? '–' : $h($row[4] . '%')) . '</td><td class="r">' . $h($row[5] ?? '–') . '</td><td class="r">' . $h($row[6] ?? '–') . '</td><td class="r'
                . (($row[7] ?? 0) > 0 ? ' bad' : '') . '">' . ($row[7] === null ? '–' : $h(sprintf('%+.1f', $row[7]))) . '</td><td class="r">' . ($row[8] === null ? '–' : $h($row[8] . '%'))
                . '</td><td class="r' . ($row[9] > 0 ? ' bad' : '') . '">' . $row[9] . '</td></tr>';
        }
        if (!$k['rows']) {
            $html .= '<tr><td colspan="10">' . $h(I18n::t('common.empty')) . '</td></tr>';
        }
        $html .= '</tbody></table><h2>' . $h(I18n::t('kpi.trend')) . '</h2><table class="t" style="width:60%"><thead><tr><th>' . $h(I18n::t('kpi.month')) . '</th><th class="r">'
            . $h(I18n::t('kpi.completed')) . '</th><th class="r">' . $h(I18n::t('kpi.on_time_rate')) . '</th><th>&nbsp;</th></tr></thead><tbody>';
        foreach ($k['trend'] as $tr) {
            $w = $tr['rate'] === null ? 0 : (int) round($tr['rate'] * 0.6);
            $html .= '<tr><td>' . $h(I18n::t('report.month.' . (int) substr($tr['month'], 5)) . ' ' . substr($tr['month'], 0, 4)) . '</td><td class="r">' . $tr['completed'] . '</td><td class="r">'
                . ($tr['rate'] === null ? '–' : $h($tr['rate'] . '%')) . '</td><td><div style="background:#3B63F3;height:3mm;width:' . $w . 'mm"></div></td></tr>';
        }
        $html .= '</tbody></table>';
        if ($k['detail']) {
            $d = $k['detail'];
            $html .= '<pagebreak /><h2>' . $h(I18n::t('kpi.drilldown_for', ['name' => $d['pic']['name']])) . '</h2><table class="t"><thead><tr><th>' . $h(I18n::t('project.project')) . '</th><th>'
                . $h(I18n::t('process.process')) . '</th><th class="r">' . $h(I18n::t('report.iteration')) . '</th><th>' . $h(I18n::t('kpi.planned_finish_activation')) . '</th><th>'
                . $h(I18n::t('process.actual_finish')) . '</th><th class="r">' . $h(I18n::t('kpi.actual_days')) . '</th><th class="r">' . $h(I18n::t('kpi.planned_days')) . '</th><th>'
                . $h(I18n::t('kpi.on_time')) . '</th></tr></thead><tbody>';
            foreach ($d['runs'] as $r) {
                $html .= '<tr><td>' . $h($r['project_code']) . '</td><td>' . $h($r['label']) . '</td><td class="r">' . (int) $r['iteration'] . '</td><td>' . $h(I18n::date($r['planned_finish_at_activation']))
                    . '</td><td>' . $h(I18n::date($r['actual_finish'])) . '</td><td class="r">' . $r['actual_days'] . '</td><td class="r">' . $r['planned_days'] . '</td><td'
                    . ($r['on_time'] === false ? ' class="bad"' : '') . '>' . $h($r['on_time'] === null ? '–' : ($r['on_time'] ? I18n::t('common.yes') : I18n::t('common.no'))) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        return ['filename' => PdfFactory::safeFilename('KPI_PIC_' . $period->from . '_' . $period->to) . '.pdf', 'content' => $mpdf->Output('', 'S'), 'mime' => 'application/pdf'];
    }

    /** Daftar project (filter yang sama dengan halaman Project). @param array<string,mixed> $filters @return array{filename:string,content:string,mime:string} */
    public function projects(User $user, array $filters): array
    {
        $q = new ProjectQuery();
        $rows = [];
        for ($page = 1; $page <= 50; $page++) {
            $res = $q->list($user, $filters, $page, 100);
            array_push($rows, ...$res['rows']);
            if (count($rows) >= $res['total']) {
                break;
            }
        }
        $wb = new ReportWorkbook(I18n::t('project.list_title'), $this->info($user, null, $filters));
        $wb->table(I18n::t('project.list_title'),
            [I18n::t('project.project'), I18n::t('project.name'), I18n::t('npr.customer'), 'No. NPR', I18n::t('common.status'), I18n::t('project.priority'), I18n::t('project.parts'),
             I18n::t('project.active_processes'), I18n::t('report.days_late'), I18n::t('project.target_finish'), I18n::t('project.forecast_finish'), I18n::t('project.npd_pic')],
            array_map(static fn ($r) => [$r['code'], $r['name'], $r['customer_name'], $r['npr_number'], I18n::t('status.' . $r['status']), I18n::t('project.priority.' . $r['priority']),
                $r['parts_completed'] . '/' . $r['parts_active'],
                implode("\n", array_map(static fn ($a) => ($a['part_name'] ? $a['part_name'] . ' › ' : '') . ProjectQuery::processName($a) . ' (' . ($a['pic_name'] ?? '–') . ')', $r['active_processes'])),
                $r['max_overdue'] ?: null, $r['target_finish'], $r['forecast_finish'], $r['npd_pic_name'] ?? '–'], $rows),
            ['text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'int', 'date', 'date', 'text'], [16, 28, 22, 20, 14, 10, 8, 44, 10, 14, 14, 20],
            static fn ($row) => (int) $row[8] > 0);
        return $wb->output('Daftar_Project_' . Clock::todayString()) + ['mime' => self::XLSX];
    }

    /** @return list<string> */
    private function kpiHeaders(): array
    {
        return ['#', 'PIC', I18n::t('kpi.role'), I18n::t('kpi.completed'), I18n::t('kpi.on_time_rate'), I18n::t('kpi.avg_actual'), I18n::t('kpi.avg_planned'),
            I18n::t('kpi.avg_diff'), I18n::t('kpi.ratio'), I18n::t('kpi.overdue')];
    }

    /** @param array<string,mixed> $r @return list<mixed> */
    private function kpiRow(array $r, int $rank): array
    {
        return [$rank, $r['name'], I18n::t('role.' . $r['role']), $r['completed'], $r['on_time_rate'], $r['avg_actual'], $r['avg_planned'], $r['avg_diff'], $r['ratio'], $r['overdue']];
    }
}
