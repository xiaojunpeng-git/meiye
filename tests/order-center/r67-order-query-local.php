<?php
$root=getenv('R67_BACKEND') ?: dirname(__DIR__,2).'/后端代码';
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
use think\facade\Db; use app\services\query\UnifiedQueryRuntime;use app\services\order\PlatformOrderCenterReadServices;
if(Db::query('SELECT DATABASE() db')[0]['db']!=='ruihao'||config('database.connections.mysql.hostname')!=='mysql')throw new RuntimeException('local only');
$admin=Db::name('system_admin')->where('status',1)->where('is_del',0)->where('level',0)->find();
[$op,$scope,$ctx]=app()->make(PlatformOrderCenterReadServices::class)->exportScopes((int)$admin['id'],'order_center_sales');
$p=UnifiedQueryRuntime::runtime()['providers']->resolve('order_center_sales');
$call=function($method,...$args)use($p){$m=new ReflectionMethod($p,$method);$m->setAccessible(true);return $m->invoke($p,...$args);};
$get=function($name)use($p){$x=new ReflectionProperty(get_parent_class($p),$name);$x->setAccessible(true);return $x;};
$base=['page'=>1,'pageSize'=>20,'storeIds'=>[1],'businessDateFrom'=>'2026-10-01','businessDateTo'=>'2026-10-31','dataScope'=>'normal'];
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
$get('sourcePayload')->setValue($p,$call('sourceQuery',$base));$rows=[];
foreach($call('authorizedSourceBatches',$ctx) as $batch)$rows=array_merge($rows,$batch);
$engine=$get('execution')->getValue($p);
$no=$rows[0]['sales_order_no'];$prefix=substr($no,0,8);
$cases=[
 ['filters'=>[['field'=>'sales_order_no','operator'=>'eq','value'=>$no]]],
 ['filters'=>[['field'=>'sales_order_no','operator'=>'contains','value'=>$prefix]],'page'=>2],
 ['filters'=>[['field'=>'sales_order_no','operator'=>'eq','value'=>$no],['field'=>'sales_order_no','operator'=>'eq','value'=>'absent']],'filterRelation'=>'any'],
 ['filters'=>[['field'=>'sales_order_no','operator'=>'contains','value'=>'%_\\']]],
 ['page'=>6,'sorts'=>[['field'=>'payment_completed_at','direction'=>'asc']]],
];
foreach($cases as $i=>$extra){$q=$extra+$base;$start=microtime(true);$actual=$p->query($ctx,$q);$uq=array_intersect_key($q,array_flip(['page','pageSize','filters','filterRelation','sorts','dataScope']));if(empty($uq['sorts']))$uq['sorts']=[['field'=>'payment_completed_at','direction'=>'desc'],['field'=>'record_id','direction'=>'desc']];
$expect=$engine->execute('order_center_sales',$rows,[],$uq,$ctx,static fn()=>true);
if($actual['records']!==$call('publicRows',$expect['rows']) || $actual['total']!==$expect['pagination']['total']){ $e=$call('publicRows',$expect['rows']); foreach(($e[0]??[]) as $k=>$v)if(($actual['records'][0][$k]??null)!==$v)echo 'DIFF '.$k.' '.gettype($v).' '.gettype($actual['records'][0][$k]??null).PHP_EOL; throw new RuntimeException('parity '.$i.' actual '.$actual['total'].' expected '.$expect['pagination']['total']); }
echo json_encode(['case'=>$i,'rows'=>$actual['total'],'seconds'=>round(microtime(true)-$start,3),'status'=>'PASS'])."\n";
}
$expected=$engine->execute('order_center_sales',$rows,[],['page'=>1,'limit'=>200,'sorts'=>[['field'=>'payment_completed_at','direction'=>'desc'],['field'=>'record_id','direction'=>'desc']]],$ctx,static fn()=>true);
foreach([1,2,5,6,10,18] as $page){$actual=$p->queryPage($ctx,array_replace($base,['page'=>$page]),$op,$scope);
 $ordered=$engine->execute('order_center_sales',$rows,[],['page'=>$page,'limit'=>20,'sorts'=>[['field'=>'payment_completed_at','direction'=>'desc'],['field'=>'record_id','direction'=>'desc']]],$ctx,static fn()=>true);
 if(array_column($actual['records'],'record_id')!==array_column($ordered['rows'],'record_id') || $actual['total']!==count($rows))throw new RuntimeException('deep index parity '.$page);
 echo json_encode(['page'=>$page,'status'=>'PASS','total'=>$actual['total']])."\n";
}
}finally{Db::rollback();}
