<?php
declare(strict_types=1);

// Local-only persisted smoke test: keep the three labeled warehouses and documents for UI review.
if (getenv('R14_LOCAL_THREE_WAREHOUSE_TEST') !== 'yes') {
    throw new RuntimeException('r14_explicit_local_test_flag_required');
}
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql'
    || config('database.connections.mysql.database') !== 'ruihao'
    || gethostbyname('mysql') !== '172.18.0.2') {
    throw new RuntimeException('r14_local_database_target_mismatch');
}

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryHqLocationServices;
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryPlatformHqReadModelServices;
use think\facade\Db;

function r14LiveCheck(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('FAIL ' . $name);
    echo 'PASS ' . $name . PHP_EOL;
}

// Every write below uses the application's warehouse, inbound and transfer services.
$admin = (array)Db::name('system_admin')->where('id', 1)->find();
r14LiveCheck((int)($admin['level'] ?? -1) === 0 && (int)($admin['status'] ?? 0) === 1, 'local platform operator');
$names = ['TEST-R14-三仓-A-20261013', 'TEST-R14-三仓-B-20261013', 'TEST-R14-三仓-C-20261013'];
$locations = [];
foreach ($names as $name) {
    $rows = Db::name('inventory_location')->where('location_name', $name)->where('location_type', 'HQ')->select()->toArray();
    r14LiveCheck(count($rows) === 1 && (int)$rows[0]['owner_id'] === 110 && $rows[0]['location_status'] === 'ACTIVE', 'warehouse ' . $name);
    $locations[] = $rows[0];
}
[$a, $b, $c] = array_map(static fn (array $row): int => (int)$row['id'], $locations);
$sku = (array)Db::name('store_product_attr_value')->where('id', 482410)->find();
$product = (array)Db::name('store_product')->where('id', 498546)->find();
r14LiveCheck((int)$sku['product_id'] === 498546 && (int)$product['no_expiry_management'] === 1
    && (int)$product['is_inventory'] === 1 && (int)$product['is_del'] === 0, 'isolated test batch catalog');
$balance = static fn (int $locationId): int => (int)Db::name('inventory_stock')
    ->where('location_id', $locationId)->where('consumable_product_id', 498546)
    ->where('sku_id', 482410)->sum('available_quantity_units');
$triple = static fn (): array => [$balance($a), $balance($b), $balance($c)];
$otherLocationsBefore = (int)Db::name('inventory_stock')->whereNotIn('location_id', [$a, $b, $c])
    ->where('consumable_product_id', 498546)->where('sku_id', 482410)->sum('available_quantity_units');
r14LiveCheck($triple() === [0, 0, 0], 'all three test warehouses start empty');

$hq = new InventoryHqLocationServices();
$scope = $hq->scope($locations[0], 1);
$scope['operatorType'] = 'PLATFORM_ADMIN';
$scope['operatorName'] = 'R14 local test';
$inbound = (new InventoryManualInboundServices())->createForHeadquarters($scope, $locations[0], [
    'idempotency_key' => 'R14-LIVE-THREE-IN-20261013',
    'business_date' => '2026-10-13',
    'remark' => 'TEST R14 三仓库存核验；只在测试仓使用',
    'lines' => [[
        'product_id' => 498546, 'sku_id' => 482410, 'sku_unique' => (string)$sku['unique'],
        'batch_no' => 'TEST-R14-THREE-20261013', 'quantity' => '3', 'unit_cost' => '1',
        'manufactured_date' => '', 'expire_date' => '',
    ]],
]);
r14LiveCheck($triple() === [3, 0, 0], 'inbound only changes A');

$transfers = new InventoryCrossSubjectTransferServices();
$runTransfer = static function (int $from, int $to, int $quantity, string $key) use ($admin, $sku, $transfers, $triple): array {
    $draft = $transfers->createDraftForHeadquarters($admin, $from, [
        'idempotency_key' => $key, 'business_date' => '2026-10-13',
        'remark' => 'TEST R14 三仓库存核验',
        'source_party_type' => 'HQ', 'source_store_id' => 0,
        'target_party_type' => 'HQ', 'target_store_id' => 0,
        'target_location_id' => $to, 'request_document_id' => 0, 'transfer_staff_id' => 0,
        'lines' => [[
            'product_id' => 498546, 'sku_id' => 482410, 'sku_unique' => (string)$sku['unique'],
            'quantity' => (string)$quantity, 'request_line_id' => 0,
        ]],
    ]);
    $id = (int)$draft['transfer_id'];
    $beforeDraft = $triple();
    r14LiveCheck(Db::name('inventory_cross_transfer_document')->where('id', $id)->value('document_status') === 'DRAFT', $key . ' draft persisted');
    r14LiveCheck($triple() === $beforeDraft, $key . ' draft leaves stock unchanged');
    $transfers->dispatchForHeadquarters($admin, $from, $id);
    $dispatched = $triple();
    r14LiveCheck(Db::name('inventory_cross_transfer_document')->where('id', $id)->value('document_status') === 'DISPATCHED', $key . ' dispatched');
    r14LiveCheck(array_sum($beforeDraft) - array_sum($dispatched) === $quantity, $key . ' in-transit conservation');
    $transfers->dispatchForHeadquarters($admin, $from, $id);
    r14LiveCheck($triple() === $dispatched, $key . ' dispatch replay deducts once');
    $wrong = 128;
    try { $transfers->receiveForHeadquarters($admin, $wrong, $id); $wrongRejected = false; }
    catch (Throwable $exception) { $wrongRejected = true; }
    r14LiveCheck($wrongRejected && $triple() === $dispatched, $key . ' wrong receiving warehouse denied');
    $transfers->receiveForHeadquarters($admin, $to, $id);
    $received = $triple();
    r14LiveCheck(Db::name('inventory_cross_transfer_document')->where('id', $id)->value('document_status') === 'RECEIVED', $key . ' received');
    r14LiveCheck(array_sum($received) === array_sum($beforeDraft), $key . ' receipt conserves total');
    $transfers->receiveForHeadquarters($admin, $to, $id);
    r14LiveCheck($triple() === $received, $key . ' receive replay adds once');
    return ['id' => $id, 'number' => (string)Db::name('inventory_cross_transfer_document')->where('id', $id)->value('transfer_no'), 'after_dispatch' => $dispatched, 'after_receive' => $received];
};

$first = $runTransfer($a, $b, 2, 'R14-LIVE-THREE-A-B-20261013');
r14LiveCheck($first['after_dispatch'] === [1, 0, 0] && $first['after_receive'] === [1, 2, 0], 'A to B exact per-warehouse balances');
$second = $runTransfer($b, $c, 1, 'R14-LIVE-THREE-B-C-20261013');
r14LiveCheck($second['after_dispatch'] === [1, 1, 0] && $second['after_receive'] === [1, 1, 1], 'B to C exact per-warehouse balances');
$otherLocationsAfter = (int)Db::name('inventory_stock')->whereNotIn('location_id', [$a, $b, $c])
    ->where('consumable_product_id', 498546)->where('sku_id', 482410)->sum('available_quantity_units');
r14LiveCheck($otherLocationsBefore === $otherLocationsAfter, 'original warehouses unchanged for tested SKU');
$reader = new InventoryPlatformHqReadModelServices();
foreach ([$a, $b, $c] as $id) {
    $summary = $reader->productSummary($admin, $id, '瑞昊坐灸仪器', 1, 20);
    r14LiveCheck((int)$summary['count'] === 1 && $summary['list'][0]['available_quantity'] === '1', 'read model location ' . $id . ' is 1');
}
echo 'R14_LIVE_RESULT ' . json_encode([
    'warehouses' => [['id' => $a, 'name' => $names[0]], ['id' => $b, 'name' => $names[1]], ['id' => $c, 'name' => $names[2]]],
    'sku_id' => 482410, 'inbound_no' => $inbound['document_no'], 'transfers' => [$first, $second],
    'final_available_units' => $triple(), 'final_total_units' => array_sum($triple()),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
