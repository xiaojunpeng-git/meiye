<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php';
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockDataScope.php';
require $root . '/后端代码/app/services/product/inventory/query/InventoryStoreBatchScopeResolver.php';

use app\services\product\inventory\query\InventoryBatchStockQueryContract as Contract;
use app\services\product\inventory\query\InventoryStoreBatchScopeResolver;

$resolver = new InventoryStoreBatchScopeResolver();
$scope = $resolver->resolve(18, [
    ['id' => 101, 'tenant_id' => 'tenant-18', 'store_id' => 18, 'location_status' => 'ACTIVE'],
    ['id' => 102, 'tenant_id' => 'tenant-18', 'store_id' => 18, 'location_status' => 'ACTIVE'],
], false);
$failed = 0;
function storeScopeAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }

storeScopeAssert('current store receives only its active warehouses', $scope->tenantId() === 'tenant-18' && $scope->locationIds() === [101, 102]);
storeScopeAssert('cost permission is denied by default', $scope->canViewCost() === false);
storeScopeAssert('scope includes the batch inventory view feature', $scope->assertRequestedLocations([]) === [101, 102]);

$wrongStore = false;
try { $resolver->resolve(18, [['id' => 101, 'tenant_id' => 'tenant-18', 'store_id' => 19, 'location_status' => 'ACTIVE']], false); } catch (InvalidArgumentException $e) { $wrongStore = $e->getMessage() === 'inventory_store_location_invalid'; }
storeScopeAssert('location from another store is rejected before query', $wrongStore);

$ambiguous = false;
try { $resolver->resolve(18, [['id' => 101, 'tenant_id' => 'tenant-a', 'store_id' => 18, 'location_status' => 'ACTIVE'], ['id' => 102, 'tenant_id' => 'tenant-b', 'store_id' => 18, 'location_status' => 'ACTIVE']], false); } catch (InvalidArgumentException $e) { $ambiguous = $e->getMessage() === 'inventory_store_tenant_ambiguous'; }
storeScopeAssert('cross-tenant warehouse rows fail closed', $ambiguous);

$costScope = $resolver->resolve(18, [['id' => 101, 'tenant_id' => 'tenant-18', 'store_id' => 18, 'location_status' => 'ACTIVE']], true);
storeScopeAssert('cost is enabled only from trusted server permission', $costScope->canViewCost() && $costScope->assertRequestedLocations([101]) === [101]);
exit($failed === 0 ? 0 : 1);
