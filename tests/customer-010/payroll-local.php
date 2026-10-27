<?php
/** 真 Reader/SQL 集成；新算法、职位继承、工资与员工统计一致，所有写入只在临时本地库。 */
use think\facade\Db;
use app\services\payroll\ProjectLaborCommissionReadServices;
use app\services\report\BasicActivityReportServices;
use app\services\report\BasicSalaryReportServices;
(new think\App())->initialize();
if (!in_array(config('database.connections.mysql.hostname'),['mohe-mysql','mysql'],true)) throw new RuntimeException('Local MySQL only');
set_exception_handler(static function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
$checks=0;
function qp($ok,$label){global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function fp($table,$data){
 static $schema=[];
 if (!isset($schema[$table])) $schema[$table]=Db::query('SHOW COLUMNS FROM eb_'.$table);
 foreach($schema[$table] as $c){$k=$c['Field'];if(array_key_exists($k,$data)||$c['Null']==='YES'||$c['Default']!==null||strpos($c['Extra'],'auto_increment')!==false)continue;$data[$k]=preg_match('/int|decimal|float|double/',$c['Type'])?0:(strpos($c['Type'],'date')!==false?'2026-10-01':'');}
 Db::name($table)->insert($data);
}
$connection=config('database.connections.mysql');$original=Db::connect();$source=$connection['database'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$source))throw new RuntimeException('Unsafe schema');
$database='payroll_test_'.bin2hex(random_bytes(6));$created=false;
try {
 $original->execute('CREATE DATABASE `'.$database.'`');$created=true;
 // 复用既有工资集成测试的结构边界，附加项目配置；只复制DDL，不复制任何客户行。
 $tables=['employee','system_store','system_store_staff','position','position_level','staff_job_position','position_yeji','salary_info','salary_field','salary_agent','salary_freeze','cashier_v3_performance_fact','cashier_v3_entitlement_service_fact','cashier_v3_sale_fact','cashier_v3_order_lifecycle_operation','cashier_v3_order_center_void_operation','cashier_v3_service_record_void_operation','cashier_v3_service_customer_group_fact','cashier_v3_payment_sale_allocation_fact','cashier_v3_payment_fact','cashier_v3_recharge_debt_repayment','cashier_v3_balance_fact','cashier_v3_customer_lifecycle_fact','cashier_v3_business_source','cashier_v3_sales_order','cashier_v3_checkout_payment_draft','cashier_v3_entitlement_writeoff_fact','cashier_v3_card_purchase_receipt','cashier_v3_card_operation_settlement','cashier_v3_card_sale_item_allocation_fact','cashier_v3_card_sale_category_allocation_fact','store_product','store_product_category','cashier_v3_refund_adjustment','system_business_product','cashier_v3_report_sale_dimension_fact','cashier_v3_sales_order_line','yeji_commission'];
 foreach($tables as $table)$original->execute('CREATE TABLE `'.$database.'`.`eb_'.$table.'` LIKE `'.$source.'`.`eb_'.$table.'`');
 $cfg=config('database');$cfg['connections']['payroll_fixture']=array_replace($connection,['database'=>$database]);$cfg['default']='payroll_fixture';config($cfg,'database');
 $manualSql=$GLOBALS['salary_manual_schema_sql'] ?? file_get_contents(getcwd().'/database/upgrades/2026-10-27-工资人工项与编辑冻结/01-结构升级.sql');
 foreach (array_filter(array_map('trim',explode(';',$manualSql))) as $sql) Db::execute($sql);
 foreach (array_filter(array_map('trim',explode(';',$GLOBALS['payroll_schema_sql']))) as $sql) Db::execute($sql);config(['position_model'=>'independent'],'payroll');$GLOBALS['payroll_test_mode']=1;
 foreach([901,902] as $store)fp('system_store',['id'=>$store,'name'=>'测试门店'.$store,'is_del'=>0]);
 fp('employee',['id'=>99101,'name'=>'测试员工','phone'=>'13999999101','status'=>1,'is_del'=>0]);
 foreach([991,994] as $position)fp('position',['id'=>$position,'name'=>'职位'.$position,'status'=>1]);
 fp('position_level',['id'=>992,'name'=>'历史等级','status'=>1]);
 fp('system_store_staff',['id'=>88101,'employee_id'=>99101,'store_id'=>901,'position'=>991,'position_level'=>992,'status'=>1,'is_del'=>0]);
 foreach([[991,1,strtotime('2026-10-20 Asia/Shanghai')],[994,strtotime('2026-10-20 Asia/Shanghai'),0]] as [$position,$start,$end])fp('staff_job_position',['employee_id'=>99101,'store_id'=>901,'staff_id'=>88101,'position_id'=>$position,'start_time'=>$start,'end_time'=>$end,'status'=>1,'is_del'=>0,'request_token'=>'fixture-'.$position]);
 foreach([[991,1,10],[992,2,99]] as [$id,$type,$rate])fp('position_yeji',['id'=>$id,'position_id'=>991,'position_level_id'=>992,'status'=>1,'commission'=>$rate,'cate_ids'=>'','range'=>'0-9999999','type'=>$type,'range_type'=>2,'time_type'=>1,'has_recharge'=>1]);
 foreach(['jixiao'=>'0','jiangjin'=>'0','yingfagongzi'=>'work_get+labor_fee+xiao_money','shifagongzi'=>'yingfagongzi'] as $key=>$formula)fp('salary_field',['key'=>$key,'name'=>$key,'type'=>2,'info'=>$formula,'table_ids'=>'1']);
 fp('store_product',['id'=>100,'pid'=>0,'type'=>0,'relation_id'=>0,'product_type'=>6,'store_name'=>'测试项目']);
 fp('store_product',['id'=>101,'pid'=>100,'type'=>1,'relation_id'=>902,'product_type'=>6,'store_name'=>'来源门店项目']);
 fp('yeji_commission',['id'=>1,'product_id'=>100,'commission_json'=>json_encode(['0-10000'=>25,'10001-20000'=>27,'20001-25000'=>28,'25001-30000'=>29,'30000-9999999'=>30])]);
 $seq=0;
 $fact=function($changes)use(&$seq){++$seq;$r=array_replace(['id'=>$seq,'tenant_id'=>'0','fact_id'=>'payroll-'.$seq,'natural_key'=>'payroll-'.$seq,'store_id'=>901,'employee_id'=>99101,'employee_name_snapshot'=>'测试员工','employee_type_snapshot'=>'internal','store_name_snapshot'=>'测试门店901','order_id'=>'order-'.$seq,'source_line_id'=>'line-'.$seq,'checkout_request_id'=>'checkout-'.$seq,'performance_type'=>'sales_performance_allocated','amount_cents'=>1005000,'business_date'=>'2026-10-05','status'=>'effective','fact_direction'=>'forward'],$changes);fp('cashier_v3_performance_fact',$r);return $r;};
 $sale=$fact([]);
 fp('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','store_id'=>901,'operation_id'=>'refund-1','source_order_id'=>$sale['order_id'],'operation_type'=>'refund','status'=>'succeeded']);
 $refund=$fact(['performance_type'=>'refund_sales_deduction','order_id'=>'refund-1','amount_cents'=>-10000]);
 $labor=$fact(['performance_type'=>'labor_performance_allocated','amount_cents'=>6000,'labor_fee_amount_cents'=>2500,'business_date'=>'2026-10-22','project_count_half_units'=>2,'role_snapshot'=>'craftsman:round']);
 fp('cashier_v3_entitlement_service_fact',['tenant_id'=>'0','service_fact_id'=>'service-1','natural_key'=>'service-1','checkout_request_id'=>$labor['checkout_request_id'],'source_line_id'=>$labor['source_line_id'],'store_id'=>901,'project_id'=>101,'project_name_snapshot'=>'测试项目','service_status'=>'completed','member_id'=>1,'service_object'=>'self','business_date'=>'2026-10-22','quantity'=>1,'project_count'=>1]);
 $range=['start'=>'2026-10-22','end'=>'2026-10-22'];$read=new ProjectLaborCommissionReadServices();
 $a=$read->read([901],$range,[99101]);$d=$a['details'][$labor['fact_id']];
 qp($d['threshold_cents']===995000 && $d['amount_cents']===1500,'Full month net-sales tier, even single-day query: '.json_encode($d));
 qp($d['project_id']===100,'Cross-store entitlement uses authoritative product parent identity');
 $input=['start_date'=>'2026-10-01','end_date'=>'2026-10-27','as_of_date'=>'2026-10-27','_report_scope'=>['mode'=>'stores']];
 $salary=(new BasicSalaryReportServices())->read('basic_salary_realtime',[901],$input);
 qp($salary['rows'][0]['work_get']===15.0,'Salary uses project rate once, not old 99 percent');
 qp($salary['rows'][0]['labor_fee']===25.0,'Independent original handwork fee retained');
 qp(!in_array('position_level_name',array_column($salary['fields'],'key'),true),'Salary removes grade in independent model');
 qp(!in_array('JOB_RULE_CONFLICT',array_column($salary['rows'][0]['gaps'],'code'),true),'Equivalent appointment conversion is not ambiguous');
 $summary=(new BasicActivityReportServices())->read('basic_employee_summary',[901],$input);
 qp($summary['rows'][0]['labor_commission']==15.0,'Employee summary and salary agree');
 qp($summary['rows'][0]['labor_fee']==25.0,'Summary does not replace original fee');
 qp(!in_array('position_level_name',array_column($summary['fields'],'key'),true),'Summary removes grade');
 qp($read->read([902],$range,[99101])['details']===[],'Store boundary prevents leaking unauthorized facts');
 Db::name('cashier_v3_performance_fact')->where('id',$refund['id'])->update(['amount_cents'=>0]);
 qp($read->read([901],$range,[99101])['details'][$labor['fact_id']]['amount_cents']===1620,'Refund change updates preview tier consistently');
 Db::name('yeji_commission')->where('id',1)->delete();
 $missing=$read->read([901],$range,[99101]);qp($missing['totals']['901:99101']['amount_cents']===null && !$missing['complete'],'Missing configuration is not zero');
 $missingSummary=(new BasicActivityReportServices())->read('basic_employee_summary',[901],$input);qp(!in_array('labor_commission',$missingSummary['sum_fields'],true),'Incomplete commission cannot become partial sum');
 qp(Db::name('cashier_v3_performance_fact')->where('id',$labor['id'])->value('labor_fee_amount_cents')===2500,'Read paths never alter original fact');
 qp(Db::name('position_yeji')->count()===2 && Db::name('position_salary_rule')->count()===0,'Old rules untouched, independent table remains empty');
 $write=new \app\services\payroll\IndependentPositionSalaryRuleServices();
 $data=['position_id'=>994,'status'=>1,'commission'=>12,'cate_ids'=>[],'range'=>'0-9999999','type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1];
 $id=$write->save(0,$data,0,1);qp($id>0,'New rule saved independently');
 $write->save($id,array_replace($data,['commission'=>13]),1,1);
 try {$write->save($id,$data,1,1);throw new RuntimeException('Stale form accepted');}catch(\mohe\exceptions\AdminException $expected){qp(true,'Stale write refused');}
 $write->change($id,2,1,0);qp((int)Db::name('position_salary_rule')->where('id',$id)->value('version')===3,'Status uses same optimistic version');
 $write->change($id,3,1,null);qp(Db::name('position_salary_rule_audit')->count()===4 && Db::name('position_yeji')->count()===2,'Audit complete and source rules untouched');
 echo json_encode(['result'=>'PASS','checks'=>$checks,'isolated_fixture_database'=>true,'persistent_business_writes'=>false],JSON_UNESCAPED_UNICODE),"\n";
} finally {if($created)$original->execute('DROP DATABASE `'.$database.'`');}
