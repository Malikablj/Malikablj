<?php
declare(strict_types=1);

namespace App\Project;

use App\Approval\ApprovalService;
use App\Core\AuditLogger;
use App\Core\BusinessRuleException;
use App\Core\Clock;
use App\Core\Db;
use App\Core\Gate;
use App\Core\I18n;
use App\Core\NotFoundException;
use App\Core\User;
use App\Core\ValidationException;
use App\Notification\Notifier;
use App\Scheduling\ScheduleService;
use App\Scheduling\WorkingCalendar;
use App\Workflow\ProcessRuns;
use App\Workflow\WorkflowEngine;
use App\Workflow\WorkflowInstantiator;

/**
 * Cancel, buka kembali, arsip & pulihkan (PRD §8.4, FR-HLD-04/05).
 *  - Cancel part/project: Admin/NPD, alasan wajib. Project Cancelled hanya bila semua part Cancelled —
 *    karena itu Cancel project ditolak bila sudah ada part Completed (OQ-27).
 *  - Buka kembali (hanya Admin, alasan wajib): part yang sudah berjalan (punya proses) dipulihkan beserta
 *    proses aktifnya; part yang dibatalkan sebelum dimulai harus diajukan lewat NPR baru (OQ-27).
 *  - Arsip/pulihkan (hanya Admin, alasan wajib): hanya menyembunyikan dari daftar/dashboard/laporan bawaan;
 *    tidak ada penghapusan permanen.
 */
final class LifecycleService
{
    private const ACTIVE = ['current', 'revision', 'problem'];

    public function __construct(
        private ScheduleService $schedule = new ScheduleService(),
        private StatusService $status = new StatusService(),
    ) {
    }

    /** Aksi yang tersedia untuk pengguna pada project (untuk menampilkan tombol; server tetap memeriksa ulang). @return array<string,bool> */
    public function abilities(User $user, array $project): array
    {
        $closed = $project['finished_at'] !== null || $project['cancelled_at'] !== null;
        $archived = (int) $project['is_archived'] === 1;
        $hasCompleted = (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND completed_at IS NOT NULL', [(int) $project['id']]) > 0;
        return [
            'hold' => !$closed && !$archived && (int) $project['is_on_hold'] === 0 && Gate::can($user, 'hold.manage'),
            'resume' => !$closed && !$archived && (int) $project['is_on_hold'] === 1 && Gate::can($user, 'hold.manage'),
            'cancel' => !$closed && !$archived && !$hasCompleted && Gate::can($user, 'project.cancel'),
            'reopen' => $project['cancelled_at'] !== null && !$archived && Gate::can($user, 'project.reopen_cancelled'),
            'archive' => !$archived && Gate::can($user, 'project.archive'),
            'restore' => $archived && Gate::can($user, 'project.archive'),
        ];
    }

    /** @return array<string,bool> */
    public function partAbilities(User $user, array $project, array $part): array
    {
        $projectOpen = $project['finished_at'] === null && $project['cancelled_at'] === null && (int) $project['is_archived'] === 0;
        $partOpen = $part['cancelled_at'] === null && $part['completed_at'] === null;
        $started = (int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [(int) $part['id']]) > 0;
        return [
            'hold' => $projectOpen && $partOpen && (int) $project['is_on_hold'] === 0 && (int) $part['is_on_hold'] === 0 && Gate::can($user, 'hold.manage'),
            'resume' => $projectOpen && $partOpen && (int) $project['is_on_hold'] === 0 && (int) $part['is_on_hold'] === 1 && Gate::can($user, 'hold.manage'),
            'cancel' => $projectOpen && $partOpen && Gate::can($user, 'project.cancel'),
            'reopen' => $part['cancelled_at'] !== null && $started && (int) $project['is_archived'] === 0 && $project['finished_at'] === null
                && $project['cancelled_at'] === null && Gate::can($user, 'project.reopen_cancelled'),
        ];
    }

    public function cancelPart(User $actor, int $partId, string $reason): void
    {
        Gate::authorize($actor, 'project.cancel');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $partId, $reason): void {
            $part = Db::fetch('SELECT * FROM project_parts WHERE id = ?', [$partId]);
            if (!$part) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $project = $this->lockProject((int) $part['project_id']);
            $this->assertOpen($project);
            $part = Db::fetch('SELECT * FROM project_parts WHERE id = ? FOR UPDATE', [$partId]);
            if ($part['cancelled_at'] !== null || $part['completed_at'] !== null) {
                throw new BusinessRuleException(I18n::t('lifecycle.part_not_cancellable'));
            }
            $now = Clock::nowString();
            $pics = $this->activePics((int) $part['project_id'], $partId);
            $this->cancelPartRows($actor, $part, $reason, $now);
            $projectId = (int) $project['id'];
            RevisionHistory::record('cancel', I18n::t('lifecycle.rev.part_cancelled', ['part' => $part['name']], 'id'), ['reason' => $reason], null, $projectId, $partId, null, $actor->id);
            AuditLogger::log('part.cancel', 'project_part', $partId, ['status' => $part['status']], ['status' => 'cancelled'], $reason, $projectId, $actor);
            // FR-HLD-05: project menjadi Cancelled bila seluruh part Cancelled — ditutup lebih dulu agar
            // proses level project (gate/Project Finish) tidak ikut teraktifkan saat part dilepas.
            $open = (int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND cancelled_at IS NULL', [$projectId]);
            if ($open === 0) {
                $this->closeProjectAsCancelled($actor, $project, $reason, $now);
            }
            (new WorkflowEngine($this->schedule, $this->status))->onPartCancelled($partId, $actor);
            Db::update('projects', ['last_activity_at' => $now], ['id' => $projectId]);
            $this->status->refresh($projectId);
            Notifier::send(array_merge($pics, array_filter([(int) $project['npd_pic_id'], (int) $project['sales_pic_id']])), 'project_cancelled',
                'notif.part_cancelled.title', 'notif.part_cancelled.body', ['project' => (string) $project['code'], 'part' => (string) $part['name'], 'reason' => $reason],
                'project.php?id=' . $projectId, $projectId, null, 'part_cancelled:' . $partId . ':' . $now, $actor->id);
        });
    }

    /** Cancel project: semua part yang belum Cancelled ikut dibatalkan; ditolak bila ada part Completed (FR-HLD-05). */
    public function cancelProject(User $actor, int $projectId, string $reason): void
    {
        Gate::authorize($actor, 'project.cancel');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $projectId, $reason): void {
            $project = $this->lockProject($projectId);
            $this->assertOpen($project);
            if ((int) Db::value('SELECT COUNT(*) FROM project_parts WHERE project_id = ? AND completed_at IS NOT NULL', [$projectId]) > 0) {
                throw new BusinessRuleException(I18n::t('lifecycle.cancel_has_completed'));
            }
            $now = Clock::nowString();
            $pics = $this->activePics($projectId, null);
            $engine = new WorkflowEngine($this->schedule, $this->status);
            $parts = Db::fetchAll('SELECT * FROM project_parts WHERE project_id = ? AND cancelled_at IS NULL FOR UPDATE', [$projectId]);
            foreach ($parts as $part) {
                $this->cancelPartRows($actor, $part, $reason, $now);
                AuditLogger::log('part.cancel', 'project_part', (int) $part['id'], ['status' => $part['status']], ['status' => 'cancelled'], $reason, $projectId, $actor);
            }
            $this->closeProjectAsCancelled($actor, $project, $reason, $now);
            foreach ($parts as $part) {
                $engine->onPartCancelled((int) $part['id'], $actor);
            }
            RevisionHistory::record('cancel', I18n::t('lifecycle.rev.project_cancelled', ['project' => $project['code']], 'id'), ['reason' => $reason], null, $projectId, null, null, $actor->id);
            Db::update('projects', ['last_activity_at' => $now], ['id' => $projectId]);
            $this->status->refresh($projectId);
            Notifier::send(array_merge($pics, array_filter([(int) $project['npd_pic_id'], (int) $project['sales_pic_id']])), 'project_cancelled',
                'notif.project_cancelled.title', 'notif.project_cancelled.body', ['project' => (string) $project['code'], 'reason' => $reason],
                'project.php?id=' . $projectId, $projectId, null, 'project_cancelled:' . $projectId . ':' . $now, $actor->id);
        });
    }

    /** Buka kembali part Cancelled yang sudah berjalan (hanya Admin, alasan wajib). */
    public function reopenPart(User $actor, int $partId, string $reason): void
    {
        Gate::authorize($actor, 'project.reopen_cancelled');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $partId, $reason): void {
            $part = Db::fetch('SELECT * FROM project_parts WHERE id = ?', [$partId]);
            if (!$part) {
                throw new NotFoundException(I18n::t('error.not_found'));
            }
            $project = $this->lockProject((int) $part['project_id']);
            if ($project['cancelled_at'] !== null) {
                throw new BusinessRuleException(I18n::t('lifecycle.reopen_project_first'));
            }
            $this->assertOpen($project);
            $this->reopenPartRows($actor, $part, $reason);
            $projectId = (int) $project['id'];
            $this->afterReopen($actor, $projectId);
            Notifier::send(array_merge($this->activePics($projectId, $partId), array_filter([(int) $project['npd_pic_id']])), 'project_reopened', 'notif.part_reopened.title', 'notif.part_reopened.body',
                ['project' => (string) $project['code'], 'part' => (string) $part['name'], 'reason' => $reason], 'project.php?id=' . $projectId, $projectId, null,
                'part_reopened:' . $partId . ':' . Clock::nowString(), $actor->id);
        });
    }

    /** Buka kembali project Cancelled: part yang ikut dibatalkan bersama project (dan sudah berjalan) dipulihkan. */
    public function reopenProject(User $actor, int $projectId, string $reason): void
    {
        Gate::authorize($actor, 'project.reopen_cancelled');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $projectId, $reason): void {
            $project = $this->lockProject($projectId);
            if ($project['cancelled_at'] === null) {
                throw new BusinessRuleException(I18n::t('lifecycle.not_cancelled'));
            }
            if ((int) $project['is_archived'] === 1) {
                throw new BusinessRuleException(I18n::t('lifecycle.archived'));
            }
            // part yang dibatalkan bersamaan dengan project (Cancel project / part terakhir)
            $parts = Db::fetchAll(
                'SELECT pp.* FROM project_parts pp WHERE pp.project_id = ? AND pp.cancelled_at >= ? AND EXISTS (SELECT 1 FROM processes pr WHERE pr.part_id = pp.id)',
                [$projectId, $project['cancelled_at']]
            );
            if ($parts === []) {
                throw new BusinessRuleException(I18n::t('lifecycle.reopen_nothing'));
            }
            Db::update('projects', ['cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null], ['id' => $projectId]);
            foreach (Db::fetchAll("SELECT * FROM processes WHERE project_id = ? AND part_id IS NULL AND status IN ('current','revision','problem')", [$projectId]) as $p) {
                $this->reopenRun($actor, $p);
            }
            foreach ($parts as $part) {
                $this->reopenPartRows($actor, $part, $reason);
            }
            RevisionHistory::record('reopen_cancelled', I18n::t('lifecycle.rev.project_reopened', ['project' => $project['code']], 'id'), ['reason' => $reason], null, $projectId, null, null, $actor->id);
            AuditLogger::log('project.reopen_cancelled', 'project', $projectId, ['cancelled_at' => $project['cancelled_at'], 'cancel_reason' => $project['cancel_reason']],
                ['cancelled_at' => null, 'parts' => array_map(static fn ($p) => (int) $p['id'], $parts)], $reason, $projectId, $actor);
            $this->afterReopen($actor, $projectId);
            Notifier::send(array_merge($this->activePics($projectId, null), array_filter([(int) $project['npd_pic_id'], (int) $project['sales_pic_id']])), 'project_reopened',
                'notif.project_reopened.title', 'notif.project_reopened.body', ['project' => (string) $project['code'], 'reason' => $reason],
                'project.php?id=' . $projectId, $projectId, null, 'project_reopened:' . $projectId . ':' . Clock::nowString(), $actor->id);
        });
    }

    /** Arsipkan project (hanya Admin, alasan wajib) — data, dokumen & audit tetap tersimpan. */
    public function archive(User $actor, int $projectId, string $reason): void
    {
        Gate::authorize($actor, 'project.archive');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $projectId, $reason): void {
            $project = $this->lockProject($projectId);
            if ((int) $project['is_archived'] === 1) {
                throw new BusinessRuleException(I18n::t('lifecycle.archived'));
            }
            $now = Clock::nowString();
            Db::update('projects', ['is_archived' => 1, 'archived_at' => $now, 'archived_by' => $actor->id, 'archive_reason' => $reason], ['id' => $projectId]);
            RevisionHistory::record('archive', I18n::t('lifecycle.rev.archived', ['project' => $project['code']], 'id'), ['reason' => $reason], null, $projectId, null, null, $actor->id);
            AuditLogger::log('project.archive', 'project', $projectId, ['is_archived' => 0], ['is_archived' => 1], $reason, $projectId, $actor);
        });
    }

    public function restore(User $actor, int $projectId, string $reason): void
    {
        Gate::authorize($actor, 'project.archive');
        $reason = $this->requireReason($reason);
        Db::transaction(function () use ($actor, $projectId, $reason): void {
            $project = $this->lockProject($projectId);
            if ((int) $project['is_archived'] === 0) {
                throw new BusinessRuleException(I18n::t('lifecycle.not_archived'));
            }
            Db::update('projects', ['is_archived' => 0, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'last_activity_at' => Clock::nowString()], ['id' => $projectId]);
            RevisionHistory::record('restore', I18n::t('lifecycle.rev.restored', ['project' => $project['code']], 'id'),
                ['reason' => $reason, 'archived_at' => $project['archived_at'], 'archive_reason' => $project['archive_reason']], null, $projectId, null, null, $actor->id);
            AuditLogger::log('project.restore', 'project', $projectId, ['is_archived' => 1, 'archived_at' => $project['archived_at'], 'archive_reason' => $project['archive_reason']],
                ['is_archived' => 0], $reason, $projectId, $actor);
            if ($project['finished_at'] === null && $project['cancelled_at'] === null) {
                $this->schedule->recalculate($projectId, 'auto_shift', null, null, $actor);
                (new WorkflowEngine($this->schedule, $this->status))->activateReady($projectId, $actor);
            }
            $this->status->refresh($projectId);
        });
    }

    /** Tandai part batal: run & approval Pending ditutup, Hold terbuka diakhiri, next action dibatalkan. */
    private function cancelPartRows(User $actor, array $part, string $reason, string $now): void
    {
        $partId = (int) $part['id'];
        Db::update('project_parts', ['cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'is_on_hold' => 0], ['id' => $partId]);
        foreach (Db::fetchAll("SELECT id FROM processes WHERE part_id = ? AND status IN ('current','revision','problem')", [$partId]) as $r) {
            ApprovalService::withdrawPending((int) $r['id'], $actor, 'cancelled');
        }
        $this->endOpenHolds((int) $part['project_id'], $partId, $actor, $now);
        Db::execute("UPDATE next_actions SET status = 'cancelled' WHERE part_id = ? AND status = 'open'", [$partId]);
    }

    private function closeProjectAsCancelled(User $actor, array $project, string $reason, string $now): void
    {
        $projectId = (int) $project['id'];
        foreach (Db::fetchAll("SELECT id FROM processes WHERE project_id = ? AND part_id IS NULL AND status IN ('current','revision','problem')", [$projectId]) as $r) {
            ProcessRuns::close((int) $r['id'], 'reset', null, null, 'cancelled', null, $actor->id);
            ApprovalService::withdrawPending((int) $r['id'], $actor, 'cancelled');
        }
        $this->endOpenHolds($projectId, null, $actor, $now);
        Db::execute("UPDATE next_actions SET status = 'cancelled' WHERE project_id = ? AND status = 'open'", [$projectId]);
        Db::update('projects', ['cancelled_at' => $now, 'cancelled_by' => $actor->id, 'cancel_reason' => $reason, 'is_on_hold' => 0], ['id' => $projectId]);
        AuditLogger::log('project.cancel', 'project', $projectId, ['status' => $project['status']], ['status' => 'cancelled'], $reason, $projectId, $actor);
    }

    /** Hold yang masih terbuka diakhiri oleh pembatalan (tercatat, tanpa baseline). */
    private function endOpenHolds(int $projectId, ?int $partId, User $actor, string $now): void
    {
        $sql = 'SELECT id, held_at FROM hold_history WHERE project_id = ? AND resumed_at IS NULL' . ($partId !== null ? ' AND part_id = ?' : '');
        foreach (Db::fetchAll($sql, $partId !== null ? [$projectId, $partId] : [$projectId]) as $h) {
            $days = $this->schedule->calendar()->countWorkingDays(substr((string) $h['held_at'], 0, 10), WorkingCalendar::shift(Clock::todayString(), -1));
            Db::update('hold_history', ['resumed_at' => $now, 'resumed_by' => $actor->id, 'resume_note' => I18n::t('lifecycle.hold_ended_by_cancel', [], 'id'),
                'hold_working_days' => max(0, $days)], ['id' => (int) $h['id']]);
        }
    }

    private function reopenPartRows(User $actor, array $part, string $reason): void
    {
        $partId = (int) $part['id'];
        if ($part['cancelled_at'] === null) {
            throw new BusinessRuleException(I18n::t('lifecycle.not_cancelled'));
        }
        if ((int) Db::value('SELECT COUNT(*) FROM processes WHERE part_id = ?', [$partId]) === 0) {
            throw new BusinessRuleException(I18n::t('lifecycle.reopen_not_started'));
        }
        Db::update('project_parts', ['cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'last_activity_at' => Clock::nowString()], ['id' => $partId]);
        (new WorkflowInstantiator($this->schedule))->attachPart($partId);
        foreach (Db::fetchAll("SELECT * FROM processes WHERE part_id = ? AND status IN ('current','revision','problem')", [$partId]) as $p) {
            $this->reopenRun($actor, $p);
        }
        RevisionHistory::record('reopen_cancelled', I18n::t('lifecycle.rev.part_reopened', ['part' => $part['name']], 'id'),
            ['reason' => $reason, 'cancelled_at' => $part['cancelled_at'], 'cancel_reason' => $part['cancel_reason']], null, (int) $part['project_id'], $partId, null, $actor->id);
        AuditLogger::log('part.reopen_cancelled', 'project_part', $partId, ['cancelled_at' => $part['cancelled_at'], 'cancel_reason' => $part['cancel_reason']],
            ['cancelled_at' => null], $reason, (int) $part['project_id'], $actor);
    }

    /** Proses yang aktif saat dibatalkan mendapat run KPI & approval Pending baru. */
    private function reopenRun(User $actor, array $p): void
    {
        $iteration = (int) Db::value('SELECT COALESCE(MAX(iteration), 0) FROM process_runs WHERE process_id = ?', [(int) $p['id']]) + 1;
        $now = Clock::nowString();
        Db::update('processes', ['iteration' => $iteration, 'activated_at' => $now], ['id' => (int) $p['id']]);
        Db::execute('UPDATE processes SET lock_version = lock_version + 1 WHERE id = ?', [(int) $p['id']]);
        $p['iteration'] = $iteration;
        ProcessRuns::open($p, $now);
        ApprovalService::requestFor($p, $actor);
    }

    private function afterReopen(User $actor, int $projectId): void
    {
        Db::update('projects', ['last_activity_at' => Clock::nowString()], ['id' => $projectId]);
        $this->schedule->recalculate($projectId, 'auto_shift', null, null, $actor);
        (new WorkflowEngine($this->schedule, $this->status))->activateReady($projectId, $actor);
        $this->status->refresh($projectId);
    }

    /** PIC proses aktif dalam cakupan. @return list<int> */
    private function activePics(int $projectId, ?int $partId): array
    {
        $sql = "SELECT DISTINCT pic_user_id FROM processes WHERE project_id = ? AND pic_user_id IS NOT NULL AND status IN ('current','revision','problem')";
        return array_map('intval', $partId !== null ? Db::column($sql . ' AND part_id = ?', [$projectId, $partId]) : Db::column($sql, [$projectId]));
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidationException(['reason' => I18n::t('npr.v.reason_required')]);
        }
        if (mb_strlen($reason) > 1000) {
            throw new ValidationException(['reason' => I18n::t('validation.max', ['max' => 1000])]);
        }
        return $reason;
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

    private function assertOpen(array $project): void
    {
        if ((int) $project['is_archived'] === 1) {
            throw new BusinessRuleException(I18n::t('lifecycle.archived'));
        }
        if ($project['finished_at'] !== null || $project['cancelled_at'] !== null) {
            throw new BusinessRuleException(I18n::t('hold.project_closed'));
        }
    }
}
