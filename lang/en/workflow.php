<?php
declare(strict_types=1);

/** Workflow, dependencies, scheduling. */
return [
    // status
    'status.not_started' => 'Not Started',
    'status.current' => 'Current',
    'status.completed' => 'Completed',
    'status.revision' => 'Revision',
    'status.problem' => 'Problem',
    'status.skipped' => 'Skipped',
    'status.on_progress' => 'On Progress',
    'status.waiting' => 'Waiting',
    'status.waiting_approval' => 'Waiting Approval',
    'status.waiting_external' => 'Waiting External',
    'status.hold' => 'Hold',
    'status.ready_to_finish' => 'Ready to Finish',
    'status.cancelled' => 'Cancelled',
    'status.overdue' => 'Overdue',
    'status.due_soon' => 'Due Soon',

    // workflow engine validation & rules
    'wf.cannot_start' => 'This process cannot be started now.',
    'wf.part_not_started' => 'The part has not started yet (waiting for NPR feedback).',
    'wf.deps_not_met' => 'Previous processes are not finished — dependencies are not met.',
    'wf.complete_via_npr' => 'This process is completed through the NPR flow (submit / feedback).',
    'wf.not_active' => 'Only an active process can be completed.',
    'wf.on_hold' => 'The project/part is on Hold. Resume it first.',
    'wf.ff_blocked' => 'Cannot be completed yet: waiting for these processes to finish (Finish-to-Finish): :list',
    'wf.missing_docs' => 'Required documents not uploaded: :list',
    'wf.parts_not_finished' => 'Some parts are not completed or cancelled yet.',
    'wf.outcome_required' => 'Select the process result/decision.',
    'wf.comment_required' => 'A note is required for this decision.',
    'wf.finish_date_invalid' => 'The finish date is invalid or later than today.',
    'wf.finish_before_start' => 'The finish date cannot be before the start date.',
    'wf.loop_target_missing' => 'The process to return to was not found.',
    'wf.gate_choose_parts' => 'Gate failed: choose the parts to repeat and the process to reopen.',
    'wf.not_skippable' => 'This process is mandatory and cannot be skipped.',
    'wf.skip_only_not_started' => 'Process ":process" has already started and cannot be skipped.',
    'wf.not_skipped' => 'This process is not skipped.',
    'wf.unskip_admin_only' => 'Later processes have already started. Only an Administrator can run this process again.',
    'wf.duration_invalid' => 'Duration must be 1–365 working days.',
    'wf.plan_only_not_started' => 'Duration and manual dates can only be changed for processes that have not started.',
    'wf.plan_closed' => 'Completed or skipped processes cannot be re-planned.',
    'wf.pic_role_mismatch' => 'The PIC must be an active user with the role required by the process.',
    'wf.skip_no_masterbatch' => 'No new masterbatch needed (NPD feedback).',

    // revision history
    'wf.rev.repeat' => ':process repeated (result: :outcome).',
    'wf.rev.loop' => ':process — :outcome: back to :target.',
    'wf.rev.activate' => ':process — :outcome: :activate activated, :reopen reopened.',
    'wf.rev.gate_fail' => ':gate failed: :count parts repeated.',
    'wf.rev.skip' => 'Skipped: :list.',
    'wf.rev.unskip' => 'Run again: :list.',
    'wf.rev.manual' => 'Manual correction of :process: :from → :to.',

    // dependencies
    'dep.cycle' => 'Dependency rejected: it creates a cycle (A → B → A).',
    'dep.self' => 'A process cannot depend on itself.',
    'dep.cross_part' => 'Cross-part dependencies are not allowed (except gates/project-level processes).',
    'dep.invalid_type' => 'Invalid dependency type.',
    'dep.lag_invalid' => 'Lag must be between -30 and 90 working days.',
    'dep.started' => 'The process has already started/finished; its dependencies cannot be changed.',
    'dep.exists' => 'That dependency already exists.',
    'dep.rev.changed' => 'Dependencies of :process changed.',
    'dep.not_found' => 'Dependency not found.',

    // scheduling
    'sched.baseline_v1' => 'Baseline v1 — initial schedule when the part started.',
    'sched.rev.baseline' => 'Baseline v:version set.',
    'sched.rev.target' => 'Target Finish changed: :old → :new.',
    'sched.warning.manual_before_dependency' => 'The manual date is earlier than the predecessor finish — the dependency wins.',
];
