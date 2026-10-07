<?php
declare(strict_types=1);

namespace App\Report;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Settings;
use App\Core\User;
use App\Master\MasterService;
use App\Notification\OverdueService;
use App\Project\ProjectQuery;
use App\Scheduling\Lateness;
use App\Scheduling\WorkingCalendar;

/**
 * Dashboard (PRD §10.1, FR-RPT-01) — semua angka dihitung dari MySQL saat halaman dibuka:
 * 8 kartu KPI (jumlah project + keterangan jumlah part), Panel Overdue (§7.2), Attention Required,
 * Menunggu tindakan Anda (per part-proses, termasuk paralel), dan 6 grafik. Project arsip tidak dihitung.
 * Filter: jenis project (part New Mold/Subcont), customer, NPD PIC, prioritas.
 */
final class DashboardService
{
    public const CARDS = ['total', 'new_mold', 'subcont', 'on_progress', 'waiting', 'overdue', 'due_soon', 'completed'];
    public const ATTENTION = ['overdue', 'due_soon', 'waiting_approval', 'waiting_external', 'no_update', 'missing_document'];
    private const ACTIVE = ['current', 'revision', 'problem'];

    public function __construct(private ?WorkingCalendar $cal = null)
    {
    }

    private function cal(): WorkingCalendar
    {
        return $this->cal ??= WorkingCalendar::fromDb();
    }

    /** @param array<string,mixed> $in @return array{part_type:?string,customer_id:?int,npd_pic_id:?int,priority:?string} */
    public static function cleanFilters(array $in): array
    {
        $type = in_array($in['part_type'] ?? null, ['new_mold', 'subcont'], true) ? (string) $in['part_type'] : null;
        $prio = in_array($in['priority'] ?? null, \App\Project\ProjectService::PRIORITIES, true) ? (string) $in['priority'] : null;
        $cust = isset($in['customer_id']) && ctype_digit((string) $in['customer_id']) ? (int) $in['customer_id'] : null;
        $npd = isset($in['npd_pic_id']) && ctype_digit((string) $in['npd_pic_id']) ? (int) $in['npd_pic_id'] : null;
        return ['part_type' => $type, 'customer_id' => $cust ?: null, 'npd_pic_id' => $npd ?: null, 'priority' => $prio];
    }

    /**
     * @param array{part_type:?string,customer_id:?int,npd_pic_id:?int,priority:?string} $f
     * @return array<string,mixed>
     */
    public function build(User $user, array $f, ?string $today = null): array
    {
        $today ??= Clock::todayString();
        $soonDays = max(1, min(30, Settings::int('notify.due_soon_days', 3)));
        $noUpdateDays = max(1, min(30, Settings::int('notify.no_update_days', 7)));

        // ---- project dalam cakupan filter
        $w = ['p.is_archived = 0'];
        $params = [];
        foreach (['customer_id' => 'p.customer_id', 'npd_pic_id' => 'p.npd_pic_id', 'priority' => 'p.priority'] as $k => $col) {
            if ($f[$k] !== null) {
                $w[] = $col . ' = ?';
                $params[] = $f[$k];
            }
        }
        if ($f['part_type'] !== null) {
            $w[] = 'EXISTS (SELECT 1 FROM project_parts tx WHERE tx.project_id = p.id AND tx.part_type = ? AND tx.cancelled_at IS NULL)';
            $params[] = $f['part_type'];
        }
        $projects = [];
        foreach (Db::fetchAll(
            'SELECT p.id, p.code, p.name, p.status, p.priority, p.customer_id, c.name AS customer_name, p.npd_pic_id, p.sales_pic_id, un.name AS npd_pic_name, p.is_on_hold,
                    p.finished_at, p.cancelled_at, p.target_finish, p.forecast_finish, DATE(COALESCE(p.last_activity_at, p.created_at)) AS last_activity
             FROM projects p JOIN customers c ON c.id = p.customer_id LEFT JOIN users un ON un.id = p.npd_pic_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.code',
            $params
        ) as $p) {
            $projects[(int) $p['id']] = $p;
        }
        $ids = array_keys($projects);

        // ---- part (dibatasi jenis bila difilter) & proses aktif
        $parts = [];
        $procs = [];
        if ($ids) {
            $partSql = 'SELECT id, project_id, name, part_type, status, is_on_hold, start_date, completed_at, cancelled_at FROM project_parts WHERE project_id IN ' . Db::in($ids);
            $partParams = $ids;
            if ($f['part_type'] !== null) {
                $partSql .= ' AND part_type = ?';
                $partParams[] = $f['part_type'];
            }
            foreach (Db::fetchAll($partSql, $partParams) as $pt) {
                $parts[(int) $pt['id']] = $pt;
            }
            foreach (Db::fetchAll(
                'SELECT pr.id, pr.project_id, pr.part_id, pr.code, pr.name, pr.name_en, pr.status, pr.iteration, pr.planned_finish, pr.actual_start, pr.pic_user_id,
                        pr.is_external, pr.is_customer_approval, pr.required_doc_types_json, u.name AS pic_name, r.code AS pic_role_code
                 FROM processes pr JOIN roles r ON r.id = pr.pic_role_id LEFT JOIN users u ON u.id = pr.pic_user_id
                 WHERE pr.project_id IN ' . Db::in($ids) . " AND pr.status IN ('current', 'revision', 'problem') ORDER BY pr.planned_finish, pr.sort_order",
                $ids
            ) as $r) {
                $pj = $projects[(int) $r['project_id']];
                $partId = $r['part_id'] !== null ? (int) $r['part_id'] : null;
                if ($partId !== null && (!isset($parts[$partId]) || $parts[$partId]['cancelled_at'] !== null)) {
                    continue; // part batal / di luar filter jenis
                }
                if ($partId === null && $f['part_type'] !== null) {
                    continue; // proses level project tidak mempunyai jenis
                }
                $closed = $pj['finished_at'] !== null || $pj['cancelled_at'] !== null;
                $held = (int) $pj['is_on_hold'] === 1 || ($partId !== null && (int) $parts[$partId]['is_on_hold'] === 1);
                $r['held'] = $held;
                $r['project_code'] = $pj['code'];
                $r['project_name'] = $pj['name'];
                $r['part_name'] = $partId !== null ? $parts[$partId]['name'] : null;
                $r['label'] = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['code'] . ' ' . ProjectQuery::processName($r);
                $r['overdue_days'] = $held || $closed ? 0 : Lateness::overdueDays($this->cal(), $r['planned_finish'], $today);
                $r['due_soon'] = !$held && !$closed && $r['overdue_days'] === 0 && Lateness::isDueSoon($this->cal(), $r['planned_finish'], $today, $soonDays);
                $procs[(int) $r['id']] = $r;
            }
        }

        // ---- 8 kartu KPI (project + keterangan part)
        $openParts = array_filter($parts, static fn ($p) => $p['cancelled_at'] === null);
        $cards = array_fill_keys(self::CARDS, ['projects' => 0, 'parts' => 0]);
        $cards['total'] = ['projects' => count($projects), 'parts' => count($openParts)];
        foreach (['new_mold', 'subcont'] as $type) {
            $typed = array_filter($openParts, static fn ($p) => $p['part_type'] === $type);
            $cards[$type] = ['projects' => count(array_unique(array_column($typed, 'project_id'))), 'parts' => count($typed)];
        }
        $byStatus = array_count_values(array_column($projects, 'status'));
        $partStatus = array_count_values(array_column($openParts, 'status'));
        $cards['on_progress'] = ['projects' => $byStatus['on_progress'] ?? 0, 'parts' => $partStatus['on_progress'] ?? 0];
        $cards['waiting'] = ['projects' => $byStatus['waiting'] ?? 0, 'parts' => ($partStatus['waiting_approval'] ?? 0) + ($partStatus['waiting_external'] ?? 0)];
        $cards['completed'] = ['projects' => $byStatus['completed'] ?? 0, 'parts' => count(array_filter($openParts, static fn ($p) => $p['completed_at'] !== null))];
        foreach (['overdue' => static fn ($r) => $r['overdue_days'] > 0, 'due_soon' => static fn ($r) => $r['due_soon']] as $key => $fn) {
            $hit = array_filter($procs, $fn);
            $cards[$key] = [
                'projects' => count(array_unique(array_column($hit, 'project_id'))),
                'parts' => count(array_unique(array_filter(array_column($hit, 'part_id')))),
            ];
        }

        // ---- Panel Overdue (urut paling lama) — sumber yang sama dengan banner & notifikasi
        $overdue = array_values(array_filter(
            (new OverdueService($this->cal()))->overdueProcesses([], $today),
            static fn ($r) => isset($procs[(int) $r['id']])
        ));

        // ---- Attention Required
        $waitingApproval = array_values(array_filter($procs, static fn ($r) => !$r['held'] && (int) $r['is_customer_approval'] === 1));
        $waitingExternal = array_values(array_filter($procs, static fn ($r) => !$r['held'] && (int) $r['is_external'] === 1 && (int) $r['is_customer_approval'] === 0));
        $dueSoon = array_values(array_filter($procs, static fn ($r) => $r['due_soon']));
        $limitDate = (new \DateTimeImmutable($today))->modify('-' . $noUpdateDays . ' days')->format('Y-m-d');
        $noUpdate = array_values(array_filter($projects, static fn ($p) => $p['finished_at'] === null && $p['cancelled_at'] === null
            && (int) $p['is_on_hold'] === 0 && $p['last_activity'] <= $limitDate));
        usort($noUpdate, static fn ($a, $b) => $a['last_activity'] <=> $b['last_activity']);
        $missing = $this->missingDocuments(array_filter($procs, static fn ($r) => !$r['held'] && $r['required_doc_types_json']));
        $attention = [
            'overdue' => $overdue,
            'due_soon' => $dueSoon,
            'waiting_approval' => $waitingApproval,
            'waiting_external' => $waitingExternal,
            'no_update' => $noUpdate,
            'missing_document' => $missing,
        ];

        // ---- Menunggu tindakan Anda: proses aktif yang ditugaskan kepada pengguna
        // (proses Sales tanpa PIC menjadi tugas Sales PIC project — sama dengan WorkflowEngine::canExecute)
        $mine = array_values(array_filter($procs, static fn ($r) => (int) $r['pic_user_id'] === $user->id
            || ($r['pic_user_id'] === null && $r['pic_role_code'] === 'admin_sales' && (int) $projects[(int) $r['project_id']]['sales_pic_id'] === $user->id)));
        usort($mine, static fn ($a, $b) => [$b['overdue_days'], (string) $a['planned_finish']] <=> [$a['overdue_days'], (string) $b['planned_finish']]);

        return [
            'today' => $today,
            'cards' => $cards,
            'overdue' => $overdue,
            'attention' => $attention,
            'mine' => $mine,
            'charts' => $this->charts($projects, $openParts, $procs),
            'due_soon_days' => $soonDays,
            'no_update_days' => $noUpdateDays,
        ];
    }

    /** Proses aktif yang dokumen wajibnya belum lengkap. @param array<int,array<string,mixed>> $procs @return list<array<string,mixed>> */
    private function missingDocuments(array $procs): array
    {
        if (!$procs) {
            return [];
        }
        $ids = array_keys($procs);
        $have = [];
        foreach (Db::fetchAll('SELECT process_id, doc_type_code FROM documents WHERE is_removed = 0 AND current_version_id IS NOT NULL AND process_id IN ' . Db::in($ids), $ids) as $d) {
            $have[(int) $d['process_id']][(string) $d['doc_type_code']] = true;
        }
        $out = [];
        foreach ($procs as $id => $r) {
            $req = json_decode((string) $r['required_doc_types_json'], true) ?: [];
            $miss = array_values(array_filter($req, static fn ($t) => !isset($have[$id][(string) $t])));
            if ($miss) {
                $r['missing'] = array_map(static fn ($t) => MasterService::label('document_type', (string) $t), $miss);
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Enam grafik (PRD §10.1). Tiap baris: label, nilai, parameter filter daftar project (bila ada).
     * @return array<string,list<array{label:string,value:int,query?:array<string,string|int>}>>
     */
    private function charts(array $projects, array $parts, array $procs): array
    {
        $count = static function (array $rows, callable $key): array {
            $out = [];
            foreach ($rows as $r) {
                $k = $key($r);
                if ($k !== null) {
                    $out[$k] = ($out[$k] ?? 0) + 1;
                }
            }
            arsort($out);
            return $out;
        };
        $byProcess = $count(array_filter($procs, static fn ($r) => $r['part_id'] !== null), static fn ($r) => $r['code'] . ' ' . ProjectQuery::processName($r));
        $byStatus = $count($projects, static fn ($p) => (string) $p['status']);
        $byCustomer = [];
        foreach ($projects as $p) {
            $byCustomer[(int) $p['customer_id']] ??= ['label' => (string) $p['customer_name'], 'value' => 0, 'query' => ['customer_id' => (int) $p['customer_id']]];
            $byCustomer[(int) $p['customer_id']]['value']++;
        }
        usort($byCustomer, static fn ($a, $b) => [$b['value'], $a['label']] <=> [$a['value'], $b['label']]);
        $byPic = $count($procs, static fn ($r) => $r['pic_name'] ?? I18n::t('project.no_pic'));
        $byType = $count($parts, static fn ($p) => (string) $p['part_type']);
        $byPriority = $count($projects, static fn ($p) => (string) $p['priority']);
        $order = array_flip(\App\Project\ProjectService::PRIORITIES);
        uksort($byPriority, static fn ($a, $b) => ($order[$b] ?? 0) <=> ($order[$a] ?? 0));

        $rows = static fn (array $m, callable $label, ?callable $query = null): array => array_map(
            static fn ($k, $v) => ['label' => $label((string) $k), 'value' => (int) $v] + ($query ? ['query' => $query((string) $k)] : []),
            array_keys($m), array_values($m)
        );
        return [
            'by_process' => array_slice($rows($byProcess, static fn ($k) => $k), 0, 12),
            'by_status' => $rows($byStatus, static fn ($k) => I18n::t('status.' . $k), static fn ($k) => ['status' => $k]),
            'by_customer' => array_slice($byCustomer, 0, 10),
            'by_pic' => array_slice($rows($byPic, static fn ($k) => $k), 0, 12),
            'by_type' => $rows($byType, static fn ($k) => I18n::t('part_type.' . $k), static fn ($k) => ['part_type' => $k]),
            'by_priority' => $rows($byPriority, static fn ($k) => I18n::t('project.priority.' . $k), static fn ($k) => ['priority' => $k]),
        ];
    }
}
