<?php
/** 第4轮本地完整Host联调。只输出行数/字段/缺口，不输出会员或订单数据。 */
$root=getenv('BACKEND_ROOT')?:getcwd();
require $root.'/vendor/autoload.php';
(new think\App($root.'/'))->initialize();
use think\facade\Db;
use app\services\report\BasicReportHostServices as Host;
use app\services\report\BasicReportCatalogServices as Catalog;
use app\services\query\UnifiedQueryRuntime;
$c=config('database.connections.mysql');
if(!in_array($c['hostname'],['mysql','mohe-mysql'],true))throw new RuntimeException('Local database only');
Db::execute('SET SESSION MAX_EXECUTION_TIME=8000');
Db::execute('SET SESSION TRANSACTION READ ONLY');
$selected=(int)(getenv('REPORT_TEST_STORE')?:105);
$sourceHashes=static function()use($root){
 $files=array_merge(glob($root.'/app/services/report/*007*.php'),glob($root.'/app/services/report/Basic*.php'),glob($root.'/app/services/report/query/Basic*.php'));
 $files[]=$root.'/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php';
 // 权益读取共用的金额和欠款边界也属于候选，防止只记录报表文件而漏掉联调期间的依赖修改。
 $files[]=$root.'/app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php';
 $files[]=$root.'/app/services/cashier/v3/card/CashierV3CardSaleActualAmountServices.php';
 $files[]=$root.'/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php';
 $files[]=$root.'/app/services/order/store/WriteOffOrderServices.php';
 $files[]=$root.'/app/services/report/CrossStoreV3CardPairReadServices.php';
 $out=[];sort($files);foreach($files as $file)if(is_file($file))$out[substr($file,strlen($root)+1)]=hash_file('sha256',$file);
 return $out;
};
$beforeHashes=$sourceHashes();
$codes=['basic_sales_data','basic_sales_pk','basic_sales_analysis','basic_store_efficiency','basic_store_summary',
'basic_cross_store_settlement','basic_store_item_sales','basic_item_consumption','basic_writeoff_detail','basic_product_sales',
'basic_balance_order_detail','basic_balance_order_summary','basic_daily_reconciliation','basic_unconsumed_summary',
'basic_unconsumed_detail','basic_followup_employee_detail','basic_customer_entitlements','basic_unconsumed_as_of_detail'];
$only=getenv('REPORT_TEST_CODES');
if($only){$requested=explode(',',$only);if(array_diff($requested,$codes))throw new RuntimeException('Unknown test code');$codes=$requested;}
$progress=fopen('php://stderr','w');
$context=Host::context([$selected],'stores',999901,'platform',false,0,$codes[0]);
$runtime=UnifiedQueryRuntime::runtime();$out=[];
foreach($codes as $code){
 fwrite($progress,'Reading '.$code.PHP_EOL);
 $start=microtime(true);
 try{
  if(!in_array($code,$runtime['providers']->pageCodes(),true))throw new RuntimeException('Missing provider');
  $current=Catalog::definition($code)['time_mode']==='current';
  $end=$current?$context['query_cutoff_date']:'2026-10-30';
  $r=Host::query($context,['pageCode'=>$code,'start_date'=>$current?$end:'2026-10-01','end_date'=>$end,'as_of_date'=>$end]);
  $out[]=['code'=>$code,'status'=>'pass','elapsed_ms'=>(int)((microtime(true)-$start)*1000),'total'=>$r['pagination']['total'],
   'fields'=>array_column(array_values(array_filter($r['queryFields'],static function($f){return !empty($f['defaultVisible']);})),'label'),
   'complete'=>$r['complete']??null,'gap_codes'=>array_values(array_unique(array_column($r['data_gaps']??[],'code')))];
 }catch(Throwable $e){$out[]=['code'=>$code,'status'=>'fail','elapsed_ms'=>(int)((microtime(true)-$start)*1000),'message'=>$e->getMessage()];}
}
Db::execute('SET SESSION TRANSACTION READ WRITE');
echo json_encode(['local_only'=>true,'database'=>$c['database'],'business_writes'=>0,'store_id'=>$selected,'fixed_source'=>$beforeHashes===$sourceHashes(),'source_sha256'=>$beforeHashes,'results'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
exit(count(array_filter($out,static function($r){return $r['status']==='fail';}))?1:0);
