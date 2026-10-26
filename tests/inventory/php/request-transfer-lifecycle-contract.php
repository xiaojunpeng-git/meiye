<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$request = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestServices.php');
$transfer = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php');
$requestController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php');
$platformRequestController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php');
$transferController = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php');
$platformTransferController = (string)file_get_contents($root . '/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php');
$requestQuery = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php');
$inventoryPage = (string)file_get_contents($root . '/前端代码/inventory-vue3/src/App.vue');
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

lifecycleAssert('transfer cancellation and reversal are rejected by the domain service',
    substr_count($transfer, "throw new \\RuntimeException('inventory_cross_transfer_void_disabled')") === 4
    && strpos($transfer, 'private function reverse(') === false
    && strpos($transfer, 'private function cancel(') === false);

lifecycleAssert('historical reversal schema is preserved for old records without enabling new writes',
    strpos($upgrade, 'uk_transfer_reversal') !== false
    && strpos($postcheck, 'r.direction<>-o.direction') !== false
    && strpos($postcheck, 'r.quantity_units<>o.quantity_units') !== false);

lifecycleAssert('historical request fulfillment reversal remains readable for status recalculation',
    strpos($upgrade, 'eb_inventory_stock_request_fulfillment_reversal') !== false
    && strpos($upgrade, 'uk_reversal_of_fulfillment') !== false
    && strpos($transfer, 'return max(0,$fulfilled-$reversed)') !== false
    && strpos($transfer, "['APPLIED','PARTIAL'],true") !== false
    && strpos($transfer, "['APPLIED','PARTIAL','DONE']") !== false
    && strpos($transfer, "['CANCELLED','TERMINATED']") !== false);

lifecycleAssert('both store and HQ legacy transfer endpoints reject void',
    strpos($transferController, 'function reverse') !== false
    && strpos($platformTransferController, 'function reverse') !== false
    && strpos($transferController, 'inventory_cross_transfer_void_disabled') !== false
    && strpos($platformTransferController, 'inventory_cross_transfer_void_disabled') !== false);

lifecycleAssert('requests with any historical transfer are never voidable',
    strpos($request, "->where('request_document_id', \$requestId)->value('id')") !== false
    && preg_match('/private function hasLinkedTransfer\([^)]*\): bool\s*\{[^}]*where\(\'request_document_id\', \$requestId\)->value\(\'id\'\)/s', $requestQuery) === 1);

lifecycleAssert('V3 transfer list has only view, dispatch and receive actions',
    strpos($inventoryPage, "runTransferAction('cancel'") === false
    && strpos($inventoryPage, "runTransferAction('reverse'") === false
    && strpos($inventoryPage, 'can_cancel" class="text-button"') === false
    && strpos($inventoryPage, 'can_reverse" class="text-button"') === false);

echo "REQUEST_TRANSFER_LIFECYCLE_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
