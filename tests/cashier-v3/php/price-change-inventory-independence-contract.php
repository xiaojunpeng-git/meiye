<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$catalog = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php');
$moreActions = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierMoreActionServices.php');
$policyStart = strpos($module, 'private static function registerMoreActionPolicies');
$policyEnd = strpos($module, 'private static function registerLineCouponPolicies', $policyStart === false ? 0 : $policyStart);
$priceChangePolicy = $policyStart === false
    ? ''
    : substr($module, $policyStart, ($policyEnd === false ? strlen($module) : $policyEnd) - $policyStart);

$checks = [
    'price change policy needs only the cashier workspace context' => strpos($priceChangePolicy, "['cashier_workspace'],\n                ['cashier_workspace'],\n                []") !== false,
    'price change policy has no server resource discovery' => $priceChangePolicy !== ''
        && strpos($priceChangePolicy, 'expand_from_server_resource_discovery') === false,
    'price change still calculates the configured cost from the locked SKU' => strpos($moreActions, '$configuredCostCents') !== false
        && strpos($moreActions, 'price_change_below_configured_cost') !== false,
    'ordinary stored-line discovery remains available for checkout validation' => strpos($catalog, 'function discoverStoredLineResources(') !== false,
    'checkout stored-line revalidation still checks inventory availability' => strpos($catalog, 'assertInventoryAvailableForSale($current, $quantity, $dataScope)') !== false,
];

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
echo $passed . '/' . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
