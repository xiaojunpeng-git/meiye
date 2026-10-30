<?php
/** 010候选只读验证：同一数据库快照比较全部既有员工指标，仅输出汇总状态，不保存客户明细。 */
chdir('/www/wwwroot/010.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();use think\facade\Db;
set_exception_handler(function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
if(Db::query('SELECT DATABASE() db')[0]['db']!=='mohe_010_app'||(int)config('database.connections.mysql.hostport')!==3307)throw new RuntimeException('Wrong target instance');
$payload=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$expected=$payload['_baseline_sha256'];unset($payload['_baseline_sha256']);if(hash_file('sha256','app/services/report/BasicActivityReportServices.php')!==$expected)throw new RuntimeException('Live shared source baseline changed');
foreach($payload as$name=>$file){$body=base64_decode($file['body'],true);if(hash('sha256',$body)!==$file['sha256'])throw new RuntimeException('Candidate source drift');if($name==='activity')$body=str_replace('final class BasicActivityReportServices','final class Round10CandidateActivityReportServices',$body);eval(substr($body,5));}
Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');Db::startTrans();
try{
 $input=['start_date'=>'2026-10-01','end_date'=>'2026-10-28','as_of_date'=>'2026-10-28','_report_scope'=>['mode'=>'stores']];
 $old=(new app\services\report\BasicActivityReportServices())->read('basic_employee_summary',[11],$input);
 $new=(new app\services\report\Round10CandidateActivityReportServices())->read('basic_employee_summary',[11],$input);
 $oldRows=array_column($old['rows'],null,'row_id');if(count($oldRows)!==count($new['rows']))throw new RuntimeException('Existing employee population changed');
 foreach($new['rows']as$row)foreach($oldRows[$row['row_id']]as$key=>$value)if($row[$key]!==$value)throw new RuntimeException('Existing metric changed: '.$key);
 $visible=array_values(array_filter($new['fields'],static function($f){return$f['defaultVisible'];}));
 $messages=[];foreach($new['gaps']as$gap)$messages[$gap['message']]=($messages[$gap['message']]??0)+1;
 $plans=[];$orders=Db::name('cashier_v3_sale_fact')->where('tenant_id','0')->where('store_id',11)->whereBetween('business_date',['2026-10-01','2026-10-28'])->limit(50)->column('order_id');
 foreach(['cashier_v3_sale_fact','cashier_v3_performance_fact','cashier_v3_payment_fact','cashier_v3_card_sale_item_allocation_fact','cashier_v3_sales_order_line']as$table){
  $q=Db::name($table)->where('tenant_id','0')->where('store_id',11)->whereIn('order_id',$orders?:['__no_order__']);
  if($table==='cashier_v3_sales_order_line')$q->where('line_status','settled');else$q->where('status','effective');
  $sql=$q->field('order_id')->buildSql();$plans[$table]=Db::query('EXPLAIN '.substr($sql,1,-1));
 }
 Db::rollback();echo json_encode(['result'=>'PASS','rows'=>count($new['rows']),'existing_values_unchanged'=>true,'visible_columns'=>array_column($visible,'label'),'complete'=>$new['complete'],'new_customer_gap_messages'=>$messages,'remote_writes'=>0,'deployed'=>false,'source_baseline_sha256'=>$expected,'query_plans'=>$plans],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable$e){Db::rollback();throw$e;}
