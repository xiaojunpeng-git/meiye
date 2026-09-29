<?php
declare(strict_types=1);

/**
 * Local MySQL workflow test. It deliberately retains all TEST-UQ rows and
 * verifies a duplicate settings submit returns the first receipt/result.
 */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\query\InventoryUnifiedQueryCommandServices;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\query\InventoryBatchStockQueryContract;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\product\inventory\query\InventoryStoreUnifiedQueryContextFactory;
use app\services\query\UnifiedQueryRuntime;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'cache.driver' => 'file', 'CACHE_DRIVER' => 'file',
    'database.type' => 'mysql', 'DATABASE_TYPE' => 'mysql',
    'database.hostname' => getenv('DB_HOST') ?: 'mysql', 'DATABASE_HOSTNAME' => getenv('DB_HOST') ?: 'mysql',
    'database.hostport' => getenv('DB_PORT') ?: '3306', 'DATABASE_HOSTPORT' => getenv('DB_PORT') ?: '3306',
    'database.database' => getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730',
    'DATABASE_DATABASE' => getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_uq_command_test_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');

function uqCommandAssert(string $name, bool $condition): void
{
    global $uqCommandFailures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) $uqCommandFailures++;
}

$uqCommandFailures = 0;
$storeId = 99101;
$operatorId = 991001;
foreach ([
    ['system_store', ['id' => $storeId, 'name' => 'TEST-UQ库存门店', 'is_del' => 0, 'is_show' => 1]],
    ['system_store_staff', ['id' => $operatorId, 'store_id' => $storeId, 'status' => 1, 'is_del' => 0]],
    ['organization', ['id' => $storeId, 'pid' => 99000, 'name' => 'TEST-UQ库存组织', 'is_del' => 0]],
    ['organization_store', ['store_id' => $storeId, 'org_id' => $storeId]],
] as [$table, $record]) {
    $query = Db::name($table);
    $table === 'organization_store' ? $query->where('store_id', $storeId) : $query->where('id', $record['id']);
    if (!$query->find()) Db::name($table)->insert($record);
}
if (!Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', $storeId)->where('is_default', 1)->find()) {
    Db::name('inventory_location')->insert([
        'tenant_id' => '0', 'organization_id' => (string)$storeId, 'organization_path' => '/99000/' . $storeId . '/',
        'organization_name_snapshot' => 'TEST-UQ库存组织', 'location_type' => 'STORE', 'owner_id' => $storeId,
        'location_code' => 'STORE-' . $storeId, 'location_name' => 'TEST-UQ默认仓', 'store_id' => $storeId,
        'store_name_snapshot' => 'TEST-UQ库存门店', 'is_default' => 1, 'location_status' => 'ACTIVE',
        'version' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
}

$command = [
    'action' => 'save-unified-query-settings',
    // The previous retained -001 receipt predates scope-bound request hashes.
    // Keep it for audit; this revision verifies the upgraded rule with -002.
    'idempotencyKey' => 'TEST-uq-settings-20260730-002',
    'pageCode' => 'inventory_batch_stock',
    'settings' => [
        'visibleFields' => ['product_name', 'sku_name', 'batch_no', 'expire_date', 'batch_balance_quantity'],
        'quickFields' => ['product_name', 'barcode'], 'sorts' => [], 'filters' => [],
        'filterRelation' => 'all', 'groupBy' => [], 'summaries' => [],
        'schemaVersion' => UnifiedQueryRuntime::runtime()['registry']->schemaVersion(),
    ],
];
$service = new InventoryUnifiedQueryCommandServices();
try {
    $runtime = UnifiedQueryRuntime::runtime();
    $context = (new InventoryStoreUnifiedQueryContextFactory($runtime['contextFactory']))->make($storeId, $operatorId, [], '2026-07-30');
    $query = $runtime['providers']->resolve(InventoryBatchStockQueryContract::PAGE_CODE)->query($context, [
        'pageCode' => InventoryBatchStockQueryContract::PAGE_CODE,
        'page' => 1, 'limit' => 20, 'keyword' => '', 'filters' => [], 'topFilters' => [],
        'sorts' => [], 'groupBy' => [], 'summaries' => [], 'visibleFields' => [],
    ]);
    $capability = $runtime['capabilities']->build(
        $context,
        InventoryBatchStockQueryContract::PAGE_CODE,
        1,
        (int)$context['data_as_of']
    );
    uqCommandAssert('inventory UQ provider keeps the authenticated store warehouse scope on a real local database',
        is_array($query['records'] ?? null)
        && (int)($query['page'] ?? 0) === 1
        && (string)($query['queryCutoffDate'] ?? '') === '2026-07-30');
    uqCommandAssert('inventory UQ capability exposes the approved setting, field and export controls',
        ($capability['enabled'] ?? false) === true
        && ($capability['permissions']['renameFields'] ?? false) === true
        && ($capability['permissions']['createCustomField'] ?? false) === true
        && ($capability['export']['enabled'] ?? false) === true);
    $first = $service->dispatch($storeId, $operatorId, [], $command);
    $second = $service->dispatch($storeId, $operatorId, [], $command);
    $receiptCount = Db::name('inventory_unified_query_command_receipt')
        ->where('store_id', $storeId)->where('operator_id', $operatorId)
        ->where('idempotency_key', $command['idempotencyKey'])->count();
    $preference = Db::name('unified_query_preference')
        ->where('tenant_id', '0')->where('account_id', $operatorId)
        ->where('page_code', 'inventory_batch_stock')->find();
    uqCommandAssert('inventory UQ command saves the account page setting once',
        ($first['result']['status'] ?? '') === 'success'
        && (int)($first['result']['data']['settingsVersion'] ?? 0) > 1
        && (int)($preference['current_version'] ?? 0) > 1);
    uqCommandAssert('duplicate inventory UQ command replays its immutable first result',
        ($second['result']['status'] ?? '') === 'success'
        && (int)($second['result']['data']['settingsVersion'] ?? 0) === (int)($first['result']['data']['settingsVersion'] ?? 0)
        && (string)($second['idempotencyKey'] ?? '') === (string)($first['idempotencyKey'] ?? '')
        && (int)$receiptCount === 1);
    $changed = $command;
    $changed['settings']['quickFields'] = ['batch_no'];
    $conflict = '';
    try { $service->dispatch($storeId, $operatorId, [], $changed); }
    catch (\Throwable $exception) { $conflict = $exception->getMessage(); }
    uqCommandAssert('same inventory UQ idempotency key rejects a changed request',
        $conflict === '本次查询操作与已提交请求不一致，请刷新后重新操作。');

    $platformRoleId = 30000;
    $platformOperatorId = 9910;
    // eb_system_admin.id is unsigned SMALLINT on supported legacy databases.
    $scopedPlatformAdminId = 30001;
    $scopedOrgAdminId = 30002;
    $menuRows = [
        [30010, 'inventory-v3-platform-batch-view'],
        [30011, 'inventory-v3-platform-batch-cost'],
        [30012, 'inventory-v3-platform-batch-export'],
        [30013, 'inventory-v3-platform-query-manage'],
    ];
    foreach ($menuRows as [$menuId, $uniqueAuth]) {
        if (!Db::name('system_menus')->where('id', $menuId)->find()) {
            Db::name('system_menus')->insert([
                'id' => $menuId, 'pid' => 0, 'type' => 1, 'icon' => '', 'menu_name' => 'TEST-UQ-' . $uniqueAuth,
                'module' => '', 'controller' => '', 'action' => '',
                'api_url' => '', 'methods' => 'GET', 'params' => '[]', 'sort' => 0,
                'is_show' => 0, 'is_show_path' => 0, 'access' => 1, 'menu_path' => '', 'path' => '',
                'auth_type' => 1, 'header' => '', 'is_header' => 0, 'unique_auth' => $uniqueAuth, 'is_del' => 0,
            ]);
        }
    }
    Db::name('system_role')->where('id', $platformRoleId)->delete();
    Db::name('system_role')->insert([
        'id' => $platformRoleId, 'type' => 0, 'relation_id' => 0, 'role_name' => 'TEST-UQ平台库存只读',
        'remark' => '', 'rules' => '30010', 'cashier_rules' => '', 'mall_rules' => '', 'level' => 1, 'status' => 1, 'add_time' => time(),
    ]);
    Db::name('system_admin')->where('id', $scopedPlatformAdminId)->delete();
    Db::name('system_admin')->insert([
        'id' => $scopedPlatformAdminId, 'account' => 'TEST-UQ-PLATFORM-SCOPED', 'admin_type' => 0,
        'employee_id' => 0, 'uid' => 0, 'relation_id' => 0, 'head_pic' => '', 'pwd' => '',
        'real_name' => 'TEST-UQ', 'phone' => '', 'roles' => (string)$platformRoleId,
        'last_ip' => '', 'last_time' => 0, 'login_count' => 0,
        'level' => 1, 'status' => 1, 'is_way' => 0, 'is_del' => 0, 'add_time' => time(),
    ]);
    Db::name('organization_admin')->where('id', $scopedOrgAdminId)->delete();
    Db::name('organization_admin')->insert([
        'id' => $scopedOrgAdminId, 'employee_id' => 0, 'org_id' => $storeId, 'name' => 'TEST-UQ范围管理员',
        'phone' => '', 'admin_id' => $scopedPlatformAdminId, 'uid' => 0, 'legacy_agent_id' => 0, 'is_del' => 0, 'scope_mode' => 'inherit',
        'add_time' => time(), 'update_time' => time(),
    ]);
    $scopedAccess = (new InventoryPlatformAccessPolicy())->resolveByAdminId($scopedPlatformAdminId);
    uqCommandAssert('non-super platform inventory access needs the menu grant and resolves only organization-authorized stores',
        empty($scopedAccess['is_super_admin'])
        && $scopedAccess['store_ids'] === [$storeId]
        && $scopedAccess['features'] === [InventoryBatchStockQueryContract::PERMISSION_VIEW, InventoryBatchStockQueryContract::PERMISSION_COST]);
    // 平台库存查看授权范围内的单价/金额不再要求另一项独立成本菜单。
    $costVisible = true;
    try { (new InventoryPlatformAccessPolicy())->assertFeature($scopedAccess, InventoryBatchStockQueryContract::PERMISSION_COST, '成本权限不足'); }
    catch (\Throwable $exception) { $costVisible = false; }
    uqCommandAssert('non-super platform inventory view includes cost within its authorized locations', $costVisible);

    if (!Db::name('system_admin')->where('id', $platformOperatorId)->find()) {
        Db::name('system_admin')->insert([
            'id' => $platformOperatorId,
            'employee_id' => 0, 'uid' => 0, 'relation_id' => 0, 'head_pic' => '', 'pwd' => '',
            'account' => 'TEST-UQ-PLATFORM-SUPER', 'real_name' => 'TEST-UQ', 'phone' => '',
            'admin_type' => 0,
            'roles' => '',
            'last_ip' => '', 'last_time' => 0, 'login_count' => 0,
            'level' => 0, 'status' => 1, 'is_way' => 0, 'is_del' => 0,
            'add_time' => time(),
        ]);
    }
    $platformFactory = new InventoryPlatformUnifiedQueryContextFactory($runtime['contextFactory']);
    $platformContext = $platformFactory->make($platformOperatorId, [], '2026-07-30');
    $authorizedLocations = (array)($platformContext['scope_dimensions']['location_id'] ?? []);
    $selectedLocationId = (int)($authorizedLocations[0] ?? 0);
    $narrowedPlatformContext = $platformFactory->make($platformOperatorId, [], '2026-07-30', $selectedLocationId);
    $platformQuery = $runtime['providers']->resolve(InventoryBatchStockQueryContract::PAGE_CODE)->query($narrowedPlatformContext, [
        'pageCode' => InventoryBatchStockQueryContract::PAGE_CODE,
        'page' => 1, 'limit' => 20, 'keyword' => '', 'filters' => [], 'topFilters' => [],
        'sorts' => [], 'groupBy' => [], 'summaries' => [], 'visibleFields' => [],
    ]);
    uqCommandAssert('platform UQ derives every active warehouse server-side and may only narrow to one of them',
        $selectedLocationId > 0
        && count($authorizedLocations) >= 1
        && (array)($narrowedPlatformContext['scope_dimensions']['location_id'] ?? []) === [(string)$selectedLocationId]
        && is_array($platformQuery['records'] ?? null));
    $platformCommand = $command;
    $platformCommand['idempotencyKey'] = 'TEST-uq-platform-20260730-001';
    $platformFirst = $service->dispatchWithContext($narrowedPlatformContext, $platformCommand);
    $platformSecond = $service->dispatchWithContext($narrowedPlatformContext, $platformCommand);
    $platformReceiptCount = Db::name('inventory_unified_query_command_receipt')
        ->where('store_id', 0)->where('operator_id', $platformOperatorId)
        ->where('idempotency_key', $platformCommand['idempotencyKey'])->count();
    uqCommandAssert('platform UQ commands retain an independent platform receipt and replay it idempotently',
        ($platformFirst['result']['status'] ?? '') === 'success'
        && ($platformSecond['result']['status'] ?? '') === 'success'
        && (int)$platformReceiptCount === 1);
    $workerContext = (new InventoryBatchStockUnifiedQueryWorkerContextResolver())->resolve([
        'origin_store_id' => 0,
        'operator_id' => $platformOperatorId,
        'tenant_id' => (string)$platformContext['tenant_id'],
    ]);
    uqCommandAssert('platform export worker rebuilds platform identity before applying its frozen warehouse scope',
        (int)($workerContext['store_id'] ?? -1) === 0
        && (array)($workerContext['scope_dimensions']['location_id'] ?? []) === $authorizedLocations);
    $invalidWarehouseRejected = false;
    try { $platformFactory->make($platformOperatorId, [], '2026-07-30', 999999999); }
    catch (\Throwable $exception) { $invalidWarehouseRejected = $exception instanceof \app\services\query\UnifiedQueryException; }
    uqCommandAssert('platform UQ rejects a browser-selected warehouse outside the server-derived scope', $invalidWarehouseRejected);
} catch (\Throwable $exception) {
    uqCommandAssert('inventory UQ command integration bootstraps successfully', false);
    echo 'ERROR ' . $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine()
        . ($exception instanceof \app\services\query\UnifiedQueryException ? ' detail=' . json_encode($exception->getDetail(), JSON_UNESCAPED_UNICODE) : '') . "\n";
}

echo "INVENTORY_UNIFIED_QUERY_COMMAND_RESULT failed={$uqCommandFailures}\n";
exit($uqCommandFailures === 0 ? 0 : 1);
