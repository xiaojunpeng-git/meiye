<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\InventoryStockSummaryTotalsServices;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;

$failed = 0;
function stockTotalAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

$totals = (new InventoryStockSummaryTotalsServices())->totals([
    ['available_quantity' => '1.25', 'inventory_amount_cents' => 125],
    ['available_quantity' => '2', 'inventory_amount_cents' => 275],
], true);
stockTotalAssert('quantity total keeps exact four-decimal-unit arithmetic', $totals['available_quantity'] === '3.25');
stockTotalAssert('amount total remains integer cents', $totals['inventory_amount_cents'] === 400);
stockTotalAssert('cost permission hides the amount total without changing quantity',
    (new InventoryStockSummaryTotalsServices())->totals([
        ['available_quantity' => '3.25', 'inventory_amount_cents' => 999],
    ], false) === ['available_quantity' => '3.25', 'inventory_amount_cents' => null]);
stockTotalAssert('table layout explicitly fixes the result and first summary row',
    InventoryStockSummaryTotalsServices::tableLayout()['fixed'] === true
    && InventoryStockSummaryTotalsServices::tableLayout()['summaryRow'] === true);

$fields = [];
foreach (InventoryBatchStockQueryContract::fields() as $field) $fields[$field['key']] = $field;
stockTotalAssert('hidden inventory amount total is server-summary-only and permission protected',
    ($fields['inventory_amount_total']['allowedOperations'] ?? []) === ['summary']
    && ($fields['inventory_amount_total']['permission'] ?? '') === InventoryBatchStockQueryContract::PERMISSION_COST);
stockTotalAssert('summary amount converts back to cents without floating point',
    InventoryBatchStockQueryContract::amountToCents('123.45') === 12345);

$provider = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryProvider.php');
$store = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$hq = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqReadModelServices.php');
stockTotalAssert('unified query injects mandatory full-result quantity and permitted amount summaries',
    strpos($provider, 'withStockSummaries') !== false
    && strpos($provider, "'batch_balance_quantity'") !== false
    && strpos($provider, "'inventory_amount_total'") !== false);
stockTotalAssert('store and headquarters legacy summaries are calculated before pagination',
    strpos($store, '->totals($items, $canViewCost)') < strpos($store, 'array_slice($items')
    && strpos($hq, '->totals($items, $canViewCost)') < strpos($hq, 'array_slice($items'));

echo "INVENTORY_STOCK_SUMMARY_TOTAL_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
