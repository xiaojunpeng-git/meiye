<?php
/** 两端共用真实查询执行器：整天边界、门店权限、分页和导出必须给出同一批会员；不连接数据库。 */
$backend=dirname(__DIR__,3).'/后端代码';
spl_autoload_register(static function($class)use($backend){if(strpos($class,'app\\')===0){$f=$backend.'/'.str_replace('\\','/',$class).'.php';if(is_file($f))require_once $f;}});
$registry=new \app\services\query\UnifiedQueryPageRegistry();
(new \app\services\query\provider\MemberUnifiedQueryPageRegistrar())->register($registry);
(new \app\services\query\provider\PlatformMemberUnifiedQueryRegistrar())->register($registry);
$registry->freeze();
$executor=new \app\services\query\UnifiedQueryExecutionServices($registry,new \app\services\query\StructuredExpressionValidator($registry),new \app\services\query\StructuredExpressionEvaluator());
$rows=[];
foreach(['2026-09-30 23:59:59','2026-10-01 00:00:00','2026-10-31 23:59:59','2026-11-01 00:00:00']as $i=>$time)$rows[]=['member_id'=>$i+1,'created_at'=>$time,'store'=>'allowed'];
$rows[]=['member_id'=>5,'created_at'=>'2026-10-15 12:00:00','store'=>'forbidden'];
foreach(['member_list','platform_member_list']as $code){
 $query=['page'=>1,'limit'=>20,'topFilters'=>[['field'=>'created_at','operator'=>'gte','value'=>'2026-10-01 00:00:00'],['field'=>'created_at','operator'=>'lte','value'=>'2026-10-31 23:59:59']],'export'=>['scope'=>'query','fields'=>['created_at']]];
 $r=$executor->execute($code,$rows,[],$query,['query_cutoff_date'=>'2026-11-05'],static fn($row)=>$row['store']==='allowed');
 if(array_column($r['rows'],'member_id')!==[2,3]||count($r['exportRows'])!==2||(int)$r['pagination']['total']!==2)throw new RuntimeException($code.' period/scope/export mismatch');
}
// 三个纯日期字段也必须使用相同的包含两端边界，不把生日或最近到店当成单日等值。
foreach(['birthday','latest_purchase_date','latest_visit_date'] as $field){
 foreach(['member_list','platform_member_list'] as $code){
  $sample=[];foreach(['2026-09-30','2026-10-01','2026-10-31','2026-11-01'] as $i=>$day)$sample[]=['member_id'=>$i+1,$field=>$day];
  $r=$executor->execute($code,$sample,[],['topFilters'=>[['field'=>$field,'operator'=>'gte','value'=>'2026-10-01'],['field'=>$field,'operator'=>'lte','value'=>'2026-10-31']]],['query_cutoff_date'=>'2026-11-05'],static fn()=>true);
  if(array_column($r['rows'],'member_id')!==[2,3])throw new RuntimeException($code.' '.$field.' mismatch');
 }
}
echo "PASS: platform/store executor includes both day boundaries, excludes adjacent dates and unauthorized member, export=2\n";
