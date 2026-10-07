<?php
declare(strict_types=1);

/**
 * Delapan role tetap (PRD §2.1). Tidak ada role baru; manajer memakai role Management (read-only).
 * Istilah form NPR: "Sales" = Admin Sales, "Admin NPD" = NPD Staff.
 */
return [
    ['code' => 'admin',       'name_id' => 'Admin',        'name_en' => 'Admin',        'is_read_only' => 0, 'sort_order' => 1],
    ['code' => 'admin_sales', 'name_id' => 'Admin Sales',  'name_en' => 'Admin Sales',  'is_read_only' => 0, 'sort_order' => 2],
    ['code' => 'npd_staff',   'name_id' => 'NPD Staff',    'name_en' => 'NPD Staff',    'is_read_only' => 0, 'sort_order' => 3],
    ['code' => 'drafter',     'name_id' => 'Drafter',      'name_en' => 'Drafter',      'is_read_only' => 0, 'sort_order' => 4],
    ['code' => 'purchasing',  'name_id' => 'Purchasing',   'name_en' => 'Purchasing',   'is_read_only' => 0, 'sort_order' => 5],
    ['code' => 'production',  'name_id' => 'Production',   'name_en' => 'Production',   'is_read_only' => 0, 'sort_order' => 6],
    ['code' => 'quality',     'name_id' => 'Quality',      'name_en' => 'Quality',      'is_read_only' => 0, 'sort_order' => 7],
    ['code' => 'management',  'name_id' => 'Management',   'name_en' => 'Management',   'is_read_only' => 1, 'sort_order' => 8],
];
