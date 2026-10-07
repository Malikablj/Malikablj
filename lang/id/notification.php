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
    'notif.process_active.title' => 'Proses aktif: :process',
    'notif.process_active.body' => 'Proses :process pada project :project sekarang aktif dan menjadi tanggung jawab Anda. Planned Finish: :finish.',
    'notif.process_no_pic.title' => 'Proses tanpa PIC: :process',
    'notif.process_no_pic.body' => 'Proses :process pada project :project aktif tetapi belum memiliki PIC. Mohon tetapkan PIC.',
    'notif.schedule_shifted.title' => 'Jadwal bergeser: :project',
    'notif.schedule_shifted.body' => 'Jadwal :count proses Anda pada :project bergeser :shift hari kerja (mis. :process mulai :date).',
    'notif.schedule_shifted_npd.title' => 'Jadwal project bergeser: :project',
    'notif.schedule_shifted_npd.body' => ':count proses pada project :project bergeser karena perubahan jadwal.',
    'notif.target_at_risk.title' => 'Berisiko melewati Target: :project',
    'notif.target_at_risk.body' => 'Perkiraan selesai :name (:project) adalah :forecast, melewati Target Finish :target.',
    'notif.approval_approved.title' => 'Approval disetujui: :process',
    'notif.approval_rejected.title' => 'Approval tidak disetujui: :process',
    'notif.approval_decided.body' => 'Keputusan approval :process pada project :project telah dicatat. Catatan: :comment',
];
