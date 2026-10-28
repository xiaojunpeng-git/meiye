<?php
/** 发布前只读同事务对账：不写工资、不修改配置文件；010历史原值与实时旧算法分开验证。 */
declare(strict_types=1);
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site]))throw new RuntimeException('Explicit site required');
chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong database');
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
foreach($request['classes']as$definition){$body=base64_decode($definition['body'],true);if(!$body||hash('sha256',$body)!==$definition['sha256'])throw new RuntimeException('Source mismatch');eval(substr($body,5));}
$body=base64_decode($request['salary']['body'],true);if(hash('sha256',$body)!==$request['salary']['sha256'])throw new RuntimeException('Salary source mismatch');
eval(substr(str_replace('final class BasicSalaryReportServices','final class Round9CandidateSalaryReportServices',$body),5));
$tx=new class{public function run(callable $callback,?array $tables=null){return $callback();}};
Db::execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');Db::startTrans();
try{
 $store=(int)Db::name('cashier_v3_performance_fact')->where('store_id','>',0)->value('store_id');
 if(!$store)$store=(int)Db::name('system_store')->where('is_del',0)->value('id');if(!$store)throw new RuntimeException('No validation store');
 $ranges=$site==='010'?['2026-11']:['2026-09','2026-10'];$checks=[];
 foreach($ranges as$month){
  $end=$month==='2026-10'?'2026-10-27':$month.'-'.date('t',strtotime($month.'-01'));
  $input=['start_date'=>$month.'-01','end_date'=>$end,'as_of_date'=>$end,'_report_scope'=>['mode'=>'stores']];
  $a=(new app\services\report\BasicSalaryReportServices(null,$tx))->read('basic_salary_realtime',[$store],$input);
  config(array_replace(config('payroll'),['realtime_start_date'=>$site==='010'?'2026-11-01':'']),'payroll');
  $b=(new app\services\report\Round9CandidateSalaryReportServices(null,$tx))->read('basic_salary_realtime',[$store],$input);
  // 新增来源元数据不属于金额；原领域行、规则命中、缺口与合计资格必须完全保持。
  foreach(['rows','sum_fields','salary_state','complete','gaps']as$key)if($a[$key]!==$b[$key])throw new RuntimeException('Existing algorithm changed: '.$key);
  $checks[]=['month'=>$month,'rows'=>count($a['rows']),'same_snapshot'=>true,'sha256'=>hash('sha256',json_encode([$a['rows'],$a['sum_fields']]))];
 }
 if($site==='010'){
  $input=['start_date'=>'2026-09-01','end_date'=>'2026-09-30','as_of_date'=>'2026-09-30','_report_scope'=>['mode'=>'stores']];
  $saved=(new app\services\report\Round9CandidateSalaryReportServices(null,$tx))->read('basic_salary_realtime',[11],$input);
  if($saved['source_kind']!=='saved_salary_snapshot'||!$saved['salary_readonly']||count($saved['rows'])!==9||array_sum(array_column($saved['rows'],'yingfagongzi'))!==27752.0||array_sum(array_column($saved['rows'],'shifagongzi'))!==27752.0)throw new RuntimeException('Saved history mismatch');
  $checks[]=['month'=>'2026-09','rows'=>9,'payable'=>27752,'actual'=>27752,'historical_readonly'=>true];
 }
 Db::rollback();echo json_encode(['result'=>'PASS','site'=>$site,'store_id'=>$store,'checks'=>$checks,'remote_writes'=>0,'configuration_file_changed'=>false],JSON_THROW_ON_ERROR);
}catch(Throwable $e){Db::rollback();throw $e;}
