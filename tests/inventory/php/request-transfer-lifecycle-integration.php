<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryStockRequestServices;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([['database.type','mysql'],['DATABASE_TYPE','mysql'],['database.hostname',getenv('DB_HOST') ?: 'mysql'],['DATABASE_HOSTNAME',getenv('DB_HOST') ?: 'mysql'],['database.hostport',getenv('DB_PORT') ?: '3306'],['DATABASE_HOSTPORT',getenv('DB_PORT') ?: '3306'],['database.database',getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801'],['DATABASE_DATABASE',getenv('DB_DATABASE') ?: 'ruihao_test_recovered_20260801'],['database.username',getenv('DB_USERNAME') ?: 'root'],['DATABASE_USERNAME',getenv('DB_USERNAME') ?: 'root'],['database.password',getenv('DB_PASSWORD') ?: 'localdev123'],['DATABASE_PASSWORD',getenv('DB_PASSWORD') ?: 'localdev123'],['cache.driver','file'],['CACHE_DRIVER','file']] as [$key,$value]) $app->env->set($key,$value);
$environment = new ReflectionProperty($app, 'envName'); $environment->setAccessible(true); $environment->setValue($app, 'inventory_lifecycle_test_skip_dotenv');
$app->initialize(); Config::set(['default'=>'file'], 'cache');

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
    Db::name('system_store_staff')->insert(['id'=>$ids['staff'],'store_id'=>$ids['store'],'status'=>1,'is_del'=>0]);
    Db::name('organization_store')->insert(['store_id'=>$ids['store'],'org_id'=>$ids['org']]);
    Db::name('system_store')->insert(['id'=>$ids['targetStore'],'name'=>'TEST 生命周期调入门店','is_del'=>0,'is_show'=>1]);
    Db::name('system_store_staff')->insert(['id'=>$ids['targetStaff'],'store_id'=>$ids['targetStore'],'status'=>1,'is_del'=>0]);
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
    $reverse = $transferService->reverseForStore($ids['store'],$ids['staff'],$transfer,['idempotency_key'=>'TEST-XFER-REVERSE-CMD','reason'=>'调拨录入错误']);
    $reverseReplay = $transferService->reverseForStore($ids['store'],$ids['staff'],$transfer,['idempotency_key'=>'TEST-XFER-REVERSE-CMD','reason'=>'调拨录入错误']);
    $reversalFact = Db::name('inventory_batch_movement_fact')->where('source_type','cross_transfer_out_reversal')->where('source_id','TEST-XFER-DISPATCHED')->find();
    lifecycleIntegrationAssert('DISPATCHED reversal restores the exact source batch once', !$reverse['idempotent'] && $reverseReplay['idempotent'] && (int)Db::name('inventory_batch')->where('id',$ids['sourceBatch'])->value('available_quantity_units')===10 && (int)$reversalFact['reversal_of']>0);

    Db::name('inventory_stock')->where('id',$ids['sourceStock'])->update(['available_quantity_units'=>7,'version'=>3,'updated_at'=>$now]);
    Db::name('inventory_batch')->where('id',$ids['sourceBatch'])->update(['available_quantity_units'=>7,'version'=>3,'updated_at'=>$now]);
    Db::name('inventory_stock')->insert(['id'=>$ids['targetStock'],'tenant_id'=>'0','organization_id'=>(string)$ids['targetOrg'],'organization_path'=>'/'.$ids['root'].'/'.$ids['targetOrg'].'/','location_id'=>$ids['targetLocation'],'store_id'=>$ids['targetStore'],'consumable_product_id'=>2,'sku_id'=>2,'product_unique'=>'DST','stock_status'=>'GOOD','stock_unit'=>'件','quantity_scale'=>0,'available_quantity_units'=>3,'estimated_unit_cost_cents'=>500,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('inventory_batch')->insert(['id'=>$ids['targetBatch'],'stock_id'=>$ids['targetStock'],'origin_batch_id'=>$ids['sourceBatch'],'source_batch_id'=>$ids['sourceBatch'],'batch_no'=>'TEST-SOURCE','available_quantity_units'=>3,'unit_cost_cents'=>500,'batch_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $receivedRequestBase = $requestBase;
    $receivedRequestBase['organization_id']=(string)$ids['targetOrg']; $receivedRequestBase['organization_path']='/'.$ids['root'].'/'.$ids['targetOrg'].'/'; $receivedRequestBase['location_id']=$ids['targetLocation']; $receivedRequestBase['store_id']=$ids['targetStore']; $receivedRequestBase['request_party_id']=$ids['targetStore']; $receivedRequestBase['request_party_name_snapshot']='TEST 生命周期调入门店'; $receivedRequestBase['operator_id']=$ids['targetStaff'];
    $receivedRequest=(int)Db::name('inventory_stock_request_document')->insertGetId($receivedRequestBase+['request_no'=>'TEST-REQ-RECEIVED','idempotency_key'=>'TEST-REQ-CREATE-RECEIVED','request_fingerprint'=>str_repeat('d',64),'document_status'=>'DONE']);
    $receivedRequestLine=(int)Db::name('inventory_stock_request_line')->insertGetId(['document_id'=>$receivedRequest,'line_no'=>1,'product_id'=>2,'sku_id'=>2,'sku_unique'=>'DST','quantity_scale'=>0,'requested_quantity_units'=>3,'created_at'=>$now]);
    $receivedTransfer=(int)Db::name('inventory_cross_transfer_document')->insertGetId(['transfer_no'=>'TEST-XFER-RECEIVED','idempotency_key'=>'TEST-XFER-CREATE-RECEIVED','request_fingerprint'=>str_repeat('e',64),'tenant_id'=>'0','from_party_type'=>'STORE','from_party_id'=>$ids['store'],'from_party_name_snapshot'=>'TEST 生命周期门店','from_location_id'=>$ids['location'],'to_party_type'=>'STORE','to_party_id'=>$ids['targetStore'],'to_party_name_snapshot'=>'TEST 生命周期调入门店','to_location_id'=>$ids['targetLocation'],'request_document_id'=>$receivedRequest,'created_by_operator_id'=>$ids['staff'],'dispatched_by_operator_id'=>$ids['staff'],'received_by_operator_id'=>$ids['targetStaff'],'document_status'=>'RECEIVED','remark'=>'TEST','business_date'=>'2026-08-05','dispatched_at'=>$now,'received_at'=>$now,'recorded_at'=>$now]);
    $receivedLine=(int)Db::name('inventory_cross_transfer_line')->insertGetId(['document_id'=>$receivedTransfer,'line_no'=>1,'request_line_id'=>$receivedRequestLine,'from_product_id'=>1,'from_sku_id'=>1,'from_sku_unique'=>'SRC','to_product_id'=>2,'to_sku_id'=>2,'to_sku_unique'=>'DST','requested_quantity_units'=>3,'quantity_scale'=>0,'created_at'=>$now]);
    $receivedAllocation=(int)Db::name('inventory_cross_transfer_batch_allocation')->insertGetId(['document_id'=>$receivedTransfer,'line_id'=>$receivedLine,'from_stock_id'=>$ids['sourceStock'],'to_stock_id'=>$ids['targetStock'],'from_batch_id'=>$ids['sourceBatch'],'to_batch_id'=>$ids['targetBatch'],'origin_batch_id'=>$ids['sourceBatch'],'quantity_units'=>3,'unit_cost_cents'=>500,'quantity_scale'=>0,'dispatched_at'=>$now,'received_at'=>$now]);
    Db::name('inventory_stock_request_fulfillment')->insert(['request_document_id'=>$receivedRequest,'request_line_id'=>$receivedRequestLine,'transfer_document_id'=>$receivedTransfer,'transfer_line_id'=>$receivedLine,'fulfilled_quantity_units'=>3,'received_at'=>$now]);
    $facts=new InventoryBatchMovementFactServices();
    $facts->append(['factKey'=>'TEST-XFER-RECEIVED-OUT','tenantId'=>'0','organizationId'=>(string)$ids['org'],'organizationPath'=>'/'.$ids['root'].'/'.$ids['org'].'/','storeId'=>$ids['store'],'stockId'=>$ids['sourceStock'],'batchId'=>$ids['sourceBatch'],'direction'=>-1,'quantityUnits'=>3,'unitCostCents'=>500,'costAmountCents'=>1500,'sourceType'=>'cross_transfer_out','sourceId'=>'TEST-XFER-RECEIVED','sourceDetailId'=>(string)$receivedAllocation,'reversalOf'=>0,'businessDate'=>'2026-08-05','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);
    $facts->append(['factKey'=>'TEST-XFER-RECEIVED-IN','tenantId'=>'0','organizationId'=>(string)$ids['targetOrg'],'organizationPath'=>'/'.$ids['root'].'/'.$ids['targetOrg'].'/','storeId'=>$ids['targetStore'],'stockId'=>$ids['targetStock'],'batchId'=>$ids['targetBatch'],'direction'=>1,'quantityUnits'=>3,'unitCostCents'=>500,'costAmountCents'=>1500,'sourceType'=>'cross_transfer_in','sourceId'=>'TEST-XFER-RECEIVED','sourceDetailId'=>(string)$receivedAllocation,'reversalOf'=>0,'businessDate'=>'2026-08-05','occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);
    $receivedReverse=$transferService->reverseForStore($ids['store'],$ids['staff'],$receivedTransfer,['idempotency_key'=>'TEST-XFER-RECEIVED-REVERSE','reason'=>'收货后发现调拨错误']);
    lifecycleIntegrationAssert('RECEIVED reversal removes target batch, restores source batch and reverses fulfillment atomically', $receivedReverse['document_status']==='REVERSED' && (int)Db::name('inventory_batch')->where('id',$ids['targetBatch'])->value('available_quantity_units')===0 && (int)Db::name('inventory_batch')->where('id',$ids['sourceBatch'])->value('available_quantity_units')===10 && (string)Db::name('inventory_stock_request_document')->where('id',$receivedRequest)->value('document_status')==='APPLIED' && (int)Db::name('inventory_stock_request_fulfillment_reversal')->where('request_document_id',$receivedRequest)->count()===1);

    echo "REQUEST_TRANSFER_LIFECYCLE_INTEGRATION_RESULT failed={$failed}\n";
    Db::rollback();
    exit($failed===0 ? 0 : 1);
} catch (Throwable $exception) {
    Db::rollback();
    fwrite(STDERR, 'INTEGRATION_ERROR ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
