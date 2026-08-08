<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryManualOutboundServices;
use app\services\product\inventory\InventoryMovementAnalyticsServices;
use app\services\product\inventory\InventoryMovementQueryServices;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
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
$envName->setValue($app, 'inventory_manual_outbound_test_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');

function ensureOutboundFixture(): void
{
    $records = [
        ['organization', ['id' => 99000, 'pid' => 0, 'name' => 'TEST-库存根组织', 'is_del' => 0]],
        ['system_store', ['id' => 99006, 'name' => 'TEST-FEFO出库门店', 'is_del' => 0, 'is_show' => 1]],
        ['system_store_staff', ['id' => 990006, 'store_id' => 99006, 'status' => 1, 'is_del' => 0]],
        ['organization', ['id' => 99006, 'pid' => 99000, 'name' => 'TEST-FEFO出库组织', 'is_del' => 0]],
        ['organization_store', ['store_id' => 99006, 'org_id' => 99006]],
        ['store_product', ['id' => 990061, 'type' => 1, 'relation_id' => 99006, 'is_del' => 0, 'is_inventory' => 1, 'store_name' => 'TEST-FEFO出库精华', 'code' => 'TEST-P-990061', 'bar_code' => '6909900100067', 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-FEFO出库精华']],
        ['store_product_attr_value', ['id' => 9900611, 'product_id' => 990061, 'type' => 0, 'unique' => 'testsku9', 'suk' => '30ml', 'bar_code' => '6909900100067', 'code' => 'TEST-S-990061', 'stock_unit' => '瓶']],
    ];
    foreach ($records as [$table, $record]) {
        $keys = $table === 'organization_store' ? ['store_id'] : ['id'];
        $query = Db::name($table);
        foreach ($keys as $key) $query->where($key, $record[$key]);
        if (!$query->find()) Db::name($table)->insert($record);
    }
}

$failed = 0;
function outboundAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
function outboundReason(callable $operation): string { try { $operation(); } catch (\Throwable $exception) { return $exception->getMessage(); } return ''; }
function outboundInbound(string $key, string $batch, string $quantity, string $cost, string $expire): array {
    return ['idempotency_key' => $key, 'business_date' => '2026-07-30', 'remark' => 'TEST-FEFO入库保留数据', 'lines' => [[
        'product_id' => 990061, 'sku_id' => 9900611, 'sku_unique' => 'testsku9', 'batch_no' => $batch,
        'quantity' => $quantity, 'unit_cost' => $cost, 'manufactured_date' => '2026-07-01', 'expire_date' => $expire,
    ]]];
}
function outboundCommand(string $key, string $quantity): array {
    return ['idempotency_key' => $key, 'business_date' => '2026-07-30', 'remark' => 'TEST-FEFO出库保留数据', 'lines' => [[
        'product_id' => 990061, 'sku_id' => 9900611, 'sku_unique' => 'testsku9', 'quantity' => $quantity,
    ]]];
}

$inbound = new InventoryManualInboundServices();
$outbound = new InventoryManualOutboundServices();
$movementQuery = new InventoryMovementQueryServices();
$movementAnalytics = new InventoryMovementAnalyticsServices();
$platformWarehouses = new InventoryPlatformWarehouseServices();
ensureOutboundFixture();
$inbound->create(99006, 990006, outboundInbound('TEST-fefo-inbound-early-20260730', 'TEST-FEFO-EARLY-20260730', '4', '77.50', '2026-10-01'));
$inbound->create(99006, 990006, outboundInbound('TEST-fefo-inbound-late-20260730', 'TEST-FEFO-LATE-20260730', '6', '88.50', '2027-10-01'));
$first = $outbound->create(99006, 990006, outboundCommand('TEST-fefo-outbound-20260730', '5'));
$replay = $outbound->create(99006, 990006, outboundCommand('TEST-fefo-outbound-20260730', '5'));

$stock = Db::name('inventory_stock')->where('tenant_id', '0')->where('store_id', 99006)->where('consumable_product_id', 990061)->find();
$batches = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->order('batch_no asc')->select()->toArray();
$movements = Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', 99006)->where('source_type', 'manual_outbound')->where('source_id', 'TEST-fefo-outbound-20260730')->order('id asc')->select()->toArray();
$byBatch = []; foreach ($batches as $batch) $byBatch[(string)$batch['batch_no']] = $batch;

outboundAssert('FEFO first consumes the earliest expiry batch before the later batch',
    (int)($byBatch['TEST-FEFO-EARLY-20260730']['available_quantity_units'] ?? -1) === 0
    && (int)($byBatch['TEST-FEFO-LATE-20260730']['available_quantity_units'] ?? -1) === 5
    && (int)$stock['available_quantity_units'] === 5);
outboundAssert('one cross-batch manual outbound appends two immutable negative facts with actual batch cost',
    count($movements) === 2 && (int)$movements[0]['direction'] === -1 && (int)$movements[1]['direction'] === -1
    && (int)$movements[0]['quantity_units'] === 4 && (int)$movements[0]['unit_cost_cents'] === 7750 && (int)$movements[0]['cost_amount_cents'] === 31000
    && (int)$movements[1]['quantity_units'] === 1 && (int)$movements[1]['unit_cost_cents'] === 8850 && (int)$movements[1]['cost_amount_cents'] === 8850);
outboundAssert('same outbound idempotency key replays the original facts without a second deduction',
    $replay['lines'][0]['idempotent']
    && $first['lines'][0]['movement_fact_ids'] === $replay['lines'][0]['movement_fact_ids']
    && (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === 5);
$changed = outboundCommand('TEST-fefo-outbound-20260730', '6');
outboundAssert('a changed quantity cannot replay an outbound idempotency key',
    outboundReason(static function () use ($outbound, $changed): void { $outbound->create(99006, 990006, $changed); }) === 'inventory_manual_outbound_idempotency_conflict');
$insufficient = outboundCommand('TEST-fefo-outbound-insufficient-20260730', '6');
outboundAssert('insufficient stock creates no negative batch or partial movement',
    outboundReason(static function () use ($outbound, $insufficient): void { $outbound->create(99006, 990006, $insufficient); }) === 'inventory_manual_outbound_stock_insufficient'
    && (int)Db::name('inventory_stock')->where('id', (int)$stock['id'])->value('available_quantity_units') === 5
    && (int)Db::name('inventory_batch_movement_fact')->where('tenant_id', '0')->where('store_id', 99006)->where('source_id', 'TEST-fefo-outbound-insufficient-20260730')->count() === 0);

$outboundList = $movementQuery->list(99006, 990006, 'outbound', 'TEST-fefo-outbound-20260730', 1, 20, true);
$movementList = $movementQuery->list(99006, 990006, 'movement', 'TEST-fefo-outbound-20260730');
outboundAssert('V3 outbound list reads the same immutable source event rather than a legacy stock order',
    (int)$outboundList['count'] === 1 && count($outboundList['list']) === 1
    && (string)$outboundList['list'][0]['source_id'] === 'TEST-fefo-outbound-20260730'
    && preg_match('/^CK\d{10}$/', (string)$outboundList['list'][0]['order_sn']) === 1
    && (int)$outboundList['list'][0]['detail_count'] === 2
    && (int)$outboundList['list'][0]['cost_amount_cents'] === 39850);
outboundAssert('V3 movement list preserves both FEFO batch facts and signed quantities',
    (int)$movementList['count'] === 2 && count($movementList['list']) === 2
    && $movementList['list'][0]['movement_type_name'] === '手工出库'
    && in_array($movementList['list'][0]['quantity_display'], ['-4', '-1'], true)
    && in_array($movementList['list'][1]['quantity_display'], ['-4', '-1'], true));

$outboundStatistics = $movementAnalytics->list(99006, 990006, 'outbound', '2026-07-30', '2026-07-30', true);
outboundAssert('outbound statistics are grouped directly from immutable batch facts by business day and SKU',
    (int)$outboundStatistics['count'] >= 1
    && array_reduce($outboundStatistics['list'], static function (bool $found, array $row): bool {
        return $found || ((int)$row['product_id'] === 990061 && (int)$row['sku_id'] === 9900611
            && (int)$row['document_count'] === 1 && (int)$row['movement_count'] === 2
            && (int)$row['quantity_units'] === 5 && (int)$row['cost_amount_cents'] === 39850);
    }, false));

$platformAdmin = ['id' => 1];
$locations = $platformWarehouses->locations($platformAdmin);
$platformStock = $platformWarehouses->batchStock($platformAdmin, (int)$stock['location_id'], '2026-07-30');
outboundAssert('platform warehouse selector reads active locations and returns only the selected warehouse batch scope',
    count($locations) >= 1 && (int)$platformStock['count'] >= 1
    && array_reduce($platformStock['list'], static function (bool $valid, array $row) use ($stock): bool { return $valid && (int)$row['location_id'] === (int)$stock['location_id']; }, true));
outboundAssert('platform warehouse selection rejects a browser-supplied location outside the server scope',
    outboundReason(static function () use ($platformWarehouses, $platformAdmin): void { $platformWarehouses->batchStock($platformAdmin, 999999999, '2026-07-30'); }) === 'inventory_query_location_scope_denied');

echo "INVENTORY_MANUAL_OUTBOUND_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
