<?php
/** 本地真实库只读验证：汇总/下钻同批次守恒、20条分页、越权拒绝、成本屏蔽。 */
$root=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;use app\services\report\ProductManagementDashboardServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('LOCAL_ONLY');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();try{
 $service=new ProductManagementDashboardServices();$stores=array_map('intval',Db::name('system_store')->column('id'));$context=['tenant_id'=>'0','store_ids'=>$stores,'cost_visible'=>true];$m=new ReflectionMethod($service,'inventoryRows');$m->setAccessible(true);$all=$m->invoke($service,$context);check(count($all)>0,'batch fixture required');
 $counts=array_column(Db::name('inventory_batch_movement_fact')->where('fact_status','SETTLED')->fieldRaw('batch_id,COUNT(*) n')->group('batch_id')->order('n desc')->select()->toArray(),'n','batch_id');usort($all,function($a,$b)use($counts){return ($counts[$b['batch_id']]??0)<=>($counts[$a['batch_id']]??0);});$batch=$all[0];$input=['detail'=>'expiry','batch_id'=>$batch['batch_id'],'batch_store_id'=>$batch['store_id'],'cutoff_date'=>date('Y-m-d')];
 $one=$service->dashboard($context,$input);check($one['batch']['quantity']===$batch['quantity'],'balance same authoritative row');check(abs($one['inbound_quantity']-$one['outbound_quantity']-$batch['quantity'])<0.00001,'all movements reconcile');check(count($one['rows'])===min(20,$one['total']),'page size exact');
 $pages=[];for($i=1;$i<=min(2,(int)ceil($one['total']/20));$i++){$x=$service->dashboard($context,array_merge($input,['page'=>$i]));$pages[$i]=array_column($x['rows'],'id');check($x['page']===$i&&$x['total']===$one['total'],'page count stable');}
 if(count($pages)>1)check(!array_intersect($pages[1],$pages[2]),'pages do not overlap');
 $hidden=$service->dashboard(array_merge($context,['cost_visible'=>false]),$input);check($hidden['batch']['inventory_cost_cents']===null,'cost permission respected');
 $otherStores=array_values(array_filter($stores,function($id)use($batch){return $id!==$batch['store_id'];}));
 foreach([array_merge($input,['batch_store_id'=>$otherStores[0]]),array_merge($input,['batch_store_id'=>2147483647]),array_merge($input,['batch_id'=>2147483647]),array_merge($input,['cutoff_date'=>'2999-01-01'])]as$bad){try{$service->dashboard($context,$bad);throw new RuntimeException('unauthorized accepted');}catch(InvalidArgumentException $e){}}
 $last=$service->dashboard($context,array_merge($input,['page'=>2147483647]));check($last['page']===max(1,(int)ceil($one['total']/20)),'out of range corrected');
 $q=new ReflectionMethod($service,'inventoryFactQuery');$q->setAccessible(true);$sql=$q->invoke($service,['tenant_id'=>'0','store_ids'=>[$batch['store_id']]],date('Y-m-d'),$batch['batch_id'])->field('f.id')->order('f.business_date desc,f.id desc')->limit(20)->fetchSql(true)->select();$plan=Db::query('EXPLAIN '.$sql);$plan=array_map(function($x){return array_intersect_key($x,array_flip(['table','type','key','rows','Extra']));},$plan);
 echo json_encode(['result'=>'PASS','explain'=>$plan,'batch_id'=>$batch['batch_id'],'store_id'=>$batch['store_id'],'quantity'=>$batch['quantity'],'total'=>$one['total'],'pageRows'=>array_map('count',$pages),'costHidden'=>true,'invalidScopeRejected'=>true,'readOnly'=>true],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{Db::rollback();}
