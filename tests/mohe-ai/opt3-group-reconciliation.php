<?php
declare(strict_types=1);

/** Exercise accepted semantic group graphs through the actual reconciliation
 * and normalization contracts. Facts and external model calls are not used. */
require __DIR__.'/r6-gateway-harness.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiIntentUnderstandingContract;

function opt3GroupedRequirement(string $id,array $fields,array $values,string $question): array
{
    return ['id'=>$id,'meaning'=>'synthetic accepted business meaning','fields'=>$fields,'values'=>$values,
        'evidence'=>[['message_id'=>'current','quote'=>$question]]];
}
function opt3GroupedMeaning(string $question,array $requirements,array $groups): array
{
    $safe=['question'=>$question,'evidence_messages'=>[['id'=>'current','text'=>$question]],
        'prior_query'=>['object_kind'=>'store']];
    return [AiIntentUnderstandingContract::normalize(['goal'=>'independent result groups','status'=>'understood',
        'topic_relation'=>'new_topic','requirements'=>$requirements,'groups'=>$groups],$safe),$safe];
}
function opt3GroupsReject(callable $call,string $label): void
{
    try {$call();} catch (AiContractException $error) {
        r6hCheck($error->getMessage()==='AI_MODEL_INTENT_CONTRACT_INVALID'
            && ($error->diagnostic()['predicate']??null)==='groups',$label);return;
    }
    throw new RuntimeException('FAIL '.$label.' was not rejected');
}

$h=new R6GatewayHarness(5,[1],'platform');
try {
    $exact=new ReflectionMethod($h->gateway,'reconcileExactRegisteredMeasurement');
    $stated=new ReflectionMethod($h->gateway,'reconcileStatedRegisteredMeasurements');
    $closed=new ReflectionMethod($h->gateway,'reconcileClosedMetricOnlyUnderstanding');
    $object=new ReflectionMethod($h->gateway,'reconcileExactRegisteredAnalyticalObject');
    if (PHP_VERSION_ID<80100) foreach ([$exact,$stated,$closed,$object] as $method) $method->setAccessible(true);
    $q='本月门店和员工的现金业绩';
    $requirements=[
        opt3GroupedRequirement('r1',['metric_codes'],['metric_terms'=>['现金业绩']],$q),
        opt3GroupedRequirement('r2',['metric_codes'],['metric_terms'=>['现金业绩']],$q),
        opt3GroupedRequirement('r3',['object_kind','operation'],['object_kind'=>'store','operation'=>'breakdown'],$q),
        opt3GroupedRequirement('r4',['object_kind','operation'],['object_kind'=>'person','operation'=>'breakdown'],$q),
    ];
    $summaries=[['metric_code'=>'cash_performance','object_contracts'=>[['object_kind'=>'store'],['object_kind'=>'person']]]];
    [$original,$safe]=opt3GroupedMeaning($q,$requirements,[
        ['id'=>'q1','requirement_ids'=>['r1','r3']],['id'=>'q2','requirement_ids'=>['r2','r4']],
    ]);
    $result=$exact->invoke($h->gateway,$original,$safe,$summaries,[]);
    r6hCheck($result===$original,'same metric in independent groups retains both measurement owners');

    [$shared,$safe]=opt3GroupedMeaning($q,$requirements,[
        ['id'=>'q1','requirement_ids'=>['r1','r2','r3']],['id'=>'q2','requirement_ids'=>['r1','r2','r4']],
    ]);
    $result=$exact->invoke($h->gateway,$shared,$safe,$summaries,[]);
    r6hCheck(array_column($result['requirements'],'id')===['r1','r3','r4']
        && $result['groups']===[['id'=>'q1','requirement_ids'=>['r1','r3']],['id'=>'q2','requirement_ids'=>['r1','r4']]],
        'duplicate metric with identical group ownership is safely merged without stale IDs');
    r6hCheck(($result['topic_relation']??null)==='new_topic','group cleanup preserves accepted new-topic metadata');
    AiIntentUnderstandingContract::normalize($result,$safe);
    r6hCheck($closed->invoke($h->gateway,$result,true,['query_shape'=>'summary'])===$result,
        'single-metric cleanup preserves independent groups and their nonmetric carriers');

    [$missing,$safe]=opt3GroupedMeaning($q,array_slice($requirements,2),[
        ['id'=>'q1','requirement_ids'=>['r3']],['id'=>'q2','requirement_ids'=>['r4']],
    ]);
    opt3GroupsReject(function()use($exact,$h,$missing,$safe,$summaries){$exact->invoke($h->gateway,$missing,$safe,$summaries,[]);},
        'omitted metric without a proven group owner cannot be globally injected');

    $q='本月门店现金业绩和实际业绩';
    $requirements=[
        opt3GroupedRequirement('r1',['metric_codes'],['metric_terms'=>['现金业绩']],$q),
        opt3GroupedRequirement('r2',['metric_codes'],['metric_terms'=>['实际业绩']],$q),
        opt3GroupedRequirement('r3',['operation'],['operation'=>'summary'],$q),
        opt3GroupedRequirement('r4',['metric_codes'],['metric_terms'=>['业绩']],$q),
    ];
    $caps=['metric_readiness'=>['cash_performance'=>[],'actual_performance'=>[]]];
    [$original,$safe]=opt3GroupedMeaning($q,$requirements,[
        ['id'=>'q1','requirement_ids'=>['r1','r3','r4']],['id'=>'q2','requirement_ids'=>['r2','r3','r4']],
    ]);
    $result=$stated->invoke($h->gateway,$original,$safe,$caps);
    r6hCheck(array_column($result['requirements'],'id')===['r1','r2','r3']
        && $result['groups']===[['id'=>'q1','requirement_ids'=>['r1','r3']],['id'=>'q2','requirement_ids'=>['r2','r3']]],
        'redundant umbrella requirement is removed while both metric groups remain valid');
    r6hCheck(($result['topic_relation']??null)==='new_topic','stated-metric cleanup preserves topic metadata');
    AiIntentUnderstandingContract::normalize($result,$safe);

    [$independentUnknown,$safe]=opt3GroupedMeaning($q,$requirements,[
        ['id'=>'q1','requirement_ids'=>['r4','r3']],['id'=>'q2','requirement_ids'=>['r1','r2','r3']],
    ]);
    r6hCheck($stated->invoke($h->gateway,$independentUnknown,$safe,$caps)===$independentUnknown,
        'cleanup cannot erase the only metric carrier of an independent group');

    $missingMetric=[$requirements[0],$requirements[2]];
    [$missing,$safe]=opt3GroupedMeaning($q,$missingMetric,[
        ['id'=>'q1','requirement_ids'=>['r1','r3']],['id'=>'q2','requirement_ids'=>['r3']],
    ]);
    opt3GroupsReject(function()use($stated,$h,$missing,$safe,$caps){$stated->invoke($h->gateway,$missing,$safe,$caps);},
        'newly discovered metric without group ownership fails explicitly instead of disappearing');
    opt3GroupsReject(function()use($object,$h,$original,$safe){
        $object->invoke($h->gateway,$original,$safe,[['object_kind'=>'store','object_label'=>'门店']]);
    },'omitted analytical object cannot be assigned to independent groups without ownership');
} finally {$h->close();}

echo "PASS OPT3 grouped reconciliation: independent ownership, shared cleanup, topic metadata and missing-metric fences\n";
