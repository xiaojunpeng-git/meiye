<?php
declare(strict_types=1);

// This test may create catalog and transfer rows only inside the dedicated inventory test database.
if (($database = getenv('DB_DATABASE')) !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('isolated_inventory_test_database_required');
}
require __DIR__ . '/manual-test-bootstrap.php';

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\http\middleware\store\StoreCkeckRoleMiddleware;
use think\facade\Config;
use think\facade\Db;

if ((string)Config::get('database.connections.mysql.database') !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('isolated_inventory_test_database_required');
}
// The shared minimal fixture predates cross-subject product mapping; add its missing catalog column only in this isolated database.
if (!Db::query("SHOW COLUMNS FROM eb_store_product LIKE 'pid'")) {
    Db::execute('ALTER TABLE eb_store_product ADD COLUMN pid bigint(20) unsigned NOT NULL DEFAULT 0');
}
foreach ([
    'transfer_staff_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
    'transfer_employee_id' => 'bigint(20) unsigned NOT NULL DEFAULT 0',
    'transfer_staff_name_snapshot' => "varchar(120) NOT NULL DEFAULT ''",
    'created_by_operator_name_snapshot' => "varchar(128) NOT NULL DEFAULT ''",
] as $column => $definition) {
    if (!Db::query("SHOW COLUMNS FROM eb_inventory_cross_transfer_document LIKE '{$column}'")) {
        Db::execute("ALTER TABLE eb_inventory_cross_transfer_document ADD COLUMN {$column} {$definition}");
    }
}
// The legacy minimal fixture has no employee directory; only this guarded test DB receives one.
if (!Db::query("SHOW TABLES LIKE 'eb_employee'")) {
    Db::execute('CREATE TABLE eb_employee (id bigint(20) unsigned NOT NULL PRIMARY KEY, name varchar(120) NOT NULL, status tinyint(1) NOT NULL DEFAULT 1, is_del tinyint(1) NOT NULL DEFAULT 0)');
}

function editIntegrationAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}

function editIntegrationReason(callable $operation): string
{
    try { $operation(); } catch (Throwable $error) { return $error->getMessage(); }
    return '';
}

$failed = 0;
$transferRoute = new ReflectionMethod(StoreCkeckRoleMiddleware::class, 'isDelegatedTransferCommand');
$transferRoute->setAccessible(true);
$transferMiddleware = new StoreCkeckRoleMiddleware();
editIntegrationAssert('view-mode route gate admits transfer create, update, draft deletion, dispatch and receipt',
    $transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer', 'POST')
    && $transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/update', 'POST')
    && $transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/delete-draft', 'POST')
    && $transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/dispatch', 'POST')
    && $transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/receive', 'POST')
    && !$transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/cancel', 'POST')
    && !$transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer/12/delete-draft', 'GET')
    && !$transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/inbound', 'POST')
    && !$transferRoute->invoke($transferMiddleware, 'storeapi/product/inventory/v3/cross-transfer', 'GET'));
$seed = random_int(800000000, 900000000);
$rootId = $seed;
$sourceId = $seed + 1;
$targetId = $seed + 2;
$otherId = $seed + 3;
$outsideRootId = $seed + 50;
$outsideStoreId = $seed + 51;
$sourceStaffId = $seed + 4;
$targetStaffId = $seed + 5;
$employeeId = $seed + 6;
$organizationSessionId = $seed + 7;
$baseProductId = $seed + 10;
$sourceProductId = $seed + 11;
$targetProductId = $seed + 12;
$otherProductId = $seed + 13;
$sourceSkuId = $seed + 21;
$targetSkuId = $seed + 22;
$otherSkuId = $seed + 23;
$now = time();
$service = new InventoryCrossSubjectTransferServices();
Db::startTrans();
try {
    Db::name('organization')->insert(['id' => $rootId, 'pid' => 0, 'name' => 'TEST 调拨编辑组织', 'status' => 1, 'is_del' => 0]);
    foreach ([$sourceId => '调出', $targetId => '调入', $otherId => '备选调入'] as $storeId => $name) {
        Db::name('organization')->insert(['id' => $storeId, 'pid' => $rootId, 'name' => 'TEST ' . $name, 'status' => 1, 'is_del' => 0]);
        Db::name('system_store')->insert(['id' => $storeId, 'name' => 'TEST ' . $name, 'is_del' => 0, 'is_show' => 1]);
        Db::name('organization_store')->insert(['store_id' => $storeId, 'org_id' => $storeId]);
        Db::name('inventory_location')->insert([
            'tenant_id' => '0', 'organization_id' => (string)$storeId, 'organization_path' => "/{$rootId}/{$storeId}/",
            'organization_name_snapshot' => 'TEST 调拨编辑组织', 'location_type' => 'STORE', 'owner_id' => $storeId,
            'location_code' => 'TEST-EDIT-' . $storeId, 'location_name' => '默认门店仓', 'store_id' => $storeId,
            'store_name_snapshot' => 'TEST ' . $name, 'is_default' => 1, 'location_status' => 'ACTIVE',
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    Db::name('organization')->insert(['id' => $outsideRootId, 'pid' => 0, 'name' => 'TEST 其他组织', 'status' => 1, 'is_del' => 0]);
    Db::name('system_store')->insert(['id' => $outsideStoreId, 'name' => 'TEST 其他组织门店', 'is_del' => 0, 'is_show' => 1]);
    Db::name('organization_store')->insert(['store_id' => $outsideStoreId, 'org_id' => $outsideRootId]);
    Db::name('system_store_staff')->insert(['id' => $sourceStaffId, 'store_id' => $sourceId, 'staff_name' => 'TEST 调出人', 'status' => 1, 'is_del' => 0]);
    Db::name('system_store_staff')->insert(['id' => $targetStaffId, 'store_id' => $targetId, 'staff_name' => 'TEST 调入人', 'status' => 1, 'is_del' => 0]);
    Db::name('employee')->insert(['id' => $employeeId, 'name' => 'TEST 组织员工', 'status' => 1, 'is_del' => 0]);
    Db::name('store_product')->insert(['id' => $baseProductId, 'type' => 0, 'relation_id' => 0, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => 'TEST 调拨编辑商品']);
    foreach ([[$sourceProductId, $sourceSkuId, $sourceId, 'source'], [$targetProductId, $targetSkuId, $targetId, 'target'], [$otherProductId, $otherSkuId, $otherId, 'other']] as [$productId, $skuId, $storeId, $suffix]) {
        Db::name('store_product')->insert(['id' => $productId, 'pid' => $baseProductId, 'type' => 1, 'relation_id' => $storeId, 'is_inventory' => 1, 'is_del' => 0, 'store_name' => 'TEST 调拨编辑商品']);
        Db::name('store_product_attr_value')->insert(['id' => $skuId, 'product_id' => $productId, 'type' => 0, 'unique' => 'test-edit-' . $seed . '-' . $suffix, 'suk' => '默认', 'stock_unit' => '盒']);
    }

    $line = ['product_id' => $sourceProductId, 'sku_id' => $sourceSkuId, 'sku_unique' => 'test-edit-' . $seed . '-source', 'quantity' => '2', 'request_line_id' => 0, 'detail_remark' => '原备注'];
    $command = ['idempotency_key' => 'test-transfer-edit-' . $seed, 'business_date' => '2026-10-09', 'remark' => '原草稿', 'target_party_type' => 'STORE', 'target_store_id' => $targetId, 'request_document_id' => 0, 'lines' => [$line]];
    $originalResolver = app()->make(CashierV3FeatureResolver::class);
    $originalProfile = (array)(request()->storeStaffInfo ?? []);
    $profile = ['id' => $organizationSessionId, 'employee_id' => $employeeId, 'store_id' => $sourceId,
        '_cashier_v3_organization' => 1, 'session_mode' => 'store_organization', 'read_only' => false];
    // Stub only the feature result; InventoryStoreAccessPolicy still checks the trusted session and active employee.
    app()->instance(CashierV3FeatureResolver::class, new class extends CashierV3FeatureResolver {
        public function resolveGrantedFeatures(array $operatorProfile): array { return ['cashier.v3.inventory.transfer']; }
    });
    try {
        request()->storeStaffInfo = $profile;
        $organizationCommand = $command;
        $organizationCommand['idempotency_key'] = 'test-transfer-org-' . $seed;
        $organizationId = (int)$service->createDraftForStore($sourceId, $organizationSessionId, $organizationCommand)['transfer_id'];
        $organizationRow = Db::name('inventory_cross_transfer_document')->where('id', $organizationId)->find();
        editIntegrationAssert('organization session creates a draft under its stable employee identity',
            (int)$organizationRow['created_by_operator_id'] === $employeeId
            && (string)$organizationRow['created_by_operator_name_snapshot'] === 'TEST 组织员工');
        $organizationEdit = $organizationCommand;
        $organizationEdit['idempotency_key'] = 'test-transfer-org-edit-' . $seed;
        $organizationEdit['expected_edit_token'] = (string)$service->detailForStore($sourceId, $organizationSessionId, $organizationId)['edit_token'];
        $organizationEdit['remark'] = '组织员工修改';
        $service->updateDraftForStore($sourceId, $organizationSessionId, $organizationId, $organizationEdit);
        editIntegrationAssert('organization session can revise its source-store draft',
            (string)Db::name('inventory_cross_transfer_document')->where('id', $organizationId)->value('remark') === '组织员工修改');
        $delegatedProfile = ['id' => $organizationSessionId, 'employee_id' => $employeeId, 'store_id' => $sourceId,
            '_cashier_v3_delegated' => 1, 'session_mode' => 'store_read_only', 'read_only' => true];
        request()->storeStaffInfo = $delegatedProfile;
        $delegatedCommand = $command;
        $delegatedCommand['idempotency_key'] = 'test-transfer-view-' . $seed;
        $delegatedId = (int)$service->createDraftForStore($sourceId, $organizationSessionId, $delegatedCommand)['transfer_id'];
        $delegatedRow = Db::name('inventory_cross_transfer_document')->where('id', $delegatedId)->find();
        editIntegrationAssert('transfer entry in view mode can save a draft with the real employee actor',
            (int)$delegatedRow['created_by_operator_id'] === $employeeId
            && (string)$delegatedRow['created_by_operator_name_snapshot'] === 'TEST 组织员工');
        $delegatedEdit = $delegatedCommand;
        $delegatedEdit['idempotency_key'] = 'test-transfer-view-edit-' . $seed;
        $delegatedEdit['expected_edit_token'] = (string)$service->detailForStore($sourceId, $organizationSessionId, $delegatedId)['edit_token'];
        $delegatedEdit['remark'] = '查看模式修改';
        $service->updateDraftForStore($sourceId, $organizationSessionId, $delegatedId, $delegatedEdit);
        editIntegrationAssert('view mode can edit only its selected source-store draft',
            (string)Db::name('inventory_cross_transfer_document')->where('id', $delegatedId)->value('remark') === '查看模式修改');
        $delegatedToken = (string)$service->detailForStore($sourceId, $organizationSessionId, $delegatedId)['edit_token'];
        $service->deleteDraftForStore($sourceId, $organizationSessionId, $delegatedId, $delegatedToken);
        editIntegrationAssert('view mode can permanently delete its own source-store draft',
            (int)Db::name('inventory_cross_transfer_document')->where('id', $delegatedId)->count() === 0
            && (int)Db::name('inventory_cross_transfer_line')->where('document_id', $delegatedId)->count() === 0);
        request()->storeStaffInfo = array_merge($delegatedProfile, ['store_id' => $targetId]);
        editIntegrationAssert('view mode cannot write a different store than its authenticated selection',
            editIntegrationReason(fn () => $service->createDraftForStore($sourceId, $organizationSessionId, $delegatedCommand)) === 'inventory_cross_transfer_scope_denied');
        request()->storeStaffInfo = $delegatedProfile;
        $outsideCommand = $delegatedCommand;
        $outsideCommand['target_store_id'] = $outsideStoreId;
        editIntegrationAssert('view mode cannot forge an out-of-organization receiving store',
            editIntegrationReason(fn () => $service->createDraftForStore($sourceId, $organizationSessionId, $outsideCommand)) === 'inventory_cross_transfer_target_scope_denied');
        request()->storeStaffInfo = array_merge($profile, ['read_only' => true]);
        editIntegrationAssert('read-only organization session cannot create a transfer',
            editIntegrationReason(fn () => $service->createDraftForStore($sourceId, $organizationSessionId, $organizationCommand)) === 'inventory_cross_transfer_scope_denied');
        request()->storeStaffInfo = $profile;
        app()->instance(CashierV3FeatureResolver::class, new class extends CashierV3FeatureResolver {
            public function resolveGrantedFeatures(array $operatorProfile): array { return []; }
        });
        editIntegrationAssert('organization employee without transfer feature cannot create a transfer',
            editIntegrationReason(fn () => $service->createDraftForStore($sourceId, $organizationSessionId, $organizationCommand)) === 'inventory_cross_transfer_scope_denied');
    } finally {
        request()->storeStaffInfo = $originalProfile;
        app()->instance(CashierV3FeatureResolver::class, $originalResolver);
    }
    $created = $service->createDraftForStore($sourceId, $sourceStaffId, $command);
    $documentId = (int)$created['transfer_id'];
    $detail = $service->detailForStore($sourceId, $sourceStaffId, $documentId);
    editIntegrationAssert('source sees an editable draft and recipient sees read-only detail',
        $detail['can_edit'] === true && $detail['can_delete'] === true
        && $service->detailForStore($targetId, $targetStaffId, $documentId)['can_delete'] === false);
    editIntegrationAssert('recipient cannot delete a source-store draft',
        editIntegrationReason(fn () => $service->deleteDraftForStore($targetId, $targetStaffId, $documentId, (string)$detail['edit_token'])) === 'inventory_cross_transfer_delete_state_invalid');
    $edit = $command;
    $edit['idempotency_key'] = 'test-transfer-update-' . $seed;
    $edit['expected_edit_token'] = (string)$detail['edit_token'];
    $edit['business_date'] = '2026-10-08';
    $edit['remark'] = '已编辑';
    $edit['target_store_id'] = $otherId;
    $edit['lines'][0]['quantity'] = '3';
    $edit['lines'][0]['detail_remark'] = '新备注';
    editIntegrationAssert('recipient store cannot replace source draft',
        editIntegrationReason(fn () => $service->updateDraftForStore($targetId, $targetStaffId, $documentId, $edit)) === 'inventory_cross_transfer_edit_state_invalid');
    $service->updateDraftForStore($sourceId, $sourceStaffId, $documentId, $edit);
    $updated = $service->detailForStore($sourceId, $sourceStaffId, $documentId);
    editIntegrationAssert('edit preserves document identity while replacing date, target and lines',
        $updated['transfer_no'] === $detail['transfer_no'] && $updated['business_date'] === '2026-10-08'
        && (int)$updated['to_party_id'] === $otherId && $updated['remark'] === '已编辑'
        && count($updated['lines']) === 1 && (int)$updated['lines'][0]['requested_quantity_units'] === 3
        && $updated['lines'][0]['detail_remark'] === '新备注');
    editIntegrationAssert('stale edit token is rejected without replacing the newer draft',
        editIntegrationReason(fn () => $service->updateDraftForStore($sourceId, $sourceStaffId, $documentId, $edit)) === 'inventory_cross_transfer_draft_changed');
    editIntegrationAssert('stale delete token cannot remove an edited draft',
        editIntegrationReason(fn () => $service->deleteDraftForStore($sourceId, $sourceStaffId, $documentId, (string)$detail['edit_token'])) === 'inventory_cross_transfer_draft_changed'
        && (int)Db::name('inventory_cross_transfer_document')->where('id', $documentId)->count() === 1);
    editIntegrationAssert('editing a draft creates no stock allocation or movement fact',
        (int)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)->count() === 0
        && (int)Db::name('inventory_batch_movement_fact')->where('source_id', (string)$detail['transfer_no'])->count() === 0);

    $requestId = (int)Db::name('inventory_stock_request_document')->insertGetId([
        'tenant_id' => '0', 'organization_id' => (string)$targetId, 'organization_path' => "/{$rootId}/{$targetId}/",
        'location_id' => (int)Db::name('inventory_location')->where('store_id', $targetId)->where('is_default', 1)->value('id'),
        'store_id' => $targetId, 'request_party_type' => 'STORE', 'request_party_id' => $targetId,
        'request_party_name_snapshot' => 'TEST 调入', 'supply_party_type' => 'STORE', 'supply_party_id' => $sourceId,
        'supply_party_name_snapshot' => 'TEST 调出', 'operator_id' => $targetStaffId,
        'request_no' => 'TEST-REQ-EDIT-' . $seed, 'idempotency_key' => 'test-request-edit-' . $seed,
        'request_fingerprint' => str_repeat('a', 64), 'document_status' => 'APPLIED',
        'remark' => '', 'business_date' => '2026-10-09', 'applied_at' => $now, 'recorded_at' => $now,
    ]);
    $requestLineId = (int)Db::name('inventory_stock_request_line')->insertGetId([
        'document_id' => $requestId, 'line_no' => 1, 'product_id' => $targetProductId, 'sku_id' => $targetSkuId,
        'sku_unique' => 'test-edit-' . $seed . '-target', 'quantity_scale' => 0,
        'requested_quantity_units' => 5, 'created_at' => $now,
    ]);
    $linkedCommand = $command;
    $linkedCommand['idempotency_key'] = 'test-linked-edit-' . $seed;
    $linkedCommand['request_document_id'] = $requestId;
    $linkedCommand['lines'][0]['request_line_id'] = $requestLineId;
    $linkedId = (int)$service->createDraftForStore($sourceId, $sourceStaffId, $linkedCommand)['transfer_id'];
    $linkedDetail = $service->detailForStore($sourceId, $sourceStaffId, $linkedId);
    $linkedEdit = $linkedCommand;
    $linkedEdit['idempotency_key'] = 'test-linked-update-' . $seed;
    $linkedEdit['expected_edit_token'] = (string)$linkedDetail['edit_token'];
    $linkedEdit['target_store_id'] = $otherId;
    editIntegrationAssert('request-linked draft cannot change its receiving store',
        editIntegrationReason(fn () => $service->updateDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedEdit)) === 'inventory_cross_transfer_request_target_locked');
    $linkedEdit['target_store_id'] = $targetId;
    $linkedEdit['lines'][0]['quantity'] = '3';
    $service->updateDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedEdit);
    editIntegrationAssert('request-linked draft can revise quantity while keeping request line association',
        (int)$service->detailForStore($sourceId, $sourceStaffId, $linkedId)['lines'][0]['request_line_id'] === $requestLineId
        && (int)$service->detailForStore($sourceId, $sourceStaffId, $linkedId)['lines'][0]['requested_quantity_units'] === 3);
    $linkedToken = (string)$service->detailForStore($sourceId, $sourceStaffId, $linkedId)['edit_token'];
    $linkedLineId = (int)$service->detailForStore($sourceId, $sourceStaffId, $linkedId)['lines'][0]['id'];
    Db::name('inventory_cross_transfer_batch_allocation')->insert(['document_id' => $linkedId, 'line_id' => $linkedLineId]);
    editIntegrationAssert('a draft with an allocation cannot be deleted',
        editIntegrationReason(fn () => $service->deleteDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedToken)) === 'inventory_cross_transfer_delete_facts_exist');
    Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $linkedId)->delete();
    Db::name('inventory_stock_request_fulfillment')->insert([
        'request_document_id' => $requestId, 'request_line_id' => $requestLineId,
        'transfer_document_id' => $linkedId, 'transfer_line_id' => $linkedLineId,
    ]);
    editIntegrationAssert('a draft with request fulfillment cannot be deleted',
        editIntegrationReason(fn () => $service->deleteDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedToken)) === 'inventory_cross_transfer_delete_facts_exist');
    Db::name('inventory_stock_request_fulfillment')->where('transfer_document_id', $linkedId)->delete();
    Db::name('inventory_batch_movement_fact')->insert([
        'tenant_id' => '0', 'fact_key' => 'test-delete-fact-' . $seed,
        'source_type' => 'cross_transfer_out', 'source_id' => (string)$linkedDetail['transfer_no'],
        'business_date' => '2026-10-09',
    ]);
    editIntegrationAssert('a draft with stock movement cannot be deleted',
        editIntegrationReason(fn () => $service->deleteDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedToken)) === 'inventory_cross_transfer_delete_facts_exist');
    Db::name('inventory_batch_movement_fact')->where('fact_key', 'test-delete-fact-' . $seed)->delete();
    $service->deleteDraftForStore($sourceId, $sourceStaffId, $linkedId, $linkedToken);
    editIntegrationAssert('physical deletion removes linked draft and lines but preserves the request',
        (int)Db::name('inventory_cross_transfer_document')->where('id', $linkedId)->count() === 0
        && (int)Db::name('inventory_cross_transfer_line')->where('document_id', $linkedId)->count() === 0
        && (int)Db::name('inventory_stock_request_document')->where('id', $requestId)->count() === 1);

    Db::name('inventory_cross_transfer_document')->where('id', $documentId)->update(['document_status' => 'DISPATCHED']);
    $edit['expected_edit_token'] = (string)$updated['edit_token'];
    editIntegrationAssert('a no-longer-draft transfer rejects edits before line replacement',
        editIntegrationReason(fn () => $service->updateDraftForStore($sourceId, $sourceStaffId, $documentId, $edit)) === 'inventory_cross_transfer_edit_state_invalid');
    editIntegrationAssert('a dispatched transfer cannot be physically deleted',
        editIntegrationReason(fn () => $service->deleteDraftForStore($sourceId, $sourceStaffId, $documentId, (string)$updated['edit_token'])) === 'inventory_cross_transfer_delete_state_invalid');
} finally {
    Db::rollback();
}

echo "CROSS_TRANSFER_DRAFT_EDIT_INTEGRATION_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
