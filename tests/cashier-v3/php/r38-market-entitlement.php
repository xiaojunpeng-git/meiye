<?php
/** 仅本地事务夹具：纯权益无销售/收款，两个项目一人次，两表及下钻同源；结束必回滚。 */
$backend=getenv('BACKEND_ROOT')?:dirname(__DIR__,3).'/后端代码';
require $backend.'/vendor/autoload.php';
$app=new \think\App($backend.'/');$app->initialize();
use think\facade\Db;
use app\services\report\StoreUnifiedReportServices;
function check38($yes,$message){if(!$yes)throw new RuntimeException($message);}
check38(getenv('R38_LOCAL_TEST')==='1','local test flag required');
$seed=Db::name('cashier_v3_entitlement_service_fact')->where('service_status','completed')->find();
$bs=Db::name('cashier_v3_checkout_business_source_selection')->where('primary_source_id','>',0)->find();
check38($seed&&$bs,'missing local fixtures');
$store=(int)$seed['store_id'];$date='2026-09-26';$member=987654321;
$source=(int)$bs['primary_source_id'];$key='channel_'.$source;
$report=new StoreUnifiedReportServices();
$query=static function($code,$extra=[])use($report,$store,$date){return $report->query([$store],array_merge(['report'=>$code,'start_date'=>$date,'end_date'=>$date,'page'=>1,'limit'=>100],$extra));};
$summary=static function($result)use($store){foreach($result['records'] as $r)if((int)$r['store_id']===$store)return $r;return [];};
$before=$summary($query('market_performance'));
Db::startTrans();
try{
 $random=bin2hex(random_bytes(16));$checkout='CKR-R38-'.$random;
 unset($bs['id']);$bs['tenant_id']=$seed['tenant_id'];$bs['store_id']=$store;$bs['checkout_request_id']=$checkout;$bs['checkout_kind']='sale';
 Db::name('cashier_v3_checkout_business_source_selection')->insert($bs);
 $ids=[];
 for($i=0;$i<2;$i++){
  $row=$seed;unset($row['id']);
  foreach(['service_fact_id','natural_key','command_idempotency_key','business_event_no','source_line_id'] as $field)$row[$field]='R38-'.$random.'-'.$i;
  $row['immutable_fingerprint']=hash('sha256',$random.$i);$row['checkout_request_id']=$checkout;
  $row['service_record_no']='R38'.substr($random,0,16).$i;
  $row['member_id']=$member;$row['member_name_snapshot']='R38本地夹具';$row['business_date']=$date;
  $ids[]=Db::name('cashier_v3_entitlement_service_fact')->insertGetId($row);
 }
 $detail=$query('market_detail',['dimension_code'=>(string)$source,'metric_code'=>'visits']);
 $matches=array_values(array_filter($detail['records'],static function($r)use($member){return (int)$r['member_id']===$member;}));
 check38(count($matches)===1,'two projects must produce one detail row');
 check38((int)$matches[0]['visits']===1&&(float)$matches[0]['amount']===0.0,'visit/zero cash mismatch');
 check38($matches[0]['dimension']===$bs['source_label_snapshot'],'source snapshot changed');
 $after=$summary($query('market_performance'));
 check38((int)($after[$key.'_visits']??0)===(int)($before[$key.'_visits']??0)+1,'summary/detail mismatch');
 check38((float)($after[$key.'_amount']??0)===(float)($before[$key.'_amount']??0),'cash amount changed');
 $cash=$query('market_detail',['metric_code'=>'amount']);
 check38(!array_filter($cash['records'],static function($r)use($member){return (int)$r['member_id']===$member;}),'service leaked into cash drilldown');
 // 同样的两个项目作为游客时也只计一次，服务单稳定键不能依赖销售单存在。
 Db::name('cashier_v3_entitlement_service_fact')->whereIn('id',$ids)->update(['member_id'=>0]);
 $guest=$query('market_detail',['dimension_code'=>(string)$source,'metric_code'=>'visits']);
 $guestRows=array_values(array_filter($guest['records'],static function($r)use($checkout){return ($r['source_order_id']??'')==='service:'.$checkout;}));
 check38(count($guestRows)===1&&(int)$guestRows[0]['visits']===1,'guest projects duplicated/lost');
 $excluded=$report->query([999999999],['report'=>'market_detail','start_date'=>$date,'end_date'=>$date]);
 check38(empty($excluded['records']),'store scope leaked');
 // 只插入本地作废账本夹具检验读侧排除，不调用或改动真实作废命令。
 foreach($ids as $i=>$id)Db::name('cashier_v3_service_record_void_operation')->insert([
  'operation_id'=>'R38-'.$random.'-'.$i,'operation_no'=>'R38-'.$random.'-'.$i,
  'tenant_id'=>$seed['tenant_id'],'store_id'=>$store,'service_fact_id'=>$id,
  'command_idempotency_key'=>'R38-'.$random.'-'.$i,'immutable_fingerprint'=>hash('sha256',$random.'void'.$i),
  'status'=>'succeeded','business_date'=>$date,
 ]);
 $end=$summary($query('market_performance'));
 check38((int)($end[$key.'_visits']??0)===(int)($before[$key.'_visits']??0),'inactive service counted');
 $gone=$query('market_detail',['dimension_code'=>(string)$source]);
 check38(!array_filter($gone['records'],static function($r)use($checkout){return ($r['source_order_id']??'')==='service:'.$checkout;}),'void detail remained');
 echo "PASS R38 pure entitlement: source, member/guest dedup, summary/detail, zero cash, amount drilldown, store scope, void exclusion\n";
}finally{Db::rollback();}
