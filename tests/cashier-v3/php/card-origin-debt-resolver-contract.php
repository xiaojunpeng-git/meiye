<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$resolver = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php'
);
$projection = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php'
);
$checkout = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php'
);
$completion = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php'
);
$reservation = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationLifecycleServices.php'
);

$checks = [
    'only a V3-issued base-card snapshot enters the new debt branch' => str_contains($resolver, "->where('cart_type', 0)")
        && str_contains($resolver, "->where('product_type', 5)")
        && str_contains($resolver, "->where('source_type', 'cashier_v3')"),
    'V3 debt is matched to the exact sale and sale line' => str_contains($resolver, "['salesOrderId']")
        && str_contains($resolver, "['salesOrderLineId']")
        && str_contains($resolver, "cashier_v3_debt_authority")
        && str_contains($resolver, "cashier_v3_debt_item_personnel_authority"),
    'imported cards retain their historic resolver branch' => str_contains($resolver, 'if ($baseCarts === []) {')
        && str_contains($resolver, 'return null;')
        && str_contains($projection, "if (\$v3CardDebt !== null) {")
        && str_contains($projection, "return \$v3CardDebt;"),
    'all entitlement-consuming paths resolve V3 debt before legacy fallback' => str_contains($checkout, 'CashierV3CardOriginDebtResolver')
        && str_contains($completion, 'CashierV3CardOriginDebtResolver')
        && str_contains($reservation, 'CashierV3CardOriginDebtResolver'),
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if ($valid) {
        $passed++;
    }
}
echo "card origin debt resolver contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
