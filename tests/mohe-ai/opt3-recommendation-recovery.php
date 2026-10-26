<?php
/** OPT3 optional recommendation transport regression. Only the cURL boundary
 * is synthetic: the real Gateway, client, compiler and SQLite Run store run.
 * No request reaches an endpoint and the fixture contains no customer data. */
namespace app\services\ai\model {
    function curl_init($endpoint) { return new \stdClass(); }
    function curl_setopt_array($handle,array $options) { $handle->options=$options; return true; }
    function curl_exec($handle) {
        $test=&$GLOBALS['opt3RecommendationTransport'];
        ++$test['calls'];
        $test['timeouts'][]=$handle->options[CURLOPT_TIMEOUT_MS];
        if (($test['mode']??'')==='unknown' || ($test['latency_ms']??0)>$handle->options[CURLOPT_TIMEOUT_MS]) {
            $handle->status=0;$handle->errno=28;return false;
        }
        if (isset($test['http_status'])) {$handle->status=$test['http_status'];$handle->errno=0;return true;}
        $handle->status=200;$handle->errno=0;
        // Candidate identifiers are taken from the server-preflighted pool;
        // this deterministic fixture order is not product recommendation policy.
        $payload=json_decode($handle->options[CURLOPT_POSTFIELDS],true);
        $input=json_decode($payload['messages'][count($payload['messages'])-1]['content'],true);
        $ids=($test['mode']??'')==='invalid'?['not_a_registered_candidate']:
            array_slice(array_column($input['candidates'],'id'),0,3);
        $body=json_encode(['choices'=>[['finish_reason'=>'stop','message'=>[
            'content'=>json_encode(['selections'=>$ids])]]],
            'usage'=>['prompt_tokens'=>20,'completion_tokens'=>10]]);
        $write=$handle->options[CURLOPT_WRITEFUNCTION];$write($handle,$body);
        return true;
    }
    function curl_getinfo($handle,$key) { return $handle->status; }
    function curl_errno($handle) { return $handle->errno; }
    function curl_close($handle) {}
}
namespace {
require __DIR__.'/r6-gateway-harness.php';

$checks=[];$observations=[];
function recommendationCheck(bool $ok,string $label): void {
    $GLOBALS['checks'][]=['pass'=>$ok,'check'=>$label];
}
function recommendationProperty($object,string $name): \ReflectionProperty {
    $property=new \ReflectionProperty($object,$name);
    if (PHP_VERSION_ID<80100) $property->setAccessible(true);
    return $property;
}
/** The one understanding fixture proves only a missing goal on the stated
 * store object. All candidate discovery and preflight remain production code. */
function recommendationHarness(?int $remainingMs=null,?string $failure=null): R6GatewayHarness {
    $h=new R6GatewayHarness(3,[1],'platform');
    $property=recommendationProperty($h->gateway,'model');
    $property->setValue($h->gateway,static function($view,$candidates,$configuration,$checkpoint,$repair=null,$phase='binding') use($h,$property,$remainingMs,$failure) {
        ++$h->models;$checkpoint();
        if ($phase==='understanding') {
            if ($remainingMs!==null) {
                // Shorten only this in-memory fixture Run's live deadline;
                // the frozen total profile is never enlarged or replaced.
                $deadline=(int)floor(microtime(true)*1000)+$remainingMs;
                $h->db->exec('UPDATE mohe_ai_run SET deadline_at='.$deadline);
            }
            if ($failure===null) $property->setValue($h->gateway,null);
            return ['understanding'=>['goal'=>'选择已登记的门店查询内容','status'=>'needs_clarification',
                'requirements'=>[['id'=>'r1','meaning'=>'门店对象已明确，查询内容待补充',
                    'fields'=>['object_kind'],'values'=>['object_kind'=>'store'],
                    'evidence'=>[['message_id'=>'current','quote'=>$view['question']]]]]],
                'usage'=>['input_tokens'=>20,'output_tokens'=>10]];
        }
        if ($phase!=='recommendations' || $failure===null) throw new \RuntimeException('FIXTURE_UNEXPECTED_MODEL_PHASE');
        throw new \app\services\ai\contract\AiContractException($failure);
    });
    return $h;
}

// A provider that needs 14 seconds used to hit the recommendation-only 10s
// limit. Simulated latency chooses the transport outcome without wall waits.
foreach ([['name'=>'ordinary','remaining_ms'=>null,'mode'=>'success','latency_ms'=>14267],
          ['name'=>'short_remaining','remaining_ms'=>6500,'mode'=>'success','latency_ms'=>0],
          ['name'=>'unknown','remaining_ms'=>null,'mode'=>'unknown','latency_ms'=>0],
          ['name'=>'account_permission','remaining_ms'=>null,'mode'=>'http','http_status'=>401],
          ['name'=>'provider_failure','remaining_ms'=>null,'mode'=>'http','http_status'=>503],
          ['name'=>'invalid_selection','remaining_ms'=>null,'mode'=>'invalid','latency_ms'=>0]] as $case) {
    $GLOBALS['opt3RecommendationTransport']=$case+['calls'=>0,'timeouts'=>[]];
    $h=recommendationHarness($case['remaining_ms']);
    try {
        $run=$h->start('门店怎么样');$transport=$GLOBALS['opt3RecommendationTransport'];
        $counts=json_decode($h->row($run)['counters_json'],true);
        $attempt=$h->db->query("SELECT state,input_tokens,output_tokens FROM mohe_ai_attempt WHERE attempt_code='select_recommendations'")->fetch(\PDO::FETCH_ASSOC);
        $observations[]=['case'=>$case['name'],'timeout_ms'=>$transport['timeouts'],
            'transport_calls'=>$transport['calls'],'status'=>$run['status']??null,'reason'=>$run['reason']??null,
            'attempt'=>$attempt,'diagnostic'=>$counts['model_diagnostics']['select_recommendations']??null];
        recommendationCheck($transport['calls']===1 && $h->models===1,$case['name'].' makes one understanding and one recommendation, without retry');
        recommendationCheck($h->queries===0,$case['name'].' does not read business facts before clarification');
        if ($case['mode']==='http') {
            $expected=$case['http_status']===401?'AI_MODEL_ACCOUNT_UNAVAILABLE':'AI_MODEL_REQUEST_FAILED';
            recommendationCheck(($run['status']??null)==='FAILED' && ($run['reason']??null)===$expected
                && !isset($run['clarification']),'known transport failure retains its reason without asking for missing meaning');
            recommendationCheck(($counts['model_diagnostics']['select_recommendations']['http_status']??null)===$case['http_status']
                && ($attempt['state']??null)==='FAILED','known transport failure preserves diagnosis and receipt');
        } elseif ($case['name']==='unknown') {
            recommendationCheck(($run['status']??null)==='FAILED' && ($run['reason']??null)==='AI_MODEL_RESULT_UNKNOWN',
                'recommendation timeout keeps its real reason instead of AI_ATTEMPT_UNRESOLVED');
            recommendationCheck(!isset($run['clarification']) && empty($run['clarification_ref']),
                'UNKNOWN does not become a published clarification');
            recommendationCheck(is_array($attempt) && $attempt['state']==='UNKNOWN'
                && $attempt['input_tokens']===null && $attempt['output_tokens']===null,
                'unknown provider receipt and unknown usage are retained');
            $diagnostic=$counts['model_diagnostics']['select_recommendations']??[];
            recommendationCheck(($diagnostic['stage']??null)==='transport' && ($diagnostic['predicate']??null)==='timeout'
                && ($diagnostic['transport_errno']??null)===28 && ($diagnostic['http_status']??null)===0,
                'recommendation keeps bounded transport diagnosis under its own attempt');
        } else {
            recommendationCheck(($run['status']??null)==='WAITING_CLARIFICATION',
                $case['name'].' returns a preflighted choice before querying facts');
            if ($case['name']==='ordinary') recommendationCheck($transport['timeouts']===[30000],
                'recommendation uses the existing 30 second model profile instead of a separate 10 second cap');
            elseif ($case['name']==='short_remaining') recommendationCheck(count($transport['timeouts'])===1 && $transport['timeouts'][0]>0 && $transport['timeouts'][0]<=1500,
                'live remaining deadline still reserves 5 seconds for finalization');
            else recommendationCheck(array_column($run['clarification']['fields'][0]['options']??[],'value')===['__supplement__']
                && ($attempt['state']??null)==='FAILED','received invalid candidate keeps the existing honest supplement path');
        }
    } finally {$h->close();}
}

// The shared profile remains a second cap even if a caller asks for more.
// Testing pure budget arithmetic avoids sleeping or altering an actual Run.
$budget=\app\services\ai\execution\AiRunBudgetPolicy::create(
    \app\services\ai\execution\AiRunBudgetPolicy::defaults(),1000);
recommendationCheck(\app\services\ai\execution\AiRunBudgetPolicy::callTimeout($budget,'model',45000,1000)===30000,
    'model profile independently caps a larger requested stage window');

// Terminal control failures cannot turn into a menu or be reported as a
// customer ambiguity. No production cancellation or account is exercised.
foreach (['AI_CANCELLED','AI_AUTHORIZATION_CHANGED','AI_CAPABILITY_CHANGED'] as $reason) {
    $h=recommendationHarness(null,$reason);
    try {
        $run=$h->start('门店怎么样');
        recommendationCheck(($run['reason']??null)===$reason && !isset($run['clarification']),
            'recommendation preserves terminal control reason '.$reason);
        recommendationCheck($h->models===2 && $h->queries===0,'terminal control failure adds no retry or fact query');
    } finally {$h->close();}
}

$failed=array_values(array_filter($checks,static function(array $check): bool { return !$check['pass']; }));
foreach ($failed as $check) echo 'FAIL '.$check['check']."\n";
echo 'OPT3_RECOMMENDATION_RESULT='.json_encode(['pass'=>$failed===[],'checks'=>count($checks),
    'failed'=>array_column($failed,'check'),'observations'=>$observations,
    'network'=>'synthetic curl; no network'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
exit($failed===[]?0:1);
}
