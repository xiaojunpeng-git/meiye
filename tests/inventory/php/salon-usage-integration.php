<?php
declare(strict_types=1);

require __DIR__ . '/manual-test-bootstrap.php';
executeInventorySqlFile(dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-08-05-库存V3手工单作废权威/02-正式升级.sql');

use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\Db;

if ((string)\think\facade\Config::get('database.connections.mysql.database') !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('isolated_inventory_test_database_required');
}
foreach ([
    ['eb_store_product', 'product_type', 'ALTER TABLE eb_store_product ADD COLUMN product_type tinyint(2) NOT NULL DEFAULT 0'],
    ['eb_store_product', 'is_show', 'ALTER TABLE eb_store_product ADD COLUMN is_show tinyint(1) NOT NULL DEFAULT 1'],
    ['eb_system_store_staff', 'staff_name', "ALTER TABLE eb_system_store_staff ADD COLUMN staff_name varchar(100) NOT NULL DEFAULT ''"],
    ['eb_inventory_salon_usage_document', 'operator_name_snapshot', "ALTER TABLE eb_inventory_salon_usage_document ADD COLUMN operator_name_snapshot varchar(128) NOT NULL DEFAULT ''"],
] as [$table, $column, $sql]) {
    if (!Db::query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")) Db::execute($sql);
}

function salonVoidAssert(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $failed++;
}
function salonVoidReason(callable $run): string
{
    try { $run(); } catch (Throwable $exception) { return $exception->getMessage(); }
    return '';
}
$failed = 0;
$suffix = date('ymdHis') . '-' . getmypid();
$storeId = 99691;
$staffId = 996910;
// 每次使用全新商品与批次，避免隔离库前次试验的库存事实污染本轮回归。
$productId = random_int(1200000000, 1299999990);
$skuId = $productId + 1;
$skuUnique = 'testsalon' . $skuId;
$projectId = $productId + 2;

// 只在白名单隔离库创建测试主体；不修改任何用户门店或历史院装记录。
foreach ([
    ['system_store', ['id' => $storeId, 'name' => 'TEST-院装门店', 'is_del' => 0, 'is_show' => 1]],
    ['system_store_staff', ['id' => $staffId, 'store_id' => $storeId, 'status' => 1, 'is_del' => 0]],
    ['organization', ['id' => 99600, 'pid' => 0, 'name' => 'TEST-库存根组织', 'is_del' => 0]],
    ['organization', ['id' => $storeId, 'pid' => 99600, 'name' => 'TEST-院装组织', 'is_del' => 0]],
    ['organization_store', ['store_id' => $storeId, 'org_id' => $storeId]],
    ['store_product', ['id' => $productId, 'type' => 1, 'relation_id' => $storeId, 'is_del' => 0, 'is_inventory' => 1, 'store_name' => 'TEST-院装精华', 'code' => 'TEST-SALON-P', 'bar_code' => '6909969100001', 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-院装精华']],
    ['store_product', ['id' => $projectId, 'type' => 1, 'relation_id' => $storeId, 'product_type' => 6, 'is_del' => 0, 'is_show' => 1, 'is_inventory' => 0, 'store_name' => 'TEST-院装项目', 'code' => 'TEST-SALON-PROJECT', 'bar_code' => '', 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-院装项目']],
    ['store_product_attr_value', ['id' => $skuId, 'product_id' => $productId, 'type' => 0, 'unique' => $skuUnique, 'suk' => '10ml', 'bar_code' => '6909969100001', 'code' => 'TEST-SALON-S', 'stock_unit' => '瓶']],
] as [$table, $row]) {
    $key = $table === 'organization_store' ? 'store_id' : 'id';
    if (!Db::name($table)->where($key, $row[$key])->find()) Db::name($table)->insert($row);
}
Db::name('system_store_staff')->where('id', $staffId)->update(['staff_name' => 'TEST-院装操作人']);
Db::name('store_product')->where('id', $projectId)->update(['product_type' => 6, 'is_show' => 1]);

$inbound = new InventoryManualInboundServices();
$salon = new InventorySalonUsageServices();
$inbound->create($storeId, $staffId, [
    'idempotency_key' => 'TEST-salon-void-in-' . $suffix,
    'business_date' => date('Y-m-d'), 'remark' => 'TEST-院装作废入库',
    'lines' => [[
        'product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $skuUnique,
        'batch_no' => 'TEST-SALON-VOID-' . $suffix, 'quantity' => '3', 'unit_cost' => '66.00',
        'manufactured_date' => '2026-07-01', 'expire_date' => '2027-07-01',
    ]],
]);
$locationId = (int)Db::name('inventory_location')->where('tenant_id', '0')->where('store_id', $storeId)->where('is_default', 1)->value('id');
$stockBefore = (int)Db::name('inventory_stock')->where('location_id', $locationId)->where('consumable_product_id', $productId)->value('available_quantity_units');
$issueInput = static function (string $key, int $project) use ($productId, $skuId, $skuUnique): array {
    return ['idempotency_key' => $key, 'business_date' => date('Y-m-d'), 'project_id' => $project,
        'project_name' => '客户端不可信名称', 'remark' => 'TEST-院装领用作废',
        'lines' => [['product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $skuUnique, 'quantity' => '1']]];
};
$issue = $salon->issue($storeId, $staffId, $issueInput('TEST-salon-void-issue-' . $suffix, $projectId));
$issueId = (int)$issue['usage_document_id'];
$originalFactId = (int)Db::name('inventory_salon_usage_line')->where('document_id', $issueId)->value('movement_fact_id');
$detailBefore = $salon->detail($storeId, $staffId, $issueId);
salonVoidAssert('issue stores the server project snapshot and one batch deduction',
    (string)$detailBefore['document']['project_name_snapshot'] === 'TEST-院装项目'
    && $detailBefore['document']['can_void'] === true
    && (int)Db::name('inventory_stock')->where('location_id', $locationId)->where('consumable_product_id', $productId)->value('available_quantity_units') === $stockBefore - 1);

$voidKey = 'TEST-salon-void-command-' . $suffix;
$voidInput = ['idempotency_key' => $voidKey, 'reason' => 'TEST-录入错误作废退库'];
$voided = $salon->voidForStore($storeId, $staffId, $issueId, $voidInput);
$replay = $salon->voidForStore($storeId, $staffId, $issueId, $voidInput);
$reverseFact = Db::name('inventory_batch_movement_fact')->where('reversal_of', $originalFactId)->find();
$detailAfter = $salon->detail($storeId, $staffId, $issueId);
salonVoidAssert('void restores exactly one unit to the original batch and preserves reversal lineage',
    (int)Db::name('inventory_stock')->where('location_id', $locationId)->where('consumable_product_id', $productId)->value('available_quantity_units') === $stockBefore
    && (int)($reverseFact['direction'] ?? 0) === 1
    && (int)($reverseFact['quantity_units'] ?? 0) === 1
    && (string)($reverseFact['source_type'] ?? '') === 'salon_usage_issue_reversal');
salonVoidAssert('void is idempotent and does not produce another inventory fact',
    $voided['idempotent'] === false && $replay['idempotent'] === true
    && (int)Db::name('inventory_batch_movement_fact')->where('reversal_of', $originalFactId)->count() === 1);
salonVoidAssert('document remains readable with void reason but cannot be voided again',
    (string)$detailAfter['document']['document_status'] === 'VOIDED'
    && $detailAfter['document']['can_void'] === false
    && (string)$detailAfter['document']['void_reason'] === $voidInput['reason']);
salonVoidAssert('different idempotency key cannot void the same issue twice',
    salonVoidReason(static fn () => $salon->voidForStore($storeId, $staffId, $issueId,
        ['idempotency_key' => 'TEST-salon-void-second-' . $suffix, 'reason' => 'TEST-重复作废']))
    === 'inventory_manual_reversal_already_voided');
salonVoidAssert('other store cannot void this issue',
    salonVoidReason(static fn () => $salon->voidForStore(99692, 996920, $issueId,
        ['idempotency_key' => 'TEST-salon-void-cross-' . $suffix, 'reason' => 'TEST-跨门店拒绝']))
    !== '');

$returnCountBefore = (int)Db::name('inventory_salon_usage_document')->where('store_id', $storeId)->where('operation_type', 'RETURN')->count();
$unlinked = $salon->issue($storeId, $staffId, $issueInput('TEST-salon-void-unlinked-' . $suffix, 0));
$unlinkedDetail = $salon->detail($storeId, $staffId, (int)$unlinked['usage_document_id']);
salonVoidAssert('issue with no linked project remains valid and explicitly stores project zero',
    (int)$unlinkedDetail['document']['project_id'] === 0
    && (string)$unlinkedDetail['document']['project_name_snapshot'] === ''
    && $unlinkedDetail['document']['can_void'] === true);
$salon->voidForStore($storeId, $staffId, (int)$unlinked['usage_document_id'],
    ['idempotency_key' => 'TEST-salon-void-unlinked-command-' . $suffix, 'reason' => 'TEST-未关联项目作废']);
salonVoidAssert('unlinked issue also restores stock without creating a return document',
    (int)Db::name('inventory_stock')->where('location_id', $locationId)->where('consumable_product_id', $productId)->value('available_quantity_units') === $stockBefore
    && (string)Db::name('inventory_salon_usage_document')->where('id', (int)$unlinked['usage_document_id'])->value('document_status') === 'VOIDED'
    && (int)Db::name('inventory_salon_usage_document')->where('store_id', $storeId)->where('operation_type', 'RETURN')->count() === $returnCountBefore);

echo "INVENTORY_SALON_VOID_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
