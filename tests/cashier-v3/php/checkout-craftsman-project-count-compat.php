<?php

// 模拟浏览器在“游客 + 朋友算 + 单行确认手艺人”路径冻结的人员字段。
// 项目数属于员工服务工资口径，旧半单位和新十进制快照须分别可结账。
$root = dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3';
require_once $root . '/CashierV3PersonnelIdentity.php';
require_once $root . '/settlement/CashierV3CheckoutCraftsmenSnapshot.php';

use app\services\cashier\v3\settlement\CashierV3CheckoutCraftsmenSnapshot;

$base = [
    'id' => 17,
    'staffId' => 17,
    'employeeId' => 19,
    'storeId' => 7,
    'name' => '测试手艺人',
    'isPrimary' => true,
    'sequence' => 1,
    'laborWeight' => 100,
    'performanceAmountCents' => 0,
    'performanceAmountManual' => false,
    'isPointCustomer' => false,
    'craftsmanPerformanceType' => 'commission_labor',
    'laborFeeCents' => 0,
    'positionId' => 2,
    'positionName' => '美容师',
    'performanceIndependent' => false,
    'allocationGroupKey' => 'normal',
];

foreach (['0.3', '6.6'] as $projectCount) {
    $normalized = CashierV3CheckoutCraftsmenSnapshot::normalize([
        $base + ['projectCount' => $projectCount],
    ]);
    if (($normalized[0]['projectCount'] ?? null) !== $projectCount) {
        throw new RuntimeException('decimal_project_count_lost:' . $projectCount);
    }
}

$legacy = CashierV3CheckoutCraftsmenSnapshot::normalize([
    $base + ['projectCountHalfUnits' => 1],
]);
if (($legacy[0]['projectCountHalfUnits'] ?? null) !== 1) {
    throw new RuntimeException('legacy_half_unit_lost');
}

echo "CHECKOUT_CRAFTSMAN_PROJECT_COUNT_COMPAT=PASS\n";
