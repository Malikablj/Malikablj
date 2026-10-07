<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\Settings;
use App\Core\User;
use App\Scheduling\Lateness;
use App\Scheduling\WorkingCalendar;

/**
 * Read model project untuk halaman (daftar, detail, part, proses, riwayat).
 * Semua query memakai parameter terikat; filter teks di-escape untuk LIKE.
 */
final class ProjectQuery
{
    public const PROJECT_STATUSES = ['not_started', 'on_progress', 'waiting', 'hold', 'ready_to_finish', 'completed', 'cancelled'];
    public const ACTIVE = ['current', 'revision', 'problem'];

    private ?WorkingCalendar $cal = null;

    public function calendar(): WorkingCalendar
    {
        return $this->cal ??= WorkingCalendar::fromDb();
    }

    /**
     * @param array{q?:?string,status?:?string,customer_id?:?int,npd_pic_id?:?int,mine?:bool,archived?:bool,overdue?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(User $user, array $f, int $page = 1, int $perPage = 25): array
    {
        $where = ['p.is_archived = ?'];
        $params = [!empty($f['archived']) ? 1 : 0];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $where[] = '(p.code LIKE ? OR p.name LIKE ? OR c.name LIKE ? OR n.npr_number LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($f['status']) && in_array($f['status'], self::PROJECT_STATUSES, true)) {
            $where[] = 'p.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['customer_id'])) {
            $where[] = 'p.customer_id = ?';
            $params[] = (int) $f['customer_id'];
        }
        if (!empty($f['npd_pic_id'])) {
            $where[] = 'p.npd_pic_id = ?';
            $params[] = (int) $f['npd_pic_id'];
        }
        if (!empty($f['overdue'])) {
            // overdue (hari kerja): ada proses aktif dengan Planned Finish sebelum hari kerja terakhir s/d hari ini, tidak Hold
            $lastWd = $this->calendar()->prevWorkingDay(Clock::todayString());
            $where[] = "(p.is_on_hold = 0 AND EXISTS (SELECT 1 FROM processes ox LEFT JOIN project_parts op ON op.id = ox.part_id
                        WHERE ox.project_id = p.id AND ox.status IN ('current', 'revision', 'problem') AND ox.planned_finish < ?
                          AND (op.id IS NULL OR (op.is_on_hold = 0 AND op.cancelled_at IS NULL))))";
            $params[] = $lastWd;
        }
        if (!empty($f['mine'])) {
            $where[] = '(p.sales_pic_id = ? OR p.npd_pic_id = ? OR EXISTS (SELECT 1 FROM processes x WHERE x.project_id = p.id AND x.pic_user_id = ?))';
            array_push($params, $user->id, $user->id, $user->id);
        }
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM projects p JOIN customers c ON c.id = p.customer_id JOIN npr n ON n.id = p.npr_id';
        $total = (int) Db::value("SELECT COUNT(*) $from WHERE $sqlWhere", $params);
        $perPage = max(5, min(100, $perPage));
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Db::fetchAll(
            "SELECT p.*, c.name AS customer_name, n.npr_number, n.id AS npr_id_ref,
                    us.name AS sales_pic_name, un.name AS npd_pic_name,
                    (SELECT COUNT(*) FROM project_parts pp WHERE pp.project_id = p.id AND pp.cancelled_at IS NULL) AS parts_active,
                    (SELECT COUNT(*) FROM project_parts pp WHERE pp.project_id = p.id AND pp.completed_at IS NOT NULL AND pp.cancelled_at IS NULL) AS parts_completed
             $from
             LEFT JOIN users us ON us.id = p.sales_pic_id
             LEFT JOIN users un ON un.id = p.npd_pic_id
             WHERE $sqlWhere
             ORDER BY FIELD(p.status, 'completed', 'cancelled'), p.created_at DESC, p.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $this->attachActive($rows);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Lampirkan proses aktif + jumlah overdue ke tiap baris project. @param list<array<string,mixed>> $rows */
    private function attachActive(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $active = Db::fetchAll(
            'SELECT pr.id, pr.project_id, pr.code, pr.name, pr.name_en, pr.status, pr.planned_finish, pr.pic_user_id, pp.name AS part_name, pp.is_on_hold AS part_hold, u.name AS pic_name
             FROM processes pr LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE pr.project_id IN ' . Db::in($ids) . " AND pr.status IN ('current', 'revision', 'problem')
             ORDER BY pr.planned_finish, pr.sort_order",
            $ids
        );
        $today = Clock::todayString();
        $by = [];
        foreach ($active as $a) {
            $a['overdue_days'] = (int) $a['part_hold'] === 1 ? 0 : Lateness::overdueDays($this->calendar(), $a['planned_finish'], $today);
            $by[(int) $a['project_id']][] = $a;
        }
        foreach ($rows as &$r) {
            $list = $by[(int) $r['id']] ?? [];
            if ((int) $r['is_on_hold'] === 1) {
                foreach ($list as &$a) {
                    $a['overdue_days'] = 0;
                }
                unset($a);
            }
            $r['active_processes'] = $list;
            $r['overdue_count'] = count(array_filter($list, static fn ($a) => $a['overdue_days'] > 0));
            $r['max_overdue'] = $list ? max(array_column($list, 'overdue_days')) : 0;
            $r['at_risk'] = self::atRisk($r, $today);
        }
        unset($r);
    }

    /** Project Overdue/Berisiko (PRD §3.3): lewat Target Finish dan belum selesai, atau perkiraan > target. */
    public static function atRisk(array $project, string $today): bool
    {
        if (in_array($project['status'], ['completed', 'cancelled'], true) || empty($project['target_finish'])) {
            return false;
        }
        return $today > $project['target_finish'] || (!empty($project['forecast_finish']) && $project['forecast_finish'] > $project['target_finish']);
    }

    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        $p = Db::fetch(
            'SELECT p.*, c.name AS customer_name, c.code AS customer_code, n.npr_number, us.name AS sales_pic_name, un.name AS npd_pic_name
             FROM projects p JOIN customers c ON c.id = p.customer_id JOIN npr n ON n.id = p.npr_id
             LEFT JOIN users us ON us.id = p.sales_pic_id LEFT JOIN users un ON un.id = p.npd_pic_id
             WHERE p.id = ?',
            [$id]
        );
        if (!$p) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        $p['at_risk'] = self::atRisk($p, Clock::todayString());
        return $p;
    }

    /** @return list<array<string,mixed>> part + ringkasan progres */
    public function parts(int $projectId): array
    {
        $parts = Db::fetchAll(
            "SELECT pp.*, ud.name AS drafter_name, upu.name AS purchasing_name, upr.name AS production_name, uq.name AS quality_name,
                    (SELECT COUNT(*) FROM processes x WHERE x.part_id = pp.id AND x.status <> 'skipped' AND NOT (x.activation = 'loop_only' AND x.status = 'not_started')) AS proc_total,
                    (SELECT COUNT(*) FROM processes x WHERE x.part_id = pp.id AND x.status = 'completed') AS proc_done
             FROM project_parts pp
             LEFT JOIN users ud ON ud.id = pp.drafter_pic_id LEFT JOIN users upu ON upu.id = pp.purchasing_pic_id
             LEFT JOIN users upr ON upr.id = pp.production_pic_id LEFT JOIN users uq ON uq.id = pp.quality_pic_id
             WHERE pp.project_id = ? ORDER BY pp.sort_order, pp.id",
            [$projectId]
        );
        $active = [];
        foreach ($this->processes($projectId) as $pr) {
            if ($pr['part_id'] !== null && in_array($pr['status'], self::ACTIVE, true)) {
                $active[(int) $pr['part_id']][] = $pr;
            }
        }
        foreach ($parts as &$pt) {
            $pt['active_processes'] = $active[(int) $pt['id']] ?? [];
        }
        unset($pt);
        return $parts;
    }

    /**
     * Semua proses project (level project + part) dengan nama PIC & tanda keterlambatan.
     * @return list<array<string,mixed>>
     */
    public function processes(int $projectId, ?int $partId = null, bool $onlyPart = false): array
    {
        $sql = 'SELECT pr.*, r.code AS pic_role_code, u.name AS pic_name, pp.name AS part_name, pp.is_on_hold AS part_on_hold, pj.is_on_hold AS project_on_hold
                FROM processes pr JOIN roles r ON r.id = pr.pic_role_id LEFT JOIN users u ON u.id = pr.pic_user_id
                LEFT JOIN project_parts pp ON pp.id = pr.part_id JOIN projects pj ON pj.id = pr.project_id
                WHERE pr.project_id = ?';
        $params = [$projectId];
        if ($onlyPart) {
            $sql .= ' AND pr.part_id <=> ?';
            $params[] = $partId;
        }
        $sql .= ' ORDER BY pr.part_id IS NOT NULL, pp.sort_order, pr.part_id, pr.sort_order, pr.id';
        $rows = Db::fetchAll($sql, $params);
        $today = Clock::todayString();
        $soon = Settings::int('notify.due_soon_days', 3);
        foreach ($rows as &$r) {
            $held = (int) $r['project_on_hold'] === 1 || (int) ($r['part_on_hold'] ?? 0) === 1;
            $isActive = in_array($r['status'], self::ACTIVE, true);
            $r['overdue_days'] = $isActive && !$held ? Lateness::overdueDays($this->calendar(), $r['planned_finish'], $today) : 0;
            $r['due_soon'] = $isActive && !$held && $r['overdue_days'] === 0 && Lateness::isDueSoon($this->calendar(), $r['planned_finish'], $today, $soon);
            $r['deviation'] = $r['status'] === 'completed' && $r['planned_finish'] && $r['actual_finish']
                ? $this->calendar()->deviation((string) $r['planned_finish'], (string) $r['actual_finish']) : null;
            $r['held'] = $held;
        }
        unset($r);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function scheduleChanges(int $projectId, int $limit = 100): array
    {
        return Db::fetchAll(
            'SELECT sc.*, p.code, p.name AS process_name, pp.name AS part_name, cp.name AS cause_name, u.name AS user_name
             FROM schedule_changes sc LEFT JOIN processes p ON p.id = sc.process_id LEFT JOIN project_parts pp ON pp.id = sc.part_id
             LEFT JOIN processes cp ON cp.id = sc.cause_process_id LEFT JOIN users u ON u.id = sc.user_id
             WHERE sc.project_id = ? ORDER BY sc.id DESC LIMIT ' . max(1, min(500, $limit)),
            [$projectId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function baselines(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT b.*, pp.name AS part_name, u.name AS user_name FROM schedule_baselines b LEFT JOIN project_parts pp ON pp.id = b.part_id
             LEFT JOIN users u ON u.id = b.created_by WHERE b.project_id = ? ORDER BY b.created_at DESC, b.id DESC',
            [$projectId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function gates(int $projectId): array
    {
        return Db::fetchAll('SELECT g.*, u.name AS user_name FROM project_gates g LEFT JOIN users u ON u.id = g.decided_by WHERE g.project_id = ? ORDER BY g.id DESC', [$projectId]);
    }

    /** Pengguna aktif per peran (pemilihan PIC). @return array<string,list<array{id:int,name:string}>> */
    public function usersByRole(): array
    {
        $out = [];
        foreach (Db::fetchAll('SELECT u.id, u.name, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 ORDER BY u.name') as $u) {
            $out[(string) $u['code']][] = ['id' => (int) $u['id'], 'name' => (string) $u['name']];
        }
        return $out;
    }

    /** Nama proses sesuai bahasa aktif. */
    public static function processName(array $p): string
    {
        return I18n::locale() === 'en' && !empty($p['name_en']) ? (string) $p['name_en'] : (string) $p['name'];
    }
}
