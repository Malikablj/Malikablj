<?php
declare(strict_types=1);

/**
 * Matriks hak akses PRD §2.3 sebagai data.
 *
 * 'roles' => [role_code => scope]
 *   scope 'all' : boleh pada seluruh data
 *   scope 'own' : hanya data miliknya — dievaluasi App\Core\Gate:
 *       admin_sales → project/NPR di mana ia Sales PIC atau pembuat
 *       npd_staff   → NPR draft yang ia buat (lihat OPEN_QUESTIONS OQ-02)
 *       drafter / purchasing / production / quality → proses di mana ia PIC
 * Management tidak memiliki satu pun izin pengubahan (read-only).
 */
$all = ['admin' => 'all', 'admin_sales' => 'all', 'npd_staff' => 'all', 'drafter' => 'all',
        'purchasing' => 'all', 'production' => 'all', 'quality' => 'all', 'management' => 'all'];
$adminNpd = ['admin' => 'all', 'npd_staff' => 'all'];

return [
    // --- Melihat & export (semua role) ---
    'project.view'          => ['project',  'Melihat project, timeline, dokumen', $all],
    'document.view'         => ['document', 'Melihat & mengunduh dokumen', $all],
    'report.view'           => ['report',   'Melihat dashboard, Weekly Report, Analytics', $all],
    'export.npr_pdf'        => ['export',   'Export NPR PDF', $all],
    'export.timeline'       => ['export',   'Export timeline PDF/Excel', $all],
    'export.report'         => ['export',   'Export laporan (Weekly/Analytics/daftar project)', $all],

    // --- NPR ---
    'npr.create'            => ['npr', 'Membuat NPR', ['admin' => 'all', 'admin_sales' => 'all', 'npd_staff' => 'all']],
    'npr.edit_sales_fields' => ['npr', 'Mengisi kolom biru NPR (Sales)', ['admin' => 'all', 'admin_sales' => 'own', 'npd_staff' => 'own']],
    'npr.submit'            => ['npr', 'Mengirim NPR', ['admin' => 'all', 'admin_sales' => 'own', 'npd_staff' => 'own']],
    'npr.edit_npd_fields'   => ['npr', 'Mengisi kolom pink NPR (berat, feedback, mould)', $adminNpd],
    'npr.return'            => ['npr', 'Mengembalikan NPR ke Sales', $adminNpd],
    'npr.complete_feedback' => ['npr', 'Menyelesaikan feedback NPR', $adminNpd],

    // --- Eksekusi proses & record ---
    'process.execute'       => ['workflow', 'Mengerjakan proses sesuai PIC/role', ['admin' => 'all', 'npd_staff' => 'all', 'admin_sales' => 'own', 'drafter' => 'own', 'purchasing' => 'own', 'production' => 'own', 'quality' => 'own']],
    'record.material'       => ['record', 'Memperbarui data/permintaan material', ['admin' => 'all', 'npd_staff' => 'all', 'purchasing' => 'all']],
    'record.trial'          => ['record', 'Mengisi catatan trial/T0/commissioning', ['admin' => 'all', 'npd_staff' => 'all', 'production' => 'all', 'quality' => 'all']],
    'record.validation'     => ['record', 'Mengisi catatan validasi', ['admin' => 'all', 'npd_staff' => 'all', 'production' => 'all', 'quality' => 'all']],
    'comment.create'        => ['project', 'Menulis komentar', ['admin' => 'all', 'admin_sales' => 'all', 'npd_staff' => 'all', 'drafter' => 'all', 'purchasing' => 'all', 'production' => 'all', 'quality' => 'all']],
    'document.upload'       => ['document', 'Mengunggah dokumen', ['admin' => 'all', 'npd_staff' => 'all', 'admin_sales' => 'own', 'drafter' => 'own', 'purchasing' => 'own', 'production' => 'own', 'quality' => 'own']],

    // --- Perencanaan & kendali (Admin, NPD) ---
    'schedule.plan'         => ['schedule', 'Merencanakan jadwal dan PIC', $adminNpd],
    'dependency.edit'       => ['workflow', 'Mengubah dependency per project', $adminNpd],
    'process.skip'          => ['workflow', '"Tidak dijalankan" proses', $adminNpd],
    'hold.manage'           => ['project',  'Hold dan Resume project/part', $adminNpd],
    'target.change'         => ['schedule', 'Menyetujui Target Finish baru', $adminNpd],
    'baseline.create'       => ['schedule', 'Menetapkan baseline baru', $adminNpd],
    'project.edit'          => ['project',  'Mengubah data project (prioritas, PIC, next action)', ['admin' => 'all', 'npd_staff' => 'all', 'admin_sales' => 'own']],
    'project.finish'        => ['project',  'Finish project', $adminNpd],
    'project.cancel'        => ['project',  'Cancel part/project', $adminNpd],
    'approval.record_customer' => ['approval', 'Mencatat approval customer', ['admin' => 'all', 'npd_staff' => 'all', 'admin_sales' => 'own']],
    'approval.decide_internal' => ['approval', 'Memutuskan approval internal', $adminNpd],
    'calendar.manage'       => ['calendar', 'Membuat agenda meeting/follow-up', ['admin' => 'all', 'npd_staff' => 'all', 'admin_sales' => 'all']],

    // --- Khusus Admin ---
    'process.manual_move'   => ['workflow', 'Memindahkan proses aktif manual (koreksi)', ['admin' => 'all']],
    'project.archive'       => ['project',  'Mengarsipkan/memulihkan project', ['admin' => 'all']],
    'project.reopen_cancelled' => ['project', 'Membuka kembali part/project Cancelled', ['admin' => 'all']],
    'process.unskip_any'    => ['workflow', 'Membatalkan "Tidak dijalankan" setelah proses sesudahnya mulai', ['admin' => 'all']],
    'settings.manage'       => ['settings', 'Pengaturan (master, workflow, libur, notifikasi)', ['admin' => 'all']],
    'user.manage'           => ['settings', 'Manajemen user', ['admin' => 'all']],
    'customer.manage'       => ['settings', 'Manajemen customer', ['admin' => 'all']],
    'audit.view_full'       => ['settings', 'Melihat audit log sistem penuh', ['admin' => 'all']],
    'project.import'        => ['settings', 'Impor data project lama dari Excel', ['admin' => 'all']],

    // --- KPI per PIC: Admin & Management saja (FR-RPT-04) ---
    'kpi.view'              => ['report', 'Melihat KPI per PIC', ['admin' => 'all', 'management' => 'all']],
];
