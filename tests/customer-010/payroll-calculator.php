<?php
/** 真实纯计算器：边界、负数冲销、零基数、缺配置与职位等价转换；不连接数据库。 */
declare(strict_types=1);
$source=getenv('PAYROLL_SOURCE_ROOT') ?: dirname(__DIR__,2).'/后端代码';
foreach (['ProjectLaborCommissionCalculator','PositionSalaryRuleResolver'] as $class) if (!class_exists('app\\services\\payroll\\'.$class)) require_once $source.'/app/services/payroll/'.$class.'.php';
use app\services\payroll\ProjectLaborCommissionCalculator as Calculator;
use app\services\payroll\PositionSalaryRuleResolver as Resolver;
$n=0;
function checkPayroll($ok,$label) { global $n; ++$n;if (!$ok) throw new RuntimeException($label); }
function rejectedPayroll($fn,$label) { try {$fn();} catch (DomainException $e) {checkPayroll(true,$label);return;}throw new RuntimeException($label); }
$c=new Calculator();$config=['0-10000'=>25,'10001-20000'=>27,'20001-25000'=>28,'25001-30000'=>29,'30000-9999999'=>30];
foreach ([[0,'25'],[1000000,'25'],[1000001,'27'],[2000000,'27'],[2000001,'28'],[2500000,'28'],[2500001,'29'],[2999999,'29'],[3000000,'30'],[3197000,'30'],[-100,'25']] as [$sales,$rate]) checkPayroll($c->rate($config,$sales)['rate']===$rate,'Unique tier '.$sales);
checkPayroll($c->calculate(['metric_value'=>32600],$config,3197000)['amount_cents']===9780,'007 example traced at exact cents');
checkPayroll($c->calculate(['metric_value'=>-32600],$config,3197000)['amount_cents']===-9780,'Signed amount preserves reversal');
checkPayroll($c->calculate(['metric_value'=>6000],$config,2394700)['amount_cents']===1680,'010 current imported basis stays explicit');
checkPayroll($c->calculate(['metric_value'=>0],[],0)['amount_cents']===0,'Zero labor cannot gain commission');
foreach ([['0-10000'=>101],['0-10000'=>25,'9000-20000'=>26],['0-10000'=>25,'10002-20000'=>26],['1-10000'=>25],['0-10000'=>'bad']] as $bad) rejectedPayroll(function()use($c,$bad){$c->rate($bad,0);},'Invalid config rejected');
$r=new Resolver([33=>[4=>[4=>true]],31=>[4=>[2=>true]]]);
$rule=['id'=>-13,'position_id'=>4,'position_level_id'=>4,'status'=>1,'type'=>1,'range_type'=>2,'time_type'=>1,'has_recharge'=>1,'cate_ids'=>'','range'=>'0-9999999','commission'=>5];
$other=array_replace($rule,['id'=>-11,'position_level_id'=>2,'commission'=>3]);
$native=array_replace($rule,['id'=>1,'position_id'=>33,'position_level_id'=>0,'commission'=>7,'_native'=>true]);
checkPayroll($r->resolve(33,[$rule,$other])[0]['commission']===5,'Source grade maps by actual foreign keys');
checkPayroll($r->resolve(33,[$rule,$other,$native])[0]['commission']===7,'Native independent rule overrides source');
checkPayroll($r->resolve(31,[$rule,$other,$native])[0]['commission']===3,'Native edit cannot leak into another position');
rejectedPayroll(function()use($rule,$other){(new Resolver([33=>[4=>[2=>true,4=>true]]]))->resolve(33,[$rule,$other]);},'Ambiguous source mapping is not silently dropped');
$person=['legacy_position'=>4,'position_level'=>4,'job_segments'=>[['position_id'=>4,'start_time'=>1,'end_time'=>strtotime('2026-10-27 12:42:15 Asia/Shanghai')],['position_id'=>33,'start_time'=>strtotime('2026-10-27 12:42:15 Asia/Shanghai'),'end_time'=>0]]];
$resolved=$r->forJobs($person,['start'=>'2026-10-01','end'=>'2026-10-31'],[$rule,$other]);
checkPayroll(count($resolved)===1 && count($resolved[0]['_effective_days'])===31,'Equivalent mid-month conversion counted once');
echo json_encode(['result'=>'PASS','checks'=>$n,'persistent_writes'=>0],JSON_PRETTY_PRINT),"\n";
