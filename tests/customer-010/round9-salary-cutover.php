<?php
/** 原保存工资真实夹具：只创建历史读取六张表，证明不依赖新事实、现任职位规则或工资公式。 */
use think\facade\Db;
use app\services\report\BasicSalaryReportServices;
use app\services\report\BasicSalaryManualServices;
use app\services\payroll\SalaryStatisticsSourcePolicy;
(new think\App())->initialize();
set_exception_handler(static function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
if(!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql'],true))throw new RuntimeException('Local fixture only');
$checks=0;function qc($ok,$label){global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
$connection=config('database.connections.mysql');$original=Db::connect();$db='salary_cutover_'.bin2hex(random_bytes(6));$created=false;
try{
 $original->execute('CREATE DATABASE `'.$db.'`');$created=true;
 $cfg=config('database');$cfg['connections']['cutover']=array_replace($connection,['database'=>$db]);$cfg['default']='cutover';config($cfg,'database');
 Db::execute("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
 $fixture=$GLOBALS['cutover_fixture'];
 foreach(['salary_info','system_store_staff','system_store','position','position_level','salary_freeze']as$table){
  Db::execute($fixture['ddl'][$table]);
  foreach($fixture['tables'][$table]??[]as$row)Db::name($table)->insert($row);
 }
 config(['position_model'=>'independent','realtime_start_date'=>'2026-11-01'],'payroll');
 qc(SalaryStatisticsSourcePolicy::savedMonth('2026-10'),'October saved');qc(!SalaryStatisticsSourcePolicy::savedMonth('2026-11'),'November realtime');
 $input=['start_date'=>'2026-09-01','end_date'=>'2026-09-30','as_of_date'=>'2026-09-30','_report_scope'=>['mode'=>'stores']];
 $read=function($stores=[11],$changes=[])use($input){return(new BasicSalaryReportServices())->read('basic_salary_realtime',$stores,array_replace($input,$changes));};
 $snapshot=Db::name('salary_info')->order('id')->select()->toArray();$result=$read();
 qc($result['salary_readonly']&&$result['source_kind']==='saved_salary_snapshot','Historical source and readonly');
 qc(count($result['rows'])===9,'Nine original payroll employees');
 qc(array_sum(array_column($result['rows'],'yingfagongzi'))===27752.0,'Original payable 27752');
 qc(array_sum(array_column($result['rows'],'shifagongzi'))===27752.0,'Original paid 27752');
 // 真实 Host/Provider/执行器贯通：列表、完整导出和工资合计共用历史来源。
 config($GLOBALS['fixed_query_config'],'unified_query');
 \app\services\query\UnifiedQueryRuntime::resetForTests();
 $host=\app\services\report\BasicReportHostServices::class;
 $context=$host::context([11],'stores',999901,'platform',false,0,'basic_salary_realtime');
 $context['salary_edit']=true;$context['salary_freeze']=true;
 $hostInput=$input;unset($hostInput['_report_scope']);
 $page=$host::query($context,array_replace($hostInput,['pageCode'=>'basic_salary_realtime','export'=>['scope'=>'query','fields'=>['row_id','employee_name','yingfagongzi','shifagongzi']]]));
 qc(!$page['salary_can_edit']&&!$page['salary_can_freeze'],'History hides platform write capabilities');
 qc(count($page['rows'])===9&&count($page['exportRows'])===9,'List and full export share nine saved rows');
 qc((float)$page['summaries']['yingfagongzi:sum']===27752.0,'Provider complete sum equals saved amount');
 qc($page['coverage_start']===null,'Saved history has no false unified-fact coverage warning');
 $source=array_column($snapshot,null,'id');
 foreach($result['rows']as$row){$saved=json_decode($source[$row['saved_salary_id']]['salary_info'],true);
  foreach($saved as$key=>$value)if(array_key_exists($key,$row)&&is_numeric($value)&&!in_array($key,['xiao_commission','product_commission','work_commission'],true))qc((float)$row[$key]===(float)$value,'Saved numeric field exact: '.$key);
  qc(!$row['editable'],'No historical editable row');
 }
 qc(is_string($result['field_sources']['yingfagongzi']),'Shared source-guide text contract');
 qc($read([99999])['rows']===[],'Unauthorized store cannot return saved salary');
 qc($read([11],['start_date'=>'2026-08-01','end_date'=>'2026-08-31','as_of_date'=>'2026-08-31'])['source_kind']==='saved_salary_snapshot','Earlier months never fallback realtime');
 // 改当前档案只影响展示，不应改变旧月金额或行数；不使用当前门店名单补人。
 Db::name('system_store_staff')->where('store_id',11)->update(['store_id'=>99999,'position'=>0,'position_level'=>0]);
 $after=$read();qc(array_column($after['rows'],'yingfagongzi')===array_column($result['rows'],'yingfagongzi'),'Current appointments do not recalculate history');
 qc(count($after['rows'])===9,'Historical membership follows saved store');
 $ctx=['visible_store_ids'=>[11],'basic_scope_mode'=>'stores','basic_actor_key'=>'platform:1','salary_edit'=>true,'salary_freeze'=>true];
 foreach([false,true]as$freeze){try{(new BasicSalaryManualServices())->save($ctx,['month'=>'2026-10'],$freeze);throw new RuntimeException('Historical write accepted');}catch(InvalidArgumentException$e){qc(strpos($e->getMessage(),'历史工资只读')!==false,'Backend rejects historical edit/freeze before transaction');}}
 $first=$result['rows'][0]['saved_salary_id'];Db::name('salary_info')->where('id',$first)->update(['salary_info'=>'invalid']);
 $invalid=$read();qc(!$invalid['complete']&&!in_array('yingfagongzi',$invalid['sum_fields'],true),'Invalid saved results never fall back or give partial wages');
 Db::name('salary_info')->delete(true);$empty=$read();qc($empty['rows']===[]&&$empty['sum_fields']===[],'Missing historical month stays empty, no fabricated zero payroll');
 config(['realtime_start_date'=>'2026-11-02'],'payroll');try{SalaryStatisticsSourcePolicy::realtimeStart();throw new RuntimeException('Invalid boundary accepted');}catch(RuntimeException$e){qc(strpos($e->getMessage(),'月首日')!==false,'Reject invalid cutoff configuration');}
 config(['realtime_start_date'=>''],'payroll');qc(!SalaryStatisticsSourcePolicy::savedMonth('2026-09'),'Blank configuration preserves other instances realtime');
 $GLOBALS['cutover_page']=$page;
 echo json_encode(['page'=>$page,'catalog'=>array_values(array_filter($host::catalog(),function($r){return $r['code']==='basic_salary_realtime';})),'result'=>'PASS','checks'=>$checks,'historical_rows'=>9,'payable'=>27752,'actual'=>27752,'only_legacy_tables'=>true,'remote_writes'=>0,'temporary_local_database'=>true]),"\n";
}finally{if($created)$original->execute('DROP DATABASE `'.$db.'`');}
