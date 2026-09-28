<?php
declare(strict_types=1);

/** Verifies count command validation without a database or any inventory write. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountServices;

$normalize = new ReflectionMethod(InventoryStockCountServices::class, 'normalize');
$normalize->setAccessible(true);
$service = new InventoryStockCountServices();
$lines = [];
for ($index = 1; $index <= 151; $index++) {
    $lines[] = [
        'product_id' => $index, 'sku_id' => $index, 'sku_unique' => 'sku-' . $index,
        'counted_quantity' => '0', 'surplus_batch_no' => '', 'surplus_unit_cost' => '',
        'surplus_manufactured_date' => '', 'surplus_expire_date' => '',
        'expected_book_quantity' => '0',
    ];
}
$request = ['idempotency_key' => 'TEST-count-151-lines', 'business_date' => '2026-09-28', 'remark' => '', 'lines' => $lines];
$normalized = $normalize->invoke($service, $request);
if (count($normalized['lines']) !== 151 || end($normalized['lines'])['skuId'] !== 151) {
    throw new RuntimeException('large_count_document_truncated');
}

// Old clients omit the optional book snapshot; their fingerprint must still use the old line shape.
$legacyLine = $lines[0];
unset($legacyLine['expected_book_quantity']);
$legacy = $normalize->invoke($service, ['idempotency_key' => 'TEST-count-legacy', 'business_date' => '2026-09-28', 'remark' => '', 'lines' => [$legacyLine]]);
if (array_key_exists('expectedBook', $legacy['lines'][0])) throw new RuntimeException('legacy_fingerprint_changed');

$request['lines'][] = $lines[0];
try {
    $normalize->invoke($service, $request);
    throw new RuntimeException('duplicate_sku_accepted');
} catch (ReflectionException $exception) {
    throw $exception;
} catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() !== 'inventory_stock_count_duplicate_sku') throw $exception;
}
echo "STOCK_COUNT_LARGE_DOCUMENT_CONTRACT_OK\n";
