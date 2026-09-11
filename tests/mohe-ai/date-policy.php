<?php
/** Offline semantic/execution boundary regression; no model or business database. */
require_once __DIR__.'/fixture-autoload.php';
use app\services\query\metric\MetricQueryDatePolicy as Dates;
use app\services\query\metric\MetricQueryContractException;
use app\services\query\metric\MetricReadViewServices;
use app\services\ai\execution\AiWorkflowPlanner;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\ai\contract\AiIntentResultContract;

$checks=0;
function dateCheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);++$checks;}
function dateReject(callable $call,string $reason){try{$call();}catch(Throwable $e){dateCheck(($e instanceof MetricQueryContractException?$e->getErrorCode():$e->getMessage())===$reason,'expected '.$reason.', got '.$e->getMessage());return;}throw new RuntimeException('expected '.$reason);}
$planner=new AiWorkflowPlanner();$today='2028-09-12';
$cases=[
    [['kind'=>'relative_days','days'=>800,'end_offset_days'=>0],'2026-07-06','2028-09-12'],
    [['kind'=>'relative_days','days'=>1,'end_offset_days'=>-800],'2026-07-05','2026-07-05'],
    [['kind'=>'relative_days','days'=>1,'end_offset_days'=>1],'2028-09-13','2028-09-13'],
    [['kind'=>'month_offset','offset_months'=>-36],'2025-09-01','2025-09-30'],
    [['kind'=>'month_offset','offset_months'=>1],'2028-10-01','2028-10-31'],
    [['kind'=>'month_offset','offset_months'=>0],'2028-09-01','2028-09-12'],
];
$client=new ReflectionClass(\app\services\ai\model\SiliconFlowClient::class);
$valid=$client->getMethod('validPeriods');if(PHP_VERSION_ID<80100)$valid->setAccessible(true);$instance=$client->newInstanceWithoutConstructor();
foreach($cases as [$term,$start,$end]){
    dateCheck(AiIntentResultContract::periods([$term]),'intent preserves semantic date');
    dateCheck($valid->invoke($instance,[$term]),'client prior context uses same semantic contract');
    dateCheck($planner->normalizeNaturalPeriod($term,$today)===['start'=>$start,'end'=>$end],'semantic resolution without execution clipping');
}
dateReject(function()use($planner,$today){$planner->normalizeNaturalPeriod(['kind'=>'relative_days','days'=>PHP_INT_MAX,'end_offset_days'=>0],$today);},'AI_DATE_INVALID');
$range=['start'=>'2027-09-13','end'=>'2028-09-12'];
Dates::assertExecutable($range,'2026-08-10',$today);dateCheck(true,'inclusive 366 passes');
$projection=new \app\services\query\metric\MetricGroupedProjection();
dateCheck(count($projection->trend([],$range,$today))===Dates::MAX_DAYS,'trend emits exactly 366 inclusive points');
dateReject(function()use($projection,$today){$projection->trend([],['start'=>'2027-09-12','end'=>$today],$today);},'METRIC_QUERY_RANGE_TOO_LONG');
$failures=[
    [['start'=>'2027-09-12','end'=>$today],'METRIC_QUERY_RANGE_TOO_LONG'],
    [['start'=>'2028-09-13','end'=>'2028-09-13'],'METRIC_QUERY_FUTURE_UNAVAILABLE'],
    [['start'=>'2026-08-09','end'=>'2026-08-09'],'METRIC_QUERY_COVERAGE_UNAVAILABLE'],
    [['start'=>'2028-09-12','end'=>'2028-09-11'],'METRIC_QUERY_RANGE_REVERSED'],
    [['start'=>'2028-02-30','end'=>'2028-03-01'],'METRIC_QUERY_RANGE_INVALID'],
];
$cap=['metric_codes'=>['cash_performance'],'query_shapes'=>['summary','trend','ranking','comparison'],'output_formats'=>['screen'],'metric_readiness'=>MetricReadViewServices::metricCapabilities(),'definition_metric_codes'=>[],'metadata_readiness'=>[]];
$compiler=new AiRegisteredPlanCompiler(null,static function()use($today){return $today;});
$query=['query_shape'=>'summary','metric_codes'=>['cash_performance'],'start_date'=>$range['start'],'end_date'=>$range['end'],'compare_range'=>null,'store_ids'=>[],'business_filters'=>[],'ranking'=>null];
$compiler->compile(['query'=>$query,'output_format'=>'screen'],$cap);dateCheck(true,'compiler allows inclusive 366');
foreach($failures as [$bad,$reason]){
    dateReject(function()use($bad,$today){Dates::assertExecutable($bad,'2026-08-10',$today);},$reason);
    foreach(['summary','comparison'] as $shape){
        $q=$query;$q['query_shape']=$shape;
        if($shape==='comparison')$q['compare_range']=$bad;else{$q['start_date']=$bad['start'];$q['end_date']=$bad['end'];}
        dateReject(function()use($q,$compiler,$cap){$compiler->compile(['query'=>$q,'output_format'=>'screen'],$cap);},Dates::aiReason($reason));
    }
}
// Validate read-view boundary without allowing any DB/storage/authorization side effect.
$viewClass=new ReflectionClass(MetricReadViewServices::class);$view=$viewClass->newInstanceWithoutConstructor();
$clock=$viewClass->getProperty('clock');if(PHP_VERSION_ID<80100)$clock->setAccessible(true);$clock->setValue($view,static function()use($today){return Dates::date($today)->getTimestamp();});
$validate=$viewClass->getMethod('range');if(PHP_VERSION_ID<80100)$validate->setAccessible(true);
$validate->invoke($view,$range);dateCheck(true,'read view allows inclusive 366');
foreach($failures as [$bad,$reason])dateReject(function()use($validate,$view,$bad){$validate->invoke($view,$bad);},$reason);
$outcome=new ReflectionMethod(\app\services\ai\execution\AiRunStore::class,'outcomeClass');if(PHP_VERSION_ID<80100)$outcome->setAccessible(true);
foreach($failures as [$bad,$reason])foreach([$reason,Dates::aiReason($reason)] as $code)dateCheck($outcome->invoke(null,['status'=>'FAILED','reason'=>$code])==='neutral','date capability refusal never user/model technical failure');
dateCheck(strpos(AiIntentResultContract::modelInstruction(false),'integer-from--365-to-0')===false,'prompt has no execution offset restriction');
echo 'PASS date-policy: '.$checks." checks (offline)\n";
