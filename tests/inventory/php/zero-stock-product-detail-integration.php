<?php
declare(strict_types=1);

use app\services\product\inventory\InventoryStoreReadModelServices;
use think\facade\Config;
use think\facade\Db;

/** Zero-stock detail regression: synthetic settled facts in a local allowlisted database, always rolled back. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$database = 'inventory_manual_inbound_test_20260730';
if ((getenv('DB_DATABASE') ?: $database) !== $database || (getenv('DB_HOST') ?: 'mysql') !== 'mysql') {
    throw new RuntimeException('isolated_local_database_required');
}
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach (['DATABASE_HOSTNAME' => 'mysql', 'DATABASE_DATABASE' => $database,
    'DATABASE_HOSTPORT' => '3306', 'CACHE_DRIVER' => 'file'] as $key => $value) $app->env->set($key, $value);
$property = new ReflectionProperty($app, 'envName');
$property->setAccessible(true);
$property->setValue($app, 'zero_stock_detail_skip_dotenv');
$app->initialize();
Config::set(['default' => 'file'], 'cache');
if (Config::get('database.connections.mysql.database') !== $database
    || Config::get('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('database_guard_failed');

$failed = 0;
function detailCheck(string $label, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) $failed++;
}
function detailReason(callable $operation): string
{
    try { $operation(); } catch (Throwable $error) { return $error->getMessage(); }
    return '';
}

$suffix = 'R49-zero-detail-' . bin2hex(random_bytes(6));
$storeId = 995061;
$productBase = 9950610;
Db::startTrans();
try {
    if (Db::name('inventory_location')->where('store_id', $storeId)->find()
        || Db::name('inventory_stock')->whereBetween('consumable_product_id', [$productBase, $productBase + 9])->find()) {
        throw new RuntimeException('test_fixture_identity_collision');
    }
    $location = static function (int $store, string $tenant = '0', string $status = 'ACTIVE') use ($suffix): int {
        return (int)Db::name('inventory_location')->insertGetId([
            'tenant_id' => $tenant, 'store_id' => $store, 'owner_id' => $store,
            'location_code' => $suffix . '-' . $store . '-' . $tenant . '-' . $status,
            'location_name' => 'TEST-warehouse', 'location_status' => $status, 'is_default' => 1,
        ]);
    };
    $ownLocation = $location($storeId);
    $foreignLocation = $location($storeId + 1);
    $tenantLocation = $location($storeId, 'detail-other');
    $inactiveLocation = $location($storeId, '0', 'INACTIVE');

    /** Balances and append-only facts agree by construction; no business writer or real document is invoked. */
    $fixture = static function (int $product, int $locationId, array $quantities,
        string $tenant = '0', string $status = 'SETTLED', int $scale = 0) use ($suffix, $storeId): array {
        $balance = $status === 'SETTLED' ? array_sum($quantities) : 0;
        $stock = (int)Db::name('inventory_stock')->insertGetId([
            'tenant_id' => $tenant, 'store_id' => $storeId, 'location_id' => $locationId,
            'consumable_product_id' => $product, 'sku_id' => $product, 'product_unique' => $suffix . '-' . $product,
            'stock_unit' => 'unit', 'quantity_scale' => $scale, 'available_quantity_units' => $balance,
        ]);
        $batch = (int)Db::name('inventory_batch')->insertGetId([
            'stock_id' => $stock, 'batch_no' => $suffix . '-' . $product,
            'product_name_snapshot' => 'TEST-zero-detail-' . $product, 'sku_name_snapshot' => 'default',
            'unit_cost_cents' => 250, 'available_quantity_units' => $balance,
            'received_business_date' => date('Y-m-d'),
        ]);
        foreach ($quantities as $index => $quantity) {
            Db::name('inventory_batch_movement_fact')->insert([
                'tenant_id' => $tenant, 'store_id' => $storeId, 'location_id' => $locationId,
                'stock_id' => $stock, 'batch_id' => $batch, 'fact_key' => $suffix . '-' . $product . '-' . $index,
                'direction' => $quantity < 0 ? -1 : 1, 'quantity_units' => abs($quantity),
                'unit_cost_cents' => 250, 'cost_amount_cents' => intdiv(abs($quantity) * 250, 10 ** $scale),
                'fact_status' => $status, 'source_type' => 'opening_balance',
                'source_id' => $suffix . '-' . $product, 'source_detail_id' => (string)$index,
                'business_date' => date('Y-m-d'), 'recorded_at' => time(),
            ]);
        }
        return ['stock' => $stock, 'batch' => $batch];
    };
    $zero = $fixture($productBase, $ownLocation, [0]);
    $depleted = $fixture($productBase + 1, $ownLocation, [100, -100], '0', 'SETTLED', 2);
    $positive = $fixture($productBase + 2, $ownLocation, [100, -75], '0', 'SETTLED', 2);
    $fixture($productBase + 3, $foreignLocation, [0]);
    $fixture($productBase + 4, $tenantLocation, [0], 'detail-other');
    $fixture($productBase + 5, $inactiveLocation, [0]);
    $fixture($productBase + 6, $ownLocation, []);
    $fixture($productBase + 7, $ownLocation, [0], '0', 'PENDING');
    $reader = new InventoryStoreReadModelServices();
    $read = static fn(int $product, bool $cost = true): array => $reader->productDetail($storeId, 1, $product, $cost);
    $before = [Db::name('inventory_stock')->where('location_id', $ownLocation)->select()->toArray(),
        Db::name('inventory_batch_movement_fact')->whereLike('fact_key', $suffix . '%')->select()->toArray()];

    $zeroDetail = $read($productBase);
    detailCheck('zero opening fact remains a real product detail with matched zero balance',
        $zeroDetail['total_available_quantity'] === '0' && $zeroDetail['reconciliation']['status'] === 'MATCHED'
        && (int)$zeroDetail['product']['product_id'] === $productBase);
    detailCheck('zero opening history is retained without inventing positive batches',
        count($zeroDetail['movements']) === 1 && $zeroDetail['batches'] === []);
    $depletedDetail = $read($productBase + 1);
    detailCheck('fully depleted fractional stock preserves both receipt and outbound history',
        $depletedDetail['total_available_quantity'] === '0' && count($depletedDetail['movements']) === 2
        && array_map('intval', array_column($depletedDetail['movements'], 'direction')) === [-1, 1]);
    $hidden = $read($productBase + 1, false);
    detailCheck('zero stock cannot bypass movement cost permission',
        $hidden['movements'][0]['unit_cost_cents'] === null && $hidden['movements'][0]['cost_amount_cents'] === null);
    $positiveDetail = $read($productBase + 2);
    detailCheck('positive fractional balance and current batch detail remain exact',
        $positiveDetail['total_available_quantity'] === '0.25' && count($positiveDetail['batches']) === 1
        && $positiveDetail['batches'][0]['available_quantity'] === '0.25'
        && $positiveDetail['batches'][0]['inventory_amount_cents'] === 62);
    $hidden = $read($productBase + 2, false);
    detailCheck('positive batch cost permission remains enforced',
        $hidden['batches'][0]['unit_cost'] === null && $hidden['batches'][0]['inventory_amount_cents'] === null);
    $summary = $reader->productSummary($storeId, 1, '', 1, 20, true);
    detailCheck('legacy default summary still excludes zero stock',
        $summary['count'] === 1 && (int)$summary['list'][0]['product_id'] === $productBase + 2
        && $summary['stockSummary']['available_quantity'] === '0.25');
    foreach ([3 => 'other store', 4 => 'other tenant', 5 => 'inactive location', 6 => 'batch without facts',
        7 => 'unsettled facts', 8 => 'nonexistent product'] as $offset => $label) {
        detailCheck($label . ' cannot become a visible zero-stock detail',
            detailReason(fn() => $read($productBase + $offset)) === 'inventory_product_stock_not_found');
    }
    detailCheck('invalid product id remains rejected',
        detailReason(fn() => $read(0)) === 'inventory_product_id_invalid');
    detailCheck('reading details never modifies balances or facts', $before === [
        Db::name('inventory_stock')->where('location_id', $ownLocation)->select()->toArray(),
        Db::name('inventory_batch_movement_fact')->whereLike('fact_key', $suffix . '%')->select()->toArray()]);
    // Include-zero is only a read filter; it must not silently excuse an aggregate/fact mismatch.
    Db::name('inventory_stock')->where('id', $zero['stock'])->update(['available_quantity_units' => 1]);
    detailCheck('zero-stock aggregate mismatch still fails closed',
        detailReason(fn() => $read($productBase)) === 'inventory_stock_batch_balance_mismatch');
} catch (Throwable $error) {
    $failed++;
    echo 'FAIL unexpected exception: ' . $error->getMessage() . PHP_EOL;
} finally {
    Db::rollback();
    echo 'ROLLBACK all zero-stock detail fixtures' . PHP_EOL;
}
detailCheck('no test facts survive rollback',
    (int)Db::name('inventory_batch_movement_fact')->whereLike('fact_key', $suffix . '%')->count() === 0);
echo "ZERO_STOCK_DETAIL_RESULT failed={$failed}\n";
exit($failed ? 1 : 0);
