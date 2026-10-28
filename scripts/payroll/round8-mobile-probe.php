<?php
declare(strict_types=1);
/** 发布诊断只用真实授权范围读取服务，不创建登录态、不写工资/业绩；只输出摘要。
 * parity同时执行现网与候选，确认新增项目数之外的所有投影保持一致。 */
$site=$argv[1]??'';$backup=$argv[2]??'';$phase=$argv[3]??'live';
$databases=['rh'=>'ruihao','008'=>'mohe_008','010'=>'mohe_010_app'];
if(!isset($databases[$site])||!in_array($phase,['live','parity'],true))throw new RuntimeException('Explicit target/phase required');
chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage());exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$databases[$site])throw new RuntimeException('Database mismatch');
$live=app()->make(\app\services\mobile\warehouse\MobileWarehouseServices::class);
if($phase==='parity'){
 // 源码仅来自签名清单中的候选，类重命名仅用于同事务对比，不写入运行源码。
 $reader=file_get_contents($backup.'/payload/files/app/services/query/metric/RegisteredMetricReadServices.php');
 $reader=str_replace('final class RegisteredMetricReadServices','final class Round8CandidateMetricReadServices',$reader);eval(substr($reader,5));
 $code=file_get_contents($backup.'/payload/files/app/services/mobile/warehouse/MobileWarehouseServices.php');
 $code=str_replace(['final class MobileWarehouseServices','\\app\\services\\query\\metric\\RegisteredMetricReadServices'],['final class Round8CandidateMobileWarehouseServices','\\app\\services\\query\\metric\\Round8CandidateMetricReadServices'],$code);eval(substr($code,5));
 $candidate=app()->make(\app\services\mobile\warehouse\Round8CandidateMobileWarehouseServices::class);
}else $candidate=$live;
Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');Db::startTrans();
$identities=Db::name('employee_mobile_auth')->alias('a')->join('cashier_v3_performance_fact p','p.employee_id=a.employee_id')->where('a.status',1)->where('a.is_del',0)->whereRaw('FIND_IN_SET(401007,a.rules)>0')->where('p.performance_type','labor_performance_allocated')->where('p.status','effective')->whereBetween('p.business_date',['2026-10-01','2026-10-31'])->field('a.employee_id,MAX(p.store_id) store_id,COUNT(*) n')->group('a.employee_id')->order('n','desc')->limit(30)->select()->toArray();
$checks=[];$errors=[];
foreach($identities as $identity){
 $merchant=['employeeId'=>(int)$identity['employee_id'],'storeId'=>(int)$identity['store_id']];
 $input=['employeeId'=>(int)$identity['employee_id'],'periodMode'=>'month','month'=>'2026-10','page'=>1,'pageSize'=>20];
 try{
  foreach(['labor','cash'] as $mode){
   $input['detailMode']=$mode;$before=$live->employeePerformance($merchant,$input)['employeePerformance'];$after=$candidate->employeePerformance($merchant,$input)['employeePerformance'];
   $projectValues=[];
   foreach($after['details'] as &$line){
    if(!array_key_exists('displayProjectCount',$line))throw new RuntimeException('Project field missing');
    if($mode==='labor'&&!preg_match('/^-?\d+(?:\.\d{1,6})?$/D',(string)$line['displayProjectCount']))throw new RuntimeException('Invalid allocated project count');
    if($mode==='cash'&&$line['displayProjectCount']!==null)throw new RuntimeException('Cash acquired labor project count');
    $projectValues[]=$line['displayProjectCount'];unset($line['displayProjectCount']);
   }unset($line);
   foreach($before['details'] as &$line)unset($line['displayProjectCount']);unset($line);
   unset($before['data_as_of'],$after['data_as_of']);
   if($before!==$after)throw new RuntimeException('Existing mobile projection changed');
   $checks[]=['detail_mode'=>$mode,'algorithm'=>$after['laborAlgorithm']['code'],'rows'=>count($after['details']),'total'=>$after['pagination']['total'],'existing_projection_sha256'=>hash('sha256',json_encode($after)),'project_values_sha256'=>hash('sha256',json_encode($projectValues))];
  }
  if(count($checks)>=6)break;
 }catch(\app\services\mobile\protocol\MobileApiException $error){$errors[]=$error->getMessage();continue;}
}
Db::rollback();
if($checks===[])throw new RuntimeException('No authorized actual mobile projection available: '.implode(';',array_unique($errors)));
echo json_encode(['result'=>'PASS','site'=>$site,'phase'=>$phase,'authenticated_http_session'=>false,'real_scope_resolver'=>true,'persistent_business_writes'=>false,'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
