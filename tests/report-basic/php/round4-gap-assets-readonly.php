<?php
declare(strict_types=1);
/** 第4轮权益/余额缺口定位：只读真实来源并输出计数、稳定记录ID，不输出个人资料或写事实。 */
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$targets=['rh'=>['ruihao','3306',164],'008'=>['mohe_008','3307',113],'010'=>['mohe_010_app','3307',4]];
$site=$input['site']??''; if(!isset($targets[$site]))throw new RuntimeException('Unapproved site');
require getcwd().'/vendor/autoload.php';
// 候选仅在独立只读进程内加载，绝不替换服务器文件。
$overlay=$input['sources']??[];
spl_autoload_register(static function(string $class)use($overlay):void{
 $path=str_replace('\\','/',$class).'.php';if(!isset($overlay[$path]))return;
 $bytes=base64_decode($overlay[$path]['base64'],true);if($bytes===false||hash('sha256',$bytes)!==$overlay[$path]['sha256'])throw new RuntimeException('Bad source fingerprint');
 eval(substr($bytes,5));
},true,true);
(new think\App(getcwd().'/'))->initialize();
use think\facade\Db;
use app\services\report\BasicReportHostServices as Host;
use app\services\report\BasicEntitlementReportServices as Assets;
use app\services\report\OtherReports007ReadServices as Other;
use app\services\report\Sidebar007UnconsumedV3ReadServices as Unconsumed;
use app\services\report\BalanceOrder007ReadServices as Balance;
$cfg=config('database.connections.mysql');$t=$targets[$site];
if($cfg['database']!==$t[0]||(string)$cfg['hostport']!==$t[1]||$cfg['prefix']!=='eb_')throw new RuntimeException('Wrong database');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');Db::execute('SET SESSION TRANSACTION READ ONLY');Db::startTrans();
$store=$t[2];$stores=[$store];$out=['site'=>$site,'store'=>$store,'database'=>$t[0],'business_writes'=>0,'range'=>['2026-10-01','2026-10-30']];
function invokePrivate($object,string $name,array $args){$m=new ReflectionMethod($object,$name);$m->setAccessible(true);return $m->invokeArgs($object,$args);}
function gapCounts(array $gaps):array{$out=[];foreach($gaps as $g){$k=($g['code']??$g['field']??'unknown').':'.($g['reason']??$g['message']??'');$out[$k]=($out[$k]??0)+1;}return $out;}
try{
 $out['source_hashes']=[];foreach(['BasicEntitlementReportServices','OtherReports007ReadServices','Sidebar007UnconsumedV3ReadServices','BalanceOrder007ReadServices'] as $name)$out['source_hashes'][$name]=hash_file('sha256','app/services/report/'.$name.'.php');
 $out['migration']=Db::name('database_upgrade_log')->order('id','desc')->field('upgrade_key,code_version,executed_at')->find();
 $ctx=Host::context($stores,'stores',999901,'platform',false,0,'basic_customer_entitlements');
 $filter=['_report_scope'=>$ctx,'as_of_date'=>$ctx['query_cutoff_date']];
 $other=new Other();$population=invokePrivate($other,'loadRightsPopulation',[$stores]);
 $out['rights_population']=count($population);$source=null;
 try{$source=(new Assets())->read($overlay?Assets::CUSTOMER_RIGHTS_SOURCE:'basic_customer_entitlements',$stores,$filter);}
 catch(Throwable $e){$out['rights_authority_error']=$e->getMessage();}
 if($source){
  $balances=[];$projects=[];$invalid=[];
  foreach($source['rows'] as $r)if(($r['asset_type']??'')==='储值')$balances[(int)$r['member_id']]=$r;
  foreach($source['rows'] as $r)if(($r['asset_type']??'')==='卡内项目'&&(float)$r['remaining_times']>0){
   $id=(int)$r['project_detail_id'];$projects[$id]=$r;$reason='';
   if(!isset($balances[(int)$r['member_id']]))$reason='member_balance_row_absent';
   elseif((int)$balances[(int)$r['member_id']]['store_id']!==(int)$r['store_id'])$reason='balance_store_mismatch';
   elseif(isset($population[$id])&&((int)$population[$id]['member_id']!==(int)$r['member_id']||(int)$population[$id]['store_id']!==(int)$r['store_id']))$reason='population_identity_mismatch';
   if($reason)$invalid[$id]=$reason;
  }
  $out['rights_authority']=['rows'=>count($source['rows']),'balances'=>count($balances),'positive_projects'=>count($projects),'identity_failures'=>array_count_values($invalid),'identity_failure_project_ids'=>array_slice(array_keys($invalid),0,20)];
  $missing=array_diff_key($population,$projects);$out['rights_missing_count']=count($missing);$reasons=[];$samples=[];
  foreach(array_chunk(array_keys($missing),200) as $chunk){
   $rows=Db::name('store_order_cart_info')->alias('ci')->join('user u','u.uid=ci.uid')->join('store_order o','o.id=ci.oid')->leftJoin('user_card_holder h','h.oid=ci.oid AND h.uid=ci.uid')
    ->whereIn('ci.id',$chunk)->field('ci.id,ci.cart_type,ci.product_type,ci.is_writeoff,u.status,u.is_del,u.delete_time,h.id holder,h.store_id,h.is_del holder_deleted,h.write_surplus_times,h.write_start,h.write_end,o.card_upgrade_use_oid,o.terminal_action,o.refund_status')->select()->toArray();
   foreach($rows as $r){
    $why=((int)$r['cart_type']!==2||(int)$r['product_type']!==6)?'legacy_nonstandard_component_'.(int)$r['cart_type'].':'.(int)$r['product_type']:
     ((int)$r['status']!==1?'member_inactive':((int)$r['is_del']!==0?'member_deleted':(!$r['holder']?'holder_missing':((int)$r['holder_deleted']!==0?'holder_deleted':((int)$r['store_id']!==$store?'holder_other_or_missing_store':((int)$r['card_upgrade_use_oid']>0?'order_upgraded':((int)$r['terminal_action']!==0?'order_terminal':((int)$r['is_writeoff']!==0?'component_marked_writeoff':'needs_deeper_authority_trace'))))))));
    if(in_array($why,['needs_deeper_authority_trace','holder_other_or_missing_store'],true) && (int)$r['write_surplus_times']===0)$why='holder_shared_or_card_remaining_zero';
    $reasons[$why]=($reasons[$why]??0)+1;if(count($samples[$why]??[])<10)$samples[$why][]=(int)$r['id'];
   }
  }$out['rights_missing_reasons']=$reasons;$out['rights_missing_samples']=$samples;
 }
 $out['storeless_holders']=Db::name('user_card_holder')->alias('h')->join('user u','u.uid=h.uid')->leftJoin('store_order o','o.id=h.oid')->where('u.belong_store_id',$store)->where('u.status',1)->where('u.is_del',0)->where('h.is_del',0)->where('h.store_id','<=',0)->group('o.store_id')->field('o.store_id original_store,COUNT(*) holders,MIN(h.id) first_holder,MAX(h.id) last_holder')->select()->toArray();
 foreach(['basic_unconsumed_detail','basic_unconsumed_as_of_detail'] as $code){
  try{$r=(new Unconsumed())->read($code,$stores,$filter);$out[$code]=['rows'=>count($r['rows']),'complete'=>$r['complete'],'gap_counts'=>gapCounts($r['data_gaps']??[]),'gap_samples'=>array_slice($r['data_gaps']??[],0,10)];
   if($code==='basic_unconsumed_detail'){
    $missingUsed=[];foreach($r['rows'] as $row)if($row['used_amount']===null)$missingUsed[]=(int)$row['project_detail_id'];
    $out['missing_used_project_ids']=$missingUsed;$usedSource=[];
    foreach(array_chunk($missingUsed,200) as $chunk){
     foreach(Db::name('store_order_writeoff')->whereIn('order_cart_id',$chunk)->where('status',0)
      ->group('order_cart_id')->fieldRaw('order_cart_id,COUNT(*) source_rows,SUM(writeoff_num) source_quantity,SUM(writeoff_price) source_amount,MIN(add_time) first_time,MAX(add_time) last_time,SUM(add_time<=0) missing_time')->select()->toArray() as $row)$usedSource[]=$row;
    }$out['missing_used_exact_sources']=$usedSource;
   }}
  catch(Throwable $e){$out[$code]=['error'=>$e->getMessage()];}
 }
 try{$r=invokePrivate($other,'loadDaily',[$stores,['start'=>'2026-10-01','end'=>'2026-10-30']]);$out['daily']=['receipts'=>count($r['receipts']),'gaps'=>$r['data_gaps'],'unresolved_legacy_count'=>$r['unresolved_legacy_count']];}catch(Throwable $e){$out['daily']=['error'=>$e->getMessage()];}
 $out['balance_coverage']=invokePrivate(new Balance(),'auditLegacyCoverage',[$stores,['start'=>'2026-10-01','end'=>'2026-10-30']]);
}finally{Db::rollback();}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
