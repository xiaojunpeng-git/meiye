<?php
/** 完整本地闭环：不写业务数据，榜单、下钻、导出与共享事实逐项对账。 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\report\HumanEfficiencyDashboardServices as Dashboard;
use app\services\report\StoreUnifiedReportPhaseSixServices as Shared;
use app\services\query\metric\RegisteredMetricReadServices as Registered;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('Local only');
function verify($condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
 $stores=[23];$range=['start'=>'2026-10-01','end'=>'2026-11-05'];$service=new Dashboard();
 $report=$service->read($stores,$range);$export=$service->read($stores,$range,['action'=>'export']);
 // 完整榜单布局与导出契约一致，分页不能改变分类列。
 foreach(['advisor','leader','manager'] as $kind){
  $ids=array_column(array_filter($report['ranking_layouts'][$kind]['columns'],static fn($c)=>$c['category_id']>0&&$c['metric']==='cash'),'category_id');
  verify($ids===array_column($export['section_categories'][$kind],'id'),"$kind 显示与导出分类一致");
 }
 verify(!in_array('manager:unknown',array_column($export['rows'],'id'),true),'未配置经理不进入榜单与导出');
 foreach($report['cohorts'] as $cohort)foreach(['active','buyers'] as $metric){
  $expectedRatio=app\services\report\HumanEfficiencyRules::ratio($cohort[$metric],$report['beauty'][$metric]);
  verify($cohort['contribution'][$metric]===$expectedRatio,"工龄组 $metric 按全部去重顾客计算占比");
 }
 $personal=array_values(array_filter($export['rows'],static fn($r)=>in_array($r['section'],['beauty','leader','unknown'],true)||($r['section']==='advisor'&&$r['role_code']==='advisor')));
 $facts=(new Shared())->humanEfficiencyFacts($stores,$range);
 $expected=0;foreach($facts as $f)if(!in_array($f['source_type']??'',['manual_refund','imported_card_refund'],true))$expected+=(int)$f['cash_cents'];
 verify(array_sum(array_column($personal,'cash_cents'))===$expected,'完整个人榜单与正向人员现金分配守恒');
 verify(count(array_filter($personal,static fn($r)=>(int)$r['store_id']!==23))===0,'导出未越出授权门店');
 foreach(['beauty','leader','advisor','manager'] as $role){
  $summary=$role==='beauty'?$report['beauty']:$report[$role.'_summary'];
  foreach(['cash','consume','items','visits','active','buyers','orders'] as $metric){
   $detail=$service->read($stores,$range,['action'=>'detail','entity_id'=>'summary:'.$role,'metric'=>$metric]);
   $key=in_array($metric,['cash','consume'],true)?$metric.'_cents':$metric;
   $actual=in_array($metric,['active','buyers','orders'],true)?$detail['total']:$detail[$key];
   verify(abs($actual-$summary[$key])<0.00001,"$role $metric 汇总与全部明细一致");
  }
 }
 $registered=Registered::withCurrentReportProductCategory();$cash=0;
 $configured=array_keys(array_filter((new app\services\report\HumanEfficiencyFactReader())->currentManagers($stores),static fn($m)=>(string)$m['organization_id']!=='unknown'));
 foreach($configured ? $registered->categoryRows('cash_performance','0',$configured,$range) : [] as $r)$cash+=(int)$r['amount_cents'];
 verify($report['manager_summary']['cash_cents']===$cash,'经理现金与门店统一现金指标一致');
 $current=(new app\services\report\HumanEfficiencyFactReader())->currentManagers($stores);
 foreach($report['tables']['manager']['rows'] as $r)verify($r['manager_id']===(string)$current[23]['organization_id'] && $r['target_status']!=='待补齐历史管辖资料','经理按当前门店归属且不再受历史资料阻断');
 $row=$report['tables']['leader']['rows'][0];
 foreach($report['categories'] as $category){
  $detail=$service->read($stores,$range,['action'=>'detail','entity_id'=>$row['id'],'category_id'=>$category['id'],'metric'=>'cash']);
  verify($detail['cash_cents']===$row['category_values'][$category['id']]['cash_cents'],'分类金额与分类原单守恒 '.$category['id']);
 }
 try{$service->read($stores,$range,['action'=>'detail','entity_id'=>'999:999']);throw new RuntimeException('Out of scope accepted');}catch(InvalidArgumentException $e){echo "PASS 越权行键拒绝下钻\n";}
 try{$service->read($stores,$range,['action'=>'detail','entity_id'=>$row['id'],'category_id'=>999999]);throw new RuntimeException('Unknown category accepted');}catch(InvalidArgumentException $e){echo "PASS 未启用分类拒绝下钻\n";}
 $person=$personal[0];$restricted=$service->read($stores,$range,['_employee_id'=>$person['employee_id'],'action'=>'export']);
 verify(count(array_filter($restricted['rows'],static fn($r)=>$r['employee_id']!==$person['employee_id']))===0,'个人权限不升级成门店全员或经理数据');
 try{$service->read($stores,$range,['_employee_id'=>$person['employee_id'],'action'=>'detail','entity_id'=>'manager:unknown']);throw new RuntimeException('Personal expanded');}catch(InvalidArgumentException $e){echo "PASS 个人权限不能下钻经理全店事实\n";}
 $before=(new Shared())->salaryRankingRows($stores,$range);$service->read($stores,$range);$after=(new Shared())->salaryRankingRows($stores,$range);
 verify($before===$after,'人效投影不改变已有薪资报表默认结果');
 $empty=$service->read($stores,['start'=>'1990-01-01','end'=>'1990-01-31']);
 verify($empty['beauty']['count']===0&&$empty['beauty']['cash_cents']===0&&$empty['beauty']['average']['cash']===null,'历史空结果零分母显示空值');
 echo 'EVIDENCE ',json_encode(['scope'=>$stores,'range'=>$range,'beauty'=>$report['beauty'],'leader'=>$report['leader_summary'],'advisor'=>$report['advisor_summary'],'manager'=>$report['manager_summary']],JSON_UNESCAPED_UNICODE),"\n";
}finally{Db::rollback();}
