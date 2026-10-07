<?php
declare(strict_types=1);

namespace App\Notification;

use App\Core\Clock;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Settings;
use App\Project\ProjectQuery;
use App\Scheduling\Lateness;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;

/**
 * Overdue & pemantauan terjadwal (PRD §7): daftar overdue (panel/banner), dan pemindaian cron yang
 * mengirim notifikasi dengan deduplikasi — overdue hari pertama (web + email), due soon, dokumen wajib
 * belum lengkap, next action jatuh tempo/terlambat, tidak ada update, perkiraan melewati target.
 * Proses/part/project yang Hold tidak dihitung overdue (FR-OVD-05).
 */
final class OverdueService
{
    public function __construct(private ?WorkingCalendar $cal = null)
    {
    }

    private function cal(): WorkingCalendar
    {
        return $this->cal ??= WorkingCalendar::fromDb();
    }

    /**
     * Proses overdue terurut dari yang paling lama.
     * @param array{project_id?:?int,pic_id?:?int,limit?:int} $f
     * @return list<array<string,mixed>>
     */
    public function overdueProcesses(array $f = [], ?string $today = null): array
    {
        $today ??= Clock::todayString();
        $w = ["pr.status IN ('current', 'revision', 'problem')", 'pr.planned_finish < ?', 'pj.is_on_hold = 0', 'pj.is_archived = 0',
              'pj.finished_at IS NULL', 'pj.cancelled_at IS NULL', '(pp.id IS NULL OR (pp.is_on_hold = 0 AND pp.cancelled_at IS NULL))'];
        $p = [$today];
        if (!empty($f['project_id'])) {
            $w[] = 'pr.project_id = ?';
            $p[] = (int) $f['project_id'];
        }
        if (!empty($f['pic_id'])) {
            $w[] = '(pr.pic_user_id = ? OR pj.npd_pic_id = ?)';
            array_push($p, (int) $f['pic_id'], (int) $f['pic_id']);
        }
        $rows = Db::fetchAll(
            'SELECT pr.id, pr.code, pr.name, pr.name_en, pr.status, pr.iteration, pr.planned_finish, pr.pic_user_id, pr.is_external, pr.is_customer_approval,
                    pr.project_id, pr.part_id, pj.code AS project_code, pj.name AS project_name, pj.npd_pic_id, pp.name AS part_name, u.name AS pic_name,
                    (SELECT n.waiting_for_note FROM next_actions n WHERE n.project_id = pr.project_id AND n.part_id <=> pr.part_id AND n.status = \'open\' LIMIT 1) AS waiting_note
             FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE ' . implode(' AND ', $w),
            $p
        );
        $out = [];
        foreach ($rows as $r) {
            $days = Lateness::overdueDays($this->cal(), (string) $r['planned_finish'], $today);
            if ($days < 1) {
                continue;
            }
            $r['overdue_days'] = $days;
            $r['overdue_since'] = Lateness::overdueSince($this->cal(), (string) $r['planned_finish']);
            $r['process_label'] = $r['code'] . ' ' . ProjectQuery::processName($r);
            $r['waiting'] = (int) $r['is_customer_approval'] === 1 ? 'customer' : ((int) $r['is_external'] === 1 ? 'external' : 'internal');
            $out[] = $r;
        }
        usort($out, static fn ($a, $b) => [$b['overdue_days'], $a['project_code']] <=> [$a['overdue_days'], $b['project_code']]);
        return isset($f['limit']) ? array_slice($out, 0, (int) $f['limit']) : $out;
    }

    /**
     * Pemindaian terjadwal (cron/overdue.php). Idempoten: notifikasi memakai dedupe_key.
     * @return array<string,int>
     */
    public function scan(?string $today = null): array
    {
        $today ??= Clock::todayString();
        $stats = ['overdue_first' => 0, 'due_soon' => 0, 'missing_document' => 0, 'next_action' => 0, 'no_update' => 0, 'target_risk' => 0];
        $isWorkingDay = $this->cal()->isWorkingDay($today);

        // 1. Overdue hari pertama: tandai run (overdue_since) + notifikasi PIC & NPD PIC (web + email)
        foreach ($this->overdueProcesses([], $today) as $r) {
            $run = Db::fetch("SELECT id, overdue_since FROM process_runs WHERE process_id = ? AND status = 'open' ORDER BY iteration DESC LIMIT 1", [(int) $r['id']]);
            if ($run && $run['overdue_since'] === null) {
                Db::update('process_runs', ['overdue_since' => $r['overdue_since']], ['id' => (int) $run['id']]);
            }
            $where = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['process_label'];
            $stats['overdue_first'] += Notifier::send(
                array_filter([$r['pic_user_id'], $r['npd_pic_id']]), 'project_overdue', 'notif.overdue_first.title', 'notif.overdue_first.body',
                ['project' => (string) $r['project_code'], 'process' => $where, 'pic' => (string) ($r['pic_name'] ?? '–'), 'days' => (int) $r['overdue_days'],
                 'finish' => I18n::date((string) $r['planned_finish'])],
                'process.php?id=' . $r['id'], (int) $r['project_id'], (int) $r['id'], 'overdue_first:' . $r['id'] . ':' . $r['iteration']
            );
        }

        // 2. Due soon (web) & dokumen wajib belum lengkap menjelang tenggat
        $soonDays = max(1, min(30, Settings::int('notify.due_soon_days', 3)));
        $active = Db::fetchAll(
            "SELECT pr.*, pj.code AS project_code, pj.npd_pic_id, pp.name AS part_name FROM processes pr JOIN projects pj ON pj.id = pr.project_id
             LEFT JOIN project_parts pp ON pp.id = pr.part_id
             WHERE pr.status IN ('current', 'revision', 'problem') AND pr.planned_finish >= ? AND pj.is_on_hold = 0 AND pj.is_archived = 0
               AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL AND (pp.id IS NULL OR (pp.is_on_hold = 0 AND pp.cancelled_at IS NULL))",
            [$today]
        );
        foreach ($active as $r) {
            if (!Lateness::isDueSoon($this->cal(), (string) $r['planned_finish'], $today, $soonDays)) {
                continue;
            }
            $where = ($r['part_name'] ? $r['part_name'] . ' › ' : '') . $r['code'] . ' ' . ProjectQuery::processName($r);
            $stats['due_soon'] += Notifier::send(array_filter([$r['pic_user_id']]), 'deadline_approaching', 'notif.due_soon.title', 'notif.due_soon.body',
                ['project' => (string) $r['project_code'], 'process' => $where, 'finish' => I18n::date((string) $r['planned_finish'])],
                'process.php?id=' . $r['id'], (int) $r['project_id'], (int) $r['id'], 'due_soon:' . $r['id'] . ':' . $r['iteration']);
            $required = $r['required_doc_types_json'] ? (json_decode((string) $r['required_doc_types_json'], true) ?: []) : [];
            if ($required) {
                $missing = [];
                foreach ($required as $type) {
                    if (!Db::value('SELECT id FROM documents WHERE process_id = ? AND doc_type_code = ? AND is_removed = 0 LIMIT 1', [(int) $r['id'], $type])) {
                        $missing[] = \App\Master\MasterService::label('document_type', (string) $type);
                    }
                }
                if ($missing) {
                    $stats['missing_document'] += Notifier::send(array_filter([$r['pic_user_id'], $r['npd_pic_id']]), 'missing_document',
                        'notif.missing_document.title', 'notif.missing_document.body',
                        ['project' => (string) $r['project_code'], 'process' => $where, 'list' => implode(', ', $missing)],
                        'process.php?id=' . $r['id'], (int) $r['project_id'], (int) $r['id'], 'missing_doc:' . $r['id'] . ':' . $r['iteration']);
                }
            }
        }

        // 3. Next action jatuh tempo (hari ini) / terlambat — pemilik & NPD PIC
        foreach (Db::fetchAll(
            "SELECT n.*, pj.code AS project_code, pj.npd_pic_id, pj.is_on_hold FROM next_actions n JOIN projects pj ON pj.id = n.project_id
             WHERE n.status = 'open' AND n.due_date IS NOT NULL AND n.due_date <= ? AND pj.finished_at IS NULL AND pj.cancelled_at IS NULL AND pj.is_archived = 0",
            [$today]
        ) as $n) {
            $late = $n['due_date'] < $today;
            $stats['next_action'] += Notifier::send(array_filter([$n['owner_user_id'], $n['npd_pic_id']]), $late ? 'next_action_overdue' : 'next_action_due',
                $late ? 'notif.next_action_overdue.title' : 'notif.next_action_due.title', 'notif.next_action_due.body',
                ['project' => (string) $n['project_code'], 'action' => (string) $n['description'], 'due' => I18n::date((string) $n['due_date'])],
                'project.php?id=' . $n['project_id'], (int) $n['project_id'], null, ($late ? 'na_overdue:' : 'na_due:') . $n['id']);
        }

        // 4. Tidak ada update N hari (bawaan 7) — NPD PIC, sekali per periode
        $noUpdate = max(1, min(30, Settings::int('notify.no_update_days', 7)));
        $limit = (new \DateTimeImmutable($today))->modify('-' . $noUpdate . ' days')->format('Y-m-d 23:59:59');
        foreach (Db::fetchAll(
            "SELECT id, code, name, npd_pic_id, DATE(COALESCE(last_activity_at, created_at)) AS since FROM projects WHERE finished_at IS NULL AND cancelled_at IS NULL AND is_archived = 0 AND is_on_hold = 0
               AND npd_pic_id IS NOT NULL AND COALESCE(last_activity_at, created_at) <= ?",
            [$limit]
        ) as $pj) {
            // pengingat diulang tiap N hari selama belum ada aktivitas
            $bucket = intdiv((int) round((strtotime($today . ' 12:00:00') - strtotime($pj['since'] . ' 12:00:00')) / 86400), $noUpdate);
            $stats['no_update'] += Notifier::send([(int) $pj['npd_pic_id']], 'no_update', 'notif.no_update.title', 'notif.no_update.body',
                ['project' => (string) $pj['code'], 'name' => (string) $pj['name'], 'days' => $noUpdate],
                'project.php?id=' . $pj['id'], (int) $pj['id'], null, 'no_update:' . $pj['id'] . ':' . $pj['since'] . ':' . $bucket);
        }

        // 5. Perkiraan selesai melewati Target Finish (dedupe per tanggal perkiraan)
        $schedule = new ScheduleService($this->cal());
        foreach (Db::fetchAll("SELECT * FROM projects WHERE finished_at IS NULL AND cancelled_at IS NULL AND is_archived = 0 AND target_finish IS NOT NULL AND forecast_finish > target_finish") as $pj) {
            $before = (int) Db::value("SELECT COUNT(*) FROM notifications WHERE type = 'target_at_risk' AND project_id = ?", [(int) $pj['id']]);
            $schedule->checkTargetRisk($pj, (string) $pj['forecast_finish']);
            $stats['target_risk'] += (int) Db::value("SELECT COUNT(*) FROM notifications WHERE type = 'target_at_risk' AND project_id = ?", [(int) $pj['id']]) - $before;
        }
        $stats['working_day'] = $isWorkingDay ? 1 : 0;
        return $stats;
    }
}
