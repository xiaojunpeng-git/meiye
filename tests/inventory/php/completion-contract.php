<?php
declare(strict_types=1);

$backend = getenv('INVENTORY_BACKEND_ROOT');
$backend = is_string($backend) && $backend !== ''
    ? rtrim($backend, '/')
    : __DIR__ . '/../../../后端代码';
require_once $backend . '/app/services/product/inventory/completion/InventoryCompletionContractException.php';
require_once $backend . '/app/services/product/inventory/completion/InventoryCompletionDataScope.php';
require_once $backend . '/app/services/product/inventory/completion/InventoryEntitlementCompletionContract.php';

use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryCompletionDataScope;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract as Contract;

$passed = 0;
$failed = 0;

function inventoryAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

function inventoryReason(callable $callable): string
{
    try {
        $callable();
    } catch (InventoryCompletionContractException $exception) {
        return $exception->reason();
    } catch (\Throwable $throwable) {
        return 'UNEXPECTED:' . get_class($throwable) . ':' . $throwable->getMessage();
    }
    return '';
}

inventoryAssert('merchant deny + inherit', Contract::resolvePolicy('deny_shortage', 'inherit') === 'deny_shortage');
inventoryAssert('merchant allow + inherit', Contract::resolvePolicy('allow_shortage', 'inherit') === 'allow_shortage');
inventoryAssert('project deny overrides allow', Contract::resolvePolicy('allow_shortage', 'deny_shortage') === 'deny_shortage');
inventoryAssert('project allow overrides deny', Contract::resolvePolicy('deny_shortage', 'allow_shortage') === 'allow_shortage');
inventoryAssert(
    'invalid merchant policy fail closed',
    inventoryReason(static function (): void { Contract::resolvePolicy('unknown', 'inherit'); }) === 'inventory_merchant_policy_invalid'
);
inventoryAssert(
    'invalid project policy fail closed',
    inventoryReason(static function (): void { Contract::resolvePolicy('deny_shortage', 'unknown'); }) === 'inventory_project_policy_invalid'
);

$version11 = Contract::policyVersion(1, 1);
$version12 = Contract::policyVersion(1, 2);
$version21 = Contract::policyVersion(2, 1);
inventoryAssert('composite policy versions are positive', $version11 > 0 && $version12 > 0 && $version21 > 0);
inventoryAssert('merchant version changes resolved version', $version11 !== $version21);
inventoryAssert('project version changes resolved version', $version11 !== $version12);
inventoryAssert(
    'shortage cursor resource id is stable and scoped by stock recipe and cost',
    Contract::shortageCursorResourceId(502, 901, 50) === '502:901:50'
        && Contract::shortageCursorResourceId(502, 901, 50)
            === Contract::shortageCursorResourceId(502, 901, 50)
);
inventoryAssert(
    'invalid shortage cursor identity fails closed',
    inventoryReason(static function (): void {
        Contract::shortageCursorResourceId(0, 901, 50);
    }) === 'inventory_shortage_cursor_identity_invalid'
);
inventoryAssert(
    'canonical decimal resource ids use numeric order',
    Contract::compareResourceIds('2', '10') < 0
        && Contract::compareResourceIds('10', '2') > 0
        && Contract::compareResourceIds('02', '10') < 0
);

inventoryAssert('integer quantity units', Contract::decimalToUnits('3', 0) === 3);
inventoryAssert('database decimal trailing zeros accepted', Contract::decimalToUnits('5.0000', 0) === 5);
inventoryAssert('fractional quantity units', Contract::decimalToUnits('1.2500', 4) === 12500);
inventoryAssert('fractional zero padding', Contract::decimalToUnits('1.2', 3) === 1200);
inventoryAssert(
    'quantity precision rejected',
    inventoryReason(static function (): void { Contract::decimalToUnits('1.001', 2); }) === 'inventory_quantity_precision_exceeded'
);
inventoryAssert(
    'zero quantity rejected',
    inventoryReason(static function (): void { Contract::decimalToUnits('0', 2); }) === 'inventory_quantity_not_positive'
);

$formula = [[
    'stockId' => '9',
    'consumableId' => 101,
    'skuId' => 201,
    'quantityUnitsPerService' => 25,
    'stockUnitScale' => 2,
], [
    'stockId' => '2',
    'consumableId' => 102,
    'skuId' => 202,
    'quantityUnitsPerService' => 1,
    'stockUnitScale' => 0,
]];
$hashA = Contract::recipeFormulaHash(7, 3, $formula);
$hashB = Contract::recipeFormulaHash(7, 3, array_reverse($formula));
$changedFormula = $formula;
$changedFormula[0]['quantityUnitsPerService'] = 26;
inventoryAssert('formula hash is stable across input order', $hashA === $hashB);
inventoryAssert('formula hash changes with locked formula', $hashA !== Contract::recipeFormulaHash(7, 3, $changedFormula));
inventoryAssert('stable recipe id is not formula hash', $hashA !== '7' && strlen($hashA) === 64);

$fingerprintA = Contract::requestFingerprint(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
$fingerprintB = Contract::requestFingerprint(['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
inventoryAssert('request fingerprint canonicalizes maps', $fingerprintA === $fingerprintB);

$scope = new InventoryCompletionDataScope('tenant-1', 'org-1', '/org-1/store-7', [8, 7, 7], 99);
$scope->assertTenantAndStore('tenant-1', 7);
inventoryAssert('server scope accepts authorized store', true);
inventoryAssert(
    'server scope rejects tenant widening',
    inventoryReason(static function () use ($scope): void { $scope->assertTenantAndStore('tenant-2', 7); }) === 'inventory_data_scope_tenant_denied'
);
inventoryAssert(
    'server scope rejects store widening',
    inventoryReason(static function () use ($scope): void { $scope->assertTenantAndStore('tenant-1', 9); }) === 'inventory_data_scope_store_denied'
);

inventoryAssert('policy lock order frozen', Contract::LOCK_ORDER_POLICY === 46);
inventoryAssert('recipe lock order frozen', Contract::LOCK_ORDER_RECIPE === 47);
inventoryAssert('stock lock order frozen', Contract::LOCK_ORDER_STOCK === 50);
inventoryAssert('batch lock order frozen', Contract::LOCK_ORDER_BATCH === 55);
inventoryAssert('shortage cursor lock order frozen', Contract::LOCK_ORDER_SHORTAGE_CURSOR === 56);
inventoryAssert('explicit shortage cursor gate frozen',
    Contract::SHORTAGE_CURSOR_GATE === 'explicit_resource_plan_locked_v1');
inventoryAssert('provider contract version frozen', Contract::CONTRACT_VERSION === 'inventory-entitlement-completion-provider-v1');

$providerSource = (string)file_get_contents(
    $backend . '/app/services/product/inventory/completion/InventoryEntitlementCompletionProvider.php'
);
$factSource = (string)file_get_contents(
    $backend . '/app/services/product/inventory/completion/InventoryCompletionFactServices.php'
);
$movementSource = (string)file_get_contents(
    $backend . '/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php'
);
$readinessSource = (string)file_get_contents(
    $backend . '/app/services/cashier/v3/checkout/provider/CashierV3InventoryCompletionReadinessProbe.php'
);
$migrationSource = (string)file_get_contents(
    $backend . '/database/upgrades/2026-07-29-库存耗材批次完成合同/02-正式升级.sql'
);
$migrationVerification = (string)file_get_contents(
    $backend . '/database/upgrades/2026-07-29-库存耗材批次完成合同/03-升级后验证.sql'
);
inventoryAssert('provider requires an outer transaction', strpos($providerSource, 'inventory_provider_transaction_required') !== false);
inventoryAssert('provider returns stable recipeId', strpos($providerSource, "'recipeId' => (int)\$recipeSet['recipe']['id']") !== false);
inventoryAssert('provider returns shortage cost cursor', strpos($providerSource, "'shortageCostAllocatedQuantityUnitsBefore'") !== false);
inventoryAssert('provider returns explicit shortage cursor id and positive version',
    strpos($providerSource, "'shortageCursorId' => \$cursor['resourceId']") !== false
    && strpos($providerSource, "'shortageCursorVersion' => \$cursor['version']") !== false
    && strpos($providerSource, "'inventory_shortage_cursor'") !== false
    && strpos($providerSource, 'LOCK_ORDER_SHORTAGE_CURSOR') !== false
);
inventoryAssert('missing shortage cursor is initialized idempotently then lock-read',
    strpos($providerSource, 'INSERT IGNORE INTO `eb_inventory_shortage_cost_cursor`') !== false
    && strpos($providerSource, "(int)\$row['version'] <= 0") !== false
);
inventoryAssert('provider locks shortage cursors independently of request line order',
    strpos($providerSource, 'lockShortageCostCursors(') !== false
    && strpos($providerSource, 'uksort($candidates') !== false
    && strpos($providerSource, 'shortageCostCursorKey(') !== false
);
inventoryAssert('provider locks true batches', strpos($providerSource, "Db::name('inventory_batch')->where('id', \$batchId)->lock(true)") !== false);
inventoryAssert('provider within-kind ids follow canonical cashier resource order',
    substr_count($providerSource, 'InventoryEntitlementCompletionContract::compareResourceIds') >= 7
    && strpos($providerSource, 'sort($projectIds, SORT_STRING)') === false
    && strpos($providerSource, 'sort($ids, SORT_STRING)') === false
    && strpos($providerSource, 'ksort($stockCandidates, SORT_STRING)') === false
    && strpos($providerSource, 'ksort($candidateBatchIds, SORT_STRING)') === false
    && strpos($providerSource, 'sort($projectIds, SORT_NUMERIC)') === false
    && strpos($providerSource, 'ksort($stockCandidates, SORT_NUMERIC)') === false
);
inventoryAssert('inventory fact and reversal writers keep canonical resource order',
    strpos($factSource, 'sortResourceMap($stockQty)') !== false
    && strpos($factSource, 'sortResourceMap($batchQty)') !== false
    && substr_count($factSource, 'sortResourceMap($actions)') === 3
    && strpos($factSource, 'InventoryEntitlementCompletionContract::compareResourceIds') !== false
    && strpos($factSource, 'ksort($batchQty, SORT_NUMERIC)') === false
    && strpos($factSource, 'ksort($actions, SORT_NUMERIC)') === false
);
inventoryAssert('provider selects stock only from one active default store location',
    strpos($providerSource, "Db::name('inventory_location')") !== false
    && strpos($providerSource, "->where('location_type', 'STORE')") !== false
    && strpos($providerSource, "->where('is_default', 1)") !== false
    && strpos($providerSource, "->where('location_status', 'ACTIVE')") !== false
    && strpos($providerSource, 'count($rows) !== 1') !== false
    && strpos($providerSource, "->where('location_id', \$defaultLocationId)") !== false
    && strpos($providerSource, 'inventory_default_location_not_ready') !== false
);
inventoryAssert('cashier readiness checks real recipe columns and every writer dependency',
    strpos($readinessSource, "'project_product_id', 'project_unique'") !== false
    && strpos($readinessSource, "'eb_inventory_location'") !== false
    && strpos($readinessSource, "'eb_inventory_consumption_receipt'") !== false
    && strpos($readinessSource, "'eb_inventory_batch_consumption_fact'") !== false
    && strpos($readinessSource, "'eb_inventory_shortage_fact'") !== false
    && strpos($readinessSource, "'eb_inventory_shortage_cost_adjustment'") !== false
    && strpos($readinessSource, "'eb_inventory_batch_movement_fact'") !== false
    && strpos($readinessSource, 'InventoryCompletionFactServices::class') !== false
    && strpos($readinessSource, 'InventoryBatchMovementFactServices::class') !== false
    && strpos($readinessSource, 'InventoryEntitlementCompletionDiscovery::class') !== false
    && strpos($readinessSource, 'CashierV3InventoryResourceVersionProvider::class') !== false
    && strpos($readinessSource, 'CashierV3InventoryCompletionGatewayAdapter::class') !== false
);
inventoryAssert('writer never creates a shortage batch', strpos($factSource, "insertGetId([\n                    'stock_id'") === false);
inventoryAssert('completion writer advances each explicit shortage cursor once by version CAS',
    strpos($factSource, "\$cursorKey = \$shortageCursorId") !== false
    && strpos($factSource, "->where('version', \$action['expectedVersion'])") !== false
    && strpos($factSource, 'inventory_shortage_cost_cursor_cas_failed') !== false
    && strpos($factSource, "Db::name('inventory_shortage_cost_cursor')->insert") === false
);
inventoryAssert('completion and reversal append the batch movement ledger in the outer transaction',
    strpos($factSource, 'appendBatchMovement(') !== false
    && strpos($factSource, 'findConsumptionMovement(') !== false
    && strpos($movementSource, "Db::name('inventory_batch_movement_fact')") !== false
    && strpos($movementSource, 'inventory_batch_movement_transaction_required') !== false
);
inventoryAssert('migration freezes stable shortage cursor identity and positive version',
    strpos($migrationSource, 'CREATE TABLE IF NOT EXISTS `eb_inventory_shortage_cost_cursor`') !== false
    && strpos($migrationSource, '`version` bigint(20) unsigned NOT NULL DEFAULT \'1\'') !== false
    && strpos(
        $migrationSource,
        'UNIQUE KEY `uk_scope_recipe_stock_cost` (`tenant_id`,`store_id`,`recipe_id`,`stock_id`,`estimated_unit_cost_cents`)'
    ) !== false
    && strpos($migrationVerification, 'SELECT COUNT(*) INTO @inventory_bad_shortage_cursor') !== false
    && strpos($migrationVerification, 'recipe_id=0 OR version=0') !== false
);
inventoryAssert('cashier source is not imported', strpos($providerSource . $factSource, 'services\\cashier') === false);

echo "INVENTORY_CONTRACT_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
