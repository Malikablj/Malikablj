<?php
declare(strict_types=1);

/** Seed user untuk test HTTP (satu per role + satu nonaktif). Password: Passw0rd! */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

use App\Core\Db;

$users = [
    ['admin', 'admin@test.local', 'Admin Test', 1],
    ['admin_sales', 'sales@test.local', 'Sales Test', 1],
    ['admin_sales', 'sales2@test.local', 'Sales Dua', 1],
    ['npd_staff', 'npd@test.local', 'NPD Test', 1],
    ['drafter', 'drafter@test.local', 'Drafter Test', 1],
    ['purchasing', 'purchasing@test.local', 'Purchasing Test', 1],
    ['production', 'production@test.local', 'Production Test', 1],
    ['quality', 'quality@test.local', 'Quality Test', 1],
    ['management', 'mgmt@test.local', 'Management Test', 1],
    ['admin_sales', 'inactive@test.local', 'Inactive Test', 0],
];
$hash = password_hash('Passw0rd!', PASSWORD_BCRYPT, ['cost' => 4]);
foreach ($users as [$role, $email, $name, $active]) {
    Db::insert('users', [
        'role_id' => (int) Db::value('SELECT id FROM roles WHERE code = ?', [$role]),
        'name' => $name,
        'email' => $email,
        'password_hash' => $hash,
        'job_title' => ucfirst($role),
        'is_active' => $active,
        'language' => 'id',
    ]);
}
echo "seeded\n";
