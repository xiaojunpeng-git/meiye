<?php
declare(strict_types=1);

// 真实服务回归只运行在本机隔离库；所有业务夹具在外层事务结束时回滚。
if (getenv('DB_DATABASE') !== 'inventory_manual_inbound_test_20260730'
    || !in_array(getenv('DB_HOST'), ['127.0.0.1', 'mohe-mysql'], true)) throw new RuntimeException('isolated_inventory_test_required');
putenv('INVENTORY_SKIP_SCHEMA_BOOTSTRAP=1');
require __DIR__ . '/manual-test-bootstrap.php';

use app\services\product\inventory\InventoryHqLocationServices;
use app\services\product\inventory\InventoryPlatformWarehouseCommandServices;
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventoryStockRequestServices;
use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryStockCountDraftServices;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\Db;

// 旧最小夹具缺少组织启用状态，先补齐隔离库再执行当前迁移。
executeInventorySqlFile($fixture);
if (!Db::query("SHOW COLUMNS FROM eb_organization LIKE 'status'")) Db::execute("ALTER TABLE eb_organization ADD COLUMN status tinyint NOT NULL DEFAULT 1");
foreach (array_slice($files, 1) as $schemaFile) executeInventorySqlFile($schemaFile);

Db::execute("CREATE TABLE IF NOT EXISTS eb_system_admin (id bigint unsigned PRIMARY KEY,account varchar(64) NOT NULL DEFAULT '',real_name varchar(64) NOT NULL DEFAULT '',level int NOT NULL DEFAULT 1,admin_type int NOT NULL DEFAULT 0,roles varchar(255) NOT NULL DEFAULT '',status tinyint NOT NULL DEFAULT 1,is_del tinyint NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Db::execute("CREATE TABLE IF NOT EXISTS eb_employee (id bigint unsigned PRIMARY KEY,name varchar(100) NOT NULL DEFAULT '',status tinyint NOT NULL DEFAULT 1,is_del tinyint NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Db::execute("CREATE TABLE IF NOT EXISTS eb_organization_employee (id bigint unsigned PRIMARY KEY,org_id bigint unsigned NOT NULL DEFAULT 0,employee_id bigint unsigned NOT NULL DEFAULT 0,is_del tinyint NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach ([['eb_system_store_staff','employee_id','bigint unsigned NOT NULL DEFAULT 0'],['eb_system_store_staff','staff_name',"varchar(100) NOT NULL DEFAULT ''"],['eb_store_product','pid','bigint unsigned NOT NULL DEFAULT 0']] as [$table,$column,$definition]) {
    if (!Db::query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")) Db::execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}
foreach ([
    '2026-07-31-库存V3仓库创建命令/02-正式升级.sql',
    '2026-08-03-库存V3Excel导入/02-正式升级.sql',
    '2026-08-03-库存V3请货申请编辑审计/02-正式升级.sql',
    '2026-08-05-库存V3总部请货方/02-正式升级.sql',
    '2026-08-05-库存V3请货终止与调拨冲销/02-正式升级.sql',
    '2026-08-06-库存V3请货人与总部盘点/02-正式升级.sql',
    '2026-08-08-库存V3调拨人快照/02-正式升级.sql',
    '2026-10-02-库存V3请货受理方/02-正式升级.sql',
    '2026-10-02-库存V3业务与操作信息分离/02-正式升级.sql',
    '2026-10-08-库存单据明细备注/01-正式升级.sql',
    '2026-10-10-出库类型与对象/01-up.sql',
    '2026-10-13-库存V3总部多仓/02-正式升级.sql',
    '2026-10-13-库存V3总部多仓/02-正式升级.sql',
] as $upgrade) {
    if (strpos($upgrade, '2026-08-08-库存V3调拨人快照/') === 0 && Db::query("SHOW COLUMNS FROM eb_inventory_cross_transfer_document LIKE 'transfer_staff_id'")) continue;
    $path = $backend . '/database/upgrades/' . $upgrade;
    $sql = (string)file_get_contents($path);
    // 迁移中的存储过程必须整体提交；普通SQL仍使用项目已有拆分器。
    if (preg_match('/DELIMITER \\$\\$\\s*([\\s\\S]*?)\\$\\$\\s*DELIMITER ;/', $sql, $procedure, PREG_OFFSET_CAPTURE)) {
        $offset = $procedure[0][1];
        foreach (splitInventorySql(substr($sql,0,$offset)) as $statement) Db::execute($statement);
        Db::execute(trim($procedure[1][0]));
        foreach (splitInventorySql(substr($sql,$offset+strlen($procedure[0][0]))) as $statement) Db::execute($statement);
    } else executeInventorySqlFile($path);
}
// 院装多行领用与作废沿用正式迁移；只升级白名单隔离测试库。
executeInventorySqlFile($backend . '/database/upgrades/2026-08-05-库存V3手工单作废权威/02-正式升级.sql');
executeInventorySqlFile($backend . '/database/upgrades/2026-10-08-院装领用多明细修复/01-正式升级.sql');

$failed = 0;
function r14Assert(string $name, bool $ok): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; if (!$ok) $failed++; }
function r14Error(callable $fn): string { try { $fn(); } catch (Throwable $e) { return $e->getMessage(); } return ''; }

Db::startTrans();
try {
    $root = 9937001; $adminId = 9937002; $baseId = 9937003; $productId = 9937004; $skuId = 9937005;
    $now = time(); $admin = ['id'=>$adminId,'real_name'=>'TEST R14 ADMIN','account'=>'TEST-R14'];
    Db::name('organization')->insert(['id'=>$root,'pid'=>0,'name'=>'TEST R14 ROOT','is_del'=>0]);
    Db::name('system_admin')->insert(['id'=>$adminId,'account'=>'TEST-R14','real_name'=>'TEST R14 ADMIN','level'=>0,'admin_type'=>0,'roles'=>'','status'=>1,'is_del'=>0]);
    Db::name('inventory_location')->insert(['id'=>$baseId,'tenant_id'=>'0','organization_id'=>(string)$root,'organization_path'=>'/'.$root.'/','organization_name_snapshot'=>'TEST R14 ROOT','location_type'=>'HQ','owner_id'=>$root,'location_code'=>'TEST-R14-BASE','location_name'=>'TEST R14 HQ','store_id'=>0,'is_default'=>1,'location_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    Db::name('store_product')->insert(['id'=>$productId,'type'=>0,'relation_id'=>0,'is_del'=>0,'is_inventory'=>1,'store_name'=>'TEST R14 PRODUCT','no_expiry_management'=>1]);
    Db::name('store_product_attr_value')->insert(['id'=>$skuId,'product_id'=>$productId,'type'=>0,'unique'=>'TEST-R14-SKU','suk'=>'TEST R14 SKU','stock_unit'=>'piece']);
    $settings = new InventoryPlatformWarehouseCommandServices(); $hq = new InventoryHqLocationServices();
    $officeInput = ['idempotency_key'=>'TEST-R14-CREATE-OFFICE','base_location_id'=>$baseId,'location_name'=>'TEST R14 OFFICE'];
    $office = $settings->create($admin,$officeInput)['location'];
    $procurement = $settings->create($admin,['idempotency_key'=>'TEST-R14-CREATE-PROCUREMENT','base_location_id'=>$baseId,'location_name'=>'TEST R14 PROCUREMENT'])['location'];
    r14Assert('create and replay keep one warehouse', (int)$settings->create($admin,$officeInput)['location']['id']===(int)$office['id']);
    r14Assert('duplicate name rejected', r14Error(fn()=>$settings->create($admin,array_merge($officeInput,['idempotency_key'=>'TEST-R14-DUPLICATE'])))!=='');
    $locations = [$hq->readableLocation($admin,$baseId)['location'],$office,$procurement];
    $balance = static fn(int $id): int => (int)Db::name('inventory_stock')->where('location_id',$id)->where('consumable_product_id',$productId)->sum('available_quantity_units');
    $inbound = new InventoryManualInboundServices();
    foreach ($locations as $index=>$location) {
        $scope = $hq->scope($location,$adminId);
        $scope['operatorType'] = 'PLATFORM_ADMIN'; $scope['operatorName'] = 'TEST R14 ADMIN';
        $inbound->createForHeadquarters($scope,$location,['idempotency_key'=>'TEST-R14-IN-'.$index,'business_date'=>'2026-10-13','remark'=>'TEST','lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','batch_no'=>'TEST-R14-BATCH-'.$index,'quantity'=>(string)(10*($index+1)),'unit_cost'=>'5','manufactured_date'=>'','expire_date'=>'']]]);
    }
    r14Assert('same SKU has independent warehouse balances', $balance($baseId)===10 && $balance((int)$office['id'])===20 && $balance((int)$procurement['id'])===30);
    $requestService = new InventoryStockRequestServices();
    $requestInput = ['idempotency_key'=>'TEST-R14-REQUEST','business_date'=>'2026-10-13','remark'=>'TEST','requester_name'=>'TEST R14 ADMIN','request_party_type'=>'HQ','request_party_id'=>0,'supply_party_type'=>'HQ','supply_party_id'=>0,'supply_location_id'=>(int)$office['id'],'lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','quantity'=>'2']]];
    $request = $requestService->applyForHeadquarters($admin,$baseId,$requestInput);
    r14Assert('request records supply warehouse without changing stock', $balance($baseId)===10 && $balance((int)$office['id'])===20 && (int)Db::name('inventory_stock_request_document')->where('id',$request['request_id'])->value('supply_location_id')===(int)$office['id']);
    $transfers = new InventoryCrossSubjectTransferServices();
    $incoming = $transfers->incomingRequestsForHeadquarters($admin,(int)$office['id'],true);
    $line = array_values(array_filter($incoming['list'],static fn(array $r):bool=>(int)$r['id']===(int)$request['request_id']))[0]['lines'][0];
    $transferInput = ['idempotency_key'=>'TEST-R14-TRANSFER','business_date'=>'2026-10-13','remark'=>'TEST 三仓调拨','source_party_type'=>'HQ','source_store_id'=>0,'target_party_type'=>'HQ','target_store_id'=>0,'target_location_id'=>$baseId,'request_document_id'=>(int)$request['request_id'],'transfer_staff_id'=>0,'lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','quantity'=>'2','request_line_id'=>(int)$line['id']]]];
    $transfer = $transfers->createDraftForHeadquarters($admin,(int)$office['id'],$transferInput);
    r14Assert('draft replay cannot cross source warehouses', r14Error(fn()=>$transfers->createDraftForHeadquarters($admin,(int)$procurement['id'],$transferInput))==='inventory_cross_transfer_idempotency_conflict');
    r14Assert('draft replay stays in exact warehouse', (int)$transfers->createDraftForHeadquarters($admin,(int)$office['id'],$transferInput)['transfer_id']===(int)$transfer['transfer_id']);
    r14Assert('draft changes no stock', $balance($baseId)===10 && $balance((int)$office['id'])===20);
    $transfers->dispatchForHeadquarters($admin,(int)$office['id'],(int)$transfer['transfer_id']);
    $transfers->dispatchForHeadquarters($admin,(int)$office['id'],(int)$transfer['transfer_id']);
    r14Assert('dispatch subtracts once and leaves receiver unchanged', $balance($baseId)===10 && $balance((int)$office['id'])===18);
    $dashboard = (new \app\services\product\inventory\InventoryPlatformWarehouseServices())->dashboard($admin,(int)$office['id']);
    r14Assert('dashboard separates in-transit cost from warehouse balance', (int)$dashboard['in_transit_count']===1 && (int)$dashboard['in_transit_amount_cents']===1000);
    r14Assert('wrong receiver warehouse rejected', r14Error(fn()=>$transfers->receiveForHeadquarters($admin,(int)$procurement['id'],(int)$transfer['transfer_id']))!=='');
    $transfers->receiveForHeadquarters($admin,$baseId,(int)$transfer['transfer_id']);
    $transfers->receiveForHeadquarters($admin,$baseId,(int)$transfer['transfer_id']);
    r14Assert('receipt adds once and preserves total stock', $balance($baseId)===12 && $balance((int)$office['id'])===18 && $balance((int)$procurement['id'])===30);
    $matchingTransfers = $transfers->listForHeadquarters($admin,(int)$office['id'],(string)$transfer['transfer_no'],1,20,'',0,'',0,'','','',(int)$office['id'],$baseId);
    $chineseTransfers = $transfers->listForHeadquarters($admin,(int)$office['id'],'三仓',1,20,'',0,'',0,'','','',(int)$office['id'],$baseId);
    $otherTargetTransfers = $transfers->listForHeadquarters($admin,(int)$office['id'],(string)$transfer['transfer_no'],1,20,'',0,'',0,'','','',(int)$office['id'],(int)$procurement['id']);
    r14Assert('platform list filters actual source and target warehouses', (int)$matchingTransfers['count']===1 && (int)$matchingTransfers['list'][0]['id']===(int)$transfer['transfer_id'] && (int)$otherTargetTransfers['count']===0);
    r14Assert('platform list searches Chinese remarks across ASCII order numbers', (int)$chineseTransfers['count']===1);
    $transferDetailFromDefault = $transfers->detailForHeadquarters($admin,$baseId,(int)$transfer['transfer_id']);
    r14Assert('platform detail resolves an authorized nondefault source warehouse', (int)$transferDetailFromDefault['from_location_id']===(int)$office['id'] && (int)$transferDetailFromDefault['to_location_id']===$baseId && (string)$matchingTransfers['list'][0]['business_date']==='2026-10-13');
    r14Assert('fulfilled request belongs to original warehouse', Db::name('inventory_stock_request_document')->where('id',$request['request_id'])->value('document_status')==='DONE');
    $legacyInput = $requestInput; $legacyInput['idempotency_key'] = 'TEST-R14-LEGACY';
    $legacy = $requestService->applyForHeadquarters($admin,$baseId,$legacyInput);
    Db::name('inventory_stock_request_document')->where('id',$legacy['request_id'])->update(['supply_location_id'=>0,'acceptance_party_id'=>1,'acceptance_party_name_snapshot'=>'OFFICE']);
    $requestService->bindSupplyWarehouse($admin,(int)$office['id'],(int)$legacy['request_id']);
    r14Assert('legacy request binding writes audit and cannot change warehouse', (int)Db::name('inventory_stock_request_revision')->where('document_id',$legacy['request_id'])->count()===1 && r14Error(fn()=>$requestService->bindSupplyWarehouse($admin,(int)$procurement['id'],(int)$legacy['request_id']))!=='');
    $summary = (new \app\services\product\inventory\InventoryPlatformHqReadModelServices())->productSummary($admin,(int)$office['id'],'TEST R14',1,20);
    r14Assert('stock summary remains warehouse-specific', (int)$summary['count']===1 && $summary['list'][0]['available_quantity']==='18');
    Db::name('inventory_location')->insert(['tenant_id'=>'0','organization_id'=>(string)$root,'organization_path'=>'/'.$root.'/','organization_name_snapshot'=>'TEST R14 ROOT','location_type'=>'STORE','owner_id'=>9937100,'location_code'=>'TEST-R14-SCOPE-STORE','location_name'=>'TEST R14 STORE','store_id'=>9937100,'is_default'=>1,'location_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]);
    $scopedIds=array_map('intval',array_column($hq->locationsForAccess(['is_super_admin'=>false,'headquarters_only'=>false,'store_ids'=>[9937100]]),'id'));
    r14Assert('warehouse directory stays inside authorized organization root', in_array((int)$office['id'],$scopedIds,true) && count($scopedIds)===3);
    r14Assert('empty unauthorized warehouse scope fails closed', $hq->locationsForAccess(['is_super_admin'=>false,'headquarters_only'=>false,'store_ids'=>[9937199]])===[]);
    $policy = new \app\services\product\inventory\InventoryPlatformAccessPolicy();
    r14Assert('settings-only capability does not grant stock command permissions', !$policy->canCreate(['features'=>['inventory.location.settings'],'unique_auth'=>[]],'inbound'));
    $registry=\app\services\query\UnifiedQueryPageRegistry::fromRegistrars([], [new \app\services\product\inventory\query\InventoryBatchStockUnifiedQueryRegistrar()]);
    $queryContextFactory=new \app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory(new \app\services\query\UnifiedQueryContextFactory($registry));
    $singleContext=$queryContextFactory->make($adminId,$admin,'2026-10-13',0,'HQ','inventory_batch_stock',(int)$office['id']);
    r14Assert('query and export context freezes selected warehouse', array_map('intval',$singleContext['scope_dimensions']['location_id'])===[(int)$office['id']]);
    r14Assert('query rejects unknown warehouse instead of widening scope', r14Error(fn()=>$queryContextFactory->make($adminId,$admin,'2026-10-13',0,'HQ','inventory_batch_stock',9937199))!=='');
    $batchRows=(new \app\services\product\inventory\query\InventoryBatchStockQueryProvider())->sourceRows(['queryCutoffDate'=>'2026-10-13','locationIds'=>[(int)$office['id']],'includeZero'=>false],new \app\services\product\inventory\query\InventoryBatchStockDataScope('0',[(int)$office['id']],$singleContext['permissions']));
    r14Assert('batch projection excludes other warehouses for same SKU', count($batchRows)===1 && (int)$batchRows[0]['location_id']===(int)$office['id']);
    $drafts = new InventoryStockCountDraftServices();
    $draft = $drafts->saveForHeadquarters($admin,(int)$office['id'],['business_date'=>'2026-10-13','remark'=>'TEST','lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','book_quantity'=>'18','counted_quantity'=>'18']]]);
    r14Assert('count draft cannot resume in another warehouse', r14Error(fn()=>$drafts->detailForHeadquarters($admin,(int)$procurement['id'],(int)$draft['draft_id']))==='inventory_stock_count_draft_missing');
    r14Assert('warehouse with stock cannot disable', r14Error(fn()=>$settings->update($admin,(int)$office['id'],['location_name'=>$office['location_name'],'location_status'=>'INACTIVE','expected_version'=>1]))!=='');
    $outboundScope = $hq->scope($procurement,$adminId); $outboundScope['operatorType']='PLATFORM_ADMIN'; $outboundScope['operatorName']='TEST R14 ADMIN';
    (new \app\services\product\inventory\InventoryManualOutboundServices())->createForHeadquarters($outboundScope,$procurement,['idempotency_key'=>'TEST-R14-OUT','business_date'=>'2026-10-13','remark'=>'TEST','lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','quantity'=>'1']]]);
    r14Assert('outbound deducts selected warehouse only', $balance($baseId)===12 && $balance((int)$office['id'])===18 && $balance((int)$procurement['id'])===29);
    (new \app\services\product\inventory\InventoryStockCountServices())->confirmForHeadquarters($admin,(int)$procurement['id'],['idempotency_key'=>'TEST-R14-COUNT','business_date'=>'2026-10-13','remark'=>'TEST','lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','counted_quantity'=>'28','surplus_batch_no'=>'','surplus_unit_cost'=>'','surplus_manufactured_date'=>'','surplus_expire_date'=>'']]]);
    r14Assert('count confirmation adjusts original warehouse only', $balance((int)$office['id'])===18 && $balance((int)$procurement['id'])===28);
    // 直接覆盖分仓持久化失败恢复；Excel解析另由已有导入合同验证。
    $importer = new \app\services\product\inventory\InventoryV3ExcelImportServices();
    $persist = new ReflectionMethod($importer,'persistPreparedImport'); $persist->setAccessible(true);
    $importCommand = ['business_date'=>'2026-10-13','remark_parts'=>[],'lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','quantity'=>'1']]];
    $importHash = md5('TEST-R14-IMPORT-FILE');
    $firstImport = $persist->invoke($importer,'outbound','HQ',$baseId,$admin,$importHash,'TEST-R14.xlsx',$importCommand,'其他出库');
    $failedCommand=$importCommand; $failedCommand['lines'][0]['quantity']='99';
    r14Assert('later warehouse import failure remains retryable', r14Error(fn()=>$persist->invoke($importer,'outbound','HQ',(int)$office['id'],$admin,$importHash,'TEST-R14.xlsx',$failedCommand,'其他出库'))!==''
        && Db::name('inventory_v3_import_record')->where('location_id',(int)$office['id'])->where('status','FAILED')->where('operator_id',$adminId)->count()===1);
    $persist->invoke($importer,'outbound','HQ',$baseId,$admin,$importHash,'TEST-R14.xlsx',$importCommand,'其他出库');
    r14Assert('successful warehouse replay does not double deduct', $balance($baseId)===11);
    $refillScope = $hq->scope($office,$adminId); $refillScope['operatorType']='PLATFORM_ADMIN'; $refillScope['operatorName']='TEST R14 ADMIN';
    $inbound->createForHeadquarters($refillScope,$office,['idempotency_key'=>'TEST-R14-REFILL','business_date'=>'2026-10-13','remark'=>'TEST','lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','batch_no'=>'TEST-R14-REFILL','quantity'=>'100','unit_cost'=>'5','manufactured_date'=>'','expire_date'=>'']]]);
    $persist->invoke($importer,'outbound','HQ',(int)$office['id'],$admin,$importHash,'TEST-R14.xlsx',$failedCommand,'其他出库');
    r14Assert('failed warehouse resumes after stock replenishment', $balance((int)$office['id'])===19 && Db::name('inventory_v3_import_record')->where('location_id',(int)$office['id'])->where('status','SUCCEEDED')->count()===1);
    $salon = new InventorySalonUsageServices();
    $usageInput = ['idempotency_key'=>'TEST-R14-HQ-USAGE','business_date'=>'2026-10-13','project_id'=>0,'project_name'=>'','remark'=>'TEST HQ usage',
        'lines'=>[['product_id'=>$productId,'sku_id'=>$skuId,'sku_unique'=>'TEST-R14-SKU','quantity'=>'1']]];
    $officeBeforeUsage = $balance((int)$office['id']);
    $otherBeforeUsage = [$balance($baseId), $balance((int)$procurement['id'])];
    $usage = $salon->issueForPlatform($admin, 0, $usageInput, (int)$office['id']);
    $usageId = (int)$usage['usage_document_id'];
    r14Assert('HQ salon issue deducts only its selected warehouse', $balance((int)$office['id']) === $officeBeforeUsage - 1
        && [$balance($baseId), $balance((int)$procurement['id'])] === $otherBeforeUsage
        && (int)Db::name('inventory_salon_usage_document')->where('id', $usageId)->value('store_id') === 0);
    r14Assert('HQ salon replay does not deduct twice', (int)$salon->issueForPlatform($admin, 0, $usageInput, (int)$office['id'])['usage_document_id'] === $usageId
        && $balance((int)$office['id']) === $officeBeforeUsage - 1);
    r14Assert('HQ salon list and detail are restricted to the original warehouse',
        count($salon->listForPlatform($admin, 0, '', '2026-10-13', '2026-10-13', (int)$office['id'])['items']) === 1
        && count($salon->listForPlatform($admin, 0, '', '2026-10-13', '2026-10-13', (int)$procurement['id'])['items']) === 0
        && (int)$salon->detailForPlatform($admin, 0, $usageId, (int)$office['id'])['document']['location_id'] === (int)$office['id']
        && r14Error(fn()=>$salon->detailForPlatform($admin, 0, $usageId, (int)$procurement['id'])) === 'inventory_salon_usage_detail_missing');
    $voidInput = ['idempotency_key'=>'TEST-R14-HQ-USAGE-VOID','reason'=>'TEST wrong issue'];
    r14Assert('HQ salon cannot void from another warehouse or ambiguous scope',
        r14Error(fn()=>$salon->voidForPlatform($admin, 0, $usageId, $voidInput, (int)$procurement['id'])) === 'inventory_salon_usage_void_document_missing'
        && r14Error(fn()=>$salon->issueForPlatform($admin, 9937100, $usageInput, (int)$office['id'])) === 'inventory_salon_usage_warehouse_selection_invalid');
    $voided = $salon->voidForPlatform($admin, 0, $usageId, $voidInput, (int)$office['id']);
    $voidReplay = $salon->voidForPlatform($admin, 0, $usageId, $voidInput, (int)$office['id']);
    r14Assert('HQ salon void restores original batch once', $balance((int)$office['id']) === $officeBeforeUsage
        && $voided['idempotent'] === false && $voidReplay['idempotent'] === true
        && (string)Db::name('inventory_salon_usage_document')->where('id', $usageId)->value('document_status') === 'VOIDED');
    // 总部领用不得把门店私有项目挂到总部单据；项目目录也只显示总部通用项目。
    $globalProjectId = $productId + 20;
    $storeProjectId = $productId + 21;
    Db::name('store_product')->insert(['id'=>$globalProjectId,'type'=>0,'relation_id'=>0,'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_inventory'=>0,'store_name'=>'TEST R14 HQ PROJECT']);
    Db::name('store_product')->insert(['id'=>$storeProjectId,'type'=>1,'relation_id'=>9937100,'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_inventory'=>0,'store_name'=>'TEST R14 STORE PROJECT']);
    $projects = $salon->projectsForPlatform($admin, 0, 'TEST R14', 1, 50, (int)$office['id']);
    $projectIds = array_map('intval', array_column($projects['list'], 'id'));
    $storeProjectInput = $usageInput;
    $storeProjectInput['idempotency_key'] = 'TEST-R14-HQ-STORE-PROJECT';
    $storeProjectInput['project_id'] = $storeProjectId;
    r14Assert('HQ salon project picker excludes store-only projects', in_array($globalProjectId, $projectIds, true)
        && !in_array($storeProjectId, $projectIds, true)
        && r14Error(fn()=>$salon->issueForPlatform($admin, 0, $storeProjectInput, (int)$office['id'])) === 'inventory_salon_usage_project_not_found'
        && $balance((int)$office['id']) === $officeBeforeUsage);
    $empty = $settings->create($admin,['idempotency_key'=>'TEST-R14-CREATE-EMPTY','base_location_id'=>$baseId,'location_name'=>'TEST R14 EMPTY'])['location'];
    $settings->update($admin,(int)$empty['id'],['location_name'=>'TEST R14 EMPTY RENAMED','location_status'=>'INACTIVE','expected_version'=>1]);
    r14Assert('inactive warehouse remains readable but not writable', (int)$hq->readableLocation($admin,(int)$empty['id'])['location']['id']===(int)$empty['id'] && r14Error(fn()=>$hq->writableLocation($admin,(int)$empty['id']))==='inventory_hq_location_scope_denied');
    for ($pageIndex=1; $pageIndex<=45; $pageIndex++) $settings->create($admin,['idempotency_key'=>'TEST-R14-PAGE-'.$pageIndex,'base_location_id'=>$baseId,'location_name'=>'TEST R14 PAGE '.$pageIndex]);
    $warehouseRead = new \app\services\product\inventory\InventoryPlatformWarehouseServices();
    $page1=$warehouseRead->settings($admin,'TEST R14 PAGE','',1);
    $page2=$warehouseRead->settings($admin,'TEST R14 PAGE','',2);
    $page3=$warehouseRead->settings($admin,'TEST R14 PAGE','',3);
    $pageEmpty=$warehouseRead->settings($admin,'TEST R14 MISSING','',9);
    r14Assert('warehouse pagination returns 20/20/5 and complete total', count($page1['list'])===20 && count($page2['list'])===20 && count($page3['list'])===5 && (int)$page2['count']===45 && (int)$pageEmpty['page']===1 && (int)$pageEmpty['count']===0);
    r14Assert('stale warehouse setting version rejected', r14Error(fn()=>$settings->update($admin,(int)$empty['id'],['location_name'=>'TEST LOST UPDATE','location_status'=>'ACTIVE','expected_version'=>1]))!=='');
} catch (Throwable $exception) {
    $failed++; echo 'FAIL exception: '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL;
} finally { Db::rollback(); }
echo "R14_HQ_MULTI_WAREHOUSE_RESULT failed={$failed}\n";
exit($failed===0?0:1);
