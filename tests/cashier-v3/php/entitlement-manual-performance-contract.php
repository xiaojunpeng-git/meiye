<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
require_once $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php';
require_once $root . '/后端代码/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php';
require_once $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\cashier\v3\checkout\persistence\CashierV3EntitlementCompletionPlanV1;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementKernel;

function entitlementManualPerformanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$normalizer = new ReflectionMethod(CashierV3CheckoutSettlementKernel::class, 'normalizeEntitlementCraftsmen');
if (PHP_VERSION_ID < 80100) {
    $normalizer->setAccessible(true);
}
$normalized = $normalizer->invoke(null, [[
    'staffId' => 101,
    'laborWeight' => 100,
    'craftsmanPerformanceType' => 'commission_labor',
    'laborFeeCents' => 500,
    'performanceAmountCents' => 55500,
    'performanceAmountManual' => true,
    'projectCount' => '0.3',
]], 'entitlementLines[0].craftsmen');
entitlementManualPerformanceAssert(
    ($normalized[0]['performanceAmountCents'] ?? null) === 55500
        && ($normalized[0]['performanceAmountManual'] ?? null) === true
        && ($normalized[0]['laborFeeCents'] ?? null) === 500
        && ($normalized[0]['projectCount'] ?? null) === '0.3',
    '权益手填业绩、手工费和项目数必须同时进入结账快照'
);

$craftsmanPlan = new ReflectionMethod(CashierV3EntitlementCompletionKernel::class, 'craftsmanPlan');
if (PHP_VERSION_ID < 80100) {
    $craftsmanPlan->setAccessible(true);
}
$allocations = $craftsmanPlan->invoke(null, [101], [[
    'staffId' => 101,
    'staffVersion' => 1,
    'staffName' => '测试手艺人',
    'storeId' => 7,
    'active' => true,
    'craftsmanEligible' => true,
    'laborWeight' => 100,
    'craftsmanPerformanceType' => 'commission_labor',
    'laborFeeCents' => 500,
    'performanceAmountCents' => 55500,
    'performanceAmountManual' => true,
    'projectCount' => '0.3',
]], 7, 33300);
entitlementManualPerformanceAssert(
    ($allocations[0]['amountCents'] ?? null) === 55500
        && ($allocations[0]['laborFeeCents'] ?? null) === 500
        && ($allocations[0]['projectCount'] ?? null) === '0.3',
    '权益完成计划必须保留手填业绩、手工费和项目数'
);

$allocationNormalizer = new ReflectionMethod(
    CashierV3EntitlementCompletionPlanV1::class,
    'normalizeAllocations'
);
if (PHP_VERSION_ID < 80100) {
    $allocationNormalizer->setAccessible(true);
}
$persistedAllocations = $allocationNormalizer->invoke(null, [[
    'staffId' => 101,
    'isPrimary' => true,
    'sequence' => 1,
    'amountCents' => 55500,
    'staffVersion' => 1,
    'staffName' => '测试手艺人',
    'storeId' => 7,
    'laborWeight' => 100,
    'craftsmanPerformanceType' => 'commission_labor',
    'laborFeeCents' => 500,
    'projectCount' => '0.3',
]], 55500, [[
    'staffId' => 101,
    'staffVersion' => 1,
    'staffName' => '测试手艺人',
]], [101 => [
    'employee_id' => 201,
    'staff_name' => '测试手艺人',
    'staff_version' => 1,
    'employee_type' => 'internal',
    'employee_type_version' => 1,
]], 7, 'line-project-count');
entitlementManualPerformanceAssert(
    ($persistedAllocations[0]['project_count_decimal'] ?? null) === '0.3',
    '权益持久化计划必须把手填项目数写入员工业绩事实'
);

$factNormalizer = new ReflectionMethod(CashierV3CheckoutFactPlanV1::class, 'normalizeSpecific');
if (PHP_VERSION_ID < 80100) {
    $factNormalizer->setAccessible(true);
}
$genericFact = $factNormalizer->invoke(null, 'performance', [
    'performanceType' => CashierV3CheckoutFactPlanV1::LABOR_PERFORMANCE,
    'employeeId' => 201,
    'employeeNameSnapshot' => '测试手艺人',
    'employeeTypeSnapshot' => 'internal',
    'employeeTypeAuthorityVersion' => 1,
    'roleSnapshot' => 'craftsman',
    'allocationWeightNumerator' => 100,
    'allocationWeightDenominator' => 100,
    'allocationBaseAmountCents' => 55500,
    'amountCents' => 55500,
    'laborFeeAmountCents' => 500,
    'projectCount' => '0.3',
    'ruleCodeSnapshot' => 'TEST-PROJECT-COUNT',
    'ruleNameSnapshot' => '测试项目数',
    'ruleVersionSnapshot' => 'v1',
], CashierV3CheckoutFactPlanV1::DIRECTION_FORWARD);
entitlementManualPerformanceAssert(
    ($genericFact['project_count_decimal'] ?? null) === '0.3'
        && ($genericFact['project_count_half_units'] ?? null) === 0,
    '普通项目事实计划必须保持精确项目数，不能截断为半项目单位'
);

$directSettlement = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php'
);
$draftAuthorityRebuilder = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php'
);
$persistence = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/checkout/persistence/CashierV3EntitlementCompletionPlanV1.php'
);
$paidProjectPlanner = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3PaidProjectCraftsmanPerformanceServices.php'
);
$saleFactAssembler = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php'
);
entitlementManualPerformanceAssert(
    substr_count($directSettlement, "'projectCount'") >= 2
        && strpos($draftAuthorityRebuilder, "\$assignment['projectCount'] = self::projectCount(") !== false
        && strpos($persistence, "'project_count_decimal'") !== false
        && strpos($paidProjectPlanner, "'projectCount'") !== false
        && strpos($saleFactAssembler, "'projectCount' => (string)\$allocation['projectCount']") !== false
        && strpos($saleFactAssembler, '] + $projectCountSnapshot + [') !== false
        && strpos($saleFactAssembler, "...(['projectCount'") === false,
    '权益和普通项目的项目数必须跨越权威草稿重建、结算计划和业绩事实持久化链路'
);

$view = (string)file_get_contents(
    $root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'
);
entitlementManualPerformanceAssert(
    strpos($view, 'line.actualAmount ?? line.finalAmount ?? line.amount') !== false
        && strpos($view, 'Math.round(amount * 100)') !== false,
    '完整分配默认基数必须将权益行金额从元转换为分'
);

echo "PASS entitlement-manual-performance-contract\n";
