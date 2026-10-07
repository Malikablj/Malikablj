<?php
declare(strict_types=1);

/** Pengaturan bawaan (Admin dapat mengubah di Pengaturan). key => [value, type, description] */
return [
    'company.name'                       => ['PT. Permata Indo Kemas', 'string', 'Nama perusahaan (kop dokumen)'],
    'company.department'                 => ['New Product and Development Dept', 'string', 'Nama departemen (kop dokumen)'],
    'security.session_timeout_minutes'   => ['480', 'int', 'Sesi berakhir setelah tidak aktif (menit). Bawaan 8 jam'],
    'security.login_max_attempts'        => ['5', 'int', 'Jumlah gagal login sebelum dikunci sementara'],
    'security.login_lockout_minutes'     => ['15', 'int', 'Lama penguncian login (menit)'],
    'security.password_min_length'       => ['8', 'int', 'Panjang minimal password'],
    'schedule.pull_forward_on_early_finish' => ['1', 'bool', 'Tarik maju jadwal bila proses selesai lebih awal'],
    'notify.due_soon_days'               => ['3', 'int', 'Ambang Due Soon (hari kerja, 1-30)'],
    'notify.no_update_days'              => ['7', 'int', 'Ambang No Update (hari, 1-30)'],
    'hold.reminder_days'                 => ['30', 'int', 'Pengingat Hold pertama (hari)'],
    'hold.reminder_repeat_days'          => ['7', 'int', 'Pengingat Hold berulang (hari)'],
    'upload.max_mb'                      => ['25', 'int', 'Ukuran maksimal file unggahan (MB)'],
    'upload.allowed_extensions'          => ['pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip,rar,7z,mp4,mov,dwg,dxf,step,stp,igs,iges,stl,x_t', 'string', 'Ekstensi file yang diizinkan (dipisah koma)'],
    'gate.default_name'                  => ['Assembly / Fit Test', 'string', 'Nama gate level project'],
    'mail.enabled'                       => ['0', 'bool', 'Kirim email notifikasi'],
    'mail.smtp_host'                     => ['', 'string', 'SMTP host'],
    'mail.smtp_port'                     => ['587', 'int', 'SMTP port'],
    'mail.smtp_encryption'               => ['tls', 'string', 'tls / ssl / none'],
    'mail.smtp_username'                 => ['', 'string', 'SMTP username'],
    'mail.smtp_password'                 => ['', 'secret', 'SMTP password (terenkripsi)'],
    'mail.from_address'                  => ['', 'string', 'Alamat pengirim'],
    'mail.from_name'                     => ['NPD Project Control', 'string', 'Nama pengirim'],
    'mail.types_enabled'                 => ['{"project_assigned":true,"project_overdue":true,"npr_submitted":true,"npr_returned":true,"hold_reminder":true}', 'json', 'Jenis email yang aktif (Lampiran C, bertanda Email = Ya)'],
];
