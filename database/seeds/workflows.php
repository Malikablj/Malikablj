<?php
declare(strict_types=1);

/**
 * Template workflow bawaan (PRD §5.1, §5.2) — versi 1.
 * Durasi = hari kerja. Semua nilai hanyalah bawaan; Admin dapat mengubah di Pengaturan Workflow.
 *
 * decision effects:
 *   continue : lanjut sesuai dependency
 *   loop     : kembali ke proses pada loop_to[0] (pilihan lain di loop_to[1..]); turunan direset
 *   repeat   : mengulang proses ini (status Problem / Revision), iterasi +1
 *   activate : mengaktifkan proses loop_only (activate) lalu membuka kembali reopen
 *   gate_fail: gate gagal; NPD memilih part yang diulang
 */
$approved = ['code' => 'approved', 'label_id' => 'Approved', 'label_en' => 'Approved', 'effect' => 'continue', 'approval_status' => 'approved'];
$notApproved = static fn (array $loopTo): array => [
    'code' => 'not_approved', 'label_id' => 'Not Approved', 'label_en' => 'Not Approved',
    'effect' => 'loop', 'loop_to' => $loopTo, 'comment_required' => true, 'approval_status' => 'rejected',
];
$notApprovedRepeat = ['code' => 'not_approved', 'label_id' => 'Not Approved', 'label_en' => 'Not Approved',
    'effect' => 'repeat', 'repeat_status' => 'revision', 'comment_required' => true, 'approval_status' => 'rejected'];
$validation = [
    ['code' => 'pass', 'label_id' => 'PASS', 'label_en' => 'PASS', 'effect' => 'continue', 'approval_status' => 'approved'],
    ['code' => 'pass_with_condition', 'label_id' => 'PASS WITH CONDITION', 'label_en' => 'PASS WITH CONDITION', 'effect' => 'continue', 'comment_required' => true, 'approval_status' => 'approved'],
    ['code' => 'fail', 'label_id' => 'FAIL', 'label_en' => 'FAIL', 'effect' => 'repeat', 'repeat_status' => 'problem', 'comment_required' => true, 'approval_status' => 'rejected'],
];

return [
    'project' => [
        'name' => 'Proses Level Project',
        'scope' => 'project',
        'part_type' => null,
        'steps' => [
            ['code' => 'P1', 'name' => 'Project Request (NPR)', 'name_en' => 'Project Request (NPR)', 'short_name' => 'NPR',
             'step_type' => 'request', 'pic_role' => 'admin_sales', 'duration' => 2, 'suggested_docs' => ['npr', 'customer_document'],
             'description' => 'Selesai saat NPR dikirim.', 'deps' => []],
            ['code' => 'P2', 'name' => 'NPD Feedback', 'name_en' => 'NPD Feedback', 'short_name' => 'Feedback',
             'step_type' => 'feedback', 'pic_role' => 'npd_staff', 'duration' => 3, 'approval_type' => 'npr', 'approval_giver' => 'internal',
             'description' => 'Selesai saat feedback seluruh part diputuskan. Part yang diterima memulai workflow-nya.',
             'deps' => [['P1', 'FS', 0]]],
            ['code' => 'G1', 'name' => 'Assembly / Fit Test', 'name_en' => 'Assembly / Fit Test', 'short_name' => 'Fit Test',
             'step_type' => 'gate', 'pic_role' => 'npd_staff', 'duration' => 2, 'is_skippable' => 1, 'is_mandatory' => 0,
             'calendar_category' => 'trial',
             'description' => 'Gate opsional: memeriksa kecocokan antar part setelah milestone trial seluruh part aktif.',
             'decisions' => [
                 ['code' => 'pass', 'label_id' => 'Pass', 'label_en' => 'Pass', 'effect' => 'continue'],
                 ['code' => 'fail', 'label_id' => 'Fail', 'label_en' => 'Fail', 'effect' => 'gate_fail', 'comment_required' => true],
             ],
             'deps' => []], // predecessor dinamis: milestone tiap part yang tidak dibatalkan
            ['code' => 'PF', 'name' => 'Project Finish', 'name_en' => 'Project Finish', 'short_name' => 'Finish',
             'step_type' => 'finish', 'pic_role' => 'npd_staff', 'duration' => 1,
             'description' => 'Tersedia bila semua part Completed atau Cancelled.',
             'deps' => []], // predecessor dinamis: Finish tiap part + gate
        ],
    ],

    'new_mold' => [
        'name' => 'New Mold',
        'scope' => 'part',
        'part_type' => 'new_mold',
        'steps' => [
            ['code' => 'N1', 'name' => 'Masterbatch Development', 'name_en' => 'Masterbatch Development', 'short_name' => 'Masterbatch',
             'pic_role' => 'npd_staff', 'duration' => 7, 'is_skippable' => 1, 'skip_group' => 'MB',
             'description' => 'Boleh dilewati. Paralel dengan 3D Prototype.', 'deps' => [['P2', 'FS', 0]]],
            ['code' => 'N2', 'name' => 'Customer Masterbatch Approval', 'name_en' => 'Customer Masterbatch Approval', 'short_name' => 'MB Approval',
             'step_type' => 'approval', 'pic_role' => 'admin_sales', 'duration' => 4, 'is_skippable' => 1, 'skip_group' => 'MB',
             'is_customer_approval' => 1, 'approval_type' => 'masterbatch', 'approval_giver' => 'customer', 'calendar_category' => 'approval',
             'decisions' => [$approved, $notApproved(['N1'])], 'deps' => [['N1', 'FS', 0]]],
            ['code' => 'N3', 'name' => '3D Prototype Development', 'name_en' => '3D Prototype Development', 'short_name' => '3D Prototype',
             'pic_role' => 'drafter', 'duration' => 6, 'is_skippable' => 1, 'skip_group' => '3D',
             'suggested_docs' => ['prototype_3d_document'], 'deps' => [['P2', 'FS', 0]]],
            ['code' => 'N4', 'name' => 'Customer 3D Approval', 'name_en' => 'Customer 3D Approval', 'short_name' => '3D Approval',
             'step_type' => 'approval', 'pic_role' => 'admin_sales', 'duration' => 4, 'is_skippable' => 1, 'skip_group' => '3D',
             'is_customer_approval' => 1, 'approval_type' => '3d', 'approval_giver' => 'customer', 'calendar_category' => 'approval',
             'decisions' => [$approved, $notApproved(['N3'])], 'deps' => [['N3', 'FS', 0]]],
            ['code' => 'N5', 'name' => '2D Drawing', 'name_en' => '2D Drawing', 'short_name' => '2D Drawing',
             'pic_role' => 'drafter', 'duration' => 4, 'required_docs' => ['layout_decoration'],
             'deps' => [['N2', 'FS', 0], ['N4', 'FS', 0]]],
            ['code' => 'N6', 'name' => 'Mold Drawing Approval', 'name_en' => 'Mold Drawing Approval', 'short_name' => 'Mold Drawing',
             'step_type' => 'approval', 'pic_role' => 'npd_staff', 'duration' => 4, 'is_customer_approval' => 1,
             'approval_type' => 'mold_drawing', 'approval_giver' => 'customer', 'required_docs' => ['technical_drawing'],
             'calendar_category' => 'approval', 'decisions' => [$approved, $notApprovedRepeat], 'deps' => [['N5', 'FS', 0]]],
            ['code' => 'N7', 'name' => 'Mold Machining', 'name_en' => 'Mold Machining', 'short_name' => 'Machining',
             'pic_role' => 'npd_staff', 'duration' => 30, 'is_external' => 1,
             'description' => 'Waiting External (mold maker).', 'deps' => [['N6', 'FS', 0]]],
            ['code' => 'N8', 'name' => 'T0 Trial & Evaluation', 'name_en' => 'T0 Trial & Evaluation', 'short_name' => 'T0 Trial',
             'step_type' => 'decision', 'pic_role' => 'npd_staff', 'duration' => 3, 'approval_type' => 't0', 'approval_giver' => 'internal',
             'record_type' => 't0', 'suggested_docs' => ['trial_report', 'trial_photo'], 'calendar_category' => 'trial', 'is_gate_milestone' => 1,
             'decisions' => [
                 ['code' => 't0_ok', 'label_id' => 'T0 OK', 'label_en' => 'T0 OK', 'effect' => 'continue', 'approval_status' => 'approved'],
                 ['code' => 't0_not_ok', 'label_id' => 'T0 Not OK', 'label_en' => 'T0 Not OK', 'effect' => 'activate', 'activate' => 'N9', 'reopen' => 'N7', 'approval_status' => 'rejected'],
             ],
             'deps' => [['N7', 'FS', 0]]],
            ['code' => 'N9', 'name' => 'Mold Correction', 'name_en' => 'Mold Correction', 'short_name' => 'Correction',
             'pic_role' => 'npd_staff', 'duration' => 10, 'is_external' => 1, 'activation' => 'loop_only', 'is_mandatory' => 0,
             'on_complete_reopen' => 'N7',
             'description' => 'Hanya dipakai bila T0 Not OK; setelah selesai kembali ke Mold Machining.', 'deps' => [['N8', 'FS', 0]]],
            ['code' => 'N10', 'name' => 'Mold Shipment', 'name_en' => 'Mold Shipment', 'short_name' => 'Shipment',
             'pic_role' => 'npd_staff', 'duration' => 5, 'is_external' => 1, 'deps' => [['N8', 'FS', 0]]],
            ['code' => 'N11', 'name' => 'Commissioning Trial', 'name_en' => 'Commissioning Trial', 'short_name' => 'Commissioning',
             'step_type' => 'decision', 'pic_role' => 'npd_staff', 'duration' => 4, 'approval_type' => 'commissioning', 'approval_giver' => 'internal',
             'record_type' => 'commissioning', 'suggested_docs' => ['trial_report'], 'calendar_category' => 'commissioning',
             'decisions' => [
                 ['code' => 'ok', 'label_id' => 'OK', 'label_en' => 'OK', 'effect' => 'continue', 'approval_status' => 'approved'],
                 ['code' => 'ng', 'label_id' => 'NG', 'label_en' => 'NG', 'effect' => 'repeat', 'repeat_status' => 'problem', 'approval_status' => 'rejected'],
             ],
             'deps' => [['N10', 'FS', 0]]],
            ['code' => 'N12', 'name' => 'Material Preparation', 'name_en' => 'Material Preparation', 'short_name' => 'Material Prep',
             'pic_role' => 'npd_staff', 'duration' => 7, 'is_external' => 1, 'record_type' => 'material_preparation',
             'suggested_docs' => ['coa', 'material_spec'], 'calendar_category' => 'material',
             'deps' => [['N11', 'FS', 0], ['G1', 'FS', 0, 1]]],
            ['code' => 'N13', 'name' => 'Validation Mass Production', 'name_en' => 'Validation Mass Production', 'short_name' => 'Validation',
             'step_type' => 'decision', 'pic_role' => 'npd_staff', 'duration' => 5, 'approval_type' => 'validation', 'approval_giver' => 'internal',
             'record_type' => 'validation', 'suggested_docs' => ['validation_report'], 'calendar_category' => 'validation',
             'decisions' => $validation, 'deps' => [['N12', 'FS', 0]]],
            ['code' => 'N14', 'name' => 'Finish (part)', 'name_en' => 'Finish (part)', 'short_name' => 'Finish',
             'step_type' => 'finish', 'pic_role' => 'npd_staff', 'duration' => 1, 'deps' => [['N13', 'FS', 0]]],
        ],
    ],

    'subcont' => [
        'name' => 'Subcont',
        'scope' => 'part',
        'part_type' => 'subcont',
        'steps' => [
            ['code' => 'S1', 'name' => 'Artwork Development', 'name_en' => 'Artwork Development', 'short_name' => 'Artwork',
             'pic_role' => 'drafter', 'duration' => 5, 'suggested_docs' => ['artwork'], 'deps' => [['P2', 'FS', 0]]],
            ['code' => 'S2', 'name' => 'Sales Submit Artwork', 'name_en' => 'Sales Submit Artwork', 'short_name' => 'Submit Artwork',
             'pic_role' => 'admin_sales', 'duration' => 1, 'deps' => [['S1', 'FS', 0]]],
            ['code' => 'S3', 'name' => 'Customer Artwork Approval', 'name_en' => 'Customer Artwork Approval', 'short_name' => 'Artwork Approval',
             'step_type' => 'approval', 'pic_role' => 'admin_sales', 'duration' => 5, 'is_customer_approval' => 1,
             'approval_type' => 'artwork', 'approval_giver' => 'customer', 'calendar_category' => 'approval',
             'decisions' => [$approved, $notApproved(['S1'])], 'deps' => [['S2', 'FS', 0]]],
            ['code' => 'S4', 'name' => 'Trial Material Preparation', 'name_en' => 'Trial Material Preparation', 'short_name' => 'Trial Material',
             'pic_role' => 'npd_staff', 'duration' => 5, 'record_type' => 'material_trial',
             'suggested_docs' => ['material_spec', 'material_request'], 'calendar_category' => 'material',
             'description' => 'Admin dapat mengubah menjadi SS agar paralel dengan approval.', 'deps' => [['S3', 'FS', 0]]],
            ['code' => 'S5', 'name' => 'Trial & Evaluation', 'name_en' => 'Trial & Evaluation', 'short_name' => 'Trial',
             'pic_role' => 'npd_staff', 'duration' => 4, 'record_type' => 'trial', 'suggested_docs' => ['trial_report', 'trial_photo'],
             'calendar_category' => 'trial', 'deps' => [['S4', 'FS', 0]]],
            ['code' => 'S6', 'name' => 'Sales Submit Trial Result', 'name_en' => 'Sales Submit Trial Result', 'short_name' => 'Submit Trial',
             'pic_role' => 'admin_sales', 'duration' => 1, 'deps' => [['S5', 'FS', 0]]],
            ['code' => 'S7', 'name' => 'Customer Trial Approval', 'name_en' => 'Customer Trial Approval', 'short_name' => 'Trial Approval',
             'step_type' => 'approval', 'pic_role' => 'admin_sales', 'duration' => 5, 'is_customer_approval' => 1,
             'approval_type' => 'trial', 'approval_giver' => 'customer', 'calendar_category' => 'approval', 'is_gate_milestone' => 1,
             'decisions' => [$approved, $notApproved(['S5', 'S4'])], 'deps' => [['S6', 'FS', 0]]],
            ['code' => 'S8', 'name' => 'Bulk Material Request', 'name_en' => 'Bulk Material Request', 'short_name' => 'Bulk Material',
             'pic_role' => 'npd_staff', 'duration' => 3, 'record_type' => 'material_bulk', 'suggested_docs' => ['material_request'],
             'deps' => [['S7', 'FS', 0]]],
            ['code' => 'S9', 'name' => 'Material Preparation', 'name_en' => 'Material Preparation', 'short_name' => 'Material Prep',
             'pic_role' => 'npd_staff', 'duration' => 7, 'is_external' => 1, 'record_type' => 'material_preparation',
             'calendar_category' => 'material', 'deps' => [['S8', 'FS', 0], ['G1', 'FS', 0, 1]]],
            ['code' => 'S10', 'name' => 'Validation Mass Production', 'name_en' => 'Validation Mass Production', 'short_name' => 'Validation',
             'step_type' => 'decision', 'pic_role' => 'npd_staff', 'duration' => 5, 'approval_type' => 'validation', 'approval_giver' => 'internal',
             'record_type' => 'validation', 'suggested_docs' => ['validation_report'], 'calendar_category' => 'validation',
             'decisions' => $validation, 'deps' => [['S9', 'FS', 0]]],
            ['code' => 'S11', 'name' => 'Finish (part)', 'name_en' => 'Finish (part)', 'short_name' => 'Finish',
             'step_type' => 'finish', 'pic_role' => 'npd_staff', 'duration' => 1, 'deps' => [['S10', 'FS', 0]]],
        ],
    ],
];
