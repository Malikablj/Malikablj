<?php
declare(strict_types=1);

namespace App\Calendar;

use App\Core\AuditLogger;
use App\Core\AuthorizationException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Project\ProjectQuery;
use App\Scheduling\Lateness;
use App\Scheduling\WorkingCalendar;

/**
 * Kalender (PRD §6.8): deadline proses, approval customer, trial, commissioning, kedatangan material,
 * validasi (dari jadwal proses), agenda meeting/follow-up manual, dan hari libur perusahaan.
 */
final class CalendarService
{
    public const CATEGORIES = ['deadline', 'approval', 'trial', 'commissioning', 'material', 'validation', 'agenda'];
    public const AGENDA_TYPES = ['meeting', 'follow_up', 'other'];

    /**
     * Event dalam rentang tanggal, dikelompokkan per tanggal.
     * @param array{category?:?string,project_id?:?int,mine?:bool} $f
     * @return array<string,list<array<string,mixed>>>
     */
    public function events(User $user, string $from, string $to, array $f = []): array
    {
        $out = [];
        $today = Clock::todayString();
        $cal = WorkingCalendar::fromDb();
        $cat = in_array($f['category'] ?? null, self::CATEGORIES, true) ? (string) $f['category'] : null;
        if ($cat !== 'agenda') {
            $where = ["pr.status NOT IN ('completed', 'skipped')", 'pr.planned_finish BETWEEN ? AND ?', 'pj.is_archived = 0', 'pj.cancelled_at IS NULL', 'pj.finished_at IS NULL',
                      "NOT (pr.activation = 'loop_only' AND pr.status = 'not_started')", '(pp.id IS NULL OR pp.cancelled_at IS NULL)'];
            $params = [$from, $to];
            if ($cat !== null) {
                $where[] = $cat === 'deadline' ? '(pr.calendar_category IS NULL OR pr.calendar_category = ?)' : 'pr.calendar_category = ?';
                $params[] = $cat;
            }
            if (!empty($f['project_id'])) {
                $where[] = 'pr.project_id = ?';
                $params[] = (int) $f['project_id'];
            }
            if (!empty($f['mine'])) {
                $where[] = '(pr.pic_user_id = ? OR pj.sales_pic_id = ? OR pj.npd_pic_id = ?)';
                array_push($params, $user->id, $user->id, $user->id);
            }
            foreach (Db::fetchAll(
                'SELECT pr.id, pr.code, pr.name, pr.name_en, pr.status, pr.planned_finish, pr.calendar_category, pp.name AS part_name, pp.is_on_hold AS part_hold,
                        pj.id AS project_id, pj.code AS project_code, pj.is_on_hold AS project_hold, u.name AS pic_name
                 FROM processes pr JOIN projects pj ON pj.id = pr.project_id LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
                 WHERE ' . implode(' AND ', $where) . ' ORDER BY pr.planned_finish, pj.code LIMIT 2000',
                $params
            ) as $r) {
                $held = (int) $r['project_hold'] === 1 || (int) ($r['part_hold'] ?? 0) === 1;
                $active = in_array($r['status'], ProjectQuery::ACTIVE, true);
                $out[(string) $r['planned_finish']][] = [
                    'type' => 'process',
                    'category' => in_array($r['calendar_category'], self::CATEGORIES, true) ? $r['calendar_category'] : 'deadline',
                    'title' => $r['code'] . ' ' . ProjectQuery::processName($r) . ' — ' . $r['project_code'] . ($r['part_name'] ? ' › ' . $r['part_name'] : ''),
                    'meta' => $r['project_code'] . ($r['part_name'] ? ' › ' . $r['part_name'] : '') . ' · ' . ($r['pic_name'] ?? I18n::t('project.no_pic')),
                    'status' => $r['status'],
                    'overdue' => $active && !$held && Lateness::overdueDays($cal, (string) $r['planned_finish'], $today) > 0,
                    'link' => 'process.php?id=' . $r['id'],
                ];
            }
        }
        if ($cat === null || $cat === 'agenda') {
            $where = ['e.event_date BETWEEN ? AND ?'];
            $params = [$from, $to];
            if (!empty($f['project_id'])) {
                $where[] = 'e.project_id = ?';
                $params[] = (int) $f['project_id'];
            }
            if (!empty($f['mine'])) {
                $where[] = 'e.created_by = ?';
                $params[] = $user->id;
            }
            foreach (Db::fetchAll(
                'SELECT e.*, pj.code AS project_code, u.name AS creator FROM calendar_events e LEFT JOIN projects pj ON pj.id = e.project_id
                 LEFT JOIN users u ON u.id = e.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY e.event_date, e.start_time, e.id',
                $params
            ) as $e) {
                $out[(string) $e['event_date']][] = [
                    'type' => 'agenda',
                    'id' => (int) $e['id'],
                    'category' => 'agenda',
                    'title' => ($e['start_time'] ? substr((string) $e['start_time'], 0, 5) . ' ' : '') . $e['title'],
                    'meta' => trim(I18n::t('calendar.type.' . $e['event_type']) . ($e['project_code'] ? ' · ' . $e['project_code'] : '') . ($e['location'] ? ' · ' . $e['location'] : '')),
                    'notes' => $e['notes'],
                    'status' => null,
                    'overdue' => false,
                    'link' => $e['project_id'] ? 'project.php?id=' . $e['project_id'] : null,
                    'can_delete' => $this->canDelete($user, $e),
                ];
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,string> tanggal => nama libur */
    public function holidays(string $from, string $to): array
    {
        $cal = WorkingCalendar::fromDb();
        $names = [];
        foreach (Db::fetchAll('SELECT holiday_date, is_recurring, name FROM holidays') as $h) {
            $names[(int) $h['is_recurring'] === 1 ? substr((string) $h['holiday_date'], 5) : (string) $h['holiday_date']] = (string) $h['name'];
        }
        $out = [];
        for ($d = $from; $d <= $to; $d = WorkingCalendar::shift($d, 1)) {
            if ($cal->isHoliday($d)) {
                $out[$d] = $names[$d] ?? $names[substr($d, 5)] ?? '';
            }
        }
        return $out;
    }

    /**
     * Agenda manual (meeting/follow-up) — Admin, NPD Staff, Admin Sales (OQ-16).
     * @param array<string,mixed> $input
     */
    public function createAgenda(User $actor, array $input): int
    {
        Gate::authorize($actor, 'calendar.manage');
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 190) {
            $errors['title'] = I18n::t('validation.required_max', ['max' => 190]);
        }
        $type = (string) ($input['event_type'] ?? 'meeting');
        if (!in_array($type, self::AGENDA_TYPES, true)) {
            $errors['event_type'] = I18n::t('validation.invalid');
        }
        $date = (string) ($input['event_date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['event_date'] = I18n::t('validation.date');
        }
        $times = [];
        foreach (['start_time', 'end_time'] as $k) {
            $v = trim((string) ($input[$k] ?? ''));
            if ($v === '') {
                $times[$k] = null;
            } elseif (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                $times[$k] = $v . ':00';
            } else {
                $errors[$k] = I18n::t('validation.invalid');
            }
        }
        if (!empty($times['start_time']) && !empty($times['end_time']) && $times['end_time'] < $times['start_time']) {
            $errors['end_time'] = I18n::t('validation.invalid');
        }
        $projectId = !empty($input['project_id']) ? (int) $input['project_id'] : null;
        if ($projectId !== null && !Db::value('SELECT id FROM projects WHERE id = ?', [$projectId])) {
            $errors['project_id'] = I18n::t('validation.invalid');
        }
        $location = mb_substr(trim((string) ($input['location'] ?? '')), 0, 190);
        $notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 2000);
        if ($errors) {
            throw new ValidationException($errors);
        }
        $id = Db::insert('calendar_events', [
            'project_id' => $projectId, 'title' => $title, 'event_type' => $type, 'event_date' => $date,
            'start_time' => $times['start_time'], 'end_time' => $times['end_time'], 'location' => $location ?: null,
            'notes' => $notes ?: null, 'created_by' => $actor->id, 'created_at' => Clock::nowString(),
        ]);
        AuditLogger::log('calendar.create', 'calendar_event', $id, null, ['title' => $title, 'date' => $date, 'type' => $type], null, $projectId, $actor);
        return $id;
    }

    /** Hapus agenda: pembuat atau Admin. */
    public function deleteAgenda(User $actor, int $id): void
    {
        $e = Db::fetch('SELECT * FROM calendar_events WHERE id = ?', [$id]);
        if (!$e) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        if (!$this->canDelete($actor, $e)) {
            throw new AuthorizationException(I18n::t('error.forbidden'));
        }
        Db::execute('DELETE FROM calendar_events WHERE id = ?', [$id]);
        AuditLogger::log('calendar.delete', 'calendar_event', $id, ['title' => $e['title'], 'date' => $e['event_date']], null, null,
            $e['project_id'] !== null ? (int) $e['project_id'] : null, $actor);
    }

    /** @param array<string,mixed> $e */
    private function canDelete(User $user, array $e): bool
    {
        return Gate::can($user, 'calendar.manage') && ((int) $e['created_by'] === $user->id || $user->isAdmin());
    }
}
