<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
require_once $root . '/后端代码/app/services/report/StoreReportPartnerCategorySnapshotServices.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\report\StoreReportPartnerCategorySnapshotServices;

$reflection = new ReflectionClass(CashierV3CheckoutFactPlanV1::class);
$plan = $reflection->newInstanceWithoutConstructor();
$rows = $reflection->getProperty('rows');
if (PHP_VERSION_ID < 80100) {
    $rows->setAccessible(true);
}
$rows->setValue($plan, [
    'sale' => [
        ['fact_id' => 'sale-a', 'sale_amount_cents' => 1],
        ['fact_id' => 'sale-b', 'sale_amount_cents' => 2],
    ],
    'payment' => [
        ['amount_cents' => 2],
    ],
]);

$allocated = StoreReportPartnerCategorySnapshotServices::cashPerformanceBySaleFact($plan);
if ($allocated !== ['sale-a' => 1, 'sale-b' => 1] || array_sum($allocated) !== 2) {
    throw new RuntimeException('cash performance allocation must conserve cents with deterministic remainder order');
}

$shareMethod = new ReflectionMethod(StoreReportPartnerCategorySnapshotServices::class, 'shareCents');
if (PHP_VERSION_ID < 80100) {
    $shareMethod->setAccessible(true);
}
$share = $shareMethod->invoke(new StoreReportPartnerCategorySnapshotServices(), 123, 33);
if ($share !== 41) {
    throw new RuntimeException('partner share cents must use half-up integer rounding');
}

$source = file_get_contents($root . '/后端代码/app/services/report/StoreReportPartnerCategorySnapshotServices.php');
foreach ([
    'two_level' => '$levelTwo = $chain[1] ?? null',
    'child_suppresses_parent' => '!$this->hasEnabledSecondLevel',
    'ratio_snapshot' => 'partner_default_ratio_snapshot',
    'ratio_rounding' => "bcadd(bcmul((string)\$cashPerformanceAmountCents, (string)\$ratio, 0), '50', 0)",
] as $name => $needle) {
    if (strpos($source, $needle) === false) {
        throw new RuntimeException("partner share snapshot contract missing {$name}");
    }
}

foreach ([
    '/后端代码/app/services/report/StoreOperationsReportDimensionServices.php',
    '/后端代码/app/services/report/CardSaleCategoryAllocationFactServices.php',
] as $file) {
    $content = file_get_contents($root . $file);
    foreach (['cash_performance_amount_cents', 'partner_category_id_snapshot', 'partner_share_amount_cents'] as $field) {
        if (strpos($content, $field) === false) {
            throw new RuntimeException("{$file} does not persist {$field}");
        }
    }
}

$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-08-15-收银V3合作方分成业绩事实快照/02-正式升级.sql');
foreach (['partner_category_id_snapshot', 'partner_config_version_snapshot', 'partner_share_amount_cents', 'idx_scope_partner_category'] as $field) {
    if (strpos($migration, $field) === false) {
        throw new RuntimeException("partner share migration missing {$field}");
    }
}

echo "PASS partner share fact snapshot contract\n";
