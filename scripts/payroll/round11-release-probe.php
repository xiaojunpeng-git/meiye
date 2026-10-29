<?php
declare(strict_types=1);
/** 固定来源只读发布探针：候选仅在CLI内存载入；不改现网文件、配置、权限或业务记录。 */
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site]))throw new RuntimeException('Explicit authorized instance required');
chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';$app=new think\App();$app->initialize();
set_exception_handler(static function(Throwable$error):void{fwrite(STDERR,(string)$error);exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong database');
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
// 服务器强制只读；即使探针未来误调用写路径，也应由数据库拒绝。
Db::execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');Db::execute('SET SESSION TRANSACTION READ ONLY');Db::startTrans();
$open=true;
try{
 if(isset($request['classes']))foreach($request['classes']as$item){
  $body=base64_decode($item['body'],true);if(hash('sha256',$body)!==$item['sha256'])throw new RuntimeException('Candidate source mismatch');
  if(class_exists($item['class'],false))throw new RuntimeException('Candidate class already loaded: '.$item['class']);
  eval(substr($body,5));
 }
 if(isset($request['config'])){$body=base64_decode($request['config']['body'],true);if(hash('sha256',$body)!==$request['config']['sha256'])throw new RuntimeException('Config hash mismatch');$app->config->set(eval(substr($body,5)),'unified_query');}
 $catalog=new app\services\organization\StorePermissionCatalogServices();
 $record=Db::name($catalog::TABLE)->where('id',1)->find();$rules=json_decode($record['rules_json'],true,512,JSON_THROW_ON_ERROR);
 $map=array_column($rules,null,'id');$definitions=$catalog::navigationItems();
 if(!isset($map[-33022]))$rules[]=$definitions[-33022];
 $catalog->validateRows($rules); // 仅在内存验证安装后完整目录，不能自动给任何岗位授权。
 $ids=array_map('intval',Db::name('system_store')->where('salary_status',1)->order('id')->column('id'));
 $ctx=app\services\report\BasicReportHostServices::context($ids,'all',1,'platform',false,0,'basic_store_reconciliation');
 $query=['pageCode'=>'basic_store_reconciliation','start_date'=>'2026-09-01','end_date'=>'2026-09-30'];
 $start=microtime(true);$matrix=app\services\report\BasicReportHostServices::query($ctx,$query);$seconds=microtime(true)-$start;
 $fields=array_column($matrix['queryFields'],'key');
 $export=app\services\report\BasicReportHostServices::query($ctx,$query+['expected_fingerprint'=>$matrix['receipt_fingerprint'],'export'=>['scope'=>'query','fields'=>$fields]]);
 if(count($export['exportRows'])!==$matrix['pagination']['total'])throw new RuntimeException('Incomplete matrix export');
 $detailChecks=0;$dailyColumns=0;
 if($ids&&$matrix['rows']){
  $store=$ids[0];$row=$matrix['rows'][0];
  $detail=app\services\report\BasicReportHostServices::query($ctx,$query+['expected_fingerprint'=>$matrix['receipt_fingerprint'],'detail'=>['method_code'=>$row['method_code'],'column_key'=>'store_'.$store],'export'=>['scope'=>'query','fields'=>['document_no','amount']]]);
  $sum='0.00';foreach($detail['exportRows']as$line)$sum=bcadd($sum,$line['amount'],2);
  if(bccomp($sum,$row['store_'.$store],2)!==0)throw new RuntimeException('Detail does not explain matrix cell');$detailChecks++;
  $single=app\services\report\BasicReportHostServices::context([$store],'stores',1,'store',false,0,'basic_store_reconciliation');
  $daily=app\services\report\BasicReportHostServices::query($single,$query+['reconciliation_mode'=>'days','reconciliation_store_id'=>$store,'month'=>'2026-09']);
  $dailyColumns=count($daily['queryFields']);if($dailyColumns!==32)throw new RuntimeException('September daily columns invalid');
  foreach($daily['queryFields']as$field)if(strpos($field['key'],'store_')===0)throw new RuntimeException('Store scope exposed other store');
 }
 // 既有工资报表自行建立独立一致读取；不能嵌在探针外层事务中改变它的保护边界。
 Db::rollback();$open=false;$regressions=[];
 $context=app\services\report\BasicReportHostServices::context($ids?[$ids[0]]:[],'stores',1,'platform',false,0,'basic_employee_summary');
 foreach(['basic_employee_summary','basic_salary_realtime','basic_cross_store_settlement']as$code){
  $period=['pageCode'=>$code,'start_date'=>'2026-10-01','end_date'=>'2026-10-26','as_of_date'=>'2026-10-26'];
  $result=app\services\report\BasicReportHostServices::query($context,$period);
  $regressions[$code]=['total'=>$result['pagination']['total'],'summaries_sha256'=>hash('sha256',json_encode($result['summaries']))];
 }
 echo json_encode(['result'=>'PASS','site'=>$site,'candidate'=>isset($request['classes']),'report'=>'basic_store_reconciliation','matrix_rows'=>$matrix['pagination']['total'],'matrix_columns'=>count($fields),'daily_columns'=>$dailyColumns,'detail_checks'=>$detailChecks,'export_rows'=>count($export['exportRows']),'query_seconds'=>$seconds,'matrix_sha256'=>hash('sha256',json_encode($export['exportRows'])),'regressions'=>$regressions,'mode'=>(int)sys_config('cashier_labor_fee_mode',0),'position_model'=>config('payroll.position_model','legacy'),'cutover'=>config('payroll.realtime_start_date',''),'business_writes'=>0,'authenticated_http_session'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}finally{if($open)Db::rollback();}
