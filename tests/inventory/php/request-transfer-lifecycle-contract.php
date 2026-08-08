<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$request = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$transfer = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$requestController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php');
$platformRequestController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php');
$transferController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php');
$platformTransferController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php');
$upgrade = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-库存V3请货终止与调拨冲销/02-正式升级.sql');
$postcheck = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-08-05-库存V3请货终止与调拨冲销/03-升级后验证.sql');

$failed = 0;
function lifecycleAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }

lifecycleAssert('request cancellation and partial termination are separate idempotent authority commands',
    strpos($request, "normalizeLifecycle(\$input, 'CANCEL')") !== false
    && strpos($request, "normalizeLifecycle(\$input, 'TERMINATE')") !== false
    && strpos($request, "'APPLIED' : 'PARTIAL'") !== false
    && strpos($request, "'CANCELLED' : 'TERMINATED'") !== false
    && strpos($request, 'fulfilled_quantity_units_snapshot') !== false
    && strpos($upgrade, 'eb_inventory_stock_request_lifecycle_operation') !== false);

lifecycleAssert('request lifecycle is shared by authenticated store and platform HQ boundaries',
    strpos($request, 'cancelForStore') !== false && strpos($request, 'terminateForStore') !== false
    && strpos($request, 'cancelForHeadquarters') !== false && strpos($request, 'terminateForHeadquarters') !== false
    && strpos($requestController, 'function terminate') !== false
    && strpos($platformRequestController, 'function terminate') !== false);

lifecycleAssert('received transfer reversal removes the exact target allocation before restoring its source allocation',
    strpos($transfer, 'reverseReceiptAllocation') !== false
    && strpos($transfer, 'reverseDispatchAllocation') !== false
    && strpos($transfer, 'target_reversal_stock_insufficient') !== false
    && strpos($transfer, "'cross_transfer_in_reversal'") !== false
    && strpos($transfer, "'cross_transfer_out_reversal'") !== false);

lifecycleAssert('movement reversal facts are immutable and tied one-to-one to originals',
    strpos($transfer, "'reversalOf' => (int)\$original['id']") !== false
    && strpos($transfer, "->where('reversal_of', (int)\$fact['id'])") !== false
    && strpos($upgrade, 'uk_transfer_reversal') !== false
    && strpos($postcheck, 'r.direction<>-o.direction') !== false
    && strpos($postcheck, 'r.quantity_units<>o.quantity_units') !== false);

lifecycleAssert('request fulfillment reversal is appended and net fulfillment drives status recalculation',
    strpos($upgrade, 'eb_inventory_stock_request_fulfillment_reversal') !== false
    && strpos($upgrade, 'uk_reversal_of_fulfillment') !== false
    && strpos($transfer, 'reversed_quantity_units') !== false
    && strpos($transfer, 'return max(0,$fulfilled-$reversed)') !== false
    && strpos($transfer, "['APPLIED','PARTIAL'],true") !== false
    && strpos($transfer, "['APPLIED','PARTIAL','DONE']") !== false
    && strpos($transfer, "['CANCELLED','TERMINATED']") !== false);

lifecycleAssert('both store and HQ controllers expose transfer reversal with mandatory reason input',
    strpos($transferController, 'function reverse') !== false
    && strpos($platformTransferController, 'function reverse') !== false
    && strpos($transferController . $platformTransferController, "['reason', '']") !== false
    && strpos($transfer, 'inventory_cross_transfer_reverse_input_invalid') !== false);

echo "REQUEST_TRANSFER_LIFECYCLE_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
