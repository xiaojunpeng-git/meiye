<?php
declare(strict_types=1);

/** Static contract: business dates drive filters; audit date/person remain separate trailing fields. */
$root = dirname(__DIR__, 3);
$contract = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php');
$provider = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$writer = file_get_contents($root . '/后端代码/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php');
$migration = file_get_contents($root . '/后端代码/database/upgrades/2026-10-02-库存V3业务与操作信息分离/02-正式升级.sql');

$mustContain = [
    [$contract, "'inventory_inbound' => '入库日期'"],
    [$contract, "'inventory_outbound' => '出库日期'"],
    [$contract, "'inventory_transfer' => '调拨日期'"],
    [$contract, "['operation_at', '操作日期', 'datetime', ''], ['operator_name', '操作人', 'text', '']"],
    [$provider, 'MAX(f.operator_name_snapshot) AS operator_name'],
    [$provider, 'd.created_by_operator_name_snapshot operator_name'],
    [$provider, 'd.operator_name_snapshot operator_name'],
    [$writer, "'operator_name_snapshot' => \$movement['operatorName']"],
    [$migration, 'eb_inventory_batch_movement_fact'],
    [$migration, 'created_by_operator_name_snapshot'],
];
foreach ($mustContain as [$source, $needle]) {
    if ($source === false || strpos($source, $needle) === false) {
        fwrite(STDERR, "missing operation audit contract: {$needle}\n");
        exit(1);
    }
}
echo "operation audit columns contract passed\n";
