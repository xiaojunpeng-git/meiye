<?php
/** 实例真实服务与Host只读验收；不会保存人工工资或冻结状态。测试权限上下文不代替真人授权。 */
declare(strict_types=1);
$site=$argv[1]??'';$targets=['rh'=>['ruihao',3306],'008'=>['mohe_008',3307],'010'=>['mohe_010_app',3307]];
if(!isset($targets[$site]))throw new RuntimeException('Explicit site required');chdir('/www/wwwroot/'.$site.'.cc3798.com');require 'vendor/autoload.php';(new think\App())->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
use think\facade\Db;
if(Db::query('SELECT DATABASE() db')[0]['db']!==$targets[$site][0]||(int)config('database.connections.mysql.hostport')!==$targets[$site][1])throw new RuntimeException('Wrong database');
$store=$site==='010'?11:(int)Db::name('cashier_v3_performance_fact')->where('store_id','>',0)->value('store_id');
if(!$store)$store=(int)Db::name('system_store')->where('is_del',0)->value('id');
$context=app\services\report\BasicReportHostServices::context([$store],'stores',1,'platform',false,0,'basic_salary_realtime');$context['salary_edit']=true;$context['salary_freeze']=true;
$result=app\services\report\BasicReportHostServices::query($context,['pageCode'=>'basic_salary_realtime','start_date'=>'2026-09-01','end_date'=>'2026-09-30','as_of_date'=>'2026-09-30','export'=>['scope'=>'query','fields'=>['row_id','employee_name','yingfagongzi','shifagongzi']]]);
foreach($result['rows']as$row)if((int)$row['store_id']!==$store)throw new RuntimeException('Scope widened');
if($site==='010'){
 if(!$result['salary_readonly']||$result['salary_can_edit']||$result['salary_can_freeze']||$result['source_kind']!=='saved_salary_snapshot'||count($result['rows'])!==9||(float)$result['summaries']['yingfagongzi:sum']!==27752.0||(float)$result['summaries']['shifagongzi:sum']!==27752.0||count($result['exportRows'])!==9)throw new RuntimeException('Historical host mismatch');
 foreach([false,true]as$freeze){try{(new app\services\report\BasicSalaryManualServices())->save($context,['month'=>'2026-10'],$freeze);throw new RuntimeException('Historical write allowed');}catch(InvalidArgumentException $e){if(strpos($e->getMessage(),'历史工资只读')===false)throw $e;}}
}else{if($result['salary_readonly']||!$result['salary_can_edit']||!$result['salary_can_freeze']||$result['source_kind']!=='unified_business_facts')throw new RuntimeException('Original realtime capabilities changed');}
$salary=['rows'=>count($result['rows']),'export_rows'=>count($result['exportRows']),'source'=>$result['source_kind'],'can_edit'=>$result['salary_can_edit'],'can_freeze'=>$result['salary_can_freeze'],'summary_sha256'=>hash('sha256',json_encode($result['summaries']))];
// 共享查询提供器继续服务员工业绩与跨店报表，金额与原算法由发布前同事务比对保护。
$other=[];foreach(['basic_employee_summary','basic_cross_store_settlement']as$code){$end='2026-10-26';$a=app\services\report\BasicReportHostServices::query($context,['pageCode'=>$code,'start_date'=>'2026-10-01','end_date'=>$end,'as_of_date'=>$end]);foreach($a['rows']as$row)if((int)$row['store_id']!==$store)throw new RuntimeException('Other report scope widened');$other[$code]=['rows'=>count($a['rows']),'total'=>$a['pagination']['total']];}
echo json_encode(['result'=>'PASS','site'=>$site,'salary'=>$salary,'other_reports'=>$other,'remote_business_writes'=>0,'authenticated_http_session'=>false],JSON_THROW_ON_ERROR);
