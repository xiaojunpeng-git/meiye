<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$assembler = $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php';
$plan = $root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php';
$repository = $root . '/后端代码/app/services/cashier/v3/fact/ThinkPhpCashierV3CheckoutFactRepository.php';
$service = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleProjectServiceCompletionServices.php';
$query = $root . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';
$salesOrderQuery = $root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';
$report = $root . '/后端代码/app/services/report/StoreUnifiedReportServices.php';
$detail = $root . '/前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue';
$migration = $root . '/后端代码/database/upgrades/2026-08-14-收银V3劳动业绩与手工费分离/02-正式升级.sql';

require $plan;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;

$checks = [
    'paid project performance fact carries a separate labor fee amount' => [$assembler, 'laborFeeAmountCents'],
    'fact plan normalizes labor fee as a signed fact amount' => [$plan, 'labor_fee_amount_cents'],
    'fact reversals include the independent labor fee amount' => [$repository, "'labor_fee_amount_cents'"],
    'completed service fact snapshots labor performance and labor fee independently' => [$service, "'laborFeeAmountCents'"],
    'service query reads the new labor fee fact field independently' => [$query, 'labor_fee_amount_cents'],
    'sales order detail projects labor fee with each craftsman allocation' => [$salesOrderQuery, "'laborFeeAmount' => \$this->moneyFromCents"],
    'sales order detail renders the separate craftsman labor fee' => [$detail, '手工费 {{ displayAmount'],
    'craftsman report reads the independent fee field instead of labor performance' => [$report, "THEN labor_fee_amount_cents ELSE 0 END) AS labor_amount_cents"],
    'migration adds the two independent labor fee columns idempotently' => [$migration, 'performance_labor_fee_already_present'],
];

$passed = 0;
foreach ($checks as $name => [$file, $needle]) {
    $contents = is_file($file) ? (string)file_get_contents($file) : '';
    if ($contents !== '' && strpos($contents, $needle) !== false) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        echo "FAIL {$name}\n";
    }
}
$method = new ReflectionMethod(CashierV3CheckoutFactPlanV1::class, 'normalizeSpecific');
$normalized = $method->invoke(null, 'performance', [
    'performanceType' => 'labor_performance_allocated',
    'employeeId' => 71,
    'employeeNameSnapshot' => '手艺人甲',
    'employeeTypeSnapshot' => 'internal',
    'employeeTypeAuthorityVersion' => 1,
    'roleSnapshot' => 'craftsman',
    'allocationWeightNumerator' => 100,
    'allocationWeightDenominator' => 100,
    'allocationBaseAmountCents' => 1500,
    'amountCents' => 1500,
    'laborFeeAmountCents' => 200,
    'ruleCodeSnapshot' => 'SALE-PROJECT-LABOR-V1',
    'ruleNameSnapshot' => '项目劳动业绩',
    'ruleVersionSnapshot' => 'project-rule:1',
], 'forward');
if (($normalized['amount_cents'] ?? null) === 1500
    && ($normalized['labor_fee_amount_cents'] ?? null) === 200) {
    $passed++;
    echo "PASS labor performance and labor fee normalize as separate amounts\n";
} else {
    echo "FAIL labor performance and labor fee normalize as separate amounts\n";
}
$expected = count($checks) + 1;
echo sprintf("%d/%d PASS\n", $passed, $expected);
exit($passed === $expected ? 0 : 1);
