<?php
declare(strict_types=1);

/** Web & email notifications (PRD §7.3, Appendix C). */
return [
    'email.open' => 'Open in NPD Project Control',
    'email.footer' => 'Automatic email from NPD Project Control — PT. Permata Indo Kemas. Please do not reply.',
    'notif.npr_submitted.title' => 'New NPR submitted: :number',
    'notif.npr_submitted.body' => ':sender submitted NPR :number for :product (project :project). Please fill in the NPD feedback per part.',
    'notif.npr_returned.title' => 'NPR returned: :number',
    'notif.npr_returned.body' => ':by returned NPR :number (:product). Reason: :reason',
    'notif.npr_feedback_completed.title' => 'NPR feedback completed: :number',
    'notif.npr_feedback_completed.body' => 'NPD feedback for :product (:project) is complete: :accepted parts accepted, :cancelled parts cancelled.',
    'notif.process_active.title' => 'Process active: :process',
    'notif.process_active.body' => 'Process :process in project :project is now active and assigned to you. Planned Finish: :finish.',
    'notif.process_no_pic.title' => 'Process without PIC: :process',
    'notif.process_no_pic.body' => 'Process :process in project :project is active but has no PIC yet. Please assign one.',
    'notif.schedule_shifted.title' => 'Schedule shifted: :project',
    'notif.schedule_shifted.body' => 'The schedule of :count of your processes in :project shifted by :shift working days (e.g. :process starts :date).',
    'notif.schedule_shifted_npd.title' => 'Project schedule shifted: :project',
    'notif.schedule_shifted_npd.body' => ':count processes in project :project shifted because of a schedule change.',
    'notif.target_at_risk.title' => 'At risk of missing Target: :project',
    'notif.target_at_risk.body' => 'The forecast finish of :name (:project) is :forecast, later than the Target Finish :target.',
    'notif.approval_approved.title' => 'Approval approved: :process',
    'notif.approval_rejected.title' => 'Approval not approved: :process',
    'notif.approval_decided.body' => 'The approval decision for :process in project :project has been recorded. Note: :comment',
];
