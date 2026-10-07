<?php
declare(strict_types=1);

namespace App\Project;

use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\Settings;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\ProcessRuns;
use App\Workflow\WorkflowEngine;

/**
 * Hold & Resume (PRD §8.1–8.3): project atau part; selama Hold proses dibekukan (tidak dapat diselesaikan,
 * tidak dihitung overdue, aging & durasi KPI tidak bertambah). Resume mewajibkan Target Finish baru dan
 * penjadwalan ulang proses sisa dengan pratinjau; membuat baseline baru (jadwal lama tetap sebagai baseline sebelumnya).
 */
final class HoldService
{
    public const ACTIVE = ['current', 'revision', 'problem'];

    public function __construct(
        private ScheduleService $schedule = new ScheduleService(),
        private StatusService $status = new StatusService(),
    ) {
    }

    /** Hold aktif (belum di-Resume) project dan part-nya. @return list<array<string,mixed>> */
    public function openHolds(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT h.*, pp.name AS part_name, u.name AS held_by_name FROM hold_history h LEFT JOIN project_parts pp ON pp.id = h.part_id
             LEFT JOIN users u ON u.id = h.held_by WHERE h.project_id = ? AND h.resumed_at IS NULL ORDER BY h.held_at',
            [$projectId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $projectId): array
    {
        return Db::fetchAll(
            'SELECT h.*, pp.name AS part_name, u.name AS held_by_name, r.name AS resumed_by_name FROM hold_history h LEFT JOIN project_parts pp ON pp.id = h.part_id
             LEFT JOIN users u ON u.id = h.held_by LEFT JOIN users r ON r.id = h.resumed_by WHERE h.project_id = ? ORDER BY h.held_at DESC, h.id DESC',
            [$projectId]
        );
    }

    /** Hold project (partId null) atau satu part. */
    public function hold(User $actor, int $projectId, ?int $partId, string $reason, ?string $expectedResume = null): void
    {
        Gate::authorize($actor, 'hold.manage');
        $reason = trim($reason);
        $errors = [];
        if ($reason === '') {
            $errors['reason'] = I18n::t('npr.v.reason_required');
        }
        if ($expectedResume !== null && $expectedResume !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expectedResume);
            if (!$d || $d->format('Y-m-d') !== $expectedResume) {
                $errors['expected_resume_date'] = I18n::t('validation.date');
            }
        } else {
            $expectedResume = null;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        Db::transaction(function () use ($actor, $projectId, $partId, $reason, $expectedResume): void {
            $project = $this->lockProject($projectId);
            $this->assertOpen($project);
            if ((int) $project['is_on_hold'] === 1) {
                throw new BusinessRuleException(I18n::t('hold.already_project'));
            }
            $now = Clock::nowString();
            if ($partId !== null) {
                $part = Db::fetch('SELECT * FROM project_parts WHERE id = ? AND project_id = ? FOR UPDATE', [$partId, $projectId]);
                if (!$part) {
                    throw new NotFoundException(I18n::t('error.not_found'));
                }
                if ($part['cancelled_at'] !== null || $part['completed_at'] !== null || (int) $part['is_on_hold'] === 1) {
                    throw new BusinessRuleException(I18n::t('hold.part_not_holdable'));
                }
                Db::update('project_parts', ['is_on_hold' => 1], ['id' => $partId]);
            } else {
                Db::update('projects', ['is_on_hold' => 1], ['id' => $projectId]);
            }
            Db::insert('hold_history', [
                'project_id' => $projectId, 'part_id' => $partId, 'scope' => $partId === null ? 'project' : 'part', 'reason' => $reason,
                'expected_resume_date' => $expectedResume, 'held_at' => $now, 'held_by' => $actor->id,
            ]);
            $scopeName = $partId === null ? (string) $project['code'] : (string) Db::value('SELECT name FROM project_parts WHERE id = ?', [$partId]);
            RevisionHistory::record('hold', I18n::t('hold.rev.hold', ['scope' => $scopeName], 'id'), ['reason' => $reason, 'expected_resume' => $expectedResume],
                null, $projectId, $partId, null, $actor->id);
            AuditLogger::log($partId === null ? 'project.hold' : 'part.hold', $partId === null ? 'project' : 'project_part', $partId ?? $projectId,
                ['is_on_hold' => 0], ['is_on_hold' => 1, 'expected_resume' => $expectedResume], $reason, $projectId, $actor);
            Db::update('projects', ['last_activity_at' => $now], ['id' => $projectId]);
            $this->status->refresh($projectId);
            Notifier::send(array_filter([$project['npd_pic_id'], $project['sales_pic_id']]), 'hold_changed', 'notif.hold.title', 'notif.hold.body',
                ['project' => (string) $project['code'], 'scope' => $scopeName, 'reason' => $reason], 'project.php?id=' . $projectId, $projectId, null,
                'hold:' . $projectId . ':' . ($partId ?? 0) . ':' . $now, $actor->id);
        });
    }

    /**
     * Data dialog Resume: proses aktif dalam cakupan dengan durasi sisa bawaan.
     * @return array{hold:array<string,mixed>,processes:list<array<string,mixed>>,restart:string,target:?string}
     */
    public function resumeContext(int $projectId, ?int $partId): array
    {
        $hold = $this->openHold($projectId, $partId);
        $cal = $this->schedule->calendar();
        $heldOn = substr((string) $hold['held_at'], 0, 10);
        $procs = Db::fetchAll(
            "SELECT pr.id, pr.code, pr.name, pr.name_en, pr.status, pr.duration, pr.actual_start, pr.planned_start, pr.planned_finish, pr.part_id, pp.name AS part_name, u.name AS pic_name
             FROM processes pr LEFT JOIN project_parts pp ON pp.id = pr.part_id LEFT JOIN users u ON u.id = pr.pic_user_id
             WHERE pr.project_id = ? AND pr.status IN ('current', 'revision', 'problem') AND (pp.id IS NULL OR pp.cancelled_at IS NULL)"
            // Resume project tidak menyentuh part yang di-Hold terpisah (tetap Hold sampai part itu di-Resume)
            . ($partId !== null ? ' AND pr.part_id = ?' : ' AND (pp.id IS NULL OR pp.is_on_hold = 0)'),
            $partId !== null ? [$projectId, $partId] : [$projectId]
        );
        foreach ($procs as &$p) {
            // durasi sisa bawaan = durasi rencana − hari kerja yang sudah dipakai sebelum Hold (PRD §8.2)
            $used = $p['actual_start'] ? $cal->countWorkingDays((string) $p['actual_start'], self::dayBefore($heldOn)) : 0;
            $p['used'] = $used;
            $p['remaining'] = max(1, (int) $p['duration'] - $used);
        }
        unset($p);
        return [
            'hold' => $hold,
            'processes' => $procs,
            'restart' => $cal->nextWorkingDay(Clock::todayString()),
            'target' => Db::value('SELECT target_finish FROM projects WHERE id = ?', [$projectId]),
        ];
    }

    /**
     * Pratinjau jadwal setelah Resume (tanpa menyimpan).
     * @param array<string,mixed> $input target_finish, restart_date, remaining[processId]
     * @return array<string,mixed>
     */
    public function previewResume(User $actor, int $projectId, ?int $partId, array $input): array
    {
        Gate::authorize($actor, 'hold.manage');
        $ctx = $this->resumeContext($projectId, $partId);
        $clean = $this->cleanResume($input, $ctx);
        $cal = $this->schedule->calendar();
        $heldParts = array_map('intval', Db::column('SELECT id FROM project_parts WHERE project_id = ? AND is_on_hold = 1', [$projectId]));
        $preview = $this->schedule->preview($projectId, static function (array &$g) use ($partId, $clean, $cal, $heldParts): void {
            $restart = $clean['restart'];
            if ($partId === null) {
                $g['project']['is_on_hold'] = 0;
                $g['project']['start_date'] = max((string) $g['project']['start_date'], $restart);
            }
            foreach ($g['partStart'] as $pp => $start) {
                if ($start !== null && ($partId === null || $pp === $partId)) {
                    $g['partStart'][$pp] = max((string) $start, $restart);
                }
            }
            foreach ($g['nodes'] as $id => $n) {
                if ($partId === null ? !in_array($n['part_id'], $heldParts, true) : $n['part_id'] === $partId) {
                    unset($g['frozen'][$id]);
                }
                if (isset($clean['remaining'][$id])) {
                    $g['nodes'][$id]['planned_finish'] = $cal->finishFromStart($clean['restart'], $clean['remaining'][$id]);
                }
            }
        });
        $preview['target_new'] = $clean['target'];
        $preview['past_target'] = $preview['project_forecast_new'] !== null && $preview['project_forecast_new'] > $clean['target'];
        $preview['active'] = array_map(static fn ($p) => $p + [
            'new_finish' => $cal->finishFromStart($clean['restart'], $clean['remaining'][(int) $p['id']]),
            'new_remaining' => $clean['remaining'][(int) $p['id']],
        ], $ctx['processes']);
        return $preview;
    }

    /**
     * Resume: target baru (wajib), jadwal ulang proses sisa, baseline baru, hari Hold dikecualikan dari KPI.
     * @param array<string,mixed> $input target_finish, restart_date, remaining[processId], note
     */
    public function resume(User $actor, int $projectId, ?int $partId, array $input): int
    {
        Gate::authorize($actor, 'hold.manage');
        return Db::transaction(function () use ($actor, $projectId, $partId, $input): int {
            $project = $this->lockProject($projectId);
            $ctx = $this->resumeContext($projectId, $partId);
            $clean = $this->cleanResume($input, $ctx);
            $note = trim((string) ($input['note'] ?? ''));
            $cal = $this->schedule->calendar();
            $now = Clock::nowString();
            $hold = $ctx['hold'];
            $heldOn = substr((string) $hold['held_at'], 0, 10);
            $holdDays = max(0, $cal->countWorkingDays($heldOn, self::dayBefore(Clock::todayString())));

            // proses yang belum mulai dalam cakupan tidak dijadwalkan sebelum tanggal mulai kembali
            if ($partId !== null) {
                Db::update('project_parts', ['is_on_hold' => 0, 'schedule_floor' => $clean['restart']], ['id' => $partId]);
            } else {
                Db::update('projects', ['is_on_hold' => 0, 'schedule_floor' => $clean['restart']], ['id' => $projectId]);
            }
            foreach ($ctx['processes'] as $p) {
                $newFinish = $cal->finishFromStart($clean['restart'], $clean['remaining'][(int) $p['id']]);
                Db::update('processes', ['planned_finish' => $newFinish], ['id' => (int) $p['id']]);
                Db::execute('UPDATE processes SET lock_version = lock_version + 1 WHERE id = ?', [(int) $p['id']]);
                ProcessRuns::updateOpenPlan((int) $p['id'], $newFinish, $holdDays); // PIC tidak dirugikan masa Hold (KPI)
            }
            if ($clean['target'] !== $project['target_finish']) {
                $this->schedule->changeTarget($actor, $projectId, $clean['target'], I18n::t('hold.target_reason', ['note' => $note], 'id'));
            }
            $scopeName = $partId === null ? (string) $project['code'] : (string) Db::value('SELECT name FROM project_parts WHERE id = ?', [$partId]);
            $this->schedule->recalculate($projectId, 'resume', null, $note !== '' ? $note : null, $actor, 'plan');
            $baselineId = $this->schedule->createBaseline($projectId, $partId, I18n::t('hold.baseline_reason', ['scope' => $scopeName], 'id') . ($note !== '' ? ' — ' . $note : ''), $actor);
            Db::update('hold_history', [
                'resumed_at' => $now, 'resumed_by' => $actor->id, 'resume_note' => $note !== '' ? $note : null,
                'new_target_finish' => $clean['target'], 'baseline_id' => $baselineId, 'hold_working_days' => $holdDays,
            ], ['id' => (int) $hold['id']]);
            RevisionHistory::record('resume', I18n::t('hold.rev.resume', ['scope' => $scopeName, 'days' => $holdDays], 'id'),
                ['note' => $note, 'target' => $clean['target'], 'restart' => $clean['restart'], 'remaining' => $clean['remaining'], 'baseline_id' => $baselineId],
                null, $projectId, $partId, null, $actor->id);
            AuditLogger::log($partId === null ? 'project.resume' : 'part.resume', $partId === null ? 'project' : 'project_part', $partId ?? $projectId,
                ['is_on_hold' => 1], ['is_on_hold' => 0, 'target_finish' => $clean['target'], 'restart' => $clean['restart'], 'hold_working_days' => $holdDays], $note ?: null, $projectId, $actor);
            Db::update('projects', ['last_activity_at' => $now], ['id' => $projectId]);
            (new WorkflowEngine($this->schedule, $this->status))->activateReady($projectId, $actor);
            $this->status->refresh($projectId);
            $pics = array_filter(array_merge(
                [$project['npd_pic_id'], $project['sales_pic_id']],
                Db::column("SELECT pic_user_id FROM processes WHERE project_id = ? AND status IN ('current','revision','problem')" . ($partId !== null ? ' AND part_id = ?' : ''), $partId !== null ? [$projectId, $partId] : [$projectId])
            ));
            Notifier::send(array_map('intval', $pics), 'hold_changed', 'notif.resume.title', 'notif.resume.body',
                ['project' => (string) $project['code'], 'scope' => $scopeName, 'target' => I18n::date($clean['target'])],
                'project.php?id=' . $projectId, $projectId, null, 'resume:' . $hold['id'], $actor->id);
            return $baselineId;
        });
    }

    /** Pengingat Hold (cron): pertama setelah N hari (bawaan 30), lalu tiap M hari (bawaan 7) — Admin & NPD PIC, web + email. */
    public function sendReminders(): int
    {
        $first = max(1, Settings::int('hold.reminder_days', 30));
        $repeat = max(1, Settings::int('hold.reminder_repeat_days', 7));
        $now = Clock::now();
        $sent = 0;
        foreach (Db::fetchAll(
            'SELECT h.*, pj.code, pj.name AS project_name, pj.npd_pic_id, pp.name AS part_name FROM hold_history h JOIN projects pj ON pj.id = h.project_id
             LEFT JOIN project_parts pp ON pp.id = h.part_id WHERE h.resumed_at IS NULL AND pj.is_archived = 0 AND h.held_at <= ?',
            [$now->modify('-' . $first . ' days')->format('Y-m-d H:i:s')]
        ) as $h) {
            if ($h['last_reminder_at'] !== null && $h['last_reminder_at'] > $now->modify('-' . $repeat . ' days')->format('Y-m-d H:i:s')) {
                continue;
            }
            $days = (int) floor(($now->getTimestamp() - strtotime((string) $h['held_at'])) / 86400);
            $recipients = array_merge(Notifier::usersWithRole('admin'), array_filter([(int) $h['npd_pic_id']]));
            $n = Notifier::send($recipients, 'hold_reminder', 'notif.hold_reminder.title', 'notif.hold_reminder.body',
                ['project' => (string) $h['code'], 'scope' => (string) ($h['part_name'] ?? $h['project_name']), 'days' => $days, 'reason' => (string) $h['reason']],
                'project.php?id=' . $h['project_id'], (int) $h['project_id'], null, 'hold_reminder:' . $h['id'] . ':' . ((int) $h['reminder_count'] + 1));
            Db::update('hold_history', ['last_reminder_at' => Clock::nowString(), 'reminder_count' => (int) $h['reminder_count'] + 1], ['id' => (int) $h['id']]);
            $sent += $n;
        }
        return $sent;
    }

    /** @return array<string,mixed> */
    private function openHold(int $projectId, ?int $partId): array
    {
        $h = Db::fetch('SELECT * FROM hold_history WHERE project_id = ? AND part_id <=> ? AND resumed_at IS NULL ORDER BY id DESC LIMIT 1', [$projectId, $partId]);
        if (!$h) {
            throw new BusinessRuleException(I18n::t('hold.not_on_hold'));
        }
        return $h;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $ctx
     * @return array{target:string,restart:string,remaining:array<int,int>}
     */
    private function cleanResume(array $input, array $ctx): array
    {
        $errors = [];
        $target = trim((string) ($input['target_finish'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $target);
        if ($target === '' || !$d || $d->format('Y-m-d') !== $target) {
            $errors['target_finish'] = I18n::t('hold.target_required');
        }
        $restart = trim((string) ($input['restart_date'] ?? '')) ?: (string) $ctx['restart'];
        $r = \DateTimeImmutable::createFromFormat('!Y-m-d', $restart);
        if (!$r || $r->format('Y-m-d') !== $restart || $restart < Clock::todayString()) {
            $errors['restart_date'] = I18n::t('hold.restart_invalid');
        } else {
            $restart = $this->schedule->calendar()->nextWorkingDay($restart);
        }
        $remaining = [];
        $in = is_array($input['remaining'] ?? null) ? $input['remaining'] : [];
        foreach ($ctx['processes'] as $p) {
            $id = (int) $p['id'];
            $v = isset($in[$id]) ? trim((string) $in[$id]) : (string) $p['remaining'];
            if (!preg_match('/^\d{1,3}$/', $v) || (int) $v < 1 || (int) $v > 365) {
                $errors['remaining.' . $id] = I18n::t('wf.duration_invalid');
            } else {
                $remaining[$id] = (int) $v;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['target' => $target, 'restart' => $restart, 'remaining' => $remaining];
    }

    /** @return array<string,mixed> */
    private function lockProject(int $projectId): array
    {
        $p = Db::fetch('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
        if (!$p) {
            throw new NotFoundException(I18n::t('error.not_found'));
        }
        return $p;
    }

    /** Hari kalender sebelum tanggal. */
    private static function dayBefore(string $date): string
    {
        return WorkingCalendar::shift($date, -1);
    }

    private function assertOpen(array $project): void
    {
        if ($project['finished_at'] !== null || $project['cancelled_at'] !== null || (int) $project['is_archived'] === 1) {
            throw new BusinessRuleException(I18n::t('hold.project_closed'));
        }
    }
}
