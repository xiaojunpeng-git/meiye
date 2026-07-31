<?php
declare(strict_types=1);

$backend = '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\query\InventoryBatchStockDataScope;
use app\services\product\inventory\query\InventoryBatchStockQueryContract as Contract;
use app\services\product\inventory\query\InventoryBatchStockQueryProvider;
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
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'inventory_batch_query');
$app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'inventory_batch_query');
$app->env->set('database.username', getenv('DB_USERNAME') ?: 'root');
$app->env->set('DATABASE_USERNAME', getenv('DB_USERNAME') ?: 'root');
$app->env->set('database.password', getenv('DB_PASSWORD') ?: '');
$app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD') ?: '');
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'inventory_batch_query_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$passed = 0;
$failed = 0;
function batchProviderAssert(string $name, bool $condition, string $detail = ''): void
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

$provider = new InventoryBatchStockQueryProvider();
$definition = $provider->pageDefinition();
batchProviderAssert('provider page code is stable', $provider->pageCode() === Contract::PAGE_CODE);
batchProviderAssert('provider keyword fields are inventory-owned',
    $definition['keywordFields'] === ['product_name', 'sku_name', 'product_code', 'barcode', 'batch_no']);

$scope = new InventoryBatchStockDataScope('tenant-1', [1], [Contract::PERMISSION_VIEW]);
$openingRows = $provider->sourceRows([
    'queryCutoffDate' => '2026-07-29',
    'locationIds' => [],
    'includeZero' => false,
], $scope);
batchProviderAssert('opening cutoff reconstructs batch quantity',
    count($openingRows) === 1 && $openingRows[0]['batch_balance_quantity'] === '10');
batchProviderAssert('cost is hidden without permission', $openingRows[0]['batch_unit_cost'] === null);
batchProviderAssert('historical snapshots are projected',
    $openingRows[0]['product_name'] === '海藻修护面膜'
    && $openingRows[0]['batch_no'] === 'BATCH-001'
    && $openingRows[0]['received_date'] === '2026-07-20',
    json_encode($openingRows[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$costScope = new InventoryBatchStockDataScope(
    'tenant-1',
    [1, 2],
    [Contract::PERMISSION_VIEW, Contract::PERMISSION_COST]
);
$currentRows = $provider->sourceRows([
    'queryCutoffDate' => '2026-07-30',
    'locationIds' => [1],
    'includeZero' => false,
], $costScope);
batchProviderAssert('later cutoff includes settled movement',
    count($currentRows) === 1 && $currentRows[0]['batch_balance_quantity'] === '8');
batchProviderAssert('cost permission returns exact unit cost', $currentRows[0]['batch_unit_cost'] === '77.00');
batchProviderAssert('inventory amount is calculated server-side from batch units and cost',
    $currentRows[0]['inventory_amount'] === '616.00');
batchProviderAssert('expired state is derived from cutoff without rewriting batch',
    $currentRows[0]['quality_status'] === '过期');
batchProviderAssert('requested location narrows server scope', $currentRows[0]['location_id'] === 1);

$scopeDenied = false;
try {
    $provider->sourceRows([
        'queryCutoffDate' => '2026-07-30',
        'locationIds' => [2],
        'includeZero' => false,
    ], $scope);
} catch (\Throwable $throwable) {
    $scopeDenied = $throwable->getMessage() === 'inventory_query_location_scope_denied';
}
batchProviderAssert('provider rejects location widening before query', $scopeDenied);

echo "INVENTORY_BATCH_QUERY_PROVIDER_RESULT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
