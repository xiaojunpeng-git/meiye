<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';

use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;

function performanceAmountAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$normalizer = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/CashierV3RequestNormalizer.php'
);
$assembler = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php'
);
$snapshot = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php'
);
$workspace = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php'
);
$projection = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php'
);
$settlementKernel = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php'
);

performanceAmountAssert(
    strpos($normalizer, "'performanceAmountCents'") !== false
        && strpos($normalizer, "'performanceAmountManual'") !== false,
    '人员金额及手工标记必须进入请求规范化层'
);
performanceAmountAssert(
    strpos($assembler, 'personnelPerformanceAmounts') !== false
        && strpos($assembler, 'SALES-CASH-MANUAL-AMOUNT-V1') !== false,
    '销售人手工金额必须从权威快照直接生成业绩事实'
);
performanceAmountAssert(
    strpos($snapshot, 'craftsmen_snapshot_weight_sum_invalid') === false,
    '手艺人快照不得恢复比例合计拦截'
);
performanceAmountAssert(
    preg_match(
        '/private function authoritativeSalespeopleInTx[\\s\\S]*?\\$requestedPerformanceAmounts = \\[\\];[\\s\\S]*?\\$requestedPerformanceAmountManual = \\[\\];[\\s\\S]*?foreach \\(\\$selections as \\$selection\\)/',
        $workspace
    ) === 1,
    '销售人权威快照必须初始化业绩金额映射后再读取请求金额'
);
performanceAmountAssert(
    preg_match(
        "/\\['performanceAmountCents'\\] = self::nonNegativeInt\\([\\s\\S]*?\\['performanceAmountManual'\\] =/",
        $projection
    ) === 1,
    '最终结账投影不得丢弃销售人手工业绩金额与手工标记'
);
performanceAmountAssert(
    strpos($projection, 'checkout_projection_salespeople_weight_invalid') === false,
    '最终结账不得再以销售人比例合计拦截手工金额结算'
);
performanceAmountAssert(
    preg_match(
        "/\\['performanceAmountCents'\\] = self::nonNegativeInt\\([\\s\\S]*?\\['performanceAmountManual'\\] =/",
        $settlementKernel
    ) === 1,
    '最终结账内核必须把销售人手工金额写入唯一快照'
);
performanceAmountAssert(
    strpos($settlementKernel, 'sale_line_salespeople_weight_invalid') === false,
    '最终结账内核不得再以销售人比例合计阻断结账'
);

$method = new ReflectionMethod(CashierV3CheckoutFactPlanV1::class, 'normalizeSpecific');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}
$base = [
    'performanceType' => CashierV3CheckoutFactPlanV1::ACTUAL_PERFORMANCE,
    'employeeId' => 0,
    'employeeNameSnapshot' => '',
    'employeeTypeSnapshot' => '',
    'employeeTypeAuthorityVersion' => 0,
    'roleSnapshot' => 'checkout_result',
    'allocationWeightNumerator' => 1,
    'allocationWeightDenominator' => 1,
    'allocationBaseAmountCents' => 10000,
    'laborFeeAmountCents' => 0,
    'ruleCodeSnapshot' => 'ACTUAL-NET-EXTERNAL-SALES-V1',
    'ruleNameSnapshot' => '实际业绩等于现金业绩扣除外部销售分配',
    'ruleVersionSnapshot' => 'v1',
];
$forward = $base;
$forward['amountCents'] = -2000;
$reverse = $base;
$reverse['allocationBaseAmountCents'] = -10000;
$reverse['amountCents'] = 2000;
$forwardRow = $method->invoke(null, 'performance', $forward, CashierV3CheckoutFactPlanV1::DIRECTION_FORWARD);
$reverseRow = $method->invoke(null, 'performance', $reverse, CashierV3CheckoutFactPlanV1::DIRECTION_REVERSAL);
performanceAmountAssert(
    ($forwardRow['amount_cents'] ?? null) === -2000
        && ($reverseRow['amount_cents'] ?? null) === 2000,
    '手工人员金额超过现金基数时，实际业绩与其作废反向事实必须保留准确正负值'
);

echo "PASS personnel-performance-amount-fact-contract\n";
