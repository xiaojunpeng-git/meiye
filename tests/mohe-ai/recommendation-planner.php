<?php
/** Real registry/compiler dry-runs: no environment, model or fact reads. Tests
 * preserve the exact semantic constraints rather than mirroring implementation. */
require_once __DIR__.'/fixture-autoload.php';
use app\services\ai\execution\AiSemanticRecommendationPlanner;
use app\services\ai\execution\AiRegisteredPlanCompiler;
use app\services\query\metric\MetricReadViewServices;
$checks=0;
$check=static function(bool $condition,string $label)use(&$checks):void{if(!$condition)throw new RuntimeException('FAIL '.$label);++$checks;};
$readiness=MetricReadViewServices::metricCapabilities();
$caps=['metric_codes'=>array_keys($readiness),'metric_readiness'=>$readiness,'store_ids'=>[80],
    'query_shapes'=>['summary','breakdown','trend','ranking','comparison','condition_count','condition_list','threshold_count'],
    'output_formats'=>['screen','screen_and_xlsx'],'current_store_bound'=>true];
$compiler=new AiRegisteredPlanCompiler(null,static function():string{return '2026-10-02';});$preflights=0;
$preflight=static function(array $plan)use($compiler,$caps,&$preflights):void{$compiler->compile($plan,$caps);++$preflights;};
$planner=new AiSemanticRecommendationPlanner();
$meaning=['status'=>'needs_clarification','requirements'=>[['fields'=>['object_kind','periods'],
    'values'=>['object_kind'=>'member','periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30']]]]]];
$pool=$planner->candidates($meaning,$caps,'2026-10-02','screen',$preflight,['member'=>'会员','store'=>'门店']);
$check(count($pool)>=3 && $preflights===count($pool),'at least three actual member plans compile before selection');
foreach ($pool as $candidate) {
    $query=$candidate['compiled']['plan']['query'];
    $check($query['business_filters']['object_kind']==='member','member subject never becomes store');
    $check([$query['start_date'],$query['end_date']]===['2026-09-01','2026-09-30'],'complete original period remains');
    $check(!in_array('recharge_amount',$query['metric_codes'],true),'store-only recharge capability cannot be recommended for members');
    $check(!in_array('member_remaining_project_amount',$query['metric_codes'],true),'current-state rights cannot replace a dated flow');
}
$ids=array_slice(array_keys($pool),0,3);$check(count($planner->select($pool,$ids))===3,'three distinct server-owned selections');
// Signed context is structural, not a phrase matcher. A continuation keeps
// the complete date and private store restriction; a topic reset keeps none.
$source=reset($pool)['compiled']['plan']['query'];$source['store_ids']=[80];
$continuation=['status'=>'needs_clarification','topic_relation'=>'continuation','requirements'=>[]];
$inherited=$planner->candidates($continuation,$caps,'2026-10-02','screen',$preflight,['member'=>'会员','store'=>'门店'],$source);
$check(count($inherited)>0,'a signed continuation can have executable recommendations without inventing extra shapes');
foreach($inherited as $candidate) {
    $query=$candidate['compiled']['plan']['query'];
    $check($query['store_ids']===[80] && $query['business_filters']===$source['business_filters']
        && $query['start_date']===$source['start_date'] && $query['end_date']===$source['end_date'],
        'signed scope, identity filters and complete period stay on every candidate');
}
$continuation['topic_relation']='new_topic';
$reset=$planner->candidates($continuation,$caps,'2026-10-02','screen',$preflight,['member'=>'会员','store'=>'门店'],$source);
foreach($reset as $candidate)$check($candidate['compiled']['plan']['query']['store_ids']===[], 'new topic never inherits signed store selection');
unset($continuation['topic_relation']);
$check($planner->candidates($continuation,$caps,'2026-10-02','screen',$preflight,['member'=>'会员','store'=>'门店'],$source)===[],
    'missing topic decision cannot silently remove a signed scope');
foreach ([$ids[0],[$ids[0],$ids[0],$ids[2]],[$ids[0],$ids[1],'made_up'],[$ids[0],$ids[1],[]]] as $invalid) {
    try{$planner->select($pool,$invalid);throw new RuntimeException('unexpected acceptance');}
    catch(RuntimeException $error){$check($error->getMessage()==='AI_RECOMMENDATION_INVALID','reject unknown, duplicate or injected selection');}
}
$locked=$meaning;$locked['requirements'][0]['fields'][]='metric_codes';$locked['requirements'][0]['values']['metric_terms']=['现金业绩'];
$exact=$planner->candidates($locked,$caps,'2026-10-02','screen',$preflight,['member'=>'会员']);
foreach($exact as $candidate)$check($candidate['compiled']['plan']['query']['metric_codes']===['cash_performance'],'clear registered metric stays locked');
foreach (['store_term','unbound','aggregate_condition','scope','result_reference'] as $field) {
    $restricted=$meaning;$restricted['requirements'][0]['fields'][]=$field;$restricted['requirements'][0]['values'][$field]='locked';
    $check($planner->candidates($restricted,$caps,'2026-10-02','screen',$preflight,['member'=>'会员'])===[],'unsupported '.$field.' never disappears');
}
foreach (['object_term'=>'ref_private','object_relation'=>'selection'] as $key=>$value) {
    $restricted=$meaning;$restricted['requirements'][0]['values'][$key]=$value;
    $check($planner->candidates($restricted,$caps,'2026-10-02','screen',$preflight,['member'=>'会员'])===[],
        'a private selected object never becomes an unfiltered member population');
}
// The customer may state a rank without repeating the response form. Every
// offered plan must keep the exact ordinal, including after a breakdown.
$rankMeaning=['status'=>'needs_clarification','requirements'=>[['fields'=>['object_kind','ranking','periods'],
    'values'=>['object_kind'=>'store','ranking'=>['direction'=>'top','limit'=>5],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30']]]]]];
$rankPool=$planner->candidates($rankMeaning,$caps,'2026-10-02','screen',$preflight,['store'=>'门店']);
$check(count($rankPool)>=3,'explicit ordinal has three executable alternatives');
foreach ($rankPool as $candidate) {
    $query=$candidate['compiled']['plan']['query'];
    $check($query['query_shape']==='ranking' && $query['ranking']===['direction'=>'top','limit'=>5],
        'every candidate preserves explicit top five');
}
$sourceMeaning=['status'=>'needs_clarification','requirements'=>[['fields'=>['object_kind','operation','periods','metric_codes'],
    'values'=>['object_kind'=>'store','operation'=>'breakdown','metric_terms'=>['现金业绩'],
        'periods'=>[['kind'=>'date_range','start'=>'2026-09-01','end'=>'2026-09-30']]]]]];
$sourcePool=$planner->candidates($sourceMeaning,$caps,'2026-10-02','screen',$preflight,['store'=>'门店']);
$check(count($sourcePool)>0,'dated cash breakdown compiles as signed predecessor');
$sourceQuery=reset($sourcePool)['compiled']['plan']['query'];$sourceQuery['store_ids']=[80];
$rankContinuation=['status'=>'needs_clarification','topic_relation'=>'continuation','requirements'=>[[
    'fields'=>['ranking'],'values'=>['ranking'=>['direction'=>'bottom','limit'=>5]]]]];
$continued=$planner->candidates($rankContinuation,$caps,'2026-10-02','screen',$preflight,['store'=>'门店'],$sourceQuery);
$check(count($continued)>=3,'explicit rank can replace an inherited breakdown with three alternatives');
foreach ($continued as $candidate) {
    $query=$candidate['compiled']['plan']['query'];
    $check($query['query_shape']==='ranking' && $query['ranking']===['direction'=>'bottom','limit'=>5]
        && $query['store_ids']===[80] && $query['start_date']==='2026-09-01' && $query['end_date']==='2026-09-30',
        'rank continuation retains signed scope and date, not breakdown shape');
}
$rankSource=reset($rankPool)['compiled']['plan']['query'];$rankSource['store_ids']=[80];
$breakdownContinuation=['status'=>'needs_clarification','topic_relation'=>'continuation','requirements'=>[[
    'fields'=>['operation'],'values'=>['operation'=>'breakdown']]]];
$brokenDown=$planner->candidates($breakdownContinuation,$caps,'2026-10-02','screen',$preflight,['store'=>'门店'],$rankSource);
$check(count($brokenDown)>0,'a signed ranking can become an executable store breakdown');
foreach ($brokenDown as $candidate) {
    $query=$candidate['compiled']['plan']['query'];
    $check($query['query_shape']==='breakdown' && $query['ranking']===null
        && $query['business_filters']===['object_kind'=>'store'] && $query['store_ids']===[80]
        && $query['start_date']==='2026-09-01' && $query['end_date']==='2026-09-30',
        'store breakdown changes only the registered dimension carrier');
}
$contradiction=$rankMeaning;$contradiction['requirements'][0]['values']['operation']='breakdown';
$check($planner->candidates($contradiction,$caps,'2026-10-02','screen',$preflight,['store'=>'门店'])===[],
    'conflicting explicit response form and ordinal cannot be offered');
echo 'Recommendation planner: '.$checks." checks PASS (real registry/compiler, no queries)\n";
