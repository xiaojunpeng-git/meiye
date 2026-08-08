<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryProductStockSummaryServices;

$failed = 0;
function summaryRangeCheck(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }

$summary = (new InventoryProductStockSummaryServices())->summarize([
    ['batch_balance_id' => 11, 'product_id' => 101, 'sku_id' => 1001, 'quantity_scale' => 0, 'batch_balance_quantity' => '440', 'inventory_amount' => '440.00'],
    ['batch_balance_id' => 12, 'product_id' => 101, 'sku_id' => 1001, 'quantity_scale' => 0, 'batch_balance_quantity' => '1', 'inventory_amount' => '1.00'],
]);
summaryRangeCheck('product SKU summary totals batch quantities before a range filter is evaluated',
    count($summary) === 1 && (string)$summary[0]['batch_balance_quantity'] === '441' && (string)$summary[0]['available_quantity'] === '441');
summaryRangeCheck('product SKU summary totals visible cost only when the scoped source provides cost values',
    (string)$summary[0]['inventory_amount'] === '441.00');

$provider = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryProvider.php');
$storeRead = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$hqRead = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqReadModelServices.php');
summaryRangeCheck('the displayed quantity range enters product SKU aggregation before UQ filters',
    strpos($provider, 'hasQuantityRangeTopFilter') !== false
    && strpos($provider, 'InventoryProductStockSummaryServices') !== false
    && strpos($provider, "'available_quantity'") !== false);
summaryRangeCheck('store and headquarters default summaries remain sourced from the same settled batch authority',
    strpos($storeRead, 'InventoryBatchStockQueryProvider') !== false
    && strpos($hqRead, 'InventoryBatchStockQueryProvider') !== false);

echo "INVENTORY_PRODUCT_SUMMARY_QUANTITY_RANGE_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
