<?php
declare(strict_types=1);

$backend = is_dir('/workspace/后端代码') ? '/workspace/后端代码' : '/var/www/html';
require $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementCanonicalizer;
use app\services\cashier\v3\settlement\CashierV3CheckoutSettlementContractException;
use app\services\cashier\v3\settlement\CashierV3SaleInventorySettlementServices;
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
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'sale_inventory');
$app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'sale_inventory');
$app->env->set('database.username', getenv('DB_USERNAME') ?: 'root');
$app->env->set('DATABASE_USERNAME', getenv('DB_USERNAME') ?: 'root');
$app->env->set('database.password', getenv('DB_PASSWORD') ?: '');
$app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD') ?: '');
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'sale_inventory_test_skip_reload_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
Db::connect('mysql', true)->query('SELECT 1');

$passed = 0;
$failed = 0;
function saleInventoryDbOk(string $name, bool $condition, string $detail = ''): void
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

function saleInventoryId(string $prefix, string $seed): string
{
    return $prefix . '-' . substr(hash('sha256', $seed), 0, 40);
}

function saleInventoryScope(): array
{
    $operator = new CashierV3OperatorScope(7, 99, 'org-1', 'tenant-1');
    $scope = new CashierV3DataScopeContext(
        99,
        99,
        7,
        'tenant-1',
        'org-1',
        [7],
        CashierV3DataScopeContext::MODE_STORES,
        ['mode' => 'stores', 'store_ids' => [7]],
        false,
        '',
        'roles:' . str_repeat('1', 32),
        ['cashier.v3.cashier'],
        ['account' => 'inventory-tester']
    );
    return [$operator, $scope];
}

function saleInventoryRequest(string $requestId): array
{
    return [
        'tenant_id' => 'tenant-1',
        'organization_id' => 'org-1',
        'organization_path' => '/org-1/store-7',
        'store_id' => 7,
        'operator_id' => 99,
        'request_id' => $requestId,
        'business_date' => '2026-07-30',
    ];
}

function saleInventoryOrder(string $requestId, string $orderId, string $commandKey): array
{
    return [
        'order_id' => $orderId,
        'checkout_request_id' => $requestId,
        'command_idempotency_key' => $commandKey,
        'tenant_id' => 'tenant-1',
        'organization_id' => 'org-1',
        'organization_path_snapshot' => '/org-1/store-7',
        'organization_name_snapshot' => '测试组织',
        'store_id' => 7,
        'store_name_snapshot' => '测试门店',
        'operator_id' => 99,
        'business_date' => '2026-07-30',
        'occurred_at' => 1785369600,
        'settled_at' => 1785369601,
        'recorded_at' => 1785369602,
    ];
}

function saleInventoryLine(
    string $orderId,
    int $productId,
    int $catalogSkuId,
    string $unique,
    int $quantity,
    int $lineNo
): array
{
    return [
        'order_line_id' => saleInventoryId('CSL', $orderId . ':' . $lineNo),
        'order_id' => $orderId,
        'tenant_id' => 'tenant-1',
        'store_id' => 7,
        'item_type' => 'product',
        'item_id' => (string)$productId,
        'catalog_sku_id' => $catalogSkuId,
        'item_code_snapshot' => $unique,
        'item_name_snapshot' => '批次库存产品' . $lineNo,
        'quantity' => $quantity,
        'line_no' => $lineNo,
    ];
}

$provider = new CashierV3SaleInventorySettlementServices();
[$operator, $dataScope] = saleInventoryScope();
$requestId = saleInventoryId('CKR', 'success');
$orderId = saleInventoryId('CSO', 'success');
$commandKey = 'CHECKOUT-00000000-0000-4000-8000-000000000001';
$request = saleInventoryRequest($requestId);
$order = saleInventoryOrder($requestId, $orderId, $commandKey);
$lines = [saleInventoryLine($orderId, 501, 601, 'SKU-501', 3, 1)];

Db::startTrans();
try {
    $plan = $provider->planInTx($request, $order, $lines, $operator, $dataScope);
    $result = $provider->persistInTx($plan);
    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}

$stock = Db::name('inventory_stock')->where('id', 701)->find();
$batchOne = Db::name('inventory_batch')->where('id', 801)->find();
$batchTwo = Db::name('inventory_batch')->where('id', 802)->find();
saleInventoryDbOk('successful sale deducts FEFO real batches and records one receipt',
    (int)$stock['available_quantity_units'] === 2
    && (int)$batchOne['available_quantity_units'] === 0
    && (int)$batchTwo['available_quantity_units'] === 2
    && (int)Db::name('cashier_v3_sale_inventory_receipt')->count() === 1
    && (int)Db::name('inventory_batch_movement_fact')->where('source_type', 'cashier_sale')->count() === 2);
saleInventoryDbOk('cost cursor and amount are persisted with the real batch allocations',
    (int)$batchOne['cost_allocated_quantity_units'] === 1
    && (int)$batchTwo['cost_allocated_quantity_units'] === 2
    && (int)$result['actualCostCents'] === 700
    && count($provider->eventInputs($result, [
        'source_type' => 'submit-checkout', 'source_id' => $requestId,
        'member_id' => 0, 'business_date' => '2026-07-30',
        'occurred_at' => 1785369600, 'settled_at' => 1785369601,
        'recorded_at' => 1785369602, 'store_name_snapshot' => '测试门店',
    ])) === 2);

Db::startTrans();
try {
    $replayed = $provider->persistInTx($plan);
    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}
saleInventoryDbOk('same plan replay returns its receipt without a second deduction',
    !empty($replayed['replayed'])
    && (int)Db::name('inventory_stock')->where('id', 701)->value('available_quantity_units') === 2
    && (int)Db::name('inventory_batch_movement_fact')->where('source_type', 'cashier_sale')->count() === 2);

$conflictReason = '';
Db::startTrans();
try {
    $driftedPlan = $plan;
    $driftedPlan['actualCostCents']++;
    $fingerprint = new ReflectionMethod(CashierV3SaleInventorySettlementServices::class, 'fingerprintInput');
    $fingerprint->setAccessible(true);
    $driftedPlan['planFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint(
        $fingerprint->invoke($provider, $driftedPlan)
    );
    $provider->persistInTx($driftedPlan);
    Db::commit();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $conflictReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('same receipt with a changed post-sale plan is an idempotency conflict',
    $conflictReason === 'sale_inventory_idempotency_conflict');

$rollbackRequestId = saleInventoryId('CKR', 'rollback');
$rollbackOrderId = saleInventoryId('CSO', 'rollback');
$rollbackOrder = saleInventoryOrder(
    $rollbackRequestId,
    $rollbackOrderId,
    'CHECKOUT-00000000-0000-4000-8000-000000000003'
);
$beforeStock = (int)Db::name('inventory_stock')->where('id', 701)->value('available_quantity_units');
$beforeMovements = (int)Db::name('inventory_batch_movement_fact')->count();
Db::startTrans();
try {
    $tampered = $provider->planInTx(
        saleInventoryRequest($rollbackRequestId),
        $rollbackOrder,
        [saleInventoryLine($rollbackOrderId, 501, 601, 'SKU-501', 1, 1)],
        $operator,
        $dataScope
    );
    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}
$tampered['batchActions'][0]['expectedVersion'] = 999;
$tampered['planFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint(
    $fingerprint->invoke($provider, $tampered)
);
$rollbackReason = '';
Db::startTrans();
try {
    $provider->persistInTx($tampered);
    Db::commit();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $rollbackReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('failure after stock stage rolls the outer transaction back',
    $rollbackReason === 'sale_inventory_batch_changed'
    && (int)Db::name('inventory_stock')->where('id', 701)->value('available_quantity_units') === $beforeStock
    && (int)Db::name('inventory_batch_movement_fact')->count() === $beforeMovements);

$shortRequestId = saleInventoryId('CKR', 'shortage');
$shortOrderId = saleInventoryId('CSO', 'shortage');
$shortOrder = saleInventoryOrder(
    $shortRequestId,
    $shortOrderId,
    'CHECKOUT-00000000-0000-4000-8000-000000000002'
);
$shortLines = [saleInventoryLine($shortOrderId, 502, 602, 'SKU-502', 2, 1)];
$shortageReason = '';
$shortStockBefore = (int)Db::name('inventory_stock')->where('id', 702)->value('available_quantity_units');
Db::startTrans();
try {
    $provider->planInTx(
        saleInventoryRequest($shortRequestId),
        $shortOrder,
        $shortLines,
        $operator,
        $dataScope
    );
    Db::commit();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $shortageReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('insufficient inventory blocks the whole sale before any mutation',
    $shortageReason === 'sale_inventory_shortage_denied'
    && (int)Db::name('inventory_stock')->where('id', 702)->value('available_quantity_units') === $shortStockBefore
    && (int)Db::name('cashier_v3_sale_inventory_receipt')->count() === 1);

$zeroRequestId = saleInventoryId('CKR', 'zero-batches');
$zeroOrderId = saleInventoryId('CSO', 'zero-batches');
$zeroOrder = saleInventoryOrder(
    $zeroRequestId,
    $zeroOrderId,
    'CHECKOUT-00000000-0000-4000-8000-000000000004'
);
$zeroReason = '';
Db::startTrans();
try {
    $provider->planInTx(
        saleInventoryRequest($zeroRequestId),
        $zeroOrder,
        [saleInventoryLine($zeroOrderId, 503, 603, 'SKU-503', 1, 1)],
        $operator,
        $dataScope
    );
    Db::commit();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $zeroReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('zero-stock products without active batches fail as a shortage, not a runtime error',
    $zeroReason === 'sale_inventory_shortage_denied'
    && (int)Db::name('inventory_stock')->where('id', 703)->value('available_quantity_units') === 0);

$ambiguousRequestId = saleInventoryId('CKR', 'same-unique-different-sku');
$ambiguousOrderId = saleInventoryId('CSO', 'same-unique-different-sku');
$ambiguousReason = '';
$stockBeforeAmbiguousSku = (int)Db::name('inventory_stock')->where('id', 701)->value('available_quantity_units');
Db::startTrans();
try {
    $provider->planInTx(
        saleInventoryRequest($ambiguousRequestId),
        saleInventoryOrder(
            $ambiguousRequestId,
            $ambiguousOrderId,
            'CHECKOUT-00000000-0000-4000-8000-000000000006'
        ),
        [saleInventoryLine($ambiguousOrderId, 501, 605, 'SKU-501', 1, 1)],
        $operator,
        $dataScope
    );
    Db::commit();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $ambiguousReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('a duplicate SKU text cannot select another frozen SKU ID',
    $ambiguousReason === 'sale_inventory_sku_changed'
    && (int)Db::name('inventory_stock')->where('id', 701)->value('available_quantity_units') === $stockBeforeAmbiguousSku);

$nonInventoryRequestId = saleInventoryId('CKR', 'ordinary-product');
$nonInventoryOrderId = saleInventoryId('CSO', 'ordinary-product');
$nonInventoryOrder = saleInventoryOrder(
    $nonInventoryRequestId,
    $nonInventoryOrderId,
    'CHECKOUT-00000000-0000-4000-8000-000000000005'
);
$nonInventoryPlan = [];
$nonInventoryReason = '';
Db::startTrans();
try {
    Db::name('inventory_location')->where('id', 71)->update(['location_status' => 'INACTIVE']);
    $nonInventoryPlan = $provider->planInTx(
        saleInventoryRequest($nonInventoryRequestId),
        $nonInventoryOrder,
        [saleInventoryLine($nonInventoryOrderId, 504, 604, 'SKU-504', 1, 1)],
        $operator,
        $dataScope
    );
    Db::rollback();
} catch (CashierV3CheckoutSettlementContractException $exception) {
    $nonInventoryReason = $exception->reason();
    Db::rollback();
}
saleInventoryDbOk('ordinary non-inventory products do not depend on a default inventory location',
    $nonInventoryReason === ''
    && ($nonInventoryPlan['lineAllocations'] ?? null) === []
    && (int)($nonInventoryPlan['locationId'] ?? -1) === 0);

echo "SALE_INVENTORY_MYSQL passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
