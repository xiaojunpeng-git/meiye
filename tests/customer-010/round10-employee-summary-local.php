<?php
/** 原员工统计列契约与旧新客定义的真实SQL/Host集成；只写随机本机夹具库。 */
use think\facade\Db;
use app\services\report\BasicActivityReportServices as Reports;
use app\services\report\EmployeeSummaryNewCustomerReadServices as Customers;
(new think\App())->initialize();set_exception_handler(function($e){file_put_contents('php://stderr',(string)$e);exit(1);});
if(!in_array(config('database.connections.mysql.hostname'),['mysql','mohe-mysql'],true))throw new RuntimeException('Local fixture only');
$checks=0;function ec($ok,$label){global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
$connection=config('database.connections.mysql');$original=Db::connect();$source=$connection['database'];$database='employee_summary_test_'.bin2hex(random_bytes(5));$created=false;
try{
 $original->execute('CREATE DATABASE `'.$database.'`');$created=true;
 foreach($original->query('SHOW TABLES')as$entry){$table=(string)reset($entry);if(preg_match('/^eb_(cashier_v3_|store_product|store_order|system_store|system_business_product|staff_job_position|position|employee|cash_source)/',$table))$original->execute('CREATE TABLE `'.$database.'`.`'.$table.'` LIKE `'.$source.'`.`'.$table.'`');}
 $cfg=config('database');$cfg['connections']['employee_fixture']=array_replace($connection,['database'=>$database]);$cfg['default']='employee_fixture';config($cfg,'database');
 Db::execute("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");config(['position_model'=>'independent'],'payroll');$GLOBALS['payroll_test_mode']=1;
 function ef($table,$values){static$schemas=[],$fixtureCounter=0;++$fixtureCounter;$schemas[$table]=$schemas[$table]??Db::query('SHOW COLUMNS FROM eb_'.$table);$r=[];foreach($schemas[$table]as$c){$k=$c['Field'];if(in_array($k,['natural_key','command_idempotency_key'],true)&&!isset($values[$k]))$values[$k]='fixture-'.$table.'-'.$fixtureCounter;if(strpos($c['Extra'],'GENERATED')!==false)continue;if(array_key_exists($k,$values)){$r[$k]=$values[$k];continue;}if($c['Default']!==null||$c['Null']==='YES'||strpos($c['Extra'],'auto_increment')!==false)continue;$r[$k]=preg_match('/int|decimal|float|double/',$c['Type'])?0:(strpos($c['Type'],'date')!==false?'2026-10-28':'');}Db::name($table)->insert(array_replace($r,$values));}
 foreach([901,902]as$store)ef('system_store',['id'=>$store,'name'=>'夹具门店'.$store,'is_del'=>0]);
 foreach([[3,'自然进店',20],[6,'老客续卡',23]]as[$old,$name,$new]){ef('cash_source',['id'=>$old,'name'=>$name,'status'=>1]);ef('cashier_v3_business_source',['id'=>$new,'parent_id'=>0,'name'=>$name,'status'=>1]);}
 ef('store_product_relation',['product_id'=>888,'relation_id'=>78,'type'=>1]);
 $seq=0;
 $make=function($member,$people,$cash=29800,$opts=[])use(&$seq){
  ++$seq;$order='employee-order-'.$seq;$line='employee-line-'.$seq;$day=$opts['day']??'2026-10-27';$store=$opts['store']??901;
  $base=['tenant_id'=>'0','store_id'=>$store,'order_id'=>$order,'source_line_id'=>$line,'checkout_request_id'=>'checkout-'.$seq,'member_id'=>$member,'business_date'=>$day,'status'=>'effective','fact_direction'=>'forward','store_name_snapshot'=>'夹具门店'.$store,'order_no_snapshot'=>$order,'source_document_type'=>'checkout','business_source_primary_id'=>$opts['source_id']??20,'business_source_primary_name_snapshot'=>$opts['source_name']??'自然进店'];
  ef('cashier_v3_sale_fact',$base+['fact_id'=>'sale-'.$seq,'source_type'=>$opts['kind']??'project','item_id'=>$opts['item']??999,'item_name_snapshot'=>'夹具项目','quantity'=>1,'sale_amount_cents'=>$opts['amount']??$cash,'debt_amount_cents'=>$opts['debt']??0]);
  ef('cashier_v3_report_sale_dimension_fact',['tenant_id'=>'0','sale_fact_id'=>'sale-'.$seq,'item_id'=>$opts['item']??999,'product_type_snapshot'=>'project','item_name_snapshot'=>'夹具项目']);
  ef('cashier_v3_sales_order_line',['tenant_id'=>'0','store_id'=>$store,'order_id'=>$order,'order_line_id'=>'order-line-'.$seq,'checkout_line_id'=>$line,'line_status'=>'settled','line_direction'=>'forward','service_object'=>$opts['object']??'self','salespeople_snapshot_json'=>'[]']);
  if($cash>0)ef('cashier_v3_payment_fact',array_replace($base,['source_line_id'=>'payment-line-'.$seq,'fact_id'=>'payment-'.$seq,'payment_method'=>'cash','amount_cents'=>$cash]));
  $facts=[];foreach($people as$person){$p=$base+['fact_id'=>'sales-person-'.$seq.'-'.$person,'performance_type'=>'sales_performance_allocated','employee_id'=>$person,'employee_name_snapshot'=>'夹具员工'.$person,'amount_cents'=>intdiv($cash,count($people)),'allocation_weight_numerator'=>1,'allocation_weight_denominator'=>count($people)];ef('cashier_v3_performance_fact',$p);$facts[]=$p['fact_id'];}
  if(isset($opts['operation']))ef('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','store_id'=>$store,'operation_id'=>'operation-'.$seq,'operation_no'=>'OP-'.$seq,'source_order_id'=>$order,'source_type'=>'sales','operation_type'=>$opts['operation'],'status'=>'succeeded']);
  return ['order'=>$order,'sale'=>'sale-'.$seq,'facts'=>$facts];
 };
 $make(100,[1,2,3]);$make(100,[1],19900);$make(100,[1],29800,['day'=>'2026-10-28']);
 $make(101,[1],29700);$make(102,[1]);$make(0,[4,5],0);$make(103,[4,5],0,['object'=>'friend']);
 $make(104,[1],29800,['operation'=>'refund']);$make(105,[1],29800,['operation'=>'void']);
 $make(106,[1],29800,['source_id'=>23,'source_name'=>'老客续卡']);$make(107,[1],0,['amount'=>29800,'debt'=>29800]);
 $make(108,[1],29800,['item'=>888]);$make(109,[1],29800,['store'=>902]);
 for($i=0;$i<25;++$i)$make(200+$i,[100+$i]);
 $range=['start'=>'2026-10-27','end'=>'2026-10-28'];
 $sales=(new app\services\report\StoreSalespersonNetReadServices())->detail([901],$range,[],0,1,20,true)['records'];
 $counts=(new Customers())->read([901],$range,$sales);
 ec($counts['901:1']['total_hundredths']===333,'Member/day dedup, independent cohorts and 297 below threshold');
 ec($counts['901:1']['converted_hundredths']===233,'298 inclusive threshold and no refund/void/source/cooperation/debt leakage');
 ec($counts['901:2']['total_hundredths']===33&&$counts['901:3']['total_hundredths']===34,'Three employees stable hundredths tail');
 ec($counts['901:4']['total_hundredths']===200&&$counts['901:5']['total_hundredths']===200,'Guest and friend each personnel row');
 ec(!isset($counts['902:1']),'Store boundary');ec(Customers::rate(1,3)===33&&Customers::rate(2,3)===66,'Rate preserves original integer percent formula');ec(Customers::rate(0,0)===0,'Known zero denominator keeps original zero percent; missing source is separate');
 $service=new Reports();$input=['start_date'=>$range['start'],'end_date'=>$range['end'],'as_of_date'=>$range['end'],'_report_scope'=>['mode'=>'stores']];
 $result=$service->read('basic_employee_summary',[901],$input);$byId=array_column($result['rows'],null,'employee_id');
 ec($result['complete'],'Complete native fixtures');ec($byId[1]['new_customer_total']===3.33&&$byId[1]['new_customer_converted']===2.33,'Report counts use hundredths, never money scaling');
 $visible=array_values(array_filter($result['fields'],static function($f){return$f['defaultVisible'];}));
 $expected=['门店','职务','姓名','现金业绩','劳动业绩','客数','客单价','项目数','指定客','服务总新客','成交总新客','新客成交率%'];
 ec(array_column($visible,'label')===$expected,'Exact 007 twelve labels and order');ec(!in_array('new_customer_conversion_rate',$result['sum_fields'],true),'Rate is not additive');ec(!in_array('labor_unit_price',$result['sum_fields'],true),'Unit price is not additive');
 $self=$service->read('basic_employee_summary',[901],array_replace($input,['_report_scope'=>['mode'=>'self_participant','employee_id'=>2]]));ec(count($self['rows'])===1&&$self['rows'][0]['new_customer_total']===0.33,'Employee narrowing happens after full coworker split');
 $old=(new app\services\report\Round10BaselineActivityReportServices())->read('basic_employee_summary',[901],$input);$oldRows=array_column($old['rows'],null,'row_id');
 foreach($result['rows']as$row)foreach($oldRows[$row['row_id']]as$key=>$value)ec($row[$key]===$value,'Existing employee metric unchanged: '.$key);
 foreach(Reports::codes()as$code)if($code!=='basic_employee_summary')ec($service->definitions($code)===(new app\services\report\Round10BaselineActivityReportServices())->definitions($code),'Other report metadata unchanged: '.$code);
 config($GLOBALS['fixed_query_config'],'unified_query');app\services\query\UnifiedQueryRuntime::resetForTests();$host=app\services\report\BasicReportHostServices::class;$ctx=$host::context([901],'stores',1,'platform',false,0,'basic_employee_summary');
 $body=$input;unset($body['_report_scope']);$body['pageCode']='basic_employee_summary';$body['export']=['scope'=>'query','fields'=>array_column($visible,'key')];
 $page=$host::query($ctx,$body);ec(count($page['rows'])===20&&$page['pagination']['total']===count($result['rows']),'Twenty-row pagination and complete population');ec(count($page['exportRows'])===count($result['rows']),'Full export is not current page');
 ec(abs($page['summaries']['new_customer_total:sum']-array_sum(array_column($result['rows'],'new_customer_total')))<0.000001,'New-customer sum uses all matching rows');
 $second=$host::query($ctx,array_replace($body,['page'=>2]));ec(count($second['rows'])===count($result['rows'])-20,'Second-page remainder');
 $filtered=$host::query($ctx,array_replace($body,['filters'=>[['field'=>'employee_id','operator'=>'eq','value'=>2]]]));ec($filtered['pagination']['total']===1&&$filtered['exportRows'][0]['new_customer_total']===0.33,'Filter, export and denominator agree');
 // 卡内项目逐项核实合作排除；不能把整卡外壳当成卡内项目。
 $card=$make(700,[8],29800,['kind'=>'card','item'=>777]);
 ef('cashier_v3_card_sale_item_allocation_fact',['tenant_id'=>'0','store_id'=>901,'order_id'=>$card['order'],'sale_fact_id'=>$card['sale'],'allocation_fact_id'=>'coop-card-allocation','component_product_id'=>888,'status'=>'effective']);
 $data=(new Reports())->read('basic_employee_summary',[901],$input);$map=array_column($data['rows'],null,'employee_id');ec($map[8]['new_customer_total']===0,'Cooperative component excludes whole original card order');
 // 迁入卡退款按发行回执定位原销售，不能把持卡ID当订单ID或漏掉原成交客。
 $imported=$make(703,[11],29800,['kind'=>'card','item'=>777]);
 ef('cashier_v3_card_sale_item_allocation_fact',['tenant_id'=>'0','store_id'=>901,'order_id'=>$imported['order'],'sale_fact_id'=>$imported['sale'],'allocation_fact_id'=>'normal-card-allocation','component_product_id'=>999,'status'=>'effective']);
 ef('cashier_v3_card_purchase_receipt',['tenant_id'=>'0','store_id'=>901,'receipt_id'=>'imported-receipt','sales_order_id'=>$imported['order'],'sales_order_line_id'=>'order-line-'.$seq,'issue_no'=>1,'card_holder_id'=>77123,'status'=>'completed']);
 ef('cashier_v3_order_lifecycle_operation',['tenant_id'=>'0','store_id'=>901,'operation_id'=>'imported-card-refund','operation_no'=>'IMPORTED-REFUND','source_type'=>'imported_card','source_order_id'=>'77123','operation_type'=>'refund','status'=>'succeeded']);
 $data=(new Reports())->read('basic_employee_summary',[901],$input);$map=array_column($data['rows'],null,'employee_id');ec($map[11]['new_customer_total']===0,'Imported-card refund excludes the verified original sale');
 // 改派后的最终正向分配决定人员集合，已冲销原人员不能继续参与分母。
 $adjust=$make(701,[7,8]);
 $originalFact=Db::name('cashier_v3_performance_fact')->where('fact_id',$adjust['facts'][0])->find();unset($originalFact['id'],$originalFact['natural_key'],$originalFact['command_idempotency_key']);
 ef('cashier_v3_performance_fact',array_replace($originalFact,['fact_id'=>'personnel-reversal','fact_direction'=>'reversal','reversal_of'=>$adjust['facts'][0],'amount_cents'=>-(int)$originalFact['amount_cents']]));
 ef('cashier_v3_performance_fact',array_replace($originalFact,['fact_id'=>'personnel-reassigned','employee_id'=>9,'employee_name_snapshot'=>'夹具员工9']));
 $data=(new Reports())->read('basic_employee_summary',[901],$input);$map=array_column($data['rows'],null,'employee_id');ec(!isset($map[7])&&$map[8]['new_customer_total']===0.5&&$map[9]['new_customer_total']===0.5,'Adjusted employee replaces old allocation and shares remain conserved');
 // 卡项缺少内含项目证明时，该人的新客留空；其现金、劳动等既有指标仍可查询。
 $make(702,[10],29800,['kind'=>'card','item'=>777]);$data=(new Reports())->read('basic_employee_summary',[901],$input);$map=array_column($data['rows'],null,'employee_id');ec($map[10]['new_customer_total']===null&&(float)$map[10]['sales_performance']===298.0,'Missing card component proof is explicit, existing cash preserved');
 // 后续补交不把旧成交重落档；未知原客源必须留空，不能展示成零或完整合计。
 $make(500,[1],0,['amount'=>10000]);ef('cashier_v3_payment_fact',['tenant_id'=>'0','store_id'=>901,'order_id'=>'employee-order-'.$seq,'fact_id'=>'later-payment','status'=>'effective','fact_direction'=>'forward','source_document_type'=>'debt_repayment','amount_cents'=>50000]);
 ef('cashier_v3_performance_fact',['tenant_id'=>'0','store_id'=>901,'order_id'=>'employee-order-'.$seq,'source_line_id'=>'employee-line-'.$seq,'fact_id'=>'repayment-person','employee_id'=>6,'employee_name_snapshot'=>'夹具员工6','performance_type'=>'sales_performance_allocated','source_document_type'=>'debt_repayment','status'=>'effective','fact_direction'=>'forward','business_date'=>'2026-10-28','amount_cents'=>50000]);
 $updated=(new Reports())->read('basic_employee_summary',[901],$input);$u=array_column($updated['rows'],null,'employee_id');ec($u[6]['new_customer_total']===0&&$u[1]['new_customer_converted']===2.33,'Later repayment does not change original 298 tier');
 $make(501,[1],29800,['source_id'=>999,'source_name'=>'未映射来源']);$incomplete=(new Reports())->read('basic_employee_summary',[901],$input);$bad=array_column($incomplete['rows'],null,'employee_id');ec($bad[1]['new_customer_total']===null&&!$incomplete['complete']&&!in_array('new_customer_total',$incomplete['sum_fields'],true),'Missing source is explicit and cannot form partial complete total');
 echo json_encode(['result'=>'PASS','checks'=>$checks,'fixture_rows'=>count($result['rows']),'temporary_local_database'=>true,'remote_writes'=>0,'browser_fixture'=>['page'=>$page,'full_rows'=>$result['rows'],'catalog'=>array_values(array_filter($host::catalog(),static function($r){return$r['code']==='basic_employee_summary';}))]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
}finally{if($created)$original->execute('DROP DATABASE `'.$database.'`');}
