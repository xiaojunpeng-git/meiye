<?php
declare(strict_types=1);

/** Isolated fixture check: typed ownership survives login renewal and never crosses to a staff ID. */
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventoryStockCountQueryServices;
use app\services\product\inventory\InventoryStockCountServices;
use app\services\product\inventory\query\InventoryCountUnifiedQueryProvider;
use think\facade\Config;
use think\facade\Db;

if (getenv('DB_DATABASE') !== 'inventory_r41_count_actor_focus') {
    throw new RuntimeException('inventory_actor_test_database_guard');
}
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([['database.type', 'mysql'], ['DATABASE_TYPE', 'mysql'],
    ['database.hostname', getenv('DB_HOST') ?: '127.0.0.1'], ['DATABASE_HOSTNAME', getenv('DB_HOST') ?: '127.0.0.1'],
    ['database.hostport', getenv('DB_PORT') ?: '3306'], ['DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306'],
    ['database.database', 'inventory_r41_count_actor_focus'], ['DATABASE_DATABASE', 'inventory_r41_count_actor_focus'],
    ['database.username', getenv('DB_USERNAME') ?: 'root'], ['DATABASE_USERNAME', getenv('DB_USERNAME') ?: 'root'],
    ['database.password', getenv('DB_PASSWORD') ?: ''], ['DATABASE_PASSWORD', getenv('DB_PASSWORD') ?: ''],
    ['cache.driver', 'file'], ['CACHE_DRIVER', 'file']] as [$key, $value]) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_r41_actor_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
if ((string)Config::get('database.connections.mysql.database') !== 'inventory_r41_count_actor_focus') {
    throw new RuntimeException('inventory_actor_test_database_guard');
}

$service = new InventoryStockCountDraftServices();
$session = static fn(int $id): array => ['id' => $id, 'employee_id' => 990001,
    'store_id' => 99001, '_cashier_v3_organization' => 1, 'session_mode' => 'store_organization',
    'read_only' => false, 'operation_features' => ['cashier.v3.inventory.count']];
$line = ['product_id' => 990001, 'sku_id' => 990011, 'sku_unique' => 'testsku990011',
    'book_quantity' => '0', 'counted_quantity' => '1'];
$input = ['draft_id' => 0, 'expected_version' => 0, 'business_date' => '2026-09-29',
    'remark' => 'TEST-R41 typed creator', 'lines' => [$line]];
$draftId = 0;
try {
    $saved = $service->save(99001, 880001, $input, $session(880001));
    $draftId = (int)$saved['draft_id'];
    $row = Db::name('inventory_stock_count_draft')->where('id', $draftId)->find();
    if ((string)$row['operator_type'] !== 'EMPLOYEE' || (int)$row['operator_id'] !== 990001) {
        throw new RuntimeException('employee_identity_not_persisted');
    }
    if (count($service->detail(99001, 880002, $draftId, $session(880002))['lines']) !== 1) {
        throw new RuntimeException('relogin_draft_missing');
    }
    $confirmedList = (new InventoryStockCountQueryServices())->list(99001, 880002, '', 1, 20, false, $session(880002));
    if (!isset($confirmedList['list'], $confirmedList['count'])) {
        throw new RuntimeException('organization_confirmed_query_denied');
    }
    $provider = (new ReflectionClass(InventoryCountUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
    $source = new ReflectionMethod($provider, 'sourceRows');
    $source->setAccessible(true);
    $countContext = ['tenant_id' => '0', 'store_id' => 99001, 'operator_id' => 880002,
        'organization_id' => '99001', 'inventory_count_creator_id' => 990001,
        'inventory_count_operator_type' => 'EMPLOYEE',
        'scope_dimensions' => ['location_id' => [99001]], 'permissions' => []];
    $rows = $source->invoke($provider, $countContext);
    if (count(array_filter($rows, static fn(array $item): bool => (int)($item['draft_id'] ?? 0) === $draftId)) !== 1) {
        throw new RuntimeException('organization_draft_list_missing');
    }
    $countContext['inventory_count_operator_type'] = '';
    $delegatedRows = $source->invoke($provider, $countContext);
    if (count(array_filter($delegatedRows, static fn(array $item): bool => (int)($item['draft_id'] ?? 0) === $draftId)) !== 0) {
        throw new RuntimeException('delegated_draft_exposed');
    }
    // Verify the confirmation transaction will receive the same typed creator and store path.
    $scopeMethod = new ReflectionMethod(InventoryStockCountServices::class, 'scope');
    $scopeMethod->setAccessible(true);
    $confirmationScope = $scopeMethod->invoke(new InventoryStockCountServices(), 99001, 880002, $session(880002));
    if ($confirmationScope['operatorId'] !== 990001 || $confirmationScope['operatorType'] !== 'EMPLOYEE'
        || $confirmationScope['organizationPath'] !== '/99000/99001/') {
        throw new RuntimeException('organization_confirmation_scope_invalid');
    }
    try {
        $service->detail(99001, 990001, $draftId);
        throw new RuntimeException('numeric_staff_collision_exposed_draft');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'inventory_stock_count_draft_missing') throw $error;
    }
    try {
        $service->save(99001, 880003, $input, ['id' => 880003, 'store_id' => 99001,
            '_cashier_v3_delegated' => 1]);
        throw new RuntimeException('delegated_write_allowed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'inventory_stock_count_scope_denied') throw $error;
    }
    $service->delete(99001, 880002, $draftId, 1, $session(880002));
    $draftId = 0;
    echo "STOCK_COUNT_ORGANIZATION_ACTOR_INTEGRATION_OK\n";
} finally {
    // Remove only this test's unconfirmed draft from the isolated fixture database.
    if ($draftId > 0) Db::name('inventory_stock_count_draft')->where('id', $draftId)
        ->where('store_id', 99001)->where('operator_type', 'EMPLOYEE')
        ->where('document_status', 'DRAFT')->delete();
}
