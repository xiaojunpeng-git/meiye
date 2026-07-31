<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$policy = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php');
$context = file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryStoreUnifiedQueryContextFactory.php');
$analytics = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php');
$movementQuery = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryMovementQueryServices.php');
$transferQuery = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryBatchTransferServices.php');
$requestQuery = file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php');
$movementController = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryMovementQuery.php');
$transferController = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryBatchTransfer.php');
$requestController = file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventoryStockRequestQuery.php');
$failed = 0;
function storePolicyAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }

storePolicyAssert('store inventory cost is derived from role menu unique auth rather than granted to every authenticated user',
    $policy !== false && strpos($policy, 'SystemMenusServices') !== false
    && strpos($policy, 'InventoryBatchStockQueryContract::PERMISSION_COST') !== false
    && $context !== false && strpos($context, 'InventoryStoreAccessPolicy') !== false);
storePolicyAssert('movement statistics use all active store warehouses and every settled fact direction',
    $analytics !== false && strpos($analytics, "where('location_type', 'STORE')->where('location_status', 'ACTIVE')") !== false
    && strpos($analytics, "where('f.direction', \$direction)") !== false
    && strpos($analytics, "'stock_count_gain' => '盘盈'") !== false
    && strpos($analytics, "'batch_transfer_in' => '调拨入库'") !== false
    && strpos($analytics, "'salon_usage_issue' => '院装领用'") !== false);
storePolicyAssert('statistics suppress cost amounts when the role lacks the dedicated cost capability',
    $analytics !== false && strpos($analytics, "if (!\$canViewCost) \$row['cost_amount_cents'] = null") !== false);
storePolicyAssert('movement details use the same active-store warehouse scope as statistics',
    $movementQuery !== false && strpos($movementQuery, 'activeStoreLocationIds($storeId)') !== false
    && strpos($movementQuery, "whereIn('f.location_id', \$locationIds)") !== false);
storePolicyAssert('every store inventory list suppresses cost fields unless the server-side role capability permits it',
    $movementController !== false && strpos($movementController, 'InventoryStoreAccessPolicy') !== false
    && $transferController !== false && strpos($transferController, 'InventoryStoreAccessPolicy') !== false
    && $requestController !== false && strpos($requestController, 'InventoryStoreAccessPolicy') !== false
    && $movementQuery !== false && strpos($movementQuery, "if (!\$canViewCost) \$row['cost_amount_cents'] = null") !== false
    && $transferQuery !== false && strpos($transferQuery, "if (!\$canViewCost) foreach (\$list as &\$row) \$row['transfer_amount_cents'] = null") !== false
    && $requestQuery !== false && strpos($requestQuery, "if (!\$canViewCost) foreach (\$list as &\$row) \$row['estimated_amount_cents'] = null") !== false);
storePolicyAssert('transfer and request totals convert stock units to cents before returning to the client',
    $transferQuery !== false && strpos($transferQuery, 'transfer_amount_cents') !== false
    && strpos($transferQuery, '/POW(10,s.quantity_scale)') !== false
    && $requestQuery !== false && strpos($requestQuery, 'estimated_amount_cents') !== false
    && strpos($requestQuery, '/POW(10,l.quantity_scale)') !== false);

echo "INVENTORY_STORE_POLICY_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
