<?php
declare(strict_types=1);

$root = dirname(dirname(__DIR__));
$required = [
    '/后端代码/app/services/report/BusinessLedgerServices.php',
    '/后端代码/app/controller/admin/v1/report/UnifiedReport.php',
    '/后端代码/app/controller/api/v1/merchant/EngineeringLedger.php',
    '/后端代码/route/admin.php',
    '/后端代码/route/cashier-v3.php',
    '/后端代码/database/upgrades/2026-08-21-第九阶段业务台账/02-正式升级.sql',
];
foreach ($required as $file) {
    if (!is_file($root . $file)) throw new RuntimeException('missing ' . $file);
}
$service = file_get_contents($root . $required[0]);
foreach (['store_building', 'engineering_quality', 'engineering_repair', 'rent_renewal', 'request_token', 'business_ledger_audit', 'business_ledger_rent_reminder', 'FILTER_DATE_FIELDS'] as $needle) {
    if (strpos($service, $needle) === false) throw new RuntimeException('missing contract ' . $needle);
}
$upgrade = file_get_contents($root . $required[5]);
foreach ([
    "admin-data-engineering-management',0",
    "admin-data-engineering-management-store-building',0",
    "admin-data-engineering-management-engineering-quality',0",
    "admin-data-engineering-management-engineering-repair',0",
    "admin-data-engineering-management-rent-renewal',0",
    '/report/engineering-management',
    '/report/store-building',
    '/report/engineering-quality',
    '/report/engineering-repair',
    '/report/rent-renewal',
    'parent_menu.auth_type=1',
    'child_menu.auth_type=1',
] as $needle) {
    if (strpos($upgrade, $needle) === false) throw new RuntimeException('missing engineering menu contract ' . $needle);
}
if (strpos($upgrade, "SELECT id,2,'','建店明细'") !== false) {
    throw new RuntimeException('engineering ledger forms must be visible menu nodes, not type=2 API nodes');
}
echo "business-ledger backend contract: PASS\n";
