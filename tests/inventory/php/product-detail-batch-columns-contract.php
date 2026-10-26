<?php
declare(strict_types=1);

/** 商品库存详情分别校验当前有余额的批次与该商品全部已结算历史流水。 */
$root = dirname(__DIR__, 3);
$readModel = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php');
$modal = file_get_contents($root . '/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue');
if ($readModel === false || $modal === false) {
    throw new RuntimeException('inventory product detail source missing');
}

function checkProductDetail(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . PHP_EOL;
}

checkProductDetail(strpos($readModel, "leftJoin('inventory_batch b', 'b.id=f.batch_id')") !== false
    && strpos($readModel, 'order_sn,b.batch_no,b.manufactured_date,b.expire_date,f.source_type') !== false
    && strpos($readModel, "where('f.fact_status', 'SETTLED')") !== false,
    'movement batch metadata follows its settled fact batch_id');

checkProductDetail(strpos($readModel, "join('inventory_stock s', 's.id=f.stock_id')") !== false
    && strpos($readModel, "where('s.consumable_product_id', \$productId)") !== false
    && strpos($readModel, "whereIn('f.location_id', \$scope->locationIds())") !== false
    && strpos($readModel, "whereIn('s.location_id', \$scope->locationIds())") !== false
    && strpos($readModel, "whereIn('f.batch_id', \$batchIds)") === false,
    'movement history includes exhausted batches without expanding the authorized product and location scope');

$batchStart = strpos($modal, '<h3>当前批次库存</h3>');
$movementStart = strpos($modal, '<h3>出入库流水</h3>', $batchStart ?: 0);
checkProductDetail($batchStart !== false && $movementStart !== false, 'both detail sections exist');
$batchSection = substr($modal, $batchStart, $movementStart - $batchStart);
$movementSection = substr($modal, $movementStart, strpos($modal, '</section>', $movementStart) - $movementStart);

checkProductDetail(strpos($batchSection, '<th>仓库</th><th>批号</th><th>当前剩余库存</th><th>正式入库日</th><th>生产日期</th><th>到期日</th>') !== false
    && strpos($batchSection, '<th>批次号</th>') === false
    && strpos($batchSection, 'colspan="8"') !== false
    && strpos($batchSection, '{{ batch.manufactured_date || \'-\' }}') !== false,
    'current batch columns and empty row match confirmed order');

checkProductDetail(strpos($movementSection, '<th>业务日期</th><th>单据号</th><th>批号</th><th>生产日期</th><th>到期日</th><th>业务类型</th>') !== false
    && strpos($movementSection, 'colspan="14"') !== false
    && strpos($movementSection, '{{ movement.batch_no || \'-\' }}</td><td>{{ movement.manufactured_date || \'-\' }}</td><td>{{ movement.expire_date || \'-\' }}') !== false,
    'movement columns and empty row match confirmed order');
