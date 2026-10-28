<?php
/** 注入既有隔离MySQL夹具：真实Reader及手机投影；只替换认证后的固定授权范围。 */
Db::execute('CREATE TABLE eb_employee_mobile_auth LIKE `'.$source.'`.eb_employee_mobile_auth');
fp('employee_mobile_auth',['employee_id'=>99101,'status'=>1,'is_del'=>0,'rules'=>'401007']);
fp('yeji_commission',['id'=>1,'product_id'=>100,'commission_json'=>json_encode(['0-10000'=>25,'10001-20000'=>27,'20001-25000'=>28,'25001-30000'=>29,'30000-9999999'=>30])]);
$mobileReflection=new ReflectionClass(\app\services\mobile\warehouse\MobileWarehouseServices::class);
$mobile=$mobileReflection->newInstanceWithoutConstructor();
$scopeProperty=$mobileReflection->getProperty('entryScopes');$scopeProperty->setAccessible(true);
$scopeProperty->setValue($mobile,new class{public function resolve(array $merchant):array{return ['authorizedStoreIds'=>[901],'entryType'=>'personal'];}});
$mobileInput=['employeeId'=>99101,'periodMode'=>'custom','startDate'=>'2026-10-22','endDate'=>'2026-10-22','detailMode'=>'labor','page'=>1,'pageSize'=>20];
$merchant=['employeeId'=>99101,'storeId'=>901];$registered=new \app\services\query\metric\RegisteredMetricReadServices();
$projections=[];
foreach([0,1] as $mode){
 $GLOBALS['payroll_test_mode']=$mode;
 foreach([['1.000000',2,'1'],['0.333333',2,'0.333333'],['0.000000',2,'0'],[null,1,'0.5'],['-0.500000',2,'-0.5']] as [$decimal,$half,$display]){
  Db::name('cashier_v3_performance_fact')->where('id',$labor['id'])->update(['project_count_decimal'=>$decimal,'project_count_half_units'=>$half]);
  $projection=$mobile->employeePerformance($merchant,$mobileInput)['employeePerformance'];
  qp(count($projection['details'])===1 && $projection['details'][0]['displayProjectCount']===$display,'Mode '.$mode.' detail preserves exact project share '.$display);
  $metrics=array_column($projection['metrics'],'value','code');
  qp($metrics['staff_project_num']===$display,'Detail project share agrees with registered summary');
  qp($projection['details'][0]['displayLaborFee']==='25' && $metrics['staff_labor_fee']==='25','Handwork fee source remains intact in both modes');
  qp($projection['laborAlgorithm']['code']===($mode===1?'project_tiers':'existing'),'Server switch remains authoritative');
 }
 Db::name('cashier_v3_performance_fact')->where('id',$labor['id'])->update(['project_count_decimal'=>'0.333333','project_count_half_units'=>2]);
 $projections[(string)$mode]=$mobile->employeePerformance($merchant,$mobileInput);
 $cash=$mobile->employeePerformance($merchant,array_replace($mobileInput,['detailMode'=>'cash','periodMode'=>'month','month'=>'2026-10']))['employeePerformance'];
 foreach($cash['details'] as $line)qp($line['displayProjectCount']===null,'Sales lines never masquerade as completed service project counts');
 $empty=$mobile->employeePerformance($merchant,array_replace($mobileInput,['startDate'=>'2026-10-23','endDate'=>'2026-10-23']))['employeePerformance'];
 qp($empty['details']===[] && $empty['pagination']['total']===0,'Empty period retains clear zero pagination');
}
$GLOBALS['payroll_test_mode']=1;
try{$mobile->employeePerformance($merchant,array_replace($mobileInput,['employeeId'=>99102]));throw new RuntimeException('Personal scope widened');}catch(\app\services\mobile\protocol\MobileApiException $expected){qp(true,'Personal employee boundary retained');}
$page=$registered->personnelDetailPage('staff_labor_yeji','0',[902],['start'=>'2026-10-22','end'=>'2026-10-22'],99101,1,20);
qp($page['rows']===[],'Detail field does not widen authorized stores');
if(!empty($GLOBALS['round8_projection_output']))file_put_contents($GLOBALS['round8_projection_output'],json_encode($projections,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
