<?php
declare(strict_types=1);

$backend = getenv('BACKEND_ROOT') ?: '/workspace/后端代码';
require $backend . '/vendor/autoload.php';

use app\services\product\inventory\InventoryBatchTransferServices;
use app\services\product\inventory\InventoryManualInboundServices;
use think\facade\Config;
use think\facade\Db;

$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
$app->env->set('cache.driver', 'file'); $app->env->set('CACHE_DRIVER', 'file');
$app->env->set('database.type', 'mysql'); $app->env->set('DATABASE_TYPE', 'mysql');
$app->env->set('database.hostname', getenv('DB_HOST') ?: 'mysql'); $app->env->set('DATABASE_HOSTNAME', getenv('DB_HOST') ?: 'mysql');
$app->env->set('database.hostport', getenv('DB_PORT') ?: '3306'); $app->env->set('DATABASE_HOSTPORT', getenv('DB_PORT') ?: '3306');
$app->env->set('database.database', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730'); $app->env->set('DATABASE_DATABASE', getenv('DB_DATABASE') ?: 'inventory_manual_inbound_test_20260730');
if (getenv('DB_USERNAME')) { $app->env->set('database.username', getenv('DB_USERNAME')); $app->env->set('DATABASE_USERNAME', getenv('DB_USERNAME')); }
if (getenv('DB_PASSWORD') !== false) { $app->env->set('database.password', getenv('DB_PASSWORD')); $app->env->set('DATABASE_PASSWORD', getenv('DB_PASSWORD')); }
$envName = new ReflectionProperty($app, 'envName'); $envName->setAccessible(true); $envName->setValue($app, 'inventory_transfer_test_skip_dotenv');
$app->initialize(); Config::set(['default' => 'file'], 'cache');

function transferAssert(string $name, bool $condition): void { global $failed; echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$condition) $failed++; }
function transferReason(callable $call): string { try { $call(); } catch (\Throwable $e) { return $e->getMessage(); } return ''; }
function transferEnsure(string $table, array $record, array $keys = ['id']): void { $q=Db::name($table);foreach($keys as $key)$q->where($key,$record[$key]);if(!$q->find())Db::name($table)->insert($record); }
function transferInbound(string $key, string $batch, string $quantity, string $expire): array { return ['idempotency_key'=>$key,'business_date'=>'2026-07-30','remark'=>'TEST 调拨入库，保留测试数据','lines'=>[['product_id'=>990071,'sku_id'=>9900711,'sku_unique'=>'testsku9900711','batch_no'=>$batch,'quantity'=>$quantity,'unit_cost'=>'66.00','manufactured_date'=>'2026-07-01','expire_date'=>$expire]]]; }
function transferCommand(string $key, int $target, string $quantity): array { return ['idempotency_key'=>$key,'business_date'=>'2026-07-30','remark'=>'TEST 批次调拨，保留测试数据','target_location_id'=>$target,'lines'=>[['product_id'=>990071,'sku_id'=>9900711,'sku_unique'=>'testsku9900711','quantity'=>$quantity]]]; }

$failed = 0;
transferEnsure('organization', ['id'=>99000,'pid'=>0,'name'=>'TEST 根组织','is_del'=>0]);
transferEnsure('organization', ['id'=>99007,'pid'=>99000,'name'=>'TEST 调拨组织','is_del'=>0]);
transferEnsure('system_store', ['id'=>99007,'name'=>'TEST 调拨门店','is_del'=>0,'is_show'=>1]);
transferEnsure('system_store_staff', ['id'=>990007,'store_id'=>99007,'status'=>1,'is_del'=>0]);
transferEnsure('organization_store', ['store_id'=>99007,'org_id'=>99007], ['store_id']);
transferEnsure('store_product', ['id'=>990071,'type'=>1,'relation_id'=>99007,'is_del'=>0,'is_inventory'=>1,'store_name'=>'TEST 调拨精华','code'=>'TEST-P-990071','bar_code'=>'6909900100074','salon_stock_enabled'=>0,'sort'=>1,'keyword'=>'TEST 调拨精华']);
transferEnsure('store_product_attr_value', ['id'=>9900711,'product_id'=>990071,'type'=>0,'unique'=>'testsku9900711','suk'=>'50ml','bar_code'=>'6909900100074','code'=>'TEST-S-990071','stock_unit'=>'瓶']);

$inbound = new InventoryManualInboundServices();
$inbound->create(99007, 990007, transferInbound('TEST-transfer-inbound-early-20260730','TEST-TRANSFER-EARLY-20260730','2','2026-09-01'));
$inbound->create(99007, 990007, transferInbound('TEST-transfer-inbound-late-20260730','TEST-TRANSFER-LATE-20260730','5','2027-09-01'));
$source = Db::name('inventory_location')->where('tenant_id','0')->where('store_id',99007)->where('is_default',1)->find();
if (!$source) throw new RuntimeException('transfer_test_source_location_missing');
$target = Db::name('inventory_location')->where('tenant_id','0')->where('location_code','TEST-TRANSFER-TARGET-99007')->find();
if (!$target) {
    $targetId = Db::name('inventory_location')->insertGetId(['tenant_id'=>'0','organization_id'=>'99007','organization_path'=>'/99000/99007/','organization_name_snapshot'=>'TEST 调拨组织','location_type'=>'STORE','owner_id'=>99007,'location_code'=>'TEST-TRANSFER-TARGET-99007','location_name'=>'TEST 调拨目标仓','store_id'=>99007,'store_name_snapshot'=>'TEST 调拨门店','is_default'=>0,'location_status'=>'ACTIVE','version'=>1,'created_at'=>time(),'updated_at'=>time()]);
    $target=Db::name('inventory_location')->where('id',$targetId)->find();
}

$service = new InventoryBatchTransferServices();
$first=$service->create(99007,990007,transferCommand('TEST-transfer-save-one-20260730',(int)$target['id'],'3'));
$replay=$service->create(99007,990007,transferCommand('TEST-transfer-save-one-20260730',(int)$target['id'],'3'));
$second=$service->create(99007,990007,transferCommand('TEST-transfer-save-two-20260730',(int)$target['id'],'2'));
$sourceStock=Db::name('inventory_stock')->where('tenant_id','0')->where('location_id',(int)$source['id'])->where('consumable_product_id',990071)->find();
$targetStock=Db::name('inventory_stock')->where('tenant_id','0')->where('location_id',(int)$target['id'])->where('consumable_product_id',990071)->find();
$targetBatches=Db::name('inventory_batch')->where('stock_id',(int)$targetStock['id'])->order('batch_no asc')->select()->toArray();
$facts=Db::name('inventory_batch_movement_fact')->where('tenant_id','0')->whereIn('source_id',['TEST-transfer-save-one-20260730','TEST-transfer-save-two-20260730'])->whereIn('source_type',['batch_transfer_out','batch_transfer_in'])->select()->toArray();

transferAssert('two retained TEST transfer documents are available', (int)Db::name('inventory_batch_transfer_document')->where('tenant_id','0')->whereIn('idempotency_key',['TEST-transfer-save-one-20260730','TEST-transfer-save-two-20260730'])->count()===2 && isset($first['lines'][0]['idempotent'], $second['lines'][0]['idempotent']));
transferAssert('replay returns saved movements without a third deduction', $replay['lines'][0]['idempotent'] && (int)$sourceStock['available_quantity_units']===2 && (int)$targetStock['available_quantity_units']===5);
$lineage=true;foreach($targetBatches as $batch){$lineage=$lineage&&(int)$batch['origin_batch_id']>0&&(int)$batch['source_batch_id']>0&&(int)$batch['available_quantity_units']>0;}
transferAssert('FEFO transfer writes paired immutable facts and preserves batch lineage', count($facts)===6 && $lineage);
transferAssert('insufficient transfer creates neither a negative batch nor a new document', transferReason(static function()use($service,$target):void{$service->create(99007,990007,transferCommand('TEST-transfer-insufficient-20260730',(int)$target['id'],'3'));})==='inventory_batch_transfer_stock_insufficient' && (int)Db::name('inventory_batch_transfer_document')->where('tenant_id','0')->where('idempotency_key','TEST-transfer-insufficient-20260730')->count()===0);
transferAssert('current-store V3 transfer list reads the two retained documents', count($service->list(99007, 990007, '', 1, 20)['list']) === 2);
echo "INVENTORY_BATCH_TRANSFER_RESULT failed={$failed}\n";
exit($failed===0?0:1);
