<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$workspace = $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php';
$assembler = $root . '/后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php';
$adapter = $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionAuthorityAdapter.php';
$service = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleProjectServiceCompletionServices.php';
$rebuilder = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php';
$kernel = $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php';
$salesOrderPlan = $root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php';
$migration = $root . '/后端代码/database/upgrades/2026-08-14-收银V3完整模式临时手工费/02-正式升级.sql';
$view = $root . '/前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue';

$checks = [
    'workspace stores a nullable manual labor fee snapshot' => [
        $workspace,
        "'manual_labor_fee_cents' => \$manualLaborFeeCents",
    ],
    'workspace fingerprint accepts a legacy row without the optional labor fee snapshot' => [
        $workspace,
        "if ((\$row['manual_labor_fee_cents'] ?? null) !== null)",
    ],
    'workspace validates a nonnegative integer-yuan override' => [
        $workspace,
        'manualLaborFeeCents($settings[\'laborManualFee\']',
    ],
    'checkout source authority carries the workspace override' => [
        $workspace,
        "\$source['laborManualFeeCents'] = \$this->storedNonnegativeInteger",
    ],
    'checkout authority carries the override into the locked performance rule' => [
        $adapter,
        "laborManualFeeCents",
    ],
    'sale fact plan records labor performance for a manual project fee' => [
        $assembler,
        "SALE-PROJECT-MANUAL-LABOR-V1",
    ],
    'completed service fact stores labor amount and mode' => [
        $service,
        "'labor_amount_cents'",
    ],
    'database migration carries workspace, draft, order and service snapshots' => [
        $migration,
        "eb_cashier_v3_sales_order_line",
    ],
    'complete mode exposes a one-time labor fee field' => [
        $view,
        '本次手工费',
    ],
    'front end only submits override after explicit editing' => [
        $view,
        'laborFeeDirty.value',
    ],
    'unset override is omitted from the draft authority' => [
        $rebuilder,
        'if (($row[\'manual_labor_fee_cents\'] ?? null) !== null)',
    ],
    'settlement accepts cents without whole-yuan validation' => [
        $kernel,
        'self::nonNegativeInt($line[\'manualLaborFeeCents\']',
    ],
    'sales order carries the manual labor snapshot' => [
        $salesOrderPlan,
        "'manual_labor_fee_cents' => \$line['manual_labor_fee_cents']",
    ],
    'resource discovery returns its resource result' => [
        $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php',
        "'resources' => \$resources,\n        ];\n        return \$result;",
    ],
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

echo sprintf("%d/%d PASS\n", $passed, count($checks));
exit($passed === count($checks) ? 0 : 1);
