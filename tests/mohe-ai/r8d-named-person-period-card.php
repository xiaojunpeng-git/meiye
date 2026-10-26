<?php
// The natural-language admission and signed planners must never invent a
// person, metric or period when only an employee name is supplied.
require_once __DIR__.'/fixture-autoload.php';

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};
$entries=\app\services\query\metric\MetricSemanticCatalog::entries();
$people=[['ref'=>'person:714','kind'=>'person','label'=>'管慧','aliases'=>[]],
    ['ref'=>'person:922','kind'=>'person','label'=>'同名员工（甲店）','aliases'=>['同名员工']],
    ['ref'=>'person:923','kind'=>'person','label'=>'同名员工（乙店）','aliases'=>['同名员工']]];
$admission=new \app\services\ai\semantic\AiDeterministicSummaryAdmission();
$matched=$admission->matchNamedPerson('管慧的业绩是多少？',$entries,$people);
$check(($matched['metric_codes']??[])===['staff_sales_yeji']
    && array_key_exists('date_term',$matched) && $matched['date_term']===null,
    'named employee metric and missing date are explicit');
$plan=['kind'=>'plan','plan'=>['workflow_code'=>'wf_performance_summary','query'=>[
    'query_shape'=>'summary','metric_codes'=>$matched['metric_codes'],'start_date'=>'2026-10-16',
    'end_date'=>'2026-10-16','compare_range'=>null,'store_ids'=>[],
    'business_filters'=>['object_kind'=>'person','selection_ref'=>$matched['objects'][0]['ref']],
    'ranking'=>null,'aggregate_condition'=>null], 'output_format'=>'screen']];
$period=new \app\services\ai\execution\AiSummaryPeriodGuidancePlanner();
$card=$period->start($plan,'2026-10-16',[['label'=>'员工','value'=>'管慧']]);
$check(array_column($card['fields'][0]['options'],'value')===['TODAY','YESTERDAY','THIS_MONTH','LAST_MONTH','CUSTOM'],
    'period card exposes quick periods and custom dates');
$last=$period->choose($card,['summary_period'=>'LAST_MONTH']);
$check(($last['plan']['query']['start_date']??null)==='2026-09-01'
    && ($last['plan']['query']['end_date']??null)==='2026-09-30'
    && ($last['plan']['query']['business_filters']['selection_ref']??null)==='person:714',
    'month choice changes only dates');
$capabilities=\app\services\ai\execution\AiAuthority::capabilities(false,[
    'scope_mode'=>'stores','store_ids'=>[1],'analysis_personnel_ready'=>true,
]);
$registered=new \app\services\ai\execution\AiRegisteredPlanCompiler();
$compiled=$registered->compile($last['plan'],$capabilities);
$registered->assertCompiled($compiled);
$check(($compiled['query']['metric_codes']??[])===['staff_sales_yeji'],
    'person summary enters the same registered compiler as model plans');
$custom=$period->choose($card,['summary_period'=>'CUSTOM']);
$check(array_column($custom['fields'],'key')===['start_date','end_date'],
    'custom option delegates to validated date fields');
$invalid=false;
try {$period->choose($card,['summary_period'=>'ALL_TIME']);}catch(RuntimeException $e){$invalid=$e->getMessage()==='AI_CLARIFICATION_INVALID';}
$check($invalid,'unlisted period cannot be forged');

$duplicate=$admission->matchNamedPerson('同名员工的业绩是多少？',$entries,$people);
$selection=new \app\services\ai\execution\AiNamedPersonGuidancePlanner();
$pending=$selection->start($period->start($plan,'2026-10-16',[]),$duplicate['objects']);
$check(count($pending['fields'][0]['options'])===2,'duplicate names require selection');
$chosen=$selection->choose($pending,['named_person'=>'person:923']);
$check(($chosen['summary_period_state']['base']['plan']['query']['business_filters']['selection_ref']??null)==='person:923',
    'person choice binds exact selected employee before time choice');
$invalid=false;
try {$selection->choose($pending,['named_person'=>'person:924']);}catch(RuntimeException $e){$invalid=$e->getMessage()==='AI_CLARIFICATION_INVALID';}
$check($invalid,'person outside signed card cannot be selected');

echo 'R8D named person period card: '.$checks.' checks PASS'.PHP_EOL;
