<?php
declare(strict_types=1);

namespace App\Timeline;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\User;
use App\Project\ProjectQuery;
use App\Scheduling\Lateness;
use App\Scheduling\WorkingCalendar;

/**
 * Data lintas project untuk Gantt (level Project/Part) dan Process Tracker (PRD §6.8).
 * Semua filter memakai parameter terikat; daftar dibatasi & dipaginasi.
 */
final class PortfolioQuery
{
    private WorkingCalendar $cal;

    public function __construct(?WorkingCalendar $cal = null)
    {
        $this->cal = $cal ?? WorkingCalendar::fromDb();
    }

    /**
     * @param array{q?:?string,customer_id?:?int,pic_id?:?int,status?:?string,part_type?:?string,overdue?:bool} $f
     * @return array{0:string,1:list<mixed>}
     */
    private function where(array $f, string $level): array
    {
        $w = ['pj.is_archived = 0'];
        $p = [];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(pj.code LIKE ? OR pj.name LIKE ? OR c.name LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (!empty($f['customer_id'])) {
            $w[] = 'pj.customer_id = ?';
            $p[] = (int) $f['customer_id'];
        }
        if (!empty($f['pic_id'])) {
            $w[] = '(pj.sales_pic_id = ? OR pj.npd_pic_id = ? OR EXISTS (SELECT 1 FROM processes x WHERE x.project_id = pj.id AND x.pic_user_id = ?))';
            array_push($p, (int) $f['pic_id'], (int) $f['pic_id'], (int) $f['pic_id']);
        }
        if (!empty($f['status'])) {
            $w[] = $level === 'part' ? 'pp.status = ?' : 'pj.status = ?';
            $p[] = (string) $f['status'];
        }
        if (!empty($f['part_type']) && in_array($f['part_type'], ['new_mold', 'subcont'], true)) {
            $w[] = $level === 'part' ? 'pp.part_type = ?' : 'EXISTS (SELECT 1 FROM project_parts y WHERE y.project_id = pj.id AND y.part_type = ?)';
            $p[] = (string) $f['part_type'];
        }
        return [implode(' AND ', $w), $p];
    }

    /**
     * Baris Gantt lintas project.
     * @return array{rows:list<array<string,mixed>>,total:int,from:string,to:string,today:string,target:null,holidays:list<array<string,string>>}
     */
    public function gantt(User $user, array $f, string $level = 'project', int $page = 1, int $perPage = 30): array
    {
        [$where, $params] = $this->where($f, $level);
        $today = Clock::todayString();
        $perPage = max(5, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        if ($level === 'part') {
            $from = 'FROM project_parts pp JOIN projects pj ON pj.id = pp.project_id JOIN customers c ON c.id = pj.customer_id';
            $where .= ' AND pp.start_date IS NOT NULL';
            $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
            $rows = Db::fetchAll(
                "SELECT pp.id, pp.name, pp.part_type, pp.status, pp.start_date, pp.forecast_finish, pp.completed_at, pp.cancelled_at, pp.is_on_hold,
                        pj.id AS project_id, pj.code AS project_code, pj.name AS project_name, pj.target_finish, c.name AS customer_name,
                        (SELECT MIN(COALESCE(x.actual_start, x.planned_start)) FROM processes x WHERE x.part_id = pp.id AND x.status <> 'skipped') AS bar_start,
                        (SELECT MAX(CASE WHEN x.status = 'completed' THEN x.actual_finish ELSE COALESCE(x.forecast_finish, x.planned_finish) END) FROM processes x WHERE x.part_id = pp.id AND x.status <> 'skipped') AS bar_end
                 $from WHERE $where ORDER BY pj.code DESC, pp.sort_order, pp.id LIMIT $perPage OFFSET $offset",
                $params
            );
            $keyCol = 'part_id';
        } else {
            $from = 'FROM projects pj JOIN customers c ON c.id = pj.customer_id';
            $total = (int) Db::value("SELECT COUNT(*) $from WHERE $where", $params);
            $rows = Db::fetchAll(
                "SELECT pj.id, pj.code, pj.name, pj.status, pj.start_date, pj.target_finish, pj.forecast_finish, pj.finished_at, pj.is_on_hold, pj.cancelled_at,
                        pj.id AS project_id, c.name AS customer_name, un.name AS npd_pic_name,
                        (SELECT MIN(COALESCE(x.actual_start, x.planned_start)) FROM processes x WHERE x.project_id = pj.id AND x.status <> 'skipped') AS bar_start,
                        (SELECT MAX(CASE WHEN x.status = 'completed' THEN x.actual_finish ELSE COALESCE(x.forecast_finish, x.planned_finish) END) FROM processes x WHERE x.project_id = pj.id AND x.status <> 'skipped') AS bar_end
                 $from LEFT JOIN users un ON un.id = pj.npd_pic_id
                 WHERE $where ORDER BY FIELD(pj.status, 'completed', 'cancelled'), pj.created_at DESC, pj.id DESC LIMIT $perPage OFFSET $offset",
                $params
            );
            $keyCol = 'project_id';
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $late = $this->overdueBy($keyCol, $ids, $today);
        $baseline = $this->baselineSpan($keyCol, $ids);
        $out = [];
        $dates = [$today];
        foreach ($rows as $i => $r) {
            $id = (int) $r['id'];
            $isPart = $level === 'part';
            $row = [
                'kind' => $isPart ? 'part' : 'portfolio',
                'id' => $id,
                'no' => $offset + $i + 1,
                'code' => $isPart ? (string) $r['project_code'] : (string) $r['code'],
                'name' => $isPart ? (string) $r['name'] : (string) $r['name'],
                'project_id' => (int) $r['project_id'],
                'customer' => (string) $r['customer_name'],
                'part_type' => $r['part_type'] ?? null,
                'pic' => $isPart ? null : ($r['npd_pic_name'] ?? null),
                'status' => (string) $r['status'],
                'bar_start' => $r['bar_start'] ?? $r['start_date'],
                'bar_end' => $r['bar_end'] ?? $r['forecast_finish'],
                'planned_finish' => null,
                'forecast_finish' => $r['forecast_finish'],
                'target' => $r['target_finish'],
                'overdue_days' => $late[$id]['max'] ?? 0,
                'overdue_count' => $late[$id]['count'] ?? 0,
                'baseline_start' => $baseline[$id][0] ?? null,
                'baseline_finish' => $baseline[$id][1] ?? null,
                'deps' => [],
                'skipped' => false,
                'cancelled' => $r['cancelled_at'] !== null,
                'critical' => false,
                'manual' => false,
                'at_risk' => !$isPart && ProjectQuery::atRisk($r + ['status' => $r['status']], $today),
            ];
            if ($row['bar_start'] && $row['bar_end'] && $row['bar_end'] < $row['bar_start']) {
                $row['bar_end'] = $row['bar_start'];
            }
            foreach (['bar_start', 'bar_end', 'target', 'baseline_start', 'baseline_finish'] as $k) {
                if (!empty($row[$k])) {
                    $dates[] = $row[$k];
                }
            }
            $out[] = $row;
        }
        [$from, $to] = TimelineService::range($dates);
        return ['rows' => $out, 'total' => $total, 'from' => $from, 'to' => $to, 'today' => $today, 'target' => null, 'holidays' => $this->holidays($from, $to)];
    }

    /** @param list<int> $ids @return array<int,array{max:int,count:int}> */
    private function overdueBy(string $keyCol, array $ids, string $today): array
    {
        if (!$ids) {
            return [];
        }
        $col = $keyCol === 'part_id' ? 'pr.part_id' : 'pr.project_id';
        $out = [];
        foreach (Db::fetchAll(
            "SELECT $col AS k, pr.planned_finish FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id
             WHERE $col IN " . Db::in($ids) . " AND pr.status IN ('current', 'revision', 'problem') AND pj.is_on_hold = 0 AND (pp.id IS NULL OR pp.is_on_hold = 0)
               AND pr.planned_finish < ?",
            array_merge($ids, [$today])
        ) as $r) {
            $d = Lateness::overdueDays($this->cal, $r['planned_finish'], $today);
            if ($d > 0) {
                $k = (int) $r['k'];
                $out[$k]['max'] = max($out[$k]['max'] ?? 0, $d);
                $out[$k]['count'] = ($out[$k]['count'] ?? 0) + 1;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,array{0:?string,1:?string}> */
    private function baselineSpan(string $keyCol, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $col = $keyCol === 'part_id' ? 'pr.part_id' : 'pr.project_id';
        $out = [];
        foreach (Db::fetchAll(
            "SELECT $col AS k, MIN(i.planned_start) AS s, MAX(i.planned_finish) AS f FROM schedule_baseline_items i
             JOIN schedule_baselines b ON b.id = i.baseline_id AND b.is_active = 1 JOIN processes pr ON pr.id = i.process_id
             WHERE $col IN " . Db::in($ids) . ' GROUP BY ' . $col,
            $ids
        ) as $r) {
            $out[(int) $r['k']] = [$r['s'], $r['f']];
        }
        return $out;
    }

    /** @return list<array{date:string,name:string}> */
    private function holidays(string $from, string $to): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d = WorkingCalendar::shift($d, 1)) {
            if ($this->cal->isHoliday($d)) {
                $out[] = ['date' => $d, 'name' => ''];
            }
        }
        return $out;
    }

    /**
     * Kartu Process Tracker: satu kartu per part-proses aktif, dikelompokkan per nama proses.
     * @return list<array{key:string,title:string,cards:list<array<string,mixed>>}>
     */
    public function tracker(User $user, array $f): array
    {
        [$where, $params] = $this->where(array_diff_key($f, ['status' => 1]), 'part');
        $rows = Db::fetchAll(
            "SELECT pr.id, pr.code, pr.name, pr.name_en, pr.status, pr.sort_order, pr.planned_finish, pr.is_customer_approval, pr.is_external, pr.iteration,
                    pr.pic_user_id, u.name AS pic_name, r.code AS pic_role_code, pp.id AS part_id, pp.name AS part_name, pp.part_type, pp.is_on_hold AS part_hold,
                    pj.id AS project_id, pj.code AS project_code, pj.name AS project_name, pj.is_on_hold AS project_hold, c.name AS customer_name
             FROM processes pr JOIN projects pj ON pj.id = pr.project_id JOIN customers c ON c.id = pj.customer_id
             LEFT JOIN project_parts pp ON pp.id = pr.part_id JOIN roles r ON r.id = pr.pic_role_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE $where AND pr.status IN ('current', 'revision', 'problem') AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL
             ORDER BY pr.planned_finish, pj.code LIMIT 1000",
            $params
        );
        $today = Clock::todayString();
        $cols = [];
        foreach ($rows as $r) {
            $held = (int) $r['project_hold'] === 1 || (int) ($r['part_hold'] ?? 0) === 1;
            $r['held'] = $held;
            $r['overdue_days'] = $held ? 0 : Lateness::overdueDays($this->cal, $r['planned_finish'], $today);
            if (!empty($f['overdue']) && $r['overdue_days'] === 0) {
                continue;
            }
            $title = ProjectQuery::processName($r);
            $key = mb_strtolower($r['name']);
            $cols[$key] ??= ['key' => $key, 'title' => $title, 'order' => (int) $r['sort_order'] + ($r['part_id'] === null ? 0 : 1000), 'cards' => [], 'overdue' => 0];
            $cols[$key]['order'] = min($cols[$key]['order'], (int) $r['sort_order'] + ($r['part_id'] === null ? 0 : 1000));
            $cols[$key]['cards'][] = $r;
            if ($r['overdue_days'] > 0) {
                $cols[$key]['overdue']++;
            }
        }
        usort($cols, static fn ($a, $b) => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
        foreach ($cols as &$c) {
            usort($c['cards'], static fn ($a, $b) => [$b['overdue_days'], (string) $a['planned_finish']] <=> [$a['overdue_days'], (string) $b['planned_finish']]);
        }
        unset($c);
        return array_values($cols);
    }

    /** Label status Gantt (lintas domain). */
    public static function statusLabel(string $status): string
    {
        return I18n::has('status.' . $status) ? I18n::t('status.' . $status) : $status;
    }
}
