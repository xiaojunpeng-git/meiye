<?php
/** 纯业务边界回归：无需数据库写入，覆盖历史、分类与完整门店组分页。 */
require $argv[1] . '/app/services/report/HumanEfficiencyRules.php';
use app\services\report\HumanEfficiencyRules as R;
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo 'PASS ', $label, "\n"; }
$categories = [['id'=>1,'pid'=>0,'statistics_enabled'=>1,'sort'=>1,'cate_name'=>'甲'],['id'=>2,'pid'=>0,'statistics_enabled'=>0,'sort'=>9,'cate_name'=>'乙'],['id'=>3,'pid'=>1,'statistics_enabled'=>1,'sort'=>9,'cate_name'=>'丙']];
check(R::categories($categories) === [['id'=>1,'name'=>'甲']], '动态分类只读取统计启用的根分类');
check(R::categories([]) === [], '没有分类不产生占位分类');
check(R::employedAt(['start_time'=>100,'end_time'=>200],199), '离职人员在历史期末仍属于在职分母');
check(!R::employedAt(['start_time'=>100,'end_time'=>200],200), '结束边界不重复计入在职人数');
check(R::tenureGroup('2026-05-05','2026-11-05')==='six_months','工龄六个月边界');
check(R::tenureGroup('2023-11-05','2026-11-05')==='three_years','工龄三年边界');
check(R::tenureGroup(null,'2026-11-05')==='unknown','缺失入职日期不冒充新员工');
$dimensions=[['organization_id'=>2,'valid_from'=>'2026-01-01','valid_to'=>'2026-06-30','enabled'=>1,'deleted_at'=>null]];
check(R::historicalManager('/1/2/3/','2026-06-30',$dimensions)!==null,'历史管辖含结束日');
check(R::historicalManager('/1/2/3/','2026-07-01',$dimensions)===null,'历史管辖不使用当前关系补齐');
check(R::ratio(100,0)===null && R::ratio(100,null)===null,'未知目标或零人数显示空比率');
check(R::allocateTarget(10001,['a'=>15,'b'=>15])===['a'=>5001,'b'=>5000],'按天分摊目标守恒及稳定尾差');
$rows=[];
for($i=1;$i<=41;$i++)foreach(['advisor','leader'] as $role)$rows[]=['id'=>"$role:$i",'store_id'=>$i,'role_code'=>$role,'cash_cents'=>$role==='advisor'?$i:999999,'completion'=>null];
$p=R::page($rows,'advisor',2);
check($p['total']===82 && count($p['rows'])===10 && $p['rows'][0]['store_id']===36 && $p['rows'][0]['role_code']==='advisor' && $p['rows'][1]['role_code']==='leader','按顾问总额排序，第二页10行，店长跟随');
check(count(R::page($rows,'advisor',9)['rows'])===2,'末页保留2行');
check(R::page([],'leader',9)['page']===1,'空结果页码归一');
$rank=[['id'=>'1','cash_cents'=>10000,'completion'=>null],['id'=>'2','cash_cents'=>100,'completion'=>2.0],['id'=>'3','cash_cents'=>200,'completion'=>1.0]];
check(array_column(R::page($rank,'manager',1)['rows'],'id')===['2','3','1'],'完成率排序优先于现金业绩，未设目标末尾');
require $argv[1] . '/app/services/report/HumanEfficiencyTargetProjector.php';
$calendar=[];for($d=1;$d<=30;$d++)$calendar[1][sprintf('2026-11-%02d',$d)]=$d<=15?'a':'b';
$projector=new app\services\report\HumanEfficiencyTargetProjector();
$lines=[['store_id'=>1,'month_key'=>'2026-11','target_value'=>10001]];
$all=$projector->project($lines,['start'=>'2026-11-01','end'=>'2026-11-30'],$calendar);
$a=$projector->project($lines,['start'=>'2026-11-01','end'=>'2026-11-15'],$calendar);
$b=$projector->project($lines,['start'=>'2026-11-16','end'=>'2026-11-30'],$calendar);
check(array_sum($all['managers'])===10001 && $a['stores'][1]+$b['stores'][1]===10001,'经理按天目标合计守恒，部分日期可加总');
$missing=$projector->project($lines,['start'=>'2026-11-01','end'=>'2026-11-30'],[]);
check($missing['managers']===[] && $missing['unknown'][1]['target_cents']===10001,'缺失历史管辖不归给当前经理');

$partial=$projector->project($lines,['start'=>'2026-11-01','end'=>'2026-11-30'],[1=>['2026-11-01'=>'a']]);
check($projector->managerCompletion(10000,'a',$partial)['completion']===null,'仅一天管辖已知不能用部分目标计算完成率');
check($projector->managerCompletion(10000,'a',$partial)['status']==='pending_history','历史缺口明确显示待补资料');
check($projector->managerCompletion(10000,'missing',$all)['status']==='missing_target','完整历史但未配置目标与历史缺口区分');
$zero=$projector->project([['store_id'=>1,'month_key'=>'2026-11','target_value'=>0]],['start'=>'2026-11-01','end'=>'2026-11-30'],$calendar);
check($projector->managerCompletion(10000,'a',$zero)['status']==='zero_target','显式零目标不冒充未配置');
check($projector->managerCompletion(10000,'a',$all)['completion']===10000/$all['managers']['a'],'补齐完整历史后按真实目标恢复完成率');

$missingTargetCalendar=$calendar;$missingTargetCalendar[2]=$calendar[1];
$missingTargetProjection=$projector->project($lines,['start'=>'2026-11-01','end'=>'2026-11-30'],$missingTargetCalendar);
check($projector->managerCompletion(10000,'a',$missingTargetProjection)['status']==='missing_target','一个管辖门店缺目标不能拿其余目标计算完成率');
require $argv[1].'/app/services/report/HumanEfficiencyHistoryServices.php';
$periods=[['store_id'=>1,'manager_organization_id'=>'a','manager_name_snapshot'=>'甲','valid_from'=>'2026-11-01','valid_to'=>'2026-11-15'],['store_id'=>1,'manager_organization_id'=>'b','manager_name_snapshot'=>'乙','valid_from'=>'2026-11-16','valid_to'=>'2026-11-30']];
$history=app\services\report\HumanEfficiencyHistoryServices::project($periods,[1],['start'=>'2026-11-01','end'=>'2026-11-30']);
check($history['complete']&&$history['days'][1]['2026-11-15']==='a'&&$history['days'][1]['2026-11-16']==='b','历史经理月中交接不重叠不漏日');
$periods[1]['valid_from']='2026-11-15';
try{app\services\report\HumanEfficiencyHistoryServices::project($periods,[1],['start'=>'2026-11-01','end'=>'2026-11-30']);throw new RuntimeException('重叠未拒绝');}catch(InvalidArgumentException $e){echo "PASS 重叠历史资料拒绝统计\n";}
require $argv[1].'/app/services/report/HumanEfficiencyDetailCollector.php';
$collector=new app\services\report\HumanEfficiencyDetailCollector(['entity_id'=>'1:1','metric'=>'cash','page'=>2],[],[]);
for($i=1;$i<=45;$i++)$collector->add(['id'=>'1:1','name'=>'测试'],['business_date'=>'2026-11-05','cash_cents'=>101,'store_id'=>1,'member_id'=>$i]);
$detail=$collector->result('测试');
check(count($detail['rows'])===10&&$detail['total']===45&&$detail['cash_cents']===4545,'流式第二页仅10条，合计仍覆盖45条分精度事实');
$collector=new app\services\report\HumanEfficiencyDetailCollector(['entity_id'=>'1:1','metric'=>'buyers','page'=>9],[],[]);
for($i=1;$i<=45;$i++)$collector->add(['id'=>'1:1','name'=>'测试'],['business_date'=>'2026-11-05','cash_cents'=>101,'store_id'=>1,'member_id'=>(int)ceil($i/2)]);
$detail=$collector->result('测试');
check($detail['total']===23&&$detail['page']===3&&count($detail['rows'])===3,'成交顾客去重及越界末页一致');

// 线上反馈回归：岗位标记冲突、跨页分类及跨组顾客占比。
check(R::role(['position_name'=>'副店长','is_store_manager'=>1],[])==='advisor','副店长优先归顾问，不重复计入店长');
check(R::role(['position_name'=>'店长','is_store_manager'=>1],[])==='leader','店长岗位保留');
check(R::role(['position_name'=>'顾问','is_store_manager'=>0],[])==='advisor','既有顾问岗位兼容');
$catalog=[['id'=>1,'name'=>'甲'],['id'=>2,'name'=>'乙'],['id'=>3,'name'=>'丙']];
$full=array_fill(0,10,['category_values'=>[]]);
$full[]=['category_values'=>[1=>['cash_cents'=>1,'buyers'=>0],2=>['cash_cents'=>0,'buyers'=>1]]];
check(array_column(R::visibleCategories($catalog,$full),'id')===[1,2],'第二页非零分类及仅有成交顾客分类保留');
check(R::visibleCategories($catalog,array_slice($full,0,10))===[],'纯零分类隐藏');
check(R::visibleCategories($catalog,[['category_values'=>[1=>['cash_cents'=>-1]]]])===[$catalog[0]],'负分值不能误判为零');
check(R::ratio(2,3)+R::ratio(2,3)>1,'跨组去重人数占比允许超过100%');
