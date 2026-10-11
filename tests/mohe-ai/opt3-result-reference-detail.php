<?php
declare(strict_types=1);

/** Synthetic continuation regression: a displayed position selects its
 * original object, never a fresh one-object ranking. The actual Gateway
 * compiler is exercised without providers, credentials or business data. */
require __DIR__.'/fixture-autoload.php';

use app\services\ai\AiGatewayServices;
use app\services\ai\contract\AiIntentUnderstandingContract;

$gateway=(new ReflectionClass(AiGatewayServices::class))->newInstanceWithoutConstructor();
$compile=new ReflectionMethod($gateway,'compileObjectDetailContinuation');
if (PHP_VERSION_ID<80100) $compile->setAccessible(true);
$checks=0;$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void {
    $checks++;
    if (!$ok) $failures[]=$label;
};
$question='第二名呢';
$makeMeaning=static function(string $group='top')use($question):array {
    return AiIntentUnderstandingContract::normalize([
        'goal'=>'inspect one displayed result','status'=>'understood','topic_relation'=>'continuation',
        'requirements'=>[['id'=>'r1','meaning'=>'select the stated displayed position','fields'=>['result_reference'],
            'values'=>['result_reference'=>['group'=>$group,'ordinal'=>2]],
            'evidence'=>[['message_id'=>'current','quote'=>$question]]]]],
        ['question'=>$question,'evidence_messages'=>[['id'=>'current','text'=>$question]],
            'prior_query'=>['operation'=>'ranking']]);
};
$source=static function(string $kind):array {
    $query=['query_shape'=>'ranking','metric_codes'=>[$kind==='store'?'actual_performance':'staff_sales_yeji'],
        'start_date'=>'2026-10-01','end_date'=>'2026-10-11','compare_range'=>null,
        'ranking'=>['direction'=>'top_and_bottom','limit'=>3],'ranking_presentation_metrics'=>[],
        'store_ids'=>[11,12,13,21,22,23],'business_filters'=>['object_kind'=>$kind],'aggregate_condition'=>null];
    $rows=[];
    foreach (['top'=>[11,12,13],'bottom'=>[21,22,23]] as $group=>$ids) {
        foreach ($ids as $index=>$id) $rows[$group][]=$kind==='store'
            ?['store_id'=>$id,'store_name'=>'fixture store '.$id,'amount_cents'=>100000-$index*10000]
            :['employee_id'=>$id,'employee_name'=>'fixture person '.$id,'amount_cents'=>100000-$index*10000];
    }
    return ['query'=>$query,'view'=>['query'=>$query,'results'=>[['object_kind'=>$kind,'rows'=>$rows]]],
        'meaning'=>['presentation_origin'=>'customer_or_verified_context']];
};

foreach (['store','person'] as $kind) foreach (['top'=>12,'bottom'=>22] as $group=>$expectedId) {
    $context=$source($kind);$meaning=$makeMeaning($group);
    $compiled=$compile->invoke($gateway,$meaning,$context,'screen');
    $query=$compiled['plan']['query']??[];
    $check(($compiled['kind']??null)==='plan', $kind.' '.$group.' pure reference uses the existing detail compiler');
    $check(($query['query_shape']??null)===($kind==='store'?'breakdown':'summary')
        && array_key_exists('ranking',$query) && $query['ranking']===null,
        $kind.' '.$group.' displayed second object cannot become a newly ranked first object');
    $check($kind==='store'?($query['store_ids']??null)===[$expectedId]
        :($query['business_filters']['selection_ref']??null)==='person:'.$expectedId,
        $kind.' '.$group.' identity follows its original group and ordinal');
    $check(($query['start_date']??null)===$context['query']['start_date']
        && ($query['end_date']??null)===$context['query']['end_date']
        && in_array($context['query']['metric_codes'][0],(array)($query['metric_codes']??[]),true)
        && ($kind!=='person'||($query['store_ids']??null)===$context['query']['store_ids']),
        $kind.' '.$group.' detail keeps the verified period, metric and unrelated store scope');
}

// A selection plus a new business request belongs to ordinary binding. These
// structural cases prevent the recovery from swallowing explicit changes.
$context=$source('store');
foreach ([
    'new metric'=>['fields'=>['metric_codes'],'values'=>['metric_terms'=>['现金业绩']]],
    'new period'=>['fields'=>['periods'],'values'=>['periods'=>[['kind'=>'relative_days','days'=>1,'end_offset_days'=>0]]]],
    'new personnel ranking'=>['fields'=>['object_kind','operation','ranking'],
        'values'=>['object_kind'=>'person','operation'=>'ranking','ranking'=>['direction'=>'top','limit'=>3]]],
    'new ranking limit'=>['fields'=>['ranking'],'values'=>['ranking'=>['direction'=>'top','limit'=>5]]],
] as $label=>$extra) {
    $meaning=$makeMeaning();$meaning['requirements'][]=['id'=>'r2','meaning'=>$label,
        'evidence'=>[['message_id'=>'current','quote'=>$question]]]+$extra;
    $check($compile->invoke($gateway,$meaning,$context,'screen')===null,
        $label.' remains on ordinary binding instead of becoming detail');
}
$newTopic=$makeMeaning();$newTopic['topic_relation']='new_topic';
$check($compile->invoke($gateway,$newTopic,$context,'screen')===null,
    'explicit new topic never gains a previous ranked identity');

foreach ($failures as $failure) fwrite(STDERR,'FAIL '.$failure.PHP_EOL);
if ($failures!==[]) exit(1);
echo 'PASS OPT3 result-reference detail checks='.$checks.PHP_EOL;
