<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php';
require $root . '/后端代码/app/services/product/inventory/query/InventoryBatchStockDataScope.php';

use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryContract as Contract;

$passed = 0;
$failed = 0;

function inventoryQueryAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

function inventoryQueryThrows(callable $callback, string $expected): bool
{
    try {
        $callback();
    } catch (\Throwable $throwable) {
        return $throwable->getMessage() === $expected;
    }
    return false;
}

inventoryQueryAssert('inventory batch page code frozen', Contract::PAGE_CODE === 'inventory_batch_stock');
inventoryQueryAssert('inventory batch stable row key frozen', Contract::STABLE_ROW_KEY === 'batch_balance_id');

$fields = Contract::fields();
$fieldIndex = [];
foreach ($fields as $field) {
    $fieldIndex[$field['key']] = $field;
}
inventoryQueryAssert('field keys are unique', count($fields) === count($fieldIndex));
inventoryQueryAssert('authoritative expiry inputs exposed',
    isset($fieldIndex['received_date'], $fieldIndex['expire_date'], $fieldIndex['batch_balance_quantity']));
inventoryQueryAssert('cost fields require cost permission',
    ($fieldIndex['batch_unit_cost']['permission'] ?? '') === Contract::PERMISSION_COST);
inventoryQueryAssert('inventory amount is not calculated by inventory provider',
    !isset($fieldIndex['inventory_amount']));

$blueprints = Contract::defaultCustomFieldBlueprints();
inventoryQueryAssert('four UQ custom field blueprints frozen',
    array_column($blueprints, 'stableName') === [
        'inventory_days_to_expiry',
        'inventory_age_days',
        'inventory_amount',
        'inventory_expiry_band',
    ]);
inventoryQueryAssert('inventory amount blueprint requires cost permission',
    ($blueprints[2]['requiredPermission'] ?? '') === Contract::PERMISSION_COST);

inventoryQueryAssert('integer units render exactly', Contract::unitsToDecimal(12, 0) === '12');
inventoryQueryAssert('fractional units render without trailing zeros', Contract::unitsToDecimal(12500, 4) === '1.25');
inventoryQueryAssert('negative units render exactly', Contract::unitsToDecimal(-5, 1) === '-0.5');
inventoryQueryAssert('money cents render exactly', Contract::centsToAmount(108) === '1.08');
inventoryQueryAssert('invalid scale rejected', inventoryQueryThrows(function (): void {
    Contract::unitsToDecimal(1, 5);
}, 'inventory_quantity_scale_invalid'));

inventoryQueryAssert('valid cutoff date accepted', Contract::assertCutoffDate('2026-07-29') === '2026-07-29');
inventoryQueryAssert('invalid cutoff date rejected', inventoryQueryThrows(function (): void {
    Contract::assertCutoffDate('2026-02-30');
}, 'inventory_query_cutoff_date_invalid'));

inventoryQueryAssert('quality status labels frozen',
    Contract::statusLabel(Contract::STATUS_GOOD) === '良品'
    && Contract::statusLabel(Contract::STATUS_DEFECTIVE) === '残次品'
    && Contract::statusLabel(Contract::STATUS_QUARANTINE) === '待检'
    && Contract::statusLabel(Contract::STATUS_FROZEN) === '冻结'
    && Contract::statusLabel(Contract::STATUS_EXPIRED) === '过期');

$scope = new InventoryBatchStockDataScope(
    'tenant-1',
    [9, 7, 9],
    [Contract::PERMISSION_VIEW]
);
inventoryQueryAssert('server location scope normalized', $scope->locationIds() === [7, 9]);
inventoryQueryAssert('view permission does not expose cost', $scope->canViewCost() === false);
inventoryQueryAssert('empty requested scope inherits server scope',
    $scope->assertRequestedLocations([]) === [7, 9]);
inventoryQueryAssert('requested locations may only narrow scope',
    $scope->assertRequestedLocations([9]) === [9]);
inventoryQueryAssert('requested locations cannot widen scope', inventoryQueryThrows(function () use ($scope): void {
    $scope->assertRequestedLocations([8]);
}, 'inventory_query_location_scope_denied'));

$costScope = new InventoryBatchStockDataScope(
    'tenant-1',
    [7],
    [Contract::PERMISSION_VIEW, Contract::PERMISSION_COST]
);
inventoryQueryAssert('cost permission exposed by server scope', $costScope->canViewCost() === true);
inventoryQueryAssert('missing view permission denied', inventoryQueryThrows(function (): void {
    new InventoryBatchStockDataScope('tenant-1', [7], [Contract::PERMISSION_COST]);
}, 'inventory_query_permission_denied'));

echo "INVENTORY_BATCH_QUERY_CONTRACT_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
