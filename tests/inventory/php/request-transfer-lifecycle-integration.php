<?php
declare(strict_types=1);

// 本用例只允许隔离库；先阻断误传的生产库名，再准备必需的历史演进表。
if (($database = getenv('DB_DATABASE')) !== false && $database !== '' && $database !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('isolated_inventory_test_database_required');
}
require __DIR__ . '/manual-test-bootstrap.php';
$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
if ((string)\think\facade\Config::get('database.connections.mysql.database') !== 'inventory_manual_inbound_test_20260730') {
    throw new RuntimeException('isolated_inventory_test_database_required');
}
foreach ([
    '2026-08-05-库存V3总部请货方',
    '2026-08-05-库存V3请货终止与调拨冲销',
    '2026-08-06-库存V3请货人与总部盘点',
    '2026-10-02-库存V3请货受理方',
] as $upgrade) executeInventorySqlFile($backend . '/database/upgrades/' . $upgrade . '/02-正式升级.sql');

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryStockRequestServices;
use app\services\product\inventory\InventoryStockRequestQueryServices;
use app\services\product\inventory\query\InventoryRequestUnifiedQueryProvider;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use think\facade\Db;

$failed = 0;
function lifecycleIntegrationAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$condition) $failed++; }
function lifecycleIntegrationReason(callable $operation): string { try { $operation(); } catch (Throwable $exception) { return $exception->getMessage(); } return ''; }

$ids = ['root'=>998800,'org'=>998801,'store'=>998802,'staff'=>998803,'location'=>998804,'sourceStock'=>998805,'sourceBatch'=>998806,'targetStock'=>998807,'targetBatch'=>998808,'targetStore'=>998809,'targetStaff'=>998810,'targetOrg'=>998811,'targetLocation'=>998812];
$now = time();
Db::startTrans();
try {
    Db::name('organization')->insert(['id'=>$ids['root'],'pid'=>0,'name'=>'TEST 生命周期根组织','status'=>1,'is_del'=>0]);
    Db::name('organization')->insert(['id'=>$ids['org'],'pid'=>$ids['root'],'name'=>'TEST 生命周期组织','status'=>1,'is_del'=>0]);
    Db::name('organization')->insert(['id'=>$ids['targetOrg'],'pid'=>$ids['root'],'name'=>'TEST 生命周期调入组织','status'=>1,'is_del'=>0]);
    Db::name('system_store')->insert(['id'=>$ids['store'],'name'=>'TEST 生命周期门店','is_del'=>0,'is_show'=>1]);
    Db::name('system_store_staff')->insert(['id'=>$ids['staff'],'store_id'=>$ids['store'],'staff_name'=>'TEST 生命周期调出人','status'=>1,'is_del'=>0]);
    Db::name('organization_store')->insert(['store_id'=>$ids['store'],'org_id'=>$ids['org']]);
    Db::name('system_store')->insert(['id'=>$ids['targetStore'],'name'=>'TEST 生命周期调入门店','is_del'=>0,'is_show'=>1]);
    Db::name('system_store_staff')->insert(['id'=>$ids['targetStaff'],'store_id'=>$ids['targetStore'],'staff_name'=>'TEST 生命周期调入人','status'=>1,'is_del'=>0]);
    Db::name('organization_store')->insert(['store_id'=>$ids['targetStore'],'org_id'=>$ids['targetOrg']]);
    Db::name('inventory_location')->insert(['id'=>$ids['location'],'tenant_id'=>'0','organization_id'=>(string)$ids['org'],'organization_path'=>'/'.$ids['root'].'/'.$ids['org'].'/','organization_name_snapshot'=>'TEST 生命周期组织','location_type'=>'STORE','owner_id'=>$ids['store'],'location_code'=>'TEST-LIFECYCLE-STORE','location_name'=>'默认门店仓','store_id'=>$ids['store'],'store_name_snapshot'=>'TEST 生命周期门店','is_default'=>1,'location_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('inventory_location')->insert(['id'=>$ids['targetLocation'],'tenant_id'=>'0','organization_id'=>(string)$ids['targetOrg'],'organization_path'=>'/'.$ids['root'].'/'.$ids['targetOrg'].'/','organization_name_snapshot'=>'TEST 生命周期调入组织','location_type'=>'STORE','owner_id'=>$ids['targetStore'],'location_code'=>'TEST-LIFECYCLE-TARGET','location_name'=>'默认门店仓','store_id'=>$ids['targetStore'],'store_name_snapshot'=>'TEST 生命周期调入门店','is_default'=>1,'location_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);

    $requestService = new InventoryStockRequestServices();
    $requestBase = ['tenant_id'=>'0','organization_id'=>(string)$ids['org'],'organization_path'=>'/'.$ids['root'].'/'.$ids['org'].'/','location_id'=>$ids['location'],'store_id'=>$ids['store'],'request_party_type'=>'STORE','request_party_id'=>$ids['store'],'request_party_name_snapshot'=>'TEST 生命周期门店','supply_party_type'=>'HQ','supply_party_id'=>0,'supply_party_name_snapshot'=>'总部仓','operator_id'=>$ids['staff'],'remark'=>'TEST','business_date'=>'2026-08-05','applied_at'=>$now,'recorded_at'=>$now];
    $cancelRequest = (int)Db::name('inventory_stock_request_document')->insertGetId($requestBase + ['request_no'=>'TEST-REQ-CANCEL','idempotency_key'=>'TEST-REQ-CREATE-CANCEL','request_fingerprint'=>str_repeat('a',64),'document_status'=>'APPLIED']);
    $cancel = $requestService->cancelForStore($ids['store'],$ids['staff'],$cancelRequest,['idempotency_key'=>'TEST-REQ-CANCEL-CMD','reason'=>'录入错误']);
    $cancelReplay = $requestService->cancelForStore($ids['store'],$ids['staff'],$cancelRequest,['idempotency_key'=>'TEST-REQ-CANCEL-CMD','reason'=>'录入错误']);
    lifecycleIntegrationAssert('APPLIED request cancellation is idempotent and keeps an audit operation', !$cancel['idempotent'] && $cancelReplay['idempotent'] && (int)Db::name('inventory_stock_request_lifecycle_operation')->where('request_document_id',$cancelRequest)->count()===1);

    $partialRequest = (int)Db::name('inventory_stock_request_document')->insertGetId($requestBase + ['request_no'=>'TEST-REQ-PARTIAL','idempotency_key'=>'TEST-REQ-CREATE-PARTIAL','request_fingerprint'=>str_repeat('b',64),'document_status'=>'PARTIAL']);
    $partialLine = (int)Db::name('inventory_stock_request_line')->insertGetId(['document_id'=>$partialRequest,'line_no'=>1,'product_id'=>1,'sku_id'=>1,'sku_unique'=>'TEST','quantity_scale'=>0,'requested_quantity_units'=>10,'created_at'=>$now]);
    Db::name('inventory_stock_request_fulfillment')->insert(['request_document_id'=>$partialRequest,'request_line_id'=>$partialLine,'transfer_document_id'=>900001,'transfer_line_id'=>900001,'fulfilled_quantity_units'=>4,'received_at'=>$now]);
    $terminate = $requestService->terminateForStore($ids['store'],$ids['staff'],$partialRequest,['idempotency_key'=>'TEST-REQ-TERMINATE-CMD','reason'=>'剩余数量不再需要']);
    lifecycleIntegrationAssert('PARTIAL request termination preserves fulfilled quantity and closes only remaining demand', $terminate['document_status']==='TERMINATED' && (int)Db::name('inventory_stock_request_fulfillment')->where('request_document_id',$partialRequest)->sum('fulfilled_quantity_units')===4);
    $doneRequest = (int)Db::name('inventory_stock_request_document')->insertGetId($requestBase + ['request_no'=>'TEST-REQ-DONE','idempotency_key'=>'TEST-REQ-CREATE-DONE','request_fingerprint'=>str_repeat('f',64),'document_status'=>'DONE']);
    lifecycleIntegrationAssert('DONE request cannot be directly cancelled', lifecycleIntegrationReason(fn()=> $requestService->cancelForStore($ids['store'],$ids['staff'],$doneRequest,['idempotency_key'=>'TEST-REQ-WRONG-CANCEL','reason'=>'错误尝试']))==='inventory_stock_request_cancel_state_invalid');

    Db::name('inventory_stock')->insert(['id'=>$ids['sourceStock'],'tenant_id'=>'0','organization_id'=>(string)$ids['org'],'organization_path'=>'/'.$ids['root'].'/'.$ids['org'].'/','location_id'=>$ids['location'],'store_id'=>$ids['store'],'consumable_product_id'=>1,'sku_id'=>1,'product_unique'=>'SRC','stock_status'=>'GOOD','stock_unit'=>'件','quantity_scale'=>0,'available_quantity_units'=>7,'estimated_unit_cost_cents'=>500,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('inventory_batch')->insert(['id'=>$ids['sourceBatch'],'stock_id'=>$ids['sourceStock'],'origin_batch_id'=>$ids['sourceBatch'],'source_batch_id'=>0,'batch_no'=>'TEST-SOURCE','available_quantity_units'=>7,'unit_cost_cents'=>500,'batch_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $transfer = (int)Db::name('inventory_cross_transfer_document')->insertGetId(['transfer_no'=>'TEST-XFER-DISPATCHED','idempotency_key'=>'TEST-XFER-CREATE-DISPATCHED','request_fingerprint'=>str_repeat('c',64),'tenant_id'=>'0','from_party_type'=>'STORE','from_party_id'=>$ids['store'],'from_party_name_snapshot'=>'TEST 生命周期门店','from_location_id'=>$ids['location'],'to_party_type'=>'HQ','to_party_id'=>0,'to_party_name_snapshot'=>'总部仓','to_location_id'=>999999,'created_by_operator_id'=>$ids['staff'],'dispatched_by_operator_id'=>$ids['staff'],'document_status'=>'DISPATCHED','remark'=>'TEST','business_date'=>'2026-08-05','dispatched_at'=>$now,'recorded_at'=>$now]);
    $line = (int)Db::name('inventory_cross_transfer_line')->insertGetId(['document_id'=>$transfer,'line_no'=>1,'from_product_id'=>1,'from_sku_id'=>1,'from_sku_unique'=>'SRC','to_product_id'=>2,'to_sku_id'=>2,'to_sku_unique'=>'DST','requested_quantity_units'=>3,'quantity_scale'=>0,'created_at'=>$now]);
    $allocation = (int)Db::name('inventory_cross_transfer_batch_allocation')->insertGetId(['document_id'=>$transfer,'line_id'=>$line,'from_stock_id'=>$ids['sourceStock'],'from_batch_id'=>$ids['sourceBatch'],'origin_batch_id'=>$ids['sourceBatch'],'quantity_units'=>3,'unit_cost_cents'=>500,'quantity_scale'=>0,'dispatched_at'=>$now]);
    (new InventoryBatchMovementFactServices())->append(['factKey'=>'TEST-XFER-OUT','tenantId'=>'0','organizationId'=>(string)$ids['org'],'organizationPath'=>'/'.$ids['root'].'/'.$ids['org'].'/','storeId'=>$ids['store'],'stockId'=>$ids['sourceStock'],'batchId'=>$ids['sourceBatch'],'direction'=>-1,'quantityUnits'=>3,'unitCostCents'=>500,'costAmountCents'=>1500,'sourceType'=>'cross_transfer_out','sourceId'=>'TEST-XFER-DISPATCHED','sourceDetailId'=>(string)$allocation,'reversalOf'=>0,'businessDate'=>'2026-08-05','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);
    $transferService = new InventoryCrossSubjectTransferServices();
    // 调拨不能被新接口作废；拒绝操作必须保持既有库存、流水和单据状态不变。
    lifecycleIntegrationAssert('transfer service rejects cancel and reverse without inventory mutation',
        lifecycleIntegrationReason(fn()=> $transferService->cancelForStore($ids['store'],$ids['staff'],$transfer))==='inventory_cross_transfer_void_disabled'
        && lifecycleIntegrationReason(fn()=> $transferService->reverseForStore($ids['store'],$ids['staff'],$transfer,['idempotency_key'=>'TEST-XFER-REVERSE-CMD','reason'=>'调拨录入错误']))==='inventory_cross_transfer_void_disabled'
        && (int)Db::name('inventory_batch')->where('id',$ids['sourceBatch'])->value('available_quantity_units')===7
        && (int)Db::name('inventory_batch_movement_fact')->where('source_type','cross_transfer_out_reversal')->where('source_id','TEST-XFER-DISPATCHED')->count()===0
        && (string)Db::name('inventory_cross_transfer_document')->where('id',$transfer)->value('document_status')==='DISPATCHED');
    $transferRows = $transferService->listForStore($ids['store'], $ids['staff'], 'TEST-XFER-DISPATCHED')['list'];
    lifecycleIntegrationAssert('transfer list exposes no cancellation or reversal action',
        count($transferRows) === 1 && !(bool)$transferRows[0]['can_cancel'] && !(bool)$transferRows[0]['can_reverse']);

    Db::name('inventory_stock')->where('id',$ids['sourceStock'])->update(['available_quantity_units'=>7,'version'=>3,'updated_at'=>$now]);
    Db::name('inventory_batch')->where('id',$ids['sourceBatch'])->update(['available_quantity_units'=>7,'version'=>3,'updated_at'=>$now]);
    Db::name('inventory_stock')->insert(['id'=>$ids['targetStock'],'tenant_id'=>'0','organization_id'=>(string)$ids['targetOrg'],'organization_path'=>'/'.$ids['root'].'/'.$ids['targetOrg'].'/','location_id'=>$ids['targetLocation'],'store_id'=>$ids['targetStore'],'consumable_product_id'=>2,'sku_id'=>2,'product_unique'=>'DST','stock_status'=>'GOOD','stock_unit'=>'件','quantity_scale'=>0,'available_quantity_units'=>3,'estimated_unit_cost_cents'=>500,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('inventory_batch')->insert(['id'=>$ids['targetBatch'],'stock_id'=>$ids['targetStock'],'origin_batch_id'=>$ids['sourceBatch'],'source_batch_id'=>$ids['sourceBatch'],'batch_no'=>'TEST-SOURCE','available_quantity_units'=>3,'unit_cost_cents'=>500,'batch_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $receivedRequestBase = $requestBase;
    $receivedRequestBase['organization_id']=(string)$ids['targetOrg']; $receivedRequestBase['organization_path']='/'.$ids['root'].'/'.$ids['targetOrg'].'/'; $receivedRequestBase['location_id']=$ids['targetLocation']; $receivedRequestBase['store_id']=$ids['targetStore']; $receivedRequestBase['request_party_id']=$ids['targetStore']; $receivedRequestBase['request_party_name_snapshot']='TEST 生命周期调入门店'; $receivedRequestBase['operator_id']=$ids['targetStaff'];
    $receivedRequestBase['supply_party_type']='STORE'; $receivedRequestBase['supply_party_id']=$ids['store']; $receivedRequestBase['supply_party_name_snapshot']='TEST 生命周期门店';
    $receivedRequest=(int)Db::name('inventory_stock_request_document')->insertGetId($receivedRequestBase+['request_no'=>'TEST-REQ-RECEIVED','idempotency_key'=>'TEST-REQ-CREATE-RECEIVED','request_fingerprint'=>str_repeat('d',64),'document_status'=>'PARTIAL']);
    $receivedRequestLine=(int)Db::name('inventory_stock_request_line')->insertGetId(['document_id'=>$receivedRequest,'line_no'=>1,'product_id'=>2,'sku_id'=>2,'sku_unique'=>'DST','quantity_scale'=>0,'requested_quantity_units'=>5,'created_at'=>$now]);
    $draftTransfer=(int)Db::name('inventory_cross_transfer_document')->insertGetId(['transfer_no'=>'TEST-XFER-DRAFT-LINKED','idempotency_key'=>'TEST-XFER-CREATE-DRAFT-LINKED','request_fingerprint'=>str_repeat('9',64),'tenant_id'=>'0','from_party_type'=>'STORE','from_party_id'=>$ids['store'],'from_party_name_snapshot'=>'TEST 生命周期门店','from_location_id'=>$ids['location'],'to_party_type'=>'STORE','to_party_id'=>$ids['targetStore'],'to_party_name_snapshot'=>'TEST 生命周期调入门店','to_location_id'=>$ids['targetLocation'],'request_document_id'=>$receivedRequest,'initiator_store_id'=>$ids['store'],'created_by_operator_id'=>$ids['staff'],'document_status'=>'DRAFT','remark'=>'TEST','business_date'=>'2026-08-05','recorded_at'=>$now]);
    Db::name('inventory_cross_transfer_line')->insert(['document_id'=>$draftTransfer,'line_no'=>1,'request_line_id'=>$receivedRequestLine,'from_product_id'=>1,'from_sku_id'=>1,'from_sku_unique'=>'SRC','to_product_id'=>2,'to_sku_id'=>2,'to_sku_unique'=>'DST','requested_quantity_units'=>2,'quantity_scale'=>0,'created_at'=>$now]);
    $receivedTransfer=(int)Db::name('inventory_cross_transfer_document')->insertGetId(['transfer_no'=>'TEST-XFER-RECEIVED','idempotency_key'=>'TEST-XFER-CREATE-RECEIVED','request_fingerprint'=>str_repeat('e',64),'tenant_id'=>'0','from_party_type'=>'STORE','from_party_id'=>$ids['store'],'from_party_name_snapshot'=>'TEST 生命周期门店','from_location_id'=>$ids['location'],'to_party_type'=>'STORE','to_party_id'=>$ids['targetStore'],'to_party_name_snapshot'=>'TEST 生命周期调入门店','to_location_id'=>$ids['targetLocation'],'request_document_id'=>$receivedRequest,'created_by_operator_id'=>$ids['staff'],'dispatched_by_operator_id'=>$ids['staff'],'received_by_operator_id'=>$ids['targetStaff'],'document_status'=>'RECEIVED','remark'=>'TEST','business_date'=>'2026-08-05','dispatched_at'=>$now,'received_at'=>$now,'recorded_at'=>$now]);
    $receivedLine=(int)Db::name('inventory_cross_transfer_line')->insertGetId(['document_id'=>$receivedTransfer,'line_no'=>1,'request_line_id'=>$receivedRequestLine,'from_product_id'=>1,'from_sku_id'=>1,'from_sku_unique'=>'SRC','to_product_id'=>2,'to_sku_id'=>2,'to_sku_unique'=>'DST','requested_quantity_units'=>3,'quantity_scale'=>0,'created_at'=>$now]);
    $receivedAllocation=(int)Db::name('inventory_cross_transfer_batch_allocation')->insertGetId(['document_id'=>$receivedTransfer,'line_id'=>$receivedLine,'from_stock_id'=>$ids['sourceStock'],'to_stock_id'=>$ids['targetStock'],'from_batch_id'=>$ids['sourceBatch'],'to_batch_id'=>$ids['targetBatch'],'origin_batch_id'=>$ids['sourceBatch'],'quantity_units'=>3,'unit_cost_cents'=>500,'quantity_scale'=>0,'dispatched_at'=>$now,'received_at'=>$now]);
    Db::name('inventory_stock_request_fulfillment')->insert(['request_document_id'=>$receivedRequest,'request_line_id'=>$receivedRequestLine,'transfer_document_id'=>$receivedTransfer,'transfer_line_id'=>$receivedLine,'fulfilled_quantity_units'=>3,'received_at'=>$now]);
    $facts=new InventoryBatchMovementFactServices();
    $facts->append(['factKey'=>'TEST-XFER-RECEIVED-OUT','tenantId'=>'0','organizationId'=>(string)$ids['org'],'organizationPath'=>'/'.$ids['root'].'/'.$ids['org'].'/','storeId'=>$ids['store'],'stockId'=>$ids['sourceStock'],'batchId'=>$ids['sourceBatch'],'direction'=>-1,'quantityUnits'=>3,'unitCostCents'=>500,'costAmountCents'=>1500,'sourceType'=>'cross_transfer_out','sourceId'=>'TEST-XFER-RECEIVED','sourceDetailId'=>(string)$receivedAllocation,'reversalOf'=>0,'businessDate'=>'2026-08-05','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);
    $facts->append(['factKey'=>'TEST-XFER-RECEIVED-IN','tenantId'=>'0','organizationId'=>(string)$ids['targetOrg'],'organizationPath'=>'/'.$ids['root'].'/'.$ids['targetOrg'].'/','storeId'=>$ids['targetStore'],'stockId'=>$ids['targetStock'],'batchId'=>$ids['targetBatch'],'direction'=>1,'quantityUnits'=>3,'unitCostCents'=>500,'costAmountCents'=>1500,'sourceType'=>'cross_transfer_in','sourceId'=>'TEST-XFER-RECEIVED','sourceDetailId'=>(string)$receivedAllocation,'reversalOf'=>0,'businessDate'=>'2026-08-05','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);
    $requestQuery = new InventoryStockRequestQueryServices();
    $supplierList = $requestQuery->list($ids['store'], $ids['staff'], 'TEST-REQ-RECEIVED');
    $supplierDetail = $requestQuery->detail($ids['store'], $ids['staff'], $receivedRequest);
    $requesterDetail = $requestQuery->detail($ids['targetStore'], $ids['targetStaff'], $receivedRequest);
    lifecycleIntegrationAssert('supplier sees incoming request but linked transfer disables void on both sides',
        (int)$supplierList['count'] === 1 && !(bool)$supplierDetail['can_void'] && !(bool)$requesterDetail['can_void']);
    // 页面消费统一查询投影，而不是旧列表接口；两条读路径都必须给出相同供货方权限。
    $provider = (new ReflectionClass(InventoryRequestUnifiedQueryProvider::class))->newInstanceWithoutConstructor();
    $projectRows = new ReflectionMethod($provider, 'requestRows'); $projectRows->setAccessible(true);
    $supplierRows = $projectRows->invoke($provider, '0', $ids['store'], [$ids['location']], false);
    $incoming = array_values(array_filter($supplierRows, static fn(array $row): bool => (int)$row['id'] === $receivedRequest));
    lifecycleIntegrationAssert('unified request page keeps incoming supplier row visible but not voidable', count($incoming) === 1
        && !(bool)$incoming[0]['can_void'] && (int)$incoming[0]['location_id'] === $ids['location']);
    $platformEligibility = new ReflectionMethod($requestQuery, 'canVoidForPlatform'); $platformEligibility->setAccessible(true);
    lifecycleIntegrationAssert('platform also hides void after any transfer, even with supplier-store grant',
        !$platformEligibility->invoke($requestQuery, $receivedRequest, 'PARTIAL', 'STORE', $ids['store'], ['store_ids' => [$ids['store']], 'is_super_admin' => false]));
    lifecycleIntegrationAssert('requester-side void is denied even for its own request', lifecycleIntegrationReason(fn() => $requestService->voidForStore($ids['targetStore'], $ids['targetStaff'], $receivedRequest, ['idempotency_key'=>'TEST-REQ-WRONG-SUPPLIER','reason'=>'无权冲销供货方库存'])) === 'inventory_stock_request_void_supplier_denied');
    $linkedVoid = lifecycleIntegrationReason(fn() => $requestService->voidForStore($ids['store'], $ids['staff'], $receivedRequest, ['idempotency_key'=>'TEST-REQ-VOID-LINKED','reason'=>'有调拨时禁止作废']));
    lifecycleIntegrationAssert('draft and received transfers block request void without changing stock or fulfillment', $linkedVoid === 'inventory_stock_request_void_transfer_exists'
        && (string)Db::name('inventory_stock_request_document')->where('id',$receivedRequest)->value('document_status') === 'PARTIAL'
        && (string)Db::name('inventory_cross_transfer_document')->where('id',$draftTransfer)->value('document_status') === 'DRAFT'
        && (string)Db::name('inventory_cross_transfer_document')->where('id',$receivedTransfer)->value('document_status') === 'RECEIVED'
        && (int)Db::name('inventory_batch')->where('id',$ids['targetBatch'])->value('available_quantity_units') === 3
        && (int)Db::name('inventory_stock_request_fulfillment_reversal')->where('request_document_id',$receivedRequest)->count() === 0);
    lifecycleIntegrationAssert('legacy terminate command cannot close a partially fulfilled linked request',
        lifecycleIntegrationReason(fn() => $requestService->terminateForStore($ids['targetStore'], $ids['targetStaff'], $receivedRequest, ['idempotency_key'=>'TEST-REQ-OLD-TERMINATE','reason'=>'旧接口绕过测试'])) === 'inventory_stock_request_void_transfer_exists');

    $historicalRequest=(int)Db::name('inventory_stock_request_document')->insertGetId($receivedRequestBase+['request_no'=>'TEST-REQ-HISTORICAL','idempotency_key'=>'TEST-REQ-CREATE-HISTORICAL','request_fingerprint'=>str_repeat('7',64),'document_status'=>'APPLIED']);
    Db::name('inventory_cross_transfer_document')->insert(['transfer_no'=>'TEST-XFER-CANCELLED-LINKED','idempotency_key'=>'TEST-XFER-CREATE-CANCELLED','request_fingerprint'=>str_repeat('6',64),'tenant_id'=>'0','from_party_type'=>'STORE','from_party_id'=>$ids['store'],'from_party_name_snapshot'=>'TEST 生命周期门店','from_location_id'=>$ids['location'],'to_party_type'=>'STORE','to_party_id'=>$ids['targetStore'],'to_party_name_snapshot'=>'TEST 生命周期调入门店','to_location_id'=>$ids['targetLocation'],'request_document_id'=>$historicalRequest,'created_by_operator_id'=>$ids['staff'],'document_status'=>'CANCELLED','remark'=>'历史取消调拨','business_date'=>'2026-08-05','recorded_at'=>$now]);
    lifecycleIntegrationAssert('historically cancelled transfer still blocks request void',
        !$requestQuery->detail($ids['store'], $ids['staff'], $historicalRequest)['can_void']
        && lifecycleIntegrationReason(fn() => $requestService->voidForStore($ids['store'], $ids['staff'], $historicalRequest, ['idempotency_key'=>'TEST-REQ-HISTORICAL-VOID','reason'=>'历史调拨不重开'])) === 'inventory_stock_request_void_transfer_exists');

    $unlinkedBase = $receivedRequestBase; $unlinkedBase['document_status'] = 'APPLIED';
    $unlinkedRequest = (int)Db::name('inventory_stock_request_document')->insertGetId($unlinkedBase + ['request_no'=>'TEST-REQ-UNLINKED','idempotency_key'=>'TEST-REQ-CREATE-UNLINKED','request_fingerprint'=>str_repeat('8',64)]);
    lifecycleIntegrationAssert('unlinked supplier request remains voidable with supplier grant',
        $requestQuery->detail($ids['store'], $ids['staff'], $unlinkedRequest)['can_void']
        && $platformEligibility->invoke($requestQuery, $unlinkedRequest, 'APPLIED', 'STORE', $ids['store'], ['store_ids' => [$ids['store']], 'is_super_admin' => false]));
    $voidCommand = ['idempotency_key'=>'TEST-REQ-UNLINKED-VOID','reason'=>'无调拨请货作废'];
    $unlinkedVoid = $requestService->voidForStore($ids['store'], $ids['staff'], $unlinkedRequest, $voidCommand);
    $unlinkedReplay = $requestService->voidForStore($ids['store'], $ids['staff'], $unlinkedRequest, $voidCommand);
    lifecycleIntegrationAssert('unlinked request void is idempotent and creates no transfer or inventory fact',
        $unlinkedVoid['document_status'] === 'VOIDED' && !$unlinkedVoid['idempotent'] && $unlinkedReplay['idempotent']
        && (int)Db::name('inventory_cross_transfer_document')->where('request_document_id',$unlinkedRequest)->count() === 0
        && (int)Db::name('inventory_batch')->where('id',$ids['targetBatch'])->value('available_quantity_units') === 3);

    echo "REQUEST_TRANSFER_LIFECYCLE_INTEGRATION_RESULT failed={$failed}\n";
    Db::rollback();
    exit($failed===0 ? 0 : 1);
} catch (Throwable $exception) {
    Db::rollback();
    fwrite(STDERR, 'INTEGRATION_ERROR ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
