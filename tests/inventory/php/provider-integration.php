<?php
declare(strict_types=1);

$backend = '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryCompletionDataScope;
use app\services\product\inventory\completion\InventoryCompletionFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract as Contract;
use app\services\product\inventory\completion\InventoryEntitlementCompletionProvider;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->env->set('cache.driver', 'file');
$app->env->set('CACHE_DRIVER', 'file');
$app->env->set('PHP_CACHE_DRIVER', 'file');
$app->env->set('database.type', 'mysql');
$app->env->set('DATABASE_TYPE', 'mysql');
$app->env->set('database.hostname', getenv('DB_HOST') ?: 'mysql');
$app->env->set('DATABASE_HOSTNAME', getenv('DB_HOST') ?: 'mysql');
$app->env->set('database.hostport', getenv('DB_PORT') ?: '3306');
$app->env->set('DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306');
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'inventory_completion');
$app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'inventory_completion');
$app->env->set('database.username', getenv('DB_USERNAME') ?: 'root');
$app->env->set('DATABASE_USERNAME', getenv('DB_USERNAME') ?: 'root');
$app->env->set('database.password', getenv('DB_PASSWORD') ?: '');
$app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD') ?: '');
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
$effectiveDatabase = Config::get('database.connections.mysql');
if (!is_array($effectiveDatabase)
    || (string)($effectiveDatabase['hostname'] ?? '') !== (getenv('DB_HOST') ?: 'mysql')
    || (string)($effectiveDatabase['hostport'] ?? '') !== (getenv('DB_PORT') ?: '3306')
    || (string)($effectiveDatabase['database'] ?? '') !== (getenv('DB_DATABASE') ?: 'inventory_completion')) {
    throw new RuntimeException('INVENTORY_TEST_DATABASE_CONFIG_MISMATCH:' . json_encode([
        'hostname' => $effectiveDatabase['hostname'] ?? '',
        'hostport' => $effectiveDatabase['hostport'] ?? '',
        'database' => $effectiveDatabase['database'] ?? '',
    ]));
}
$testConnection = Db::connect('mysql', true);
$testConnection->query('SELECT 1');

$passed = 0;
$failed = 0;
function providerAssert(string $name, bool $condition, string $detail = ''): void
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

$scope = new InventoryCompletionDataScope('tenant-1', 'org-1', '/org-1/store-7', [7], 99);
$provider = new InventoryEntitlementCompletionProvider();
$writer = new InventoryCompletionFactServices();
$providerRequest = [
    'contractVersion' => Contract::CONTRACT_VERSION,
    'tenantId' => 'tenant-1',
    'storeId' => 7,
    'lines' => [[
        'lineId' => 'provider-line-1',
        'projectId' => 101,
        'projectUnique' => 'STORE-PROJECT-SKU',
    ]],
];
$command = [
    'contractVersion' => Contract::CONTRACT_VERSION,
    'receiptKey' => 'provider-receipt-1',
    'idempotencyKey' => 'provider-idem-1',
    'tenantId' => 'tenant-1',
    'storeId' => 7,
    'organizationNameSnapshot' => 'Org 1',
    'storeNameSnapshot' => 'Store 7',
    'sourceType' => 'writeoff',
    'sourceId' => 'provider-source-1',
    'sourceDetailId' => 'provider-detail-1',
    'businessDate' => '2026-07-29',
    'occurredAt' => 2000,
    'settledAt' => 2000,
    'recordedAt' => 2000,
];

$duplicateDefaultReason = '';
Db::startTrans();
try {
    Db::name('inventory_location')->insert([
        'tenant_id' => 'tenant-1',
        'organization_id' => 'org-1',
        'organization_path' => '/org-1/store-7',
        'organization_name_snapshot' => 'Org 1',
        'location_type' => 'STORE',
        'owner_id' => 7,
        'location_code' => 'STORE-7-DUPLICATE-DEFAULT',
        'location_name' => 'Duplicate default',
        'store_id' => 7,
        'store_name_snapshot' => 'Store 7',
        'is_default' => 1,
        'location_status' => 'ACTIVE',
        'version' => 1,
        'created_at' => 1999,
        'updated_at' => 1999,
    ]);
    $provider->lockSnapshot($providerRequest, $scope);
} catch (InventoryCompletionContractException $exception) {
    $duplicateDefaultReason = $exception->reason();
} finally {
    Db::rollback();
}
providerAssert(
    'duplicate active default locations fail closed',
    $duplicateDefaultReason === 'inventory_default_location_not_ready'
);

Db::startTrans();
try {
    $snapshot = $provider->lockSnapshot($providerRequest, $scope);
    $inventory = $snapshot['lineInventoryByLineId']['provider-line-1'];
    $stock = $snapshot['inventoryStocks'][0];
    $batch = $stock['batches'][0];
    providerAssert('stable recipe id returned', $inventory['recipeId'] === 901);
    providerAssert('locked formula hash returned', strlen($inventory['recipeFormulaHash']) === 64);
    providerAssert('merchant/project/resolved policies returned',
        $inventory['merchantDefaultPolicy'] === 'deny_shortage'
        && $inventory['productPolicyOverride'] === 'allow_shortage'
        && $inventory['policy'] === 'allow_shortage');
    providerAssert('all policy versions returned',
        $inventory['merchantDefaultPolicyVersion'] === 2
        && $inventory['productPolicyVersion'] === 6
        && $inventory['policyVersion'] > 0);
    providerAssert('explicit shortage cost cursor returned with stable id and positive version',
        $inventory['consumables'][0]['shortageCostAllocatedQuantityUnitsBefore'] === 0
        && $inventory['consumables'][0]['shortageCursorId'] === '502:901:50'
        && $inventory['consumables'][0]['shortageCursorVersion'] === 1
        && $snapshot['shortageCursorLockGate'] === Contract::SHORTAGE_CURSOR_GATE);
    providerAssert('real batch snapshot returned',
        $stock['stockId'] === '502' && $batch['batchId'] === 802 && $batch['availableQuantityUnits'] === 3);
    providerAssert('stock persistence snapshot is bound to the active default location',
        (int)$snapshot['persistenceSnapshot']['502']['locationId'] > 0);
    providerAssert('canonical inventory resource lock order returned',
        array_column($snapshot['lockedInventoryResources'], 'lockOrder') === [46, 47, 50, 55, 56]
        && array_values(array_filter(
            $snapshot['lockedInventoryResources'],
            static function (array $resource): bool {
                return $resource['kind'] === 'inventory_shortage_cursor'
                    && $resource['id'] === '502:901:50'
                    && $resource['lockedVersion'] === 1;
            }
        )) !== []);

    $completionPlan = [
        'contractVersion' => 'c2-entitlement-completion-v3',
        'linePlans' => [[
            'lineId' => 'provider-line-1',
            'source' => [
                'projectId' => 101,
                'sourceDetailId' => 777,
                'projectNameSnapshot' => 'Project 101',
            ],
            'inventory' => array_merge($inventory, [
                'costComplete' => false,
                'actualCostCents' => 300,
                'estimatedShortageCostCents' => 100,
                'consumables' => [[
                    'stockId' => '502',
                    'stockVersion' => 1,
                    'consumableId' => 201,
                    'skuId' => 303,
                    'stockUnitScale' => 0,
                    'quantityUnitsPerService' => 5,
                    'requiredQuantityUnits' => 5,
                    'actualQuantityUnits' => 3,
                    'shortageQuantityUnits' => 2,
                    'actualCostCents' => 300,
                    'estimatedShortageCostCents' => 100,
                    'shortageEstimatedUnitCostCents' => 50,
                    'shortageCostAllocatedQuantityUnitsBefore' => 0,
                    'shortageCostAllocatedQuantityUnitsAfter' => 2,
                    'shortageCursorId' => $inventory['consumables'][0]['shortageCursorId'],
                    'shortageCursorVersion' => $inventory['consumables'][0]['shortageCursorVersion'],
                    'batchAllocations' => [[
                        'batchId' => 802,
                        'batchVersion' => 1,
                        'quantityUnits' => 3,
                        'unitCostCents' => 100,
                        'costAllocatedQuantityUnitsBefore' => 0,
                        'costAllocatedQuantityUnitsAfter' => 3,
                        'actualCostCents' => 300,
                    ]],
                    'costComplete' => false,
                ]],
            ]),
        ]],
    ];
    $result = $writer->persistCompletion($command, $completionPlan, $snapshot, $scope);
    Db::commit();
} catch (\Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
providerAssert('completion receipt persisted', $result['receiptId'] > 0 && $result['costComplete'] === false);
providerAssert('only real batch deducted',
    (int)Db::name('inventory_batch')->where('id', 802)->value('available_quantity_units') === 0
    && (int)Db::name('inventory_stock')->where('id', 502)->value('available_quantity_units') === 0);
providerAssert('shortage fact persisted outside batches',
    (int)Db::name('inventory_shortage_fact')->where('receipt_id', $result['receiptId'])->value('shortage_quantity_units') === 2
    && Db::name('inventory_batch')->where('stock_id', 502)->count() === 1);
providerAssert('shortage cursor row advances exactly once with one version step',
    Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->count() === 1
    && (int)Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->value('allocated_quantity_units') === 2
    && (int)Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->value('version') === 2);
providerAssert('real batch deduction appends one movement fact',
    (int)Db::name('inventory_batch_movement_fact')
        ->where('source_type', 'completion_batch')
        ->where('source_id', (string)$result['receiptId'])
        ->where('direction', -1)
        ->sum('quantity_units') === 3);

Db::startTrans();
try {
    $replaySnapshot = $provider->lockSnapshot($providerRequest, $scope);
    $replay = $writer->persistCompletion($command, $completionPlan, $replaySnapshot, $scope);
    Db::commit();
} catch (\Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
providerAssert('same natural key replays existing result', $replay['replayed'] === true && $replay['receiptId'] === $result['receiptId']);
providerAssert('replay does not double deduct', (int)Db::name('inventory_stock')->where('id', 502)->value('available_quantity_units') === 0);
providerAssert('replay does not duplicate or re-advance shortage cursor',
    Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->count() === 1
    && (int)Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->value('allocated_quantity_units') === 2
    && (int)Db::name('inventory_shortage_cost_cursor')
        ->where('tenant_id', 'tenant-1')
        ->where('store_id', 7)
        ->where('stock_id', 502)
        ->where('recipe_id', 901)
        ->where('estimated_unit_cost_cents', 50)
        ->value('version') === 2);

$shortageId = (int)Db::name('inventory_shortage_fact')->where('receipt_id', $result['receiptId'])->value('id');
Db::startTrans();
try {
    $adjustment = $writer->recordShortageCostAdjustment([
        'contractVersion' => Contract::CONTRACT_VERSION,
        'adjustmentKey' => 'provider-adjustment-1',
        'tenantId' => 'tenant-1',
        'storeId' => 7,
        'sourceType' => 'stock_replenishment',
        'sourceId' => 'provider-replenishment-1',
        'occurredAt' => 2100,
        'recordedAt' => 2100,
    ], $shortageId, 2, 60, $scope);
    Db::commit();
} catch (\Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
providerAssert('shortage cost adjustment completes cost', $adjustment['actualCostCents'] === 120 && $adjustment['costComplete'] === true);

$reverseCommand = $command;
$reverseCommand['receiptKey'] = 'provider-reversal-1';
$reverseCommand['idempotencyKey'] = 'provider-reversal-idem-1';
$reverseCommand['sourceType'] = 'writeoff_reversal';
$reverseCommand['sourceId'] = 'provider-reversal-source-1';
$reverseCommand['sourceDetailId'] = 'provider-reversal-detail-1';
$reverseCommand['occurredAt'] = 2200;
$reverseCommand['settledAt'] = 2200;
$reverseCommand['recordedAt'] = 2200;
Db::startTrans();
try {
    $reversal = $writer->reverseCompletion($reverseCommand, 'provider-receipt-1', $scope);
    Db::commit();
} catch (\Throwable $throwable) {
    Db::rollback();
    throw $throwable;
}
providerAssert('reversal restores original real batch quantity',
    (int)Db::name('inventory_batch')->where('id', 802)->value('available_quantity_units') === 3
    && (int)Db::name('inventory_stock')->where('id', 502)->value('available_quantity_units') === 3);
$batchNet = (int)Db::name('inventory_batch_consumption_fact')
    ->whereIn('receipt_id', [$result['receiptId'], $reversal['receiptId']])
    ->sum(Db::raw("IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))"));
$shortageNet = (int)Db::name('inventory_shortage_fact')
    ->where('id', $shortageId)
    ->whereOr('reversal_of', $shortageId)
    ->sum(Db::raw("IF(direction=1,shortage_quantity_units,-CAST(shortage_quantity_units AS SIGNED))"));
$adjustmentNet = (int)Db::name('inventory_shortage_cost_adjustment')
    ->where('shortage_fact_id', $shortageId)
    ->sum(Db::raw("IF(direction=1,actual_cost_cents,-CAST(actual_cost_cents AS SIGNED))"));
providerAssert('reversal facts net to zero without overwriting history', $batchNet === 0 && $shortageNet === 0 && $adjustmentNet === 0);
$movementNet = (int)Db::name('inventory_batch_movement_fact')
    ->where('batch_id', 802)
    ->where('source_type', 'completion_batch')
    ->sum(Db::raw("IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))"));
providerAssert('reversal movement links and nets the original deduction',
    $movementNet === 0
    && (int)Db::name('inventory_batch_movement_fact')
        ->where('batch_id', 802)
        ->where('source_type', 'completion_batch')
        ->where('direction', 1)
        ->value('reversal_of') > 0);

echo "INVENTORY_PROVIDER_INTEGRATION_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
