<?php
declare(strict_types=1);

/** Notifikasi web & email (PRD §7.3, Lampiran C). */
return [
    'email.open' => 'Buka di NPD Project Control',
    'email.footer' => 'Email otomatis dari NPD Project Control — PT. Permata Indo Kemas. Jangan balas email ini.',
    'notif.npr_submitted.title' => 'NPR baru dikirim: :number',
    'notif.npr_submitted.body' => ':sender mengirim NPR :number untuk :product (project :project). Mohon isi feedback NPD per part.',
    'notif.npr_returned.title' => 'NPR dikembalikan: :number',
    'notif.npr_returned.body' => ':by mengembalikan NPR :number (:product). Alasan: :reason',
    'notif.npr_feedback_completed.title' => 'Feedback NPR selesai: :number',
    'notif.npr_feedback_completed.body' => 'Feedback NPD untuk :product (:project) telah selesai: :accepted part diterima, :cancelled part dibatalkan.',
];
