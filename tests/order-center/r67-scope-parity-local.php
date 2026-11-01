<?php
/** 本地真实业务表：平台选择单店与门店数据范围使用同一provider、相同结果；不替代浏览器登录验收。 */
$root=getenv('R67_BACKEND') ?: dirname(__DIR__,2).'/后端代码';require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use think\facade\Db;use app\services\query\UnifiedQueryRuntime;use app\services\order\PlatformOrderCenterReadServices;use app\services\cashier\v3\CashierV3DataScopeContext;use app\services\cashier\v3\CashierV3OperatorScope;
if(Db::query('SELECT DATABASE() db')[0]['db']!=='ruihao'||config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('local only');
$admin=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->find();$read=app()->make(PlatformOrderCenterReadServices::class);
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{foreach(['all','sales','recharge','refund','service','gift','card_operation','debt','supplement'] as $type){
 $code='order_center_'.$type;[$op,$scope,$ctx]=$read->exportScopes((int)$admin['id'],$code);
 $tenant=(string)$op->tenantId();$storeOp=new CashierV3OperatorScope(1,1,'',$tenant);$storeScope=new CashierV3DataScopeContext(1,1,1,$tenant,'',[1],'stores',[],false,'','test',['cashier.v3.order_center'],[]);
 $provider=UnifiedQueryRuntime::runtime()['providers']->resolve($code);$q=['recordType'=>$type,'page'=>1,'pageSize'=>20,'businessDateFrom'=>'2026-10-01','businessDateTo'=>'2026-10-31','storeIds'=>[1],'dataScope'=>'normal'];
 $a=$provider->queryPage($ctx,$q,$op,$scope);$b=$provider->queryPage($ctx,$q,$storeOp,$storeScope);
 if($type==='all'){ $reference=$provider->query($ctx,$q); if($a['total']!==$reference['total']||array_column($a['records'],'record_id')!==array_column($reference['records'],'record_id'))throw new RuntimeException('explicit all payload lost sales records'); }
 if($a['total']!==$b['total']||$a['records']!==$b['records'])throw new RuntimeException('scope parity '.$type);
 echo json_encode(['type'=>$type,'status'=>'PASS','total'=>$a['total'],'page_rows'=>count($a['records'])])."\n";
}}finally{Db::rollback();}
