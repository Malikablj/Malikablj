<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Notification\OverdueService;
use App\Project\ProjectQuery;
use App\Scheduling\WorkingCalendar;

/**
 * Weekly NPD Report (PRD §10.2): ringkasan periode, Top Issues (overdue, proses bermasalah, penolakan),
 * Action Required (next action jatuh tempo s/d akhir periode + proses yang jatuh tempo dalam periode).
 * Setiap item menyebut part & PIC. Dapat disalin sebagai teks (text()) dan diekspor ke Excel.
 * Filter: jenis project (part), customer, NPD PIC. Project arsip tidak dihitung.
 */
final class WeeklyReport
{
    public function __construct(private ?WorkingCalendar $cal = null)
    {
    }

    private function cal(): WorkingCalendar
    {
        return $this->cal ??= WorkingCalendar::fromDb();
    }

    /** @param array<string,mixed> $in @return array{part_type:?string,customer_id:?int,npd_pic_id:?int} */
    public static function cleanFilters(array $in): array
    {
        $f = DashboardService::cleanFilters($in);
        return ['part_type' => $f['part_type'], 'customer_id' => $f['customer_id'], 'npd_pic_id' => $f['npd_pic_id']];
    }

    /**
     * @param array{part_type:?string,customer_id:?int,npd_pic_id:?int} $f
     * @return array<string,mixed>
     */
    public function build(ReportPeriod $period, array $f, ?string $today = null): array
    {
        $today ??= Clock::todayString();
        [$pw, $pp] = $this->projectWhere($f);
        $ids = array_map('intval', Db::column("SELECT p.id FROM projects p WHERE $pw", $pp));
        $empty = ['ids' => [], 'in' => '(NULL)'];
        $in = $ids ? ['ids' => $ids, 'in' => Db::in($ids)] : $empty;
        $typeSql = $f['part_type'] !== null ? ' AND pp.part_type = ?' : '';
        $tp = $f['part_type'] !== null ? [$f['part_type']] : [];
        $from = $period->from;
        $to = $period->to;
        $toTs = $to . ' 23:59:59';

        $count = static fn (string $sql, array $params = []): int => $ids ? (int) Db::value($sql, array_merge($in['ids'], $params)) : 0;
        $summary = [
            'active_projects' => $count("SELECT COUNT(*) FROM projects WHERE id IN {$in['in']} AND finished_at IS NULL AND cancelled_at IS NULL"),
            'new_projects' => $count("SELECT COUNT(*) FROM projects WHERE id IN {$in['in']} AND created_at BETWEEN ? AND ?", [$from, $toTs]),
            'completed_projects' => $count("SELECT COUNT(*) FROM projects WHERE id IN {$in['in']} AND finished_at BETWEEN ? AND ?", [$from, $toTs]),
            'parts_started' => $count("SELECT COUNT(*) FROM project_parts pp WHERE pp.project_id IN {$in['in']} AND pp.start_date BETWEEN ? AND ?$typeSql", array_merge([$from, $to], $tp)),
            'processes_completed' => $count("SELECT COUNT(*) FROM process_runs r JOIN processes pr ON pr.id = r.process_id LEFT JOIN project_parts pp ON pp.id = pr.part_id
                WHERE pr.project_id IN {$in['in']} AND r.status = 'completed' AND r.is_imported = 0 AND pr.step_type <> 'request' AND r.actual_finish BETWEEN ? AND ?$typeSql", array_merge([$from, $to], $tp)),
            'on_hold' => $count("SELECT COUNT(*) FROM projects WHERE id IN {$in['in']} AND is_on_hold = 1"),
            'approved' => $count("SELECT COUNT(*) FROM approvals a LEFT JOIN project_parts pp ON pp.id = a.part_id WHERE a.project_id IN {$in['in']} AND a.status = 'approved' AND a.decided_at BETWEEN ? AND ?$typeSql", array_merge([$from, $toTs], $tp)),
            'rejected' => $count("SELECT COUNT(*) FROM approvals a LEFT JOIN project_parts pp ON pp.id = a.part_id WHERE a.project_id IN {$in['in']} AND a.status = 'rejected' AND a.decided_at BETWEEN ? AND ?$typeSql", array_merge([$from, $toTs], $tp)),
        ];

        // ---- Top Issues
        $partTypes = $ids ? array_column(Db::fetchAll("SELECT id, part_type FROM project_parts WHERE project_id IN {$in['in']}", $ids), 'part_type', 'id') : [];
        $overdue = array_values(array_filter((new OverdueService($this->cal()))->overdueProcesses([], $today),
            static fn ($r) => in_array((int) $r['project_id'], $ids, true)
                && ($f['part_type'] === null || ($r['part_id'] !== null && ($partTypes[(int) $r['part_id']] ?? null) === $f['part_type']))));
        $summary['overdue_now'] = count($overdue);
        $problems = $ids ? Db::fetchAll(
            "SELECT h.created_at, h.summary, h.details_json, pj.code AS project_code, pj.id AS project_id, pp.name AS part_name, pr.id AS process_id, pr.code, pr.name, pr.name_en,
                    u.name AS pic_name, a.name AS by_name
             FROM revision_history h JOIN projects pj ON pj.id = h.project_id LEFT JOIN processes pr ON pr.id = h.process_id LEFT JOIN project_parts pp ON pp.id = h.part_id
             LEFT JOIN users u ON u.id = pr.pic_user_id LEFT JOIN users a ON a.id = h.user_id
             WHERE h.project_id IN {$in['in']} AND h.revision_type = 'loop' AND h.created_at BETWEEN ? AND ?$typeSql ORDER BY h.created_at DESC LIMIT 50",
            array_merge($ids, [$from, $toTs], $tp)
        ) : [];
        foreach ($problems as &$pr) {
            $d = json_decode((string) $pr['details_json'], true) ?: [];
            $pr['comment'] = is_string($d['comment'] ?? null) ? $d['comment'] : '';
            $pr['label'] = ($pr['part_name'] ? $pr['part_name'] . ' › ' : '') . ($pr['code'] ? $pr['code'] . ' ' . ProjectQuery::processName($pr) : '');
        }
        unset($pr);
        $stuck = $ids ? Db::fetchAll(
            "SELECT pr.id AS process_id, pr.code, pr.name, pr.name_en, pr.status, pr.iteration, pj.code AS project_code, pj.id AS project_id, pp.name AS part_name, u.name AS pic_name
             FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE pr.project_id IN {$in['in']} AND pr.status = 'problem' AND (pp.id IS NULL OR pp.cancelled_at IS NULL)$typeSql ORDER BY pj.code, pr.sort_order",
            array_merge($ids, $tp)
        ) : [];
        foreach ($stuck as &$s) {
            $s['label'] = ($s['part_name'] ? $s['part_name'] . ' › ' : '') . $s['code'] . ' ' . ProjectQuery::processName($s);
        }
        unset($s);
        $rejections = $ids ? Db::fetchAll(
            "SELECT a.code AS approval_code, a.approval_type, a.decided_at, a.comment, a.decision_maker_name, pj.code AS project_code, pj.id AS project_id, pp.name AS part_name,
                    pr.id AS process_id, pr.code, pr.name, pr.name_en, u.name AS pic_name
             FROM approvals a JOIN projects pj ON pj.id = a.project_id LEFT JOIN project_parts pp ON pp.id = a.part_id LEFT JOIN processes pr ON pr.id = a.process_id
             LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE a.project_id IN {$in['in']} AND a.status = 'rejected' AND a.decided_at BETWEEN ? AND ?$typeSql ORDER BY a.decided_at DESC LIMIT 50",
            array_merge($ids, [$from, $toTs], $tp)
        ) : [];
        foreach ($rejections as &$r) {
            $r['label'] = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . ($r['code'] ? $r['code'] . ' ' . ProjectQuery::processName($r) : '');
        }
        unset($r);
        $nprReturns = $ids ? Db::fetchAll(
            "SELECT h.created_at, h.details_json, pj.code AS project_code, pj.id AS project_id, n.npr_number, n.id AS npr_id, u.name AS by_name
             FROM revision_history h JOIN projects pj ON pj.id = h.project_id JOIN npr n ON n.id = h.npr_id LEFT JOIN users u ON u.id = h.user_id
             WHERE h.project_id IN {$in['in']} AND h.revision_type = 'npr_return' AND h.created_at BETWEEN ? AND ? ORDER BY h.created_at DESC",
            array_merge($ids, [$from, $toTs])
        ) : [];
        foreach ($nprReturns as &$n) {
            $d = json_decode((string) $n['details_json'], true) ?: [];
            $n['reason'] = is_string($d['reason'] ?? null) ? $d['reason'] : '';
        }
        unset($n);

        // ---- Action Required
        $actions = $ids ? Db::fetchAll(
            "SELECT n.id, n.description, n.due_date, n.waiting_for, n.waiting_for_note, pj.code AS project_code, pj.id AS project_id, pp.name AS part_name, u.name AS owner_name
             FROM next_actions n JOIN projects pj ON pj.id = n.project_id LEFT JOIN project_parts pp ON pp.id = n.part_id LEFT JOIN users u ON u.id = n.owner_user_id
             WHERE n.project_id IN {$in['in']} AND n.status = 'open' AND n.due_date IS NOT NULL AND n.due_date <= ?
               AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL" . ($f['part_type'] !== null ? ' AND (pp.id IS NULL OR pp.part_type = ?)' : '') . '
             ORDER BY n.due_date, pj.code',
            array_merge($ids, [$to], $tp)
        ) : [];
        foreach ($actions as &$a) {
            $a['late'] = $a['due_date'] < $today;
        }
        unset($a);
        $due = $ids ? Db::fetchAll(
            "SELECT pr.id AS process_id, pr.code, pr.name, pr.name_en, pr.planned_finish, pj.code AS project_code, pj.id AS project_id, pp.name AS part_name, u.name AS pic_name
             FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE pr.project_id IN {$in['in']} AND pr.status IN ('current', 'revision', 'problem') AND pr.planned_finish BETWEEN ? AND ?
               AND pj.is_on_hold = 0 AND (pp.id IS NULL OR (pp.is_on_hold = 0 AND pp.cancelled_at IS NULL))$typeSql ORDER BY pr.planned_finish, pj.code",
            array_merge($ids, [max($from, $today), $to], $tp)
        ) : [];
        foreach ($due as &$d) {
            $d['label'] = ($d['part_name'] ? $d['part_name'] . ' › ' : '') . $d['code'] . ' ' . ProjectQuery::processName($d);
        }
        unset($d);

        return [
            'period' => $period,
            'summary' => $summary,
            'overdue' => $overdue,
            'problems' => $problems,
            'stuck' => $stuck,
            'rejections' => $rejections,
            'npr_returns' => $nprReturns,
            'actions' => $actions,
            'due' => $due,
        ];
    }

    /** Versi teks untuk disalin (email/WhatsApp). @param array<string,mixed> $r */
    public function text(array $r): string
    {
        $L = [];
        $L[] = 'WEEKLY NPD REPORT — PT. PERMATA INDO KEMAS';
        $L[] = I18n::t('report.period') . ': ' . $r['period']->label();
        $L[] = '';
        $L[] = mb_strtoupper(I18n::t('report.summary'));
        foreach ($r['summary'] as $k => $v) {
            $L[] = '- ' . I18n::t('report.s.' . $k) . ': ' . $v;
        }
        $L[] = '';
        $L[] = mb_strtoupper(I18n::t('report.top_issues'));
        $L[] = I18n::t('report.overdue_list') . ' (' . count($r['overdue']) . ')';
        foreach ($r['overdue'] as $o) {
            $L[] = '- ' . $o['project_code'] . ' · ' . ($o['part_name'] ? $o['part_name'] . ' › ' : '') . $o['process_label'] . ' · PIC: ' . ($o['pic_name'] ?? '–')
                . ' · ' . I18n::t('notif.days_late', ['days' => $o['overdue_days']]);
        }
        $L[] = I18n::t('report.problem_list') . ' (' . (count($r['problems']) + count($r['stuck'])) . ')';
        foreach ($r['stuck'] as $s) {
            $L[] = '- ' . $s['project_code'] . ' · ' . $s['label'] . ' · PIC: ' . ($s['pic_name'] ?? '–') . ' · ' . I18n::t('status.problem');
        }
        foreach ($r['problems'] as $p) {
            $L[] = '- ' . $p['project_code'] . ' · ' . $p['summary'] . ($p['label'] !== '' ? ' · PIC: ' . ($p['pic_name'] ?? '–') : '') . ($p['comment'] !== '' ? ' — ' . $p['comment'] : '');
        }
        $L[] = I18n::t('report.rejection_list') . ' (' . (count($r['rejections']) + count($r['npr_returns'])) . ')';
        foreach ($r['rejections'] as $x) {
            $L[] = '- ' . $x['project_code'] . ' · ' . $x['label'] . ' · ' . I18n::t('approval.type.' . $x['approval_type']) . ' · PIC: ' . ($x['pic_name'] ?? '–') . ($x['comment'] ? ' — ' . $x['comment'] : '');
        }
        foreach ($r['npr_returns'] as $n) {
            $L[] = '- ' . $n['project_code'] . ' · NPR ' . $n['npr_number'] . ' ' . I18n::t('report.npr_returned') . ($n['reason'] !== '' ? ' — ' . $n['reason'] : '');
        }
        $L[] = '';
        $L[] = mb_strtoupper(I18n::t('report.action_required'));
        foreach ($r['actions'] as $a) {
            $L[] = '- ' . $a['project_code'] . ($a['part_name'] ? ' · ' . $a['part_name'] : '') . ' · ' . $a['description'] . ' · PIC: ' . ($a['owner_name'] ?? '–')
                . ' · ' . I18n::date($a['due_date']) . ($a['late'] ? ' (' . I18n::t('next.late') . ')' : '');
        }
        foreach ($r['due'] as $d) {
            $L[] = '- ' . $d['project_code'] . ' · ' . $d['label'] . ' · PIC: ' . ($d['pic_name'] ?? '–') . ' · ' . I18n::t('report.due_on', ['date' => I18n::date($d['planned_finish'])]);
        }
        if (!$r['actions'] && !$r['due']) {
            $L[] = '- ' . I18n::t('report.none');
        }
        return implode("\n", $L);
    }

    /** @param array<string,mixed> $f @return array{0:string,1:list<mixed>} */
    private function projectWhere(array $f): array
    {
        $w = ['p.is_archived = 0'];
        $p = [];
        if ($f['customer_id'] !== null) {
            $w[] = 'p.customer_id = ?';
            $p[] = $f['customer_id'];
        }
        if ($f['npd_pic_id'] !== null) {
            $w[] = 'p.npd_pic_id = ?';
            $p[] = $f['npd_pic_id'];
        }
        if ($f['part_type'] !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM project_parts tx WHERE tx.project_id = p.id AND tx.part_type = ?)';
            $p[] = $f['part_type'];
        }
        return [implode(' AND ', $w), $p];
    }
}
