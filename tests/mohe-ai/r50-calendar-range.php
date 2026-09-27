<?php
/** R50: complete calendar evidence and ranking admission; no database/model calls. */
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\semantic\AiSemanticIntentParser;
use app\services\ai\semantic\AiExactRankingCollectionAdmission;
use app\services\ai\execution\AiWorkflowPlanner;

$planner=new AiWorkflowPlanner();$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
foreach ([
    ['2026年3月到今天，最高营业额是哪个门店','2026-03-01','2026-09-27'],
    ['从今年三月一直到今天营业额最高是哪个门店','2026-03-01','2026-09-27'],
    ['2026年3月份至今营业额最高是哪个门店','2026-03-01','2026-09-27'],
    ['去年十二月到今天营业额最高是哪个门店','2025-12-01','2026-09-27'],
    ['2025年3月到5月现金业绩','2025-03-01','2025-05-31'],
    ['2026-03-02至2026-09-20现金业绩','2026-03-02','2026-09-20'],
    ['今年二月现金业绩','2026-02-01','2026-02-28'],
    ['昨天到今天现金业绩','2026-09-26','2026-09-27'],
] as [$question,$start,$end]) {
    $evidence=AiSemanticIntentParser::calendarEvidence($question);
    $check($evidence['complete'] && count($evidence['periods'])===1,'complete interval: '.$question);
    $check($planner->normalizePeriod($evidence['periods'][0],'2026-09-27')===['start'=>$start,'end'=>$end],'resolved interval: '.$question);
}
foreach (['春节到今天营业额','开业至今天营业额','三月初到今天营业额','今年以来今天营业额','2026年第一季度到今天营业额'] as $question) {
    $check(!AiSemanticIntentParser::calendarEvidence($question)['complete'],'partial date must not override model: '.$question);
}
$admission=new AiExactRankingCollectionAdmission();
foreach (['store'=>'门店','member'=>'会员','staff'=>'员工','project'=>'项目','product'=>'产品'] as $kind=>$label) {
    $metric=in_array($kind,['project','product'],true)?'sales_amount':'cash_performance';
    $term=$metric==='cash_performance'?'营业额':'销售额';
    $result=$admission->match('2026年3月到今天，最高'.$term.'是哪个'.$label,[['object_kind'=>$kind,'object_label'=>$label]],[$metric]);
    $check(($result[0]['metric_code']??null)===$metric && ($result[0]['object_kind']??null)===$kind,'generic registered ranking: '.$label);
}
$check($admission->match('2026年3月到今天，扣除退款的营业额最高是哪个门店',[['object_kind'=>'store','object_label'=>'门店']],['cash_performance'])===null,'refund modifier must retain semantic interpretation');
// Exercise the exact gateway correction that previously replaced a correct
// model interval with TODAY; partial evidence must leave that result untouched.
$gateway=(new ReflectionClass(app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$method=new ReflectionMethod($gateway,'resolveExactStatedSinglePeriod');
if (PHP_VERSION_ID<80100) $method->setAccessible(true);
$understanding=['status'=>'understood','requirements'=>[['fields'=>['periods'],'values'=>['periods'=>[['kind'=>'date_range','start'=>'2026-03-01','end'=>'2026-09-27']]]]]];
$corrected=$method->invoke($gateway,$understanding,['question'=>'2026年3月到今天，最高营业额是哪个门店'],'2026-09-27');
$check($corrected['requirements'][0]['values']['periods']===$understanding['requirements'][0]['values']['periods'],'gateway preserves complete March-to-today interval');
$check($method->invoke($gateway,$understanding,['question'=>'春节到今天营业额'],'2026-09-27')===$understanding,'gateway cannot override an unknown starting boundary');
foreach (['2026年2月30日到今天','2026年13月到今天','2026年9月到2026年3月'] as $question) {
    $rejected=false;
    try {$planner->normalizePeriod(AiSemanticIntentParser::calendarEvidence($question)['periods'][0],'2026-09-27');}
    catch (app\services\ai\contract\AiContractException $error) {$rejected=true;}
    $check($rejected,'invalid or reversed interval cannot roll over: '.$question);
}
echo "R50 calendar range: {$checks} checks PASS\n";
