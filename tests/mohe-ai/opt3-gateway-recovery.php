<?php
/** OPT3 isolated gateway regressions. The SQL facade below is synthetic;
 * actual instance/model and result reconciliation are separate live tests. */
namespace think\facade {
    final class Db {
        public static function table($table) { return new \Opt3Query(); }
    }
}
namespace {
require __DIR__.'/r6-gateway-harness.php';

final class Opt3Query extends R6Facts {
    public function buildSql($wrap=true) { return 'SELECT 1 member_id,498000 metric_value'; }
    public function count($column='*') { return 1; }
}
function opt3Property($object,string $name) {
    $p=new ReflectionProperty($object,$name); if (PHP_VERSION_ID<80100) $p->setAccessible(true); return $p;
}
function opt3Harness(): R6GatewayHarness {
    $h=new R6GatewayHarness(5,[1],'platform');
    opt3Property($h->gateway,'queryTransaction')->setValue($h->gateway,function($call)use($h) {
        $h->queries++;
        return $call(new \app\services\query\metric\GroupPerformanceMetricReadServices(
            static function(){return new Opt3Query();},static function(){}
        ));
    });
    return $h;
}
function opt3Query(R6GatewayHarness $h,array $run): array {
    r6hCheck(($run['status']??null)==='COMPLETED','expected completed query: '.($run['reason']??''));
    return $h->private->read($h->row($run)['evidence_ref'])['query'];
}

// A complete count owns all its conditions when the predecessor added no
// store/cohort restriction. No model call is needed to re-propose its meaning.
$h=opt3Harness();
try {
    $first=$h->start('今天现金业绩多少'); $before=$h->models;
    $count=$h->start('今年消费4980有多少人',$first['answer']['context_ref']);
    $query=opt3Query($h,$count);
    r6hCheck($h->models===$before,'independent closed count makes no model request');
    r6hCheck($query['query_shape']==='condition_count'
        && $query['condition_set']['subject']==='member'
        && $query['condition_set']['conditions'][0]['value']===498000,'member predicate is preserved exactly in cents');
    r6hCheck(($count['topic_boundary']??null)==='reset','confirmed independent count establishes its own topic');
    $next=$h->start('上个月呢',$count['answer']['context_ref']);
    $changed=opt3Query($h,$next);
    r6hCheck($changed['condition_set']===$query['condition_set']
        && $changed['start_date']!==$query['start_date'],'period follow-up retains the verified member predicate');
} finally {$h->close();}

// A single registered measurement edits the same verified ordinary query.
// Time and authority remain owned by the source; condition queries are excluded.
$h=opt3Harness();
try {
    $first=$h->start('昨天现金业绩多少');$source=opt3Query($h,$first);$before=$h->models;
    $next=$h->start('那实际业绩呢',$first['answer']['context_ref']);$query=opt3Query($h,$next);
    r6hCheck($query['metric_codes']===['actual_performance']
        && $query['start_date']===$source['start_date'] && $query['end_date']===$source['end_date']
        && $query['store_ids']===$source['store_ids'],'metric follow-up changes only its measurement');
    $phases=$h->db->query('SELECT attempt_code FROM mohe_ai_attempt WHERE run_id='.$h->db->quote($next['run_id']).' AND kind=\'model\'')->fetchAll(\PDO::FETCH_COLUMN);
    r6hCheck(!in_array('understand_meaning',$phases,true),'closed metric continuation reuses typed meaning');
} finally {$h->close();}

// A closed metric phrase may still name two distinct measurements. The
// shortcut must leave those requests to the ordinary multi-result contract;
// aliases of the same registry metric remain one measurement.
$gateway=new \app\services\ai\AiGatewayServices();
$localMetric=new ReflectionMethod($gateway,'localVerifiedMetricOnlyUnderstanding');
if (PHP_VERSION_ID<80100) $localMetric->setAccessible(true);
$caps=['metric_readiness'=>array_fill_keys(array_keys(\app\services\query\metric\MetricDefinitionRegistry::all()),true)];
foreach (['现金业绩和客户消耗金额呢','现金业绩和销售明细记录数呢'] as $question) {
    $outbound=['question'=>$question,'history'=>[],'prior_query'=>['metric_codes'=>['actual_performance']]];
    r6hCheck($localMetric->invoke($gateway,$outbound,[],['query_shape'=>'summary'],true,$caps)===null,
        'metric shortcut preserves every separately stated measurement');
}
$outbound=['question'=>'现金业绩和现金业绩呢','history'=>[],'prior_query'=>['metric_codes'=>['actual_performance']]];
$outbound['evidence_messages']=[['id'=>'current','text'=>$outbound['question']]];
r6hCheck($localMetric->invoke($gateway,$outbound,[],['query_shape'=>'summary'],true,$caps)!==null,
    'repeated evidence of the same metric remains a single measurement');

// A semantic failure and a transport failure are not proof of a new topic.
foreach (['continuation','new_topic','transport'] as $relation) {
    $h=opt3Harness();
    try {
        $first=$h->start('今天现金业绩多少');
        if ($relation==='transport') {
            opt3Property($h->gateway,'model')->setValue($h->gateway,static function(){
                throw new \app\services\ai\contract\AiContractException('AI_MODEL_RESULT_UNKNOWN',[
                    'stage'=>'transport','predicate'=>'timeout','transport_errno'=>28,'http_status'=>100,'elapsed_ms'=>30000,
                ]);
            });
        } else {
            $h->understandingOverride=['goal'=>'query meaning unresolved','status'=>'needs_clarification',
                'topic_relation'=>$relation,'requirements'=>[]];
        }
        $question=$relation==='new_topic'?'今天天气怎么样':'那另一项具体如何';
        $failed=$h->start($question,$first['answer']['context_ref']);
        r6hCheck(($failed['status']??null)==='FAILED','failure remains an honest terminal result');
        r6hCheck(($failed['topic_boundary']??null)===($relation==='new_topic'?'reset':null),
            'only an accepted new-topic relation resets context');
        r6hCheck(!isset($failed['failure_guidance']['options']),'platform failure has no fixed recovery menu');
        if ($relation==='transport') {
            $attempts=$h->db->query('SELECT state FROM mohe_ai_attempt WHERE run_id='.$h->db->quote($failed['run_id']).' AND kind=\'model\'')->fetchAll(\PDO::FETCH_COLUMN);
            r6hCheck($attempts===['UNKNOWN'],'HTTP100 timeout is not retried or falsified as a known result');
            r6hCheck(strpos($failed['message'],'AI 理解服务')!==false,'transport reason is conveyed without business recommendations');
        }
    } finally {$h->close();}
}

// Legacy drawer callers retain their existing action contract. Platform
// messages have typed reasons and carry no instruction to alter valid data.
$gateway=new \app\services\ai\AiGatewayServices();
$guidance=new ReflectionMethod($gateway,'failureGuidance');if (PHP_VERSION_ID<80100) $guidance->setAccessible(true);
foreach (['AI_MODEL_RESULT_UNKNOWN','AI_MODEL_CIRCUIT_OPEN','AI_CAPABILITY_CHANGED','RATE_LIMITED','DEADLINE_EXCEEDED'] as $reason) {
    $card=$guidance->invoke($gateway,$reason,true);
    r6hCheck(($card['kind']??null)==='technical_error' && !isset($card['options'])
        && strpos($card['question'],'现金业绩')===false,'typed platform failure '.$reason);
}
$legacy=$guidance->invoke($gateway,'AI_MODEL_RESULT_UNKNOWN');
r6hCheck(count($legacy['options'])===3,'separately approved drawer remains compatible');
echo "PASS OPT3 gateway recovery: member topic, metric delta, failure relation and transport audit (synthetic)\n";
}
