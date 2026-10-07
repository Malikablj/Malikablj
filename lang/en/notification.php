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
];
