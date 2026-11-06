<?php
/** 无数据库的边界验证：分档、月份、任职历史、去重及均值重算。 */
require __DIR__.'/../../后端代码/app/services/report/HumanEfficiencyRules.php';
require __DIR__.'/../../后端代码/app/services/report/HumanEfficiencyStoreCohortServices.php';
use app\services\report\HumanEfficiencyStoreCohortServices as C;
$checks=0;
function checkC($ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); ++$checks; echo "PASS $message\n"; }
checkC(C::monthEnds(['start'=>'2026-01-31','end'=>'2026-02-01'])===['2026-01-31','2026-02-01'],'跨月不足整月按两个覆盖月');
checkC(C::monthEnds(['start'=>'2026-11-06','end'=>'2026-11-06'])===['2026-11-06'],'单日为一个月且人数截止当日');
checkC(C::monthEnds(['start'=>'2025-12-20','end'=>'2026-02-02'])===['2025-12-31','2026-01-31','2026-02-02'],'跨年按自然月连续计数');
foreach ([60000000=>'A',59999999=>'B',40000000=>'B',39999999=>'C',20000000=>'C',19999999=>'D',0=>'D'] as $cents=>$expected) checkC(C::grade($cents,2)===$expected,'两月分档分精度边界 '.$cents);
try { C::grade(100,0); throw new RuntimeException('zero months accepted'); } catch (InvalidArgumentException $e) { checkC(true,'拒绝零月份'); }
$make=static fn($id,$cash,$beauty,$active,$visits,$leaders,$advisors)=>['store_id'=>$id,'cash_cents'=>$cash,'consume_cents'=>100000,'beauty_month_count'=>$beauty,'active_month_count'=>$active,'visits'=>$visits,'leaders'=>$leaders,'advisors'=>$advisors,'category_cents'=>[1=>$cash]];
$stores=[$make(1,40000000,4,20,60,[11=>true],[21=>true]),$make(2,40000000,8,40,120,[12=>true,13=>true],[21=>true,22=>true]),$make(3,0,0,0,0,[],[])];
$p=C::project($stores,2,[['id'=>1,'name'=>'生美'],['id'=>2,'name'=>'花园']]);$b=$p['rows'][1];
checkC($b['stores']===2 && $b['average_cash']===200000.0,'两店两月80万元得到月店均20万元');
checkC($b['average_beauty']===3.0 && $b['average_active']===15.0 && $b['average_visits']===45.0,'美容师、活客与人次统一月店分母');
checkC($b['per_beauty_active']===5.0 && $b['per_beauty_visits']===15.0,'人均服务以月均美容师为分母');
checkC($p['summary']===['with_leader'=>2,'without_leader'=>1,'advisors'=>2],'一店多店长只计一家，跨店顾问去重');
checkC($b['leaders']===3 && $b['advisors']===2,'店长人数与有店长院店数分别统计');
checkC(abs($p['total']['average_cash']-800000/6)<0.0001 && $p['total']['average_active']===10.0,'合计使用全部三店重算均值');
checkC($p['rows'][0]['average_cash']===null && $p['rows'][0]['stores']===0,'空分档无分母返回空值而非伪造0均值');
checkC($p['rows'][3]['average_cash']===0.0 && $p['rows'][3]['per_beauty_active']===null,'有店无业绩为0，无美容师人均为空');
checkC(count($p['layout']['columns'])===13 && $b['category_values'][2]===0.0,'零成交分类仍展示');
$stores[0]['category_cents']=[1=>39999900,0=>100];$p=C::project($stores,2,[['id'=>1,'name'=>'生美']]);
checkC(in_array('category:0',array_column($p['layout']['columns'],'key'),true),'分类外现金单列而不漏计');
checkC(abs(array_sum($p['rows'][1]['category_values'])-$p['rows'][1]['average_cash'])<0.0001,'分类和总销售金额守恒');
checkC(C::project([],1,[])['total']['average_cash']===null,'空授权投影不产生均值');
$person=static fn($id,$employee,$store,$craft=0)=>['staff_id'=>$id,'employee_id'=>$employee,'store_id'=>$store,'join_date'=>'2026-01-01','status'=>1,'is_del'=>0,'cashier_craftsman_enabled'=>$craft];
$job=static fn($id,$role,$start,$end=0)=>['id'=>$id,'staff_id'=>$id,'start_time'=>strtotime($start),'end_time'=>$end?strtotime($end):0,'position_name'=>$role,'is_store_manager'=>1];
$data=['staff'=>[$person(1,11,1),$person(2,12,1),$person(3,13,1),$person(4,14,2),$person(5,15,1,1)],
 'tenures'=>[['staff_id'=>1,'start_time'=>strtotime('2026-01-01'),'end_time'=>strtotime('2026-02-01')]],
 'jobs'=>[$job(1,'店长','2026-01-01'),$job(2,'副店长','2026-01-01'),$job(3,'店长','2026-01-01','2026-02-01'),$job(4,'店长','2026-01-01')]];
$j=C::workforce($data,[1],'2026-01-31');$f=C::workforce($data,[1],'2026-02-01');
checkC(count($j[1]['leader'])===2 && count($f[1]['leader'])===0,'离职或结束店长岗位后不再计在职，历史月保留');
checkC($f[1]['advisor']===[12=>true],'副店长优先归顾问，不重复店长人数');
checkC(!isset($f[2]) && $f[1]['beauty']===[15=>true],'人员范围不能扩大，美容师沿用有效手艺人资格');
checkC(C::workforce($data,[1],'2025-12-31')[1]===['leader'=>[],'advisor'=>[],'beauty'=>[]],'未来入职及岗位不进入过去月份');
// 010真实历史形态：2350元现金中有351元项目缺分类维度，整店分档不可漏计。
$legacy=C::withAuthoritativeTotals(['cash_cents'=>199900,'consume_cents'=>0,'category_cents'=>[0=>199900]],235000,123400);
checkC($legacy['cash_cents']===235000 && $legacy['category_cents']===[0=>235000] && $legacy['consume_cents']===123400,'缺分类关联的351元保留整店总额及未分类金额');
$exact=['cash_cents'=>40000000,'consume_cents'=>100,'category_cents'=>[1=>30000000,2=>10000000]];
checkC(C::withAuthoritativeTotals($exact,40000000,100)===$exact,'完整分类不生成差额或额外未分类列');
$threshold=C::withAuthoritativeTotals(['category_cents'=>[1=>29990000]],30000000,0);
checkC(C::grade($threshold['cash_cents'],1)==='A' && array_sum($threshold['category_cents'])===30000000,'缺分类关联不能使30万门店误分B类');
try { C::withAuthoritativeTotals(['category_cents'=>[1=>101]],100,0); throw new LogicException('overcount accepted'); }
catch (RuntimeException $e) { checkC(true,'分类重复超过总额不能负数补差掩盖'); }
echo "TOTAL $checks checks passed\n";
