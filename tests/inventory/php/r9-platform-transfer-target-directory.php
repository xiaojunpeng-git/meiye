<?php
/**
 * Local R9 platform receiver contract: destinations are not operator data grants.
 * All catalogs, warehouses and drafts below are synthetic and transaction-rolled back;
 * never grant a real account access or dispatch/receive real inventory.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformHqCatalogServices;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME') || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}
function r9TargetCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
/** Check the exact rejection rather than accepting an unrelated fixture failure. */
function r9TargetReject(callable $operation, string $reason, string $name): void {
    try { $operation(); } catch (Throwable $error) {
        r9TargetCheck($error->getMessage() === $reason, $name);
        return;
    }
    throw new RuntimeException('Unexpected grant: ' . $name);
}

$tag = 'r9-target-' . bin2hex(random_bytes(6));
$hqUnique = bin2hex(random_bytes(4));
$storeUnique = bin2hex(random_bytes(4));
$service = new InventoryCrossSubjectTransferServices();
$policy = new InventoryPlatformAccessPolicy();
$hq = Db::name('inventory_location')->where('location_type', 'HQ')->where('location_status', 'ACTIVE')->where('is_default', 1)->find();
if (!$hq) throw new RuntimeException('Local HQ metadata required');
Db::startTrans();
try {
    $menuIds = [];
    foreach (['admin-stock-transfer', 'admin-stock-transfer-add'] as $auth) $menuIds[] = Db::name('system_menus')->insertGetId([
        'menu_name' => $tag, 'type' => 1, 'auth_type' => 1, 'unique_auth' => $auth, 'is_del' => 0,
    ]);
    $roleId = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'rules' => implode(',', $menuIds), 'status' => 1]);
    $adminId = (int)Db::name('system_admin')->insertGetId(['account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId, 'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0]);
    $admin = ['id' => $adminId, 'real_name' => $tag];
    r9TargetCheck($policy->resolve($admin)['store_ids'] === [], 'operator deliberately has no store data grants');
    // A separate organization root proves receiver choices are not an implicit organizational grant.
    $rootId = (int)Db::name('organization')->insertGetId(['pid' => 0, 'name' => $tag, 'status' => 1, 'is_del' => 0]);
    $stores = [];
    foreach (['active', 'inactive', 'deleted', 'unbound'] as $kind) {
        $stores[$kind] = (int)Db::name('system_store')->insertGetId(['name' => $tag . '-' . $kind, 'is_del' => $kind === 'deleted' ? 1 : 0, 'is_show' => $kind === 'inactive' ? 0 : 1]);
        if ($kind !== 'unbound') Db::name('organization_store')->insert(['store_id' => $stores[$kind], 'org_id' => $rootId]);
    }
    $targetId = $stores['active'];
    $parties = $service->counterpartiesForHeadquarters($admin, (int)$hq['id'])['list'];
    $partyIds = array_map('intval', array_column($parties, 'id'));
    r9TargetCheck(in_array($targetId, $partyIds, true), 'unassigned store in another organization is a receiving choice');
    foreach (['inactive', 'deleted', 'unbound'] as $kind) r9TargetCheck(!in_array($stores[$kind], $partyIds, true), 'invalid receiver excluded: ' . $kind);
    r9TargetCheck(!array_diff(array_keys($parties[0]), ['id', 'name', 'party_type', 'location_id']), 'receiver directory exposes identity only');
    r9TargetReject(fn () => $service->counterpartiesForHeadquarters($admin, (int)$hq['id'], 'STORE', $targetId), 'inventory_cross_transfer_source_scope_denied', 'receiver choice does not grant source directory access');
    $staff = $service->transferStaffCandidatesForHeadquarters($admin, (int)$hq['id'], 'HQ', 0, $targetId);
    r9TargetCheck(count($staff['list']) === 1 && (int)$staff['list'][0]['id'] === 0, 'unassigned receiver does not block existing operator selection or reveal its employees');
    foreach (['inactive', 'deleted', 'unbound'] as $kind) r9TargetReject(
        fn () => $service->transferStaffCandidatesForHeadquarters($admin, (int)$hq['id'], 'HQ', 0, $stores[$kind]),
        'inventory_cross_transfer_scope_denied', 'invalid receiver rejected by form dependency: ' . $kind);

    $productId = (int)Db::name('store_product')->insertGetId(['type' => 0, 'relation_id' => 0, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => $tag]);
    $skuId = (int)Db::name('store_product_attr_value')->insertGetId(['product_id' => $productId, 'type' => 0, 'unique' => $hqUnique, 'suk' => 'default', 'stock_unit' => 'unit']);
    $targetProductId = (int)Db::name('store_product')->insertGetId(['pid' => $productId, 'type' => 1, 'relation_id' => $targetId, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => $tag]);
    $targetSkuId = (int)Db::name('store_product_attr_value')->insertGetId(['product_id' => $targetProductId, 'type' => 0, 'unique' => $storeUnique, 'suk' => 'default', 'stock_unit' => 'unit']);
    $command = ['idempotency_key' => $tag, 'business_date' => date('Y-m-d'), 'remark' => $tag,
        'source_party_type' => 'HQ', 'source_store_id' => 0, 'target_party_type' => 'STORE', 'target_store_id' => $targetId,
        'request_document_id' => 0, 'transfer_staff_id' => 0,
        'lines' => [['product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $hqUnique, 'quantity' => '2', 'request_line_id' => 0]]];
    $draft = $service->createDraftForHeadquarters($admin, (int)$hq['id'], $command);
    $detail = $service->detailForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']);
    r9TargetCheck((int)$detail['to_party_id'] === $targetId && $detail['document_status'] === 'DRAFT'
        && (int)$detail['lines'][0]['to_sku_id'] === $targetSkuId, 'actual draft saves and reads back an unassigned receiver with correct SKU mapping');
    $replay = $service->createDraftForHeadquarters($admin, (int)$hq['id'], $command);
    r9TargetCheck($replay['idempotent'] && $replay['transfer_id'] === $draft['transfer_id'], 'same request replays without another document');
    r9TargetCheck(!Db::name('inventory_batch_movement_fact')->where('source_id', $draft['transfer_no'])->count()
        && !Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $draft['transfer_id'])->count()
        && !Db::name('inventory_stock')->where('store_id', $targetId)->count(), 'draft creates no stock movement, allocation or target balance');
    r9TargetCheck($policy->resolve($admin)['store_ids'] === [], 'successful destination selection never mutates data grants');
    // Exercise the real shared write kernel with synthetic documents only; stale tabs must fail closed.
    r9TargetCheck($detail['can_edit'] && $detail['can_delete'], 'transfer feature exposes editable and deletable source draft');
    $edit = array_merge($command, ['expected_edit_token' => $detail['edit_token'], 'remark' => $tag . '-edited']);
    $edit['lines'][0]['quantity'] = '3';
    $service->updateDraftForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'], $edit);
    $edited = $service->detailForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']);
    r9TargetCheck($edited['remark'] === $tag . '-edited' && (int)$edited['lines'][0]['requested_quantity_units'] === 3
        && $edited['edit_token'] !== $detail['edit_token'], 'platform update persists quantity and remark with a fresh token');
    r9TargetReject(fn () => $service->updateDraftForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'], $edit),
        'inventory_cross_transfer_draft_changed', 'stale update cannot overwrite the edited draft');
    r9TargetReject(fn () => $service->deleteDraftForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'], $detail['edit_token']),
        'inventory_cross_transfer_draft_changed', 'stale delete cannot remove the edited draft');
    r9TargetCheck(!Db::name('inventory_batch_movement_fact')->where('source_id', $draft['transfer_no'])->count(), 'editing creates no inventory fact');
    $deletable = $service->createDraftForHeadquarters($admin, (int)$hq['id'], array_merge($command, ['idempotency_key' => $tag . '-delete']));
    $deleteDetail = $service->detailForHeadquarters($admin, (int)$hq['id'], (int)$deletable['transfer_id']);
    $service->deleteDraftForHeadquarters($admin, (int)$hq['id'], (int)$deletable['transfer_id'], $deleteDetail['edit_token']);
    r9TargetCheck(!Db::name('inventory_cross_transfer_document')->where('id', $deletable['transfer_id'])->count()
        && !Db::name('inventory_cross_transfer_line')->where('document_id', $deletable['transfer_id'])->count(), 'synthetic draft deletion removes header and lines together');
    $listed = $service->listForHeadquarters($admin, (int)$hq['id'], $draft['transfer_no'])['list'][0];
    r9TargetCheck($listed['can_edit'] && $listed['can_delete'] && $listed['can_dispatch'] && !$listed['can_receive'], 'list capabilities match source draft workflow');
    r9TargetReject(fn () => $service->dispatchForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']),
        'inventory_cross_transfer_stock_insufficient', 'failed dispatch leaves draft intact when stock is missing');
    r9TargetCheck($service->detailForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'])['edit_token'] === $edited['edit_token'], 'failed dispatch rolls back without changing the draft');
    $now = time();
    $stockId = (int)Db::name('inventory_stock')->insertGetId([
        'tenant_id' => $hq['tenant_id'], 'organization_id' => $hq['organization_id'], 'organization_path' => $hq['organization_path'],
        'location_id' => $hq['id'], 'store_id' => 0, 'consumable_product_id' => $productId, 'sku_id' => $skuId,
        'product_unique' => $hqUnique, 'stock_status' => 'GOOD', 'stock_unit' => 'unit', 'quantity_scale' => 0,
        'available_quantity_units' => 10, 'estimated_unit_cost_cents' => 500, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $batchId = (int)Db::name('inventory_batch')->insertGetId([
        'stock_id' => $stockId, 'batch_no' => $tag, 'available_quantity_units' => 10, 'unit_cost_cents' => 500,
        'batch_status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::name('inventory_batch')->where('id', $batchId)->update(['origin_batch_id' => $batchId]);
    $service->dispatchForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']);
    r9TargetCheck((int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === 7
        && Db::name('inventory_batch_movement_fact')->where('source_id', $draft['transfer_no'])->count() === 1, 'dispatch deducts exactly edited quantity and writes one stock fact');
    r9TargetCheck($service->dispatchForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'])['idempotent']
        && (int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === 7, 'dispatch replay never deducts stock twice');
    r9TargetReject(fn () => $service->updateDraftForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'], array_merge($edit, ['expected_edit_token' => $edited['edit_token']])),
        'inventory_cross_transfer_edit_state_invalid', 'in-transit transfer cannot be edited');
    r9TargetReject(fn () => $service->deleteDraftForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id'], $edited['edit_token']),
        'inventory_cross_transfer_delete_state_invalid', 'in-transit transfer cannot be deleted');
    r9TargetReject(fn () => $service->receiveForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']),
        'inventory_cross_transfer_receive_state_invalid', 'HQ sender cannot receive on behalf of destination store');
    $inTransit = $service->detailForHeadquarters($admin, (int)$hq['id'], (int)$draft['transfer_id']);
    r9TargetCheck(!$inTransit['can_edit'] && !$inTransit['can_delete'], 'in-transit detail is read-only');
    // Seed an incoming transfer through the shared kernel, without granting the platform operator source-store rights.
    // Only fixture construction uses reflection; HQ receipt below exercises the real authorized public command.
    $reflect = function (string $method, array $arguments) use ($service) {
        $reflection = new ReflectionMethod($service, $method); $reflection->setAccessible(true);
        return $reflection->invokeArgs($service, $arguments);
    };
    $sourceStore = $reflect('scope', [$targetId, 0, false]);
    $targetHq = $reflect('headquartersScope', [$admin, (int)$hq['id'], true, 'transfer']);
    $incomingCommand = array_merge($command, ['idempotency_key' => $tag . '-incoming', 'source_party_type' => 'STORE', 'source_store_id' => $targetId, 'target_party_type' => 'HQ', 'target_store_id' => 0]);
    $incomingCommand['lines'] = [['product_id' => $targetProductId, 'sku_id' => $targetSkuId, 'sku_unique' => $storeUnique, 'quantity' => '2', 'request_line_id' => 0]];
    $incoming = $reflect('createDraft', [$sourceStore, $targetHq, $reflect('normalize', [$incomingCommand])]);
    $incomingDetail = Db::name('inventory_cross_transfer_document')->where('id', $incoming['transfer_id'])->find();
    $sourceStockId = (int)Db::name('inventory_stock')->insertGetId([
        'tenant_id' => $sourceStore['tenantId'], 'organization_id' => $sourceStore['organizationId'], 'organization_path' => $sourceStore['organizationPath'],
        'location_id' => $incomingDetail['from_location_id'], 'store_id' => $targetId, 'consumable_product_id' => $targetProductId, 'sku_id' => $targetSkuId,
        'product_unique' => $storeUnique, 'stock_status' => 'GOOD', 'stock_unit' => 'unit', 'quantity_scale' => 0,
        'available_quantity_units' => 10, 'estimated_unit_cost_cents' => 500, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sourceBatchId = (int)Db::name('inventory_batch')->insertGetId([
        'stock_id' => $sourceStockId, 'batch_no' => $tag . '-incoming', 'available_quantity_units' => 10, 'unit_cost_cents' => 500,
        'batch_status' => 'ACTIVE', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Db::name('inventory_batch')->where('id', $sourceBatchId)->update(['origin_batch_id' => $sourceBatchId]);
    $reflect('dispatch', [$sourceStore, (int)$incoming['transfer_id']]);
    $incomingRow = $service->listForHeadquarters($admin, (int)$hq['id'], $incoming['transfer_no'])['list'][0];
    r9TargetCheck($incomingRow['can_receive'] && !$incomingRow['can_dispatch'] && !$incomingRow['can_edit'], 'HQ incoming transfer exposes receipt only');
    $service->receiveForHeadquarters($admin, (int)$hq['id'], (int)$incoming['transfer_id']);
    r9TargetCheck((int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === 9
        && Db::name('inventory_batch_movement_fact')->where('source_id', $incoming['transfer_no'])->count() === 2, 'HQ receipt adds exact quantity and keeps balanced source/target facts');
    r9TargetCheck($service->receiveForHeadquarters($admin, (int)$hq['id'], (int)$incoming['transfer_id'])['idempotent']
        && (int)Db::name('inventory_stock')->where('id', $stockId)->value('available_quantity_units') === 9, 'receipt replay does not add inventory twice');
    $completed = $service->listForHeadquarters($admin, (int)$hq['id'], $incoming['transfer_no'])['list'][0];
    r9TargetCheck(!$completed['can_edit'] && !$completed['can_delete'] && !$completed['can_dispatch'] && !$completed['can_receive'], 'completed transfer remains view-only');
    $wrongSource = array_merge($command, ['idempotency_key' => $tag . '-source', 'source_party_type' => 'STORE', 'source_store_id' => $targetId, 'target_party_type' => 'HQ', 'target_store_id' => 0]);
    r9TargetReject(fn () => $service->createDraftForHeadquarters($admin, (int)$hq['id'], $wrongSource), 'inventory_cross_transfer_source_scope_denied', 'create still rejects unassigned source stock');
    r9TargetReject(fn () => (new InventoryPlatformHqCatalogServices())->search($admin, (int)$hq['id'], '', 1, 12, 'STORE', $targetId), 'inventory_catalog_source_scope_denied', 'destination does not grant its product catalog');
    foreach (['inactive', 'deleted', 'unbound'] as $kind) {
        $invalid = array_merge($command, ['idempotency_key' => $tag . '-' . $kind, 'target_store_id' => $stores[$kind]]);
        r9TargetReject(fn () => $service->createDraftForHeadquarters($admin, (int)$hq['id'], $invalid), 'inventory_cross_transfer_scope_denied', 'save rejects invalid receiver: ' . $kind);
    }
    $self = array_merge($command, ['idempotency_key' => $tag . '-self', 'target_party_type' => 'HQ', 'target_store_id' => 0]);
    r9TargetReject(fn () => $service->createDraftForHeadquarters($admin, (int)$hq['id'], $self), 'inventory_cross_transfer_target_invalid', 'self-transfer remains rejected');
    $tenantCheck = new ReflectionMethod($service, 'assertSameTenant'); $tenantCheck->setAccessible(true);
    r9TargetReject(fn () => $tenantCheck->invoke($service, ['tenantId' => '0'], ['tenantId' => 'foreign']), 'inventory_cross_transfer_target_scope_denied', 'foreign tenant still rejected');
    Db::name('system_role')->where('id', $roleId)->update(['rules' => (string)$menuIds[0]]);
    try { $service->createDraftForHeadquarters($admin, (int)$hq['id'], $command); throw new LogicException('Create without feature accepted'); }
    catch (\app\services\query\UnifiedQueryException $expected) { echo 'PASS removing create feature still blocks creation' . PHP_EOL; }
} finally {
    Db::rollback();
}
r9TargetCheck(!Db::name('system_admin')->where('account', $tag)->count()
    && !Db::name('organization')->where('name', $tag)->count()
    && !Db::name('system_store')->whereLike('name', $tag . '%')->count()
    && !Db::name('store_product')->where('store_name', $tag)->count()
    && !Db::name('inventory_cross_transfer_document')->where('remark', $tag)->count(), 'all synthetic receiver fixtures and documents rolled back');
