<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';
use app\services\product\inventory\InventoryManualInboundServices;
use app\services\product\inventory\InventorySalonUsageServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/'); $app->env->load($backend . '/.env');
foreach (['cache.driver'=>'file','CACHE_DRIVER'=>'file','database.type'=>'mysql','DATABASE_TYPE'=>'mysql','database.hostname'=>getenv('DB_HOST') ?: 'mysql','DATABASE_HOSTNAME'=>getenv('DB_HOST') ?: 'mysql','database.hostport'=>getenv('DB_PORT') ?: '3306','DATABASE_HOSTPORT'=>getenv('DB_PORT') ?: '3306','database.database'=>getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730','DATABASE_DATABASE'=>getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'] as $key=>$value) $app->env->set($key,$value);
if (($value=getenv('DB_USERNAME'))!==false&&$value!=='') {$app->env->set('database.username',$value);$app->env->set('DATABASE_USERNAME',$value);} if (($value=getenv('DB_PASSWORD'))!==false){$app->env->set('database.password',$value);$app->env->set('DATABASE_PASSWORD',$value);}
$property=new ReflectionProperty($app,'envName');$property->setAccessible(true);$property->setValue($app,'inventory_salon_usage_test_skip_dotenv');$app->initialize();Config::set(['default'=>'file'],'cache');
if (!Db::query("SHOW COLUMNS FROM eb_store_product LIKE 'product_type'")) {
    Db::execute("ALTER TABLE eb_store_product ADD COLUMN product_type tinyint(2) NOT NULL DEFAULT 0");
}
if (!Db::query("SHOW COLUMNS FROM eb_store_product LIKE 'is_show'")) {
    Db::execute("ALTER TABLE eb_store_product ADD COLUMN is_show tinyint(1) NOT NULL DEFAULT 1");
}

function testAssert(string $name,bool $ok):void{global $failed;echo($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)$failed++;}
$failed=0; $now=time();
foreach ([
 ['system_store',['id'=>99691,'name'=>'TEST-院装门店','is_del'=>0,'is_show'=>1]],
 ['system_store_staff',['id'=>996910,'store_id'=>99691,'status'=>1,'is_del'=>0]],
 ['organization',['id'=>99600,'pid'=>0,'name'=>'TEST-库存根组织','is_del'=>0]],
 ['organization',['id'=>99691,'pid'=>99600,'name'=>'TEST-院装组织','is_del'=>0]],
 ['organization_store',['store_id'=>99691,'org_id'=>99691]],
 ['store_product',['id'=>996911,'type'=>1,'relation_id'=>99691,'is_del'=>0,'is_inventory'=>1,'store_name'=>'TEST-院装精华','code'=>'TEST-SALON-P','bar_code'=>'6909969100001','salon_stock_enabled'=>0,'sort'=>1,'keyword'=>'TEST-院装精华']],
 ['store_product',['id'=>996912,'type'=>1,'relation_id'=>99691,'product_type'=>6,'is_del'=>0,'is_show'=>1,'is_inventory'=>0,'store_name'=>'TEST-院装项目','code'=>'TEST-SALON-PROJECT','bar_code'=>'','salon_stock_enabled'=>0,'sort'=>1,'keyword'=>'TEST-院装项目']],
 ['store_product_attr_value',['id'=>9969111,'product_id'=>996911,'type'=>0,'unique'=>'testsalon9969111','suk'=>'10ml','bar_code'=>'6909969100001','code'=>'TEST-SALON-S','stock_unit'=>'瓶']],
] as [$table,$row]) { $query=Db::name($table); foreach(($table==='organization_store'?['store_id']:['id'])as$key)$query->where($key,$row[$key]); if(!$query->find())Db::name($table)->insert($row); }

$inbound=new InventoryManualInboundServices(); $salon=new InventorySalonUsageServices();
$inbound->create(99691,996910,['idempotency_key'=>'TEST-salon-inbound-20260731-v4','business_date'=>'2026-07-30','remark'=>'TEST-院装入库保留','lines'=>[['product_id'=>996911,'sku_id'=>9969111,'sku_unique'=>'testsalon9969111','batch_no'=>'TEST-SALON-BATCH-20260731-V4','quantity'=>'2','unit_cost'=>'66.00','manufactured_date'=>'2026-07-01','expire_date'=>'2027-07-01']]]);
$issue=$salon->issue(99691,996910,['idempotency_key'=>'TEST-salon-issue-20260731-v4','business_date'=>'2026-07-30','project_id'=>996912,'project_name'=>'不可信客户端名称','remark'=>'TEST-院装领用保留','lines'=>[['product_id'=>996911,'sku_id'=>9969111,'sku_unique'=>'testsalon9969111','quantity'=>'2']]]);
$issueList=$salon->list(99691,996910,0,'2026-07-30','2026-07-30');
$issueDetail=$salon->detail(99691,996910,(int)$issue['usage_document_id']);
$location=(int)Db::name('inventory_location')->where('tenant_id','0')->where('store_id',99691)->where('is_default',1)->value('id');
$return=$salon->returnToDefault(99691,996910,['idempotency_key'=>'TEST-salon-return-20260731-v4','business_date'=>'2026-07-30','project_id'=>996912,'project_name'=>'另一条不可信名称','remark'=>'TEST-院装退回保留','return_location_id'=>$location,'lines'=>[['source_usage_line_id'=>(int)$issue['line_ids'][0],'quantity'=>'1']]]);
$stock=Db::name('inventory_stock')->where('tenant_id','0')->where('location_id',$location)->where('consumable_product_id',996911)->find();
$batch=Db::name('inventory_batch')->where('stock_id',(int)$stock['id'])->where('batch_no','TEST-SALON-BATCH-20260731-V4')->find();
$documents=Db::name('inventory_salon_usage_document')->where('tenant_id','0')->where('store_id',99691)->where('project_id',996912)->order('id asc')->select()->toArray();
$returnLine=Db::name('inventory_salon_usage_line')->where('document_id',(int)$return['usage_document_id'])->find();
$facts=Db::name('inventory_batch_movement_fact')->where('tenant_id','0')->whereIn('source_type',['salon_usage_issue','salon_usage_return'])->whereIn('source_id',[$issue['usage_no'],$return['usage_no']])->order('id asc')->select()->toArray();
testAssert('two persisted TEST salon documents are issue then return',count($documents)===2&&(string)$documents[0]['operation_type']==='ISSUE'&&(string)$documents[1]['operation_type']==='RETURN');
testAssert('salon documents use the authoritative current-store project name snapshot',(string)$documents[0]['project_name_snapshot']==='TEST-院装项目'&&(string)$documents[1]['project_name_snapshot']==='TEST-院装项目');
testAssert('issued salon usage is immediately projected into the store list',count($issueList['items'])===1&&(int)$issueList['items'][0]['id']===(int)$issue['usage_document_id']);
testAssert('issued salon usage detail exposes the full returnable quantity',count($issueDetail['lines'])===1&&(string)$issueDetail['lines'][0]['returnable_quantity']==='2'&&$issueDetail['document']['can_return']===true);
testAssert('issue consumes a real batch and return restores the selected default warehouse', (int)$stock['available_quantity_units']===1&&(int)$batch['available_quantity_units']===1&&count($facts)===2&&(int)$facts[0]['direction']===-1&&(int)$facts[1]['direction']===1);
testAssert('return keeps its source issue line and original batch lineage',(int)$returnLine['source_usage_line_id']===(int)$issue['line_ids'][0]&&(int)$returnLine['origin_batch_id']===(int)$batch['origin_batch_id']);
testAssert('same issue key replays without a second deduction',$salon->issue(99691,996910,['idempotency_key'=>'TEST-salon-issue-20260731-v4','business_date'=>'2026-07-30','project_id'=>996912,'project_name'=>'任意客户端名称不影响权威指纹','remark'=>'TEST-院装领用保留','lines'=>[['product_id'=>996911,'sku_id'=>9969111,'sku_unique'=>'testsalon9969111','quantity'=>'2']]])['idempotent']===true&&(int)Db::name('inventory_stock')->where('id',(int)$stock['id'])->value('available_quantity_units')===1);
testAssert('project and date query returns both persisted documents',count($salon->list(99691,996910,996912,'2026-07-30','2026-07-30')['items'])===2);
testAssert('returned quantity is no longer available for a second over-limit return',(static function()use($salon,$issue,$location){try{$salon->returnToDefault(99691,996910,['idempotency_key'=>'TEST-salon-return-over-limit-20260731-v4','business_date'=>'2026-07-30','project_id'=>996912,'project_name'=>'不可信客户端名称','remark'=>'','return_location_id'=>$location,'lines'=>[['source_usage_line_id'=>(int)$issue['line_ids'][0],'quantity'=>'2']]]);}catch(\Throwable $e){return$e->getMessage()==='inventory_salon_usage_return_exceeds_issue';}return false;})());
testAssert('a nonexistent project is rejected before writing an authoritative document',(static function()use($salon){try{$salon->issue(99691,996910,['idempotency_key'=>'TEST-salon-invalid-project-20260731','business_date'=>'2026-07-30','project_id'=>999999999,'project_name'=>'完全不存在的项目XYZ','remark'=>'','lines'=>[['product_id'=>996911,'sku_id'=>9969111,'sku_unique'=>'testsalon9969111','quantity'=>'1']]]);}catch(\Throwable $e){return$e->getMessage()==='inventory_salon_usage_project_not_found';}return false;})());
echo "INVENTORY_SALON_USAGE_RESULT failed={$failed}\n";exit($failed===0?0:1);
