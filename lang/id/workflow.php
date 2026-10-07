<?php
declare(strict_types=1);

/** Workflow, dependency, penjadwalan. */
return [
    // status
    'status.not_started' => 'Belum Mulai',
    'status.current' => 'Current',
    'status.completed' => 'Selesai',
    'status.revision' => 'Revisi',
    'status.problem' => 'Problem',
    'status.skipped' => 'Tidak dijalankan',
    'status.on_progress' => 'On Progress',
    'status.waiting' => 'Waiting',
    'status.waiting_approval' => 'Waiting Approval',
    'status.waiting_external' => 'Waiting External',
    'status.hold' => 'Hold',
    'status.ready_to_finish' => 'Siap Finish',
    'status.cancelled' => 'Dibatalkan',
    'status.overdue' => 'Overdue',
    'status.due_soon' => 'Due Soon',

    // validasi & aturan mesin workflow
    'wf.cannot_start' => 'Proses ini tidak dapat dimulai sekarang.',
    'wf.part_not_started' => 'Part belum dimulai (menunggu feedback NPR selesai).',
    'wf.deps_not_met' => 'Proses sebelumnya belum selesai — dependency belum terpenuhi.',
    'wf.complete_via_npr' => 'Proses ini diselesaikan melalui alur NPR (kirim / feedback).',
    'wf.not_active' => 'Hanya proses yang sedang aktif yang dapat diselesaikan.',
    'wf.on_hold' => 'Project/part sedang Hold. Lanjutkan (Resume) terlebih dahulu.',
    'wf.ff_blocked' => 'Belum dapat diselesaikan: menunggu proses berikut selesai (Finish-to-Finish): :list',
    'wf.missing_docs' => 'Dokumen wajib belum diunggah: :list',
    'wf.parts_not_finished' => 'Masih ada part yang belum selesai atau dibatalkan.',
    'wf.outcome_required' => 'Pilih hasil/keputusan proses.',
    'wf.comment_required' => 'Catatan wajib diisi untuk keputusan ini.',
    'wf.finish_date_invalid' => 'Tanggal selesai tidak valid atau melewati hari ini.',
    'wf.finish_before_start' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
    'wf.loop_target_missing' => 'Proses tujuan pengulangan tidak ditemukan.',
    'wf.gate_choose_parts' => 'Gate gagal: pilih part yang harus diulang dan proses yang dibuka kembali.',
    'wf.not_skippable' => 'Proses ini wajib dan tidak dapat ditandai "Tidak dijalankan".',
    'wf.skip_only_not_started' => 'Proses ":process" sudah dimulai sehingga tidak dapat dilewati.',
    'wf.not_skipped' => 'Proses ini tidak berstatus "Tidak dijalankan".',
    'wf.unskip_admin_only' => 'Proses sesudahnya sudah berjalan. Hanya Administrator yang dapat menjalankan kembali proses ini.',
    'wf.duration_invalid' => 'Durasi harus 1–365 hari kerja.',
    'wf.plan_only_not_started' => 'Durasi dan tanggal manual hanya dapat diubah untuk proses yang belum dimulai.',
    'wf.plan_closed' => 'Proses selesai atau tidak dijalankan tidak dapat direncanakan ulang.',
    'wf.pic_role_mismatch' => 'PIC harus pengguna aktif dengan peran yang sesuai proses.',
    'wf.skip_no_masterbatch' => 'Tidak perlu masterbatch baru (feedback NPD).',

    // riwayat revisi
    'wf.rev.repeat' => ':process diulang (hasil: :outcome).',
    'wf.rev.loop' => ':process — :outcome: kembali ke :target.',
    'wf.rev.activate' => ':process — :outcome: :activate diaktifkan, :reopen dibuka kembali.',
    'wf.rev.gate_fail' => ':gate gagal: :count part diulang.',
    'wf.rev.skip' => 'Tidak dijalankan: :list.',
    'wf.rev.unskip' => 'Dijalankan kembali: :list.',
    'wf.rev.manual' => 'Koreksi manual :process: :from → :to.',

    // dependency
    'dep.cycle' => 'Dependency ditolak: membentuk lingkaran (A → B → A).',
    'dep.self' => 'Proses tidak dapat bergantung pada dirinya sendiri.',
    'dep.cross_part' => 'Dependency lintas part tidak diizinkan (kecuali gate/proses level project).',
    'dep.invalid_type' => 'Jenis dependency tidak valid.',
    'dep.lag_invalid' => 'Lag harus antara -30 dan 90 hari kerja.',
    'dep.started' => 'Proses sudah dimulai/selesai; dependency tidak dapat diubah.',
    'dep.exists' => 'Dependency tersebut sudah ada.',
    'dep.rev.changed' => 'Dependency :process diubah.',
    'dep.not_found' => 'Dependency tidak ditemukan.',

    // penjadwalan
    'sched.baseline_v1' => 'Baseline v1 — jadwal awal saat part dimulai.',
    'sched.rev.baseline' => 'Baseline v:version ditetapkan.',
    'sched.rev.target' => 'Target Finish diubah: :old → :new.',
    'sched.warning.manual_before_dependency' => 'Tanggal manual lebih awal dari selesainya proses sebelumnya — mengikuti dependency.',
];
