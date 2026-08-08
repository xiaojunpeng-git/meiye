<?php
declare(strict_types=1);

require __DIR__ . '/manual-test-bootstrap.php';
executeInventorySqlFile(dirname(__DIR__, 3) . '/后端代码/database/upgrades/2026-08-05-库存V3手工单作废权威/02-正式升级.sql');

use app\services\product\inventory\InventoryManualDocumentReversalServices;
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryManualOutboundServices;
use think\facade\Db;

$failed = 0;
function reversalAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
function reversalReason(callable $operation): string { try { $operation(); } catch (\Throwable $exception) { return $exception->getMessage(); } return ''; }

$suffix = date('ymdHis') . '-' . getmypid();
$storeId = 99306;
$staffId = 993006;
$rootOrg = 99300;
$hqLocationId = 993000;

foreach ([
    ['organization', ['id' => $rootOrg, 'pid' => 0, 'name' => 'TEST-手工单作废根组织', 'is_del' => 0]],
    ['organization', ['id' => $storeId, 'pid' => $rootOrg, 'name' => 'TEST-手工单作废门店组织', 'is_del' => 0]],
    ['system_store', ['id' => $storeId, 'name' => 'TEST-手工单作废门店', 'is_del' => 0, 'is_show' => 1]],
    ['system_store_staff', ['id' => $staffId, 'store_id' => $storeId, 'status' => 1, 'is_del' => 0]],
    ['organization_store', ['store_id' => $storeId, 'org_id' => $storeId]],
] as [$table, $row]) {
    $key = $table === 'organization_store' ? 'store_id' : 'id';
    if (!Db::name($table)->where($key, $row[$key])->find()) Db::name($table)->insert($row);
}

if (!Db::name('inventory_location')->where('id', $hqLocationId)->find()) {
    Db::name('inventory_location')->insert([
        'id' => $hqLocationId, 'tenant_id' => '0', 'organization_id' => (string)$rootOrg,
        'organization_path' => '/' . $rootOrg . '/', 'organization_name_snapshot' => 'TEST-手工单作废根组织',
        'location_type' => 'HQ', 'owner_id' => $rootOrg, 'location_code' => 'TEST-HQ-REVERSAL',
        'location_name' => 'TEST-总部仓', 'store_id' => 0, 'store_name_snapshot' => '',
        'is_default' => 1, 'location_status' => 'ACTIVE', 'version' => 1,
        'created_at' => time(), 'updated_at' => time(),
    ]);
}

$products = [
    [993061, 9930611, 'test-reversal-store-a', 1, $storeId],
    [993062, 9930621, 'test-reversal-store-b', 1, $storeId],
    [993063, 9930631, 'test-reversal-hq-a', 0, 0],
];
foreach ($products as [$productId, $skuId, $unique, $type, $relationId]) {
    if (!Db::name('store_product')->where('id', $productId)->find()) {
        Db::name('store_product')->insert([
            'id' => $productId, 'type' => $type, 'relation_id' => $relationId, 'is_del' => 0, 'is_inventory' => 1,
            'store_name' => 'TEST-作废商品-' . $productId, 'code' => 'TEST-P-' . $productId,
            'bar_code' => '69' . $productId, 'salon_stock_enabled' => 0, 'sort' => 1, 'keyword' => 'TEST-作废商品',
        ]);
    }
    if (!Db::name('store_product_attr_value')->where('id', $skuId)->find()) {
        Db::name('store_product_attr_value')->insert([
            'id' => $skuId, 'product_id' => $productId, 'type' => 0, 'unique' => $unique,
            'suk' => '默认规格', 'bar_code' => '69' . $skuId, 'code' => 'TEST-S-' . $skuId, 'stock_unit' => '瓶',
        ]);
    }
}

$inbound = new InventoryManualInboundServices();
$outbound = new InventoryManualOutboundServices();
$reversal = new InventoryManualDocumentReversalServices();
$inboundCommand = static function (string $key, int $productId, int $skuId, string $unique, string $batch, string $quantity): array {
    return ['idempotency_key' => $key, 'business_date' => date('Y-m-d'), 'remark' => 'TEST-作废集成入库', 'lines' => [[
        'product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $unique, 'batch_no' => $batch,
        'quantity' => $quantity, 'unit_cost' => '12.34', 'manufactured_date' => '2026-01-01', 'expire_date' => '2027-01-01',
    ]]];
};
$outboundCommand = static function (string $key, int $productId, int $skuId, string $unique, string $quantity): array {
    return ['idempotency_key' => $key, 'business_date' => date('Y-m-d'), 'remark' => 'TEST-作废集成出库', 'lines' => [[
        'product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $unique, 'quantity' => $quantity,
    ]]];
};
$reverseCommand = static function (string $sourceId, string $key, string $reason = '录入错误，测试作废'): array {
    return ['source_id' => $sourceId, 'idempotency_key' => $key, 'reason' => $reason];
};

$storeInboundKey = 'TEST-reverse-store-in-' . $suffix;
$storeInbound = $inbound->create($storeId, $staffId, $inboundCommand($storeInboundKey, 993061, 9930611, 'test-reversal-store-a', 'TEST-RSI-' . $suffix, '10'));
$storeReverseKey = 'TEST-reverse-store-in-command-' . $suffix;
$storeReverse = $reversal->reverseForStore($storeId, $staffId, InventoryManualDocumentReversalServices::INBOUND, $reverseCommand($storeInboundKey, $storeReverseKey));
$storeReplay = $reversal->reverseForStore($storeId, $staffId, InventoryManualDocumentReversalServices::INBOUND, $reverseCommand($storeInboundKey, $storeReverseKey));
$originalInboundFact = (int)$storeInbound['lines'][0]['movement_fact_id'];
$reverseInboundFact = Db::name('inventory_batch_movement_fact')->where('reversal_of', $originalInboundFact)->find();
$storeStock = Db::name('inventory_stock')->where('store_id', $storeId)->where('consumable_product_id', 993061)->find();
reversalAssert('store inbound reversal preserves and links the original fact while removing its exact batch quantity',
    (string)$storeReverse['status'] === 'VOIDED' && (int)$storeStock['available_quantity_units'] === 0
    && (int)($reverseInboundFact['direction'] ?? 0) === -1 && (int)($reverseInboundFact['quantity_units'] ?? 0) === 10
    && (int)($reverseInboundFact['unit_cost_cents'] ?? 0) === 1234);
reversalAssert('same reversal idempotency key replays without a second balance mutation',
    !empty($storeReplay['idempotent']) && (int)$storeReplay['reversal_id'] === (int)$storeReverse['reversal_id']
    && (int)Db::name('inventory_batch_movement_fact')->where('reversal_of', $originalInboundFact)->count() === 1);
reversalAssert('changed retry payload cannot reuse the original reversal key',
    reversalReason(static function () use ($reversal, $storeId, $staffId, $reverseCommand, $storeInboundKey, $storeReverseKey): void {
        $reversal->reverseForStore($storeId, $staffId, InventoryManualDocumentReversalServices::INBOUND, $reverseCommand($storeInboundKey, $storeReverseKey, '不同的作废原因'));
    }) === 'inventory_manual_reversal_idempotency_conflict');

$storeOutboundInboundKey = 'TEST-reverse-store-out-base-' . $suffix;
$inbound->create($storeId, $staffId, $inboundCommand($storeOutboundInboundKey, 993062, 9930621, 'test-reversal-store-b', 'TEST-RSO-' . $suffix, '5'));
$storeOutboundKey = 'TEST-reverse-store-out-' . $suffix;
$outboundResult = $outbound->create($storeId, $staffId, $outboundCommand($storeOutboundKey, 993062, 9930621, 'test-reversal-store-b', '3'));
$originalOutboundFact = (int)$outboundResult['lines'][0]['movement_fact_ids'][0];
$reversal->reverseForStore($storeId, $staffId, InventoryManualDocumentReversalServices::OUTBOUND,
    $reverseCommand($storeOutboundKey, 'TEST-reverse-store-out-command-' . $suffix));
$storeOutboundStock = Db::name('inventory_stock')->where('store_id', $storeId)->where('consumable_product_id', 993062)->find();
$reverseOutboundFact = Db::name('inventory_batch_movement_fact')->where('reversal_of', $originalOutboundFact)->find();
reversalAssert('store outbound reversal restores the exact batch and actual cost originally consumed',
    (int)$storeOutboundStock['available_quantity_units'] === 5 && (int)($reverseOutboundFact['direction'] ?? 0) === 1
    && (int)($reverseOutboundFact['batch_id'] ?? 0) === (int)Db::name('inventory_batch_movement_fact')->where('id', $originalOutboundFact)->value('batch_id')
    && (int)($reverseOutboundFact['cost_amount_cents'] ?? 0) === (int)Db::name('inventory_batch_movement_fact')->where('id', $originalOutboundFact)->value('cost_amount_cents'));

$hqScope = [
    'tenantId' => '0', 'organizationId' => (string)$rootOrg, 'organizationPath' => '/' . $rootOrg . '/',
    'organizationName' => 'TEST-手工单作废根组织', 'storeId' => 0, 'storeName' => '',
    'operatorId' => 1, 'partyType' => 'HQ',
];
$hqLocation = Db::name('inventory_location')->where('id', $hqLocationId)->find();
$hqInboundKey = 'TEST-reverse-hq-in-' . $suffix;
$hqInbound = $inbound->createForHeadquarters($hqScope, $hqLocation,
    $inboundCommand($hqInboundKey, 993063, 9930631, 'test-reversal-hq-a', 'TEST-RHQ-' . $suffix, '4'));
$hqReverse = $reversal->reverseForHeadquarters($hqScope, $hqLocation, InventoryManualDocumentReversalServices::INBOUND,
    $reverseCommand($hqInboundKey, 'TEST-reverse-hq-in-command-' . $suffix));
$hqStock = Db::name('inventory_stock')->where('location_id', $hqLocationId)->where('consumable_product_id', 993063)->find();
$hqOriginalFact = (int)$hqInbound['lines'][0]['movement_fact_id'];
reversalAssert('headquarters uses the same reversal authority with platform administrator audit identity',
    (int)$hqStock['available_quantity_units'] === 0 && (string)$hqReverse['status'] === 'VOIDED'
    && (int)Db::name('inventory_batch_movement_fact')->where('reversal_of', $hqOriginalFact)->count() === 1
    && (string)Db::name('inventory_manual_document_reversal')->where('id', (int)$hqReverse['reversal_id'])->value('operator_type') === 'PLATFORM_ADMIN');

$blockedInboundKey = 'TEST-reverse-blocked-in-' . $suffix;
$blockedInbound = $inbound->create($storeId, $staffId,
    $inboundCommand($blockedInboundKey, 993061, 9930611, 'test-reversal-store-a', 'TEST-RBLOCK-' . $suffix, '2'));
$outbound->create($storeId, $staffId,
    $outboundCommand('TEST-reverse-blocked-out-' . $suffix, 993061, 9930611, 'test-reversal-store-a', '2'));
$blockedReverseKey = 'TEST-reverse-blocked-command-' . $suffix;
reversalAssert('inbound reversal with consumed original batch fails atomically and records no receipt or reversal fact',
    reversalReason(static function () use ($reversal, $storeId, $staffId, $reverseCommand, $blockedInboundKey, $blockedReverseKey): void {
        $reversal->reverseForStore($storeId, $staffId, InventoryManualDocumentReversalServices::INBOUND, $reverseCommand($blockedInboundKey, $blockedReverseKey));
    }) === 'inventory_manual_reversal_inbound_batch_insufficient'
    && (int)Db::name('inventory_manual_document_reversal')->where('idempotency_key', $blockedReverseKey)->count() === 0
    && (int)Db::name('inventory_batch_movement_fact')->where('reversal_of', (int)$blockedInbound['lines'][0]['movement_fact_id'])->count() === 0);

echo "INVENTORY_MANUAL_REVERSAL_INTEGRATION failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
