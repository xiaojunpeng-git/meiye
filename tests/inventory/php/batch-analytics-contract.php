<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php';
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchAnalyticsServices.php';

use app\services\product\inventory\query\InventoryBatchAnalyticsServices;

$service = new InventoryBatchAnalyticsServices();
$result = $service->analyze([
    ['batch_balance_id' => 1, 'batch_balance_quantity_units' => 8, 'quantity_scale' => 0, 'unit_cost_cents' => 7700, 'expire_date' => '2026-07-30', 'received_date' => '2026-07-20'],
    ['batch_balance_id' => 2, 'batch_balance_quantity_units' => 125, 'quantity_scale' => 2, 'unit_cost_cents' => 10800, 'expire_date' => null, 'received_date' => null],
], '2026-07-29', true);

$failed = 0;
function analyticsAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
analyticsAssert('expiry derives from cutoff date', $result['detail_rows'][0]['remaining_shelf_life_days'] === 1 && $result['detail_rows'][0]['remaining_shelf_life_band'] === '0-30天');
analyticsAssert('age derives from formal receipt date', $result['detail_rows'][0]['inventory_age_days'] === 9 && $result['detail_rows'][0]['inventory_age_band'] === '0-30天');
analyticsAssert('amount uses integer quantity units and cost cents', $result['detail_rows'][0]['inventory_amount'] === '616.00' && $result['detail_rows'][1]['inventory_amount'] === '135.00');
analyticsAssert('unknown dates stay separate', $result['detail_rows'][1]['remaining_shelf_life_band'] === '到期日未知' && $result['detail_rows'][1]['inventory_age_band'] === '正式入库日期未知');
$hidden = $service->analyze([['batch_balance_id' => 1, 'batch_balance_quantity_units' => 8, 'quantity_scale' => 0, 'unit_cost_cents' => 7700, 'expire_date' => '2026-07-30', 'received_date' => '2026-07-20']], '2026-07-29', false);
analyticsAssert('cost is hidden without permission', $hidden['detail_rows'][0]['inventory_amount'] === null && $hidden['expiry_buckets'][0]['inventory_amount'] === null);
exit($failed === 0 ? 0 : 1);
