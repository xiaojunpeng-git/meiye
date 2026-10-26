<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php';
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchReportProjectionServices.php';

use app\services\product\inventory\query\InventoryBatchReportProjectionServices;

$service = new InventoryBatchReportProjectionServices();
$result = $service->project([
    ['batch_balance_id' => 1, 'expire_date' => '2026-07-28', 'received_date' => '2026-06-01', 'inventory_amount' => '77.00'],
    ['batch_balance_id' => 2, 'expire_date' => '2026-08-15', 'received_date' => '2025-01-01', 'inventory_amount' => '123.45'],
    ['batch_balance_id' => 3, 'expire_date' => null, 'received_date' => null, 'inventory_amount' => null],
], '2026-07-29');
$failed = 0;
function batchReportAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }

batchReportAssert('expiry is calculated from cutoff date', $result['list'][0]['remaining_shelf_life_days'] === -1 && $result['list'][0]['remaining_shelf_life_band'] === '已过期');
batchReportAssert('age uses formal received date', $result['list'][1]['inventory_age_days'] === 574 && $result['list'][1]['inventory_age_band'] === '1年以上');
batchReportAssert('unknown dates stay in separate buckets', $result['list'][2]['remaining_shelf_life_band'] === '到期日未知' && $result['list'][2]['inventory_age_band'] === '正式入库日期未知');
batchReportAssert('bucket amount is server aggregate and remains hidden when cost is hidden', $result['expiry_buckets'][0]['inventory_amount'] === '77.00' && $result['expiry_buckets'][2]['inventory_amount'] === null);
exit($failed === 0 ? 0 : 1);
