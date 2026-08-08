<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$store = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$platform = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryPlatformHqReadModelServices.php');
$modal = file_get_contents($root . '/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue');

$failed = 0;
function reconciliationAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

foreach (['store' => $store, 'platform' => $platform] as $side => $source) {
    reconciliationAssert($side . ' detail returns the server-authoritative batch total',
        $source !== false
        && strpos($source, "'total_available_quantity' => \$reconciliation['batch_total_available_quantity']") !== false
        && strpos($source, "'reconciliation' => \$reconciliation") !== false);
    reconciliationAssert($side . ' detail compares inventory stock with settled batch balances and fails closed',
        $source !== false
        && strpos($source, "Db::name('inventory_stock')") !== false
        && strpos($source, "'available_quantity_units'") !== false
        && strpos($source, "'status' => 'MATCHED'") !== false
        && strpos($source, "throw new \\RuntimeException('inventory_stock_batch_balance_mismatch')") !== false);
    reconciliationAssert($side . ' movement detail includes display scale, unit and unit cost from the authoritative stock row',
        $source !== false
        && strpos($source, "leftJoin('inventory_stock s', 's.id=f.stock_id')") !== false
        && strpos($source, 's.quantity_scale,s.stock_unit,f.unit_cost_cents') !== false
        && strpos($source, "\$fact['unit_cost_cents'] = null") !== false);
}

reconciliationAssert('stock detail displays server total, reconciliation state and each batch remaining quantity',
    $modal !== false
    && strpos($modal, '总可用库存：<b>{{ detail.total_available_quantity }}') !== false
    && strpos($modal, "detail.reconciliation?.status === 'MATCHED' ? '正常' : '异常'") !== false
    && strpos($modal, '<th>当前剩余库存</th>') !== false
    && strpos($modal, '{{ batch.available_quantity }} {{ batch.stock_unit }}') !== false);

reconciliationAssert('stock movement detail formats quantity with its scale and displays a readable type, unit and unit cost',
    $modal !== false
    && strpos($modal, 'movementTypeName(movement.source_type)') !== false
    && strpos($modal, 'formatQuantity(movement.quantity_units, movement.quantity_scale)') !== false
    && strpos($modal, 'movement.stock_unit || detail.product.stock_unit') !== false
    && strpos($modal, 'movement.unit_cost_cents') !== false);

echo "INVENTORY_BATCH_RECONCILIATION_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
