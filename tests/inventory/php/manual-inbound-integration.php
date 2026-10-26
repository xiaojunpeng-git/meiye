<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\InventoryManualInboundServices;
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
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730');
$app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730');
$databaseUsername = getenv('DB_USERNAME');
if ($databaseUsername !== false && $databaseUsername !== '') {
    $app->env->set('database.username', $databaseUsername);
    $app->env->set('DATABASE_USERNAME', $databaseUsername);
}
$databasePassword = getenv('DB_PASSWORD');
if ($databasePassword !== false) {
    $app->env->set('database.password', $databasePassword);
    $app->env->set('DATABASE_PASSWORD', $databasePassword);
}
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_manual_inbound_test_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
$effectiveDatabase = Config::get('database.connections.mysql');
if (!is_array($effectiveDatabase)
    || (string)($effectiveDatabase['hostname'] ?? '') !== (getenv('DB_HOST') ?: 'mysql')
    || (string)($effectiveDatabase['hostport'] ?? '') !== (getenv('DB_PORT') ?: '3306')
    || (string)($effectiveDatabase['database'] ?? '') !== (getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730')) {
    throw new RuntimeException('INVENTORY_TEST_DATABASE_CONFIG_MISMATCH:' . json_encode([
        'hostname' => $effectiveDatabase['hostname'] ?? '',
        'hostport' => $effectiveDatabase['hostport'] ?? '',
        'database' => $effectiveDatabase['database'] ?? '',
    ]));
}
$testConnection = Db::connect('mysql', true);
$testConnection->query('SELECT 1');

$service = new InventoryManualInboundServices();
$failed = 0;

// This suite keeps all records.  Use a dedicated second namespace so an
// earlier negative test that intentionally created duplicate defaults cannot
// turn the positive inbound regression into a destructive cleanup exercise.
$primaryStoreId = 99111;
$primaryOperatorId = 991111;
$primaryProductId = 9911101;
$primarySkuId = 99111011;
$primarySkuUnique = 'testsku99111011';
foreach ([
    ['system_store', ['id' => $primaryStoreId, 'name' => 'TEST-入库隔离门店', 'is_del' => 0, 'is_show' => 1]],
    ['system_store_staff', ['id' => $primaryOperatorId, 'store_id' => $primaryStoreId, 'status' => 1, 'is_del' => 0]],
    ['organization', ['id' => $primaryStoreId, 'pid' => 99000, 'name' => 'TEST-入库隔离组织', 'is_del' => 0]],
    ['organization_store', ['store_id' => $primaryStoreId, 'org_id' => $primaryStoreId]],
    ['store_product', ['id' => $primaryProductId, 'type' => 1, 'relation_id' => $primaryStoreId, 'is_del' => 0, 'is_inventory' => 1, 'store_name' => 'TEST-入库隔离精华R2', 'code' => 'TEST-P-9911101', 'bar_code' => '6909911100016', 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-入库隔离精华R2']],
    ['store_product_attr_value', ['id' => $primarySkuId, 'product_id' => $primaryProductId, 'type' => 0, 'unique' => $primarySkuUnique, 'suk' => '100ml', 'bar_code' => '6909911100016', 'code' => 'TEST-S-99111011', 'stock_unit' => '瓶']],
] as [$table, $record]) {
    $query = Db::name($table);
    if ($table === 'organization_store') $query->where('store_id', $record['store_id']);
    else $query->where('id', $record['id']);
    if (!$query->find()) Db::name($table)->insert($record);
}

function inboundAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failed++;
    }
}

function inboundLine(
    int $productId,
    int $skuId,
    string $skuUnique,
    string $batchNo,
    string $quantity = '12'
): array {
    return [
        'product_id' => $productId,
        'sku_id' => $skuId,
        'sku_unique' => $skuUnique,
        'batch_no' => $batchNo,
        'quantity' => $quantity,
        'unit_cost' => '77.50',
        'manufactured_date' => '2026-07-01',
        'expire_date' => '2027-07-01',
    ];
}

function inboundCommand(string $key, array $lines): array
{
    return [
        'idempotency_key' => $key,
        'business_date' => '2026-07-30',
        'remark' => 'TEST-真实入库事务验证',
        'lines' => $lines,
    ];
}

function inboundReason(callable $operation): string
{
    try {
        $operation();
    } catch (\Throwable $exception) {
        return $exception->getMessage();
    }
    return '';
}

function inboundLocation(int $id, int $storeId, int $organizationId, string $code): array
{
    return [
        'id' => $id,
        'tenant_id' => '0',
        'organization_id' => (string)$organizationId,
        'organization_path' => '/99000/' . $organizationId . '/',
        'organization_name_snapshot' => 'TEST-库存组织',
        'location_type' => 'STORE',
        'owner_id' => $storeId,
        'location_code' => $code,
        'location_name' => 'TEST-默认仓',
        'store_id' => $storeId,
        'store_name_snapshot' => 'TEST-库存门店',
        'is_default' => 1,
        'location_status' => 'ACTIVE',
        'version' => 1,
        'created_at' => 1780000000,
        'updated_at' => 1780000000,
    ];
}

$firstCommand = inboundCommand('TEST-inbound-isolated-r2-20260730-001', [
    inboundLine($primaryProductId, $primarySkuId, $primarySkuUnique, 'TEST-BATCH-ISOLATED-R2-20260730-001'),
]);
$first = $service->create($primaryStoreId, $primaryOperatorId, $firstCommand);
$location = Db::name('inventory_location')
    ->where('tenant_id', '0')
    ->where('store_id', $primaryStoreId)
    ->where('location_type', 'STORE')
    ->where('is_default', 1)
    ->where('location_status', 'ACTIVE')
    ->find();
$second = $service->create($primaryStoreId, $primaryOperatorId, $firstCommand);
$third = $service->create($primaryStoreId, $primaryOperatorId, inboundCommand('TEST-inbound-isolated-r2-20260730-002', [
    inboundLine($primaryProductId, $primarySkuId, $primarySkuUnique, 'TEST-BATCH-ISOLATED-R2-20260730-002', '2'),
]));
$stock = Db::name('inventory_stock')
    ->where('tenant_id', '0')
    ->where('store_id', $primaryStoreId)
    ->where('consumable_product_id', $primaryProductId)
    ->find();
$batches = Db::name('inventory_batch')->where('stock_id', (int)($stock['id'] ?? 0))->select()->toArray();
$facts = Db::name('inventory_batch_movement_fact')
    ->where('tenant_id', '0')
    ->where('store_id', $primaryStoreId)
    ->where('source_type', 'manual_inbound')
    ->select()
    ->toArray();

inboundAssert('first inbound creates exactly one active default store location',
    count(Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->where('is_default', 1)->where('location_status', 'ACTIVE')->select()->toArray()) === 1
    && (string)($location['location_code'] ?? '') === 'STORE-' . $primaryStoreId);
inboundAssert('created default location snapshots the current server scope',
    (string)($location['tenant_id'] ?? '') === CashierV3ScopeResolver::TENANT_SCOPE_ID
    && (string)($location['organization_id'] ?? '') === (string)$primaryStoreId
    && (string)($location['organization_path'] ?? '') === '/99000/' . $primaryStoreId . '/'
    && (int)($location['owner_id'] ?? 0) === $primaryStoreId
    && (int)($location['version'] ?? 0) === 1);
inboundAssert('same idempotency key returns the original immutable movement',
    $second['lines'][0]['idempotent']
    && (int)$first['lines'][0]['movement_fact_id'] === (int)$second['lines'][0]['movement_fact_id']);
inboundAssert('existing unique default location is reused for a later inbound',
    count(Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->where('is_default', 1)->where('location_status', 'ACTIVE')->select()->toArray()) === 1
    && (int)($stock['location_id'] ?? 0) === (int)($location['id'] ?? 0));
inboundAssert('stock, batches and immutable movement facts are appended once',
    (int)($stock['available_quantity_units'] ?? 0) === 14
    && count($batches) === 2
    && count($facts) === 2
    && (int)($facts[0]['direction'] ?? 0) === 1
    && count(array_filter($batches, static function (array $batch): bool {
        return (string)($batch['data_quality'] ?? '') !== 'COMPLETE';
    })) === 0);

$changedSku = $firstCommand;
$changedSku['lines'][0]['product_id'] = 990099;
$changedSku['lines'][0]['sku_id'] = 9900991;
$changedSku['lines'][0]['sku_unique'] = 'changed-sku';
$changedBatch = $firstCommand;
$changedBatch['lines'][0]['batch_no'] = 'TEST-BATCH-CHANGED-R2';
$changedQuantity = $firstCommand;
$changedQuantity['lines'][0]['quantity'] = '13';
$changedCost = $firstCommand;
$changedCost['lines'][0]['unit_cost'] = '78.50';
$changedBusinessDate = $firstCommand;
$changedBusinessDate['business_date'] = '2026-07-31';
$changedReplayReasons = [];
foreach ([$changedSku, $changedBatch, $changedQuantity, $changedCost, $changedBusinessDate] as $changedCommand) {
    $changedReplayReasons[] = inboundReason(static function () use ($service, $changedCommand, $primaryStoreId, $primaryOperatorId): void {
        $service->create($primaryStoreId, $primaryOperatorId, $changedCommand);
    });
}
inboundAssert('changed product SKU batch quantity cost or business date cannot replay an inbound key',
    $changedReplayReasons === array_fill(0, 5, 'inventory_manual_inbound_idempotency_conflict')
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->value('available_quantity_units') === 14
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->count() === 2);

Db::name('inventory_batch')
    ->where('stock_id', (int)$stock['id'])
    ->where('batch_no', 'TEST-BATCH-ISOLATED-R2-20260730-001')
    ->update(['batch_status' => 'CLOSED']);
$closedBatchReason = inboundReason(static function () use ($service, $primaryStoreId, $primaryOperatorId, $primaryProductId, $primarySkuId, $primarySkuUnique): void {
    $service->create($primaryStoreId, $primaryOperatorId, inboundCommand('TEST-inbound-isolated-r2-20260730-closed', [
        inboundLine($primaryProductId, $primarySkuId, $primarySkuUnique, 'TEST-BATCH-ISOLATED-R2-20260730-001', '1'),
    ]));
});
inboundAssert('closed batches cannot receive a new inbound quantity',
    $closedBatchReason === 'inventory_manual_inbound_batch_not_active'
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->value('available_quantity_units') === 14
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->count() === 2);

foreach ([inboundLocation(990201, 99002, 99002, 'TEST-DUP-DEFAULT-A'), inboundLocation(990202, 99002, 99002, 'TEST-DUP-DEFAULT-B')] as $locationFixture) {
    if (!Db::name('inventory_location')->where('id', (int)$locationFixture['id'])->find()) Db::name('inventory_location')->insert($locationFixture);
}
$duplicateReason = inboundReason(static function () use ($service): void {
    $service->create(99002, 990002, inboundCommand('TEST-inbound-20260730-003', [
        inboundLine(990001, 990011, 'testsku990011', 'TEST-BATCH-20260730-003'),
    ]));
});
inboundAssert('multiple active defaults fail closed before any inbound write',
    $duplicateReason === 'inventory_manual_inbound_default_location_ambiguous'
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', 99002)->count() === 0
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', 99002)->count() === 0);

$operatorReason = inboundReason(static function () use ($service, $primaryStoreId, $primaryProductId, $primarySkuId, $primarySkuUnique): void {
    $service->create($primaryStoreId, 991112, inboundCommand('TEST-inbound-isolated-r2-20260730-004', [
        inboundLine($primaryProductId, $primarySkuId, $primarySkuUnique, 'TEST-BATCH-ISOLATED-R2-20260730-004'),
    ]));
});
inboundAssert('operator assigned to another store is rejected by server scope',
    $operatorReason === 'inventory_manual_inbound_operator_scope_denied'
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->count() === 2);

$crossStoreIdempotencyReason = inboundReason(static function () use ($service, $firstCommand): void {
    $service->create(99005, 990005, $firstCommand);
});
inboundAssert('a reused idempotency key cannot cross a different store scope',
    $crossStoreIdempotencyReason === 'inventory_manual_inbound_idempotency_conflict'
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99005)->count() === 0
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', 99005)->count() === 0);

$collision = inboundLocation(990203, 99002, 99002, 'STORE-99003');
$collision['is_default'] = 0;
if (!Db::name('inventory_location')->where('id', (int)$collision['id'])->find()) Db::name('inventory_location')->insert($collision);
$collisionReason = inboundReason(static function () use ($service): void {
    $service->create(99003, 990003, inboundCommand('TEST-inbound-20260730-005', [
        inboundLine(990001, 990011, 'testsku990011', 'TEST-BATCH-20260730-005'),
    ]));
});
inboundAssert('deterministic location-code collision with another scope fails closed',
    $collisionReason === 'inventory_manual_inbound_default_location_conflict'
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99003)->count() === 0
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', 99003)->count() === 0);

$missingDates = inboundCommand('TEST-inbound-20260730-007', [
    inboundLine(990041, 9900411, 'testsku9900411', 'TEST-BATCH-20260730-MISSING', '1'),
]);
$missingDates['lines'][0]['expire_date'] = '';
$missingDatesReason = inboundReason(static function () use ($service, $missingDates): void {
    $service->create(99004, 990004, $missingDates);
});
inboundAssert('new manual inbound requires both production and expiry dates',
    $missingDatesReason === 'inventory_manual_inbound_dates_required'
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99004)->count() === 0);

$rollbackReason = inboundReason(static function () use ($service): void {
    $service->create(99004, 990004, inboundCommand('TEST-inbound-20260730-006', [
        inboundLine(990041, 9900411, 'testsku9900411', 'TEST-BATCH-20260730-006-A', '3'),
        inboundLine(999999, 9999991, 'missing-sku', 'TEST-BATCH-20260730-006-B', '1'),
    ]));
});
inboundAssert('later-line failure rolls back initialized location, stock, batch and fact',
    $rollbackReason === 'inventory_manual_inbound_sku_not_found'
    && (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', 99004)->count() === 0
    && (int)Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', 99004)->count() === 0
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', 99004)->count() === 0);

$scopeRepairLocationId = (int)$location['id'];
$scopeRepairStockBefore = (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units');
$scopeRepairFactCountBefore = (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->count();
Db::name('inventory_location')->where('id', $scopeRepairLocationId)->update([
    'organization_id' => '99000',
    'organization_path' => '/99000/obsolete/',
    'organization_name_snapshot' => 'TEST-过期组织',
    'store_name_snapshot' => 'TEST-过期门店',
]);
Db::name('inventory_stock')->where('id', (int)$stock['id'])->update([
    'organization_id' => '99000',
    'organization_path' => '/99000/obsolete/',
]);
$scopeRepairResult = $service->create($primaryStoreId, $primaryOperatorId, inboundCommand('TEST-inbound-scope-repair-20260730-001', [
    inboundLine($primaryProductId, $primarySkuId, $primarySkuUnique, 'TEST-BATCH-SCOPE-REPAIR-20260730-001', '1'),
]));
$scopeRepairedLocation = Db::name('inventory_location')->where('id', $scopeRepairLocationId)->find();
$scopeRepairedStock = Db::name('inventory_stock')->where('id', (int)$stock['id'])->find();
inboundAssert('an existing default store location with stale organization snapshots is repaired before inbound writes',
    (string)($scopeRepairedLocation['organization_id'] ?? '') === (string)$primaryStoreId
    && (string)($scopeRepairedLocation['organization_path'] ?? '') === '/99000/' . $primaryStoreId . '/'
    && (string)($scopeRepairedLocation['organization_name_snapshot'] ?? '') === 'TEST-入库隔离组织'
    && (string)($scopeRepairedLocation['store_name_snapshot'] ?? '') === 'TEST-入库隔离门店'
    && (int)($scopeRepairedLocation['version'] ?? 0) === 2
    && (string)($scopeRepairedStock['organization_id'] ?? '') === (string)$primaryStoreId
    && (string)($scopeRepairedStock['organization_path'] ?? '') === '/99000/' . $primaryStoreId . '/'
    && (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === $scopeRepairStockBefore + 1
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', $primaryStoreId)->count() === $scopeRepairFactCountBefore + 1
    && (int)($scopeRepairResult['lines'][0]['idempotent'] ?? 1) === 0
);

$historicalFactId = (int)$first['lines'][0]['movement_fact_id'];
Db::name('inventory_batch_movement_fact')->where('id', $historicalFactId)->update([
    'organization_id' => '99000',
    'organization_path' => '/99000/legacy/',
]);
$historicalReplay = $service->create($primaryStoreId, $primaryOperatorId, $firstCommand);
$historicalFact = Db::name('inventory_batch_movement_fact')->where('id', $historicalFactId)->find();
inboundAssert('an idempotent replay accepts an immutable historical organization snapshot after a store move',
    (int)($historicalReplay['lines'][0]['idempotent'] ?? 0) === 1
    && (int)($historicalReplay['lines'][0]['movement_fact_id'] ?? 0) === $historicalFactId
    && (string)($historicalFact['organization_path'] ?? '') === '/99000/legacy/'
);

echo "INVENTORY_MANUAL_INBOUND_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
