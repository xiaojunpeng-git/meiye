<?php
require_once __DIR__.'/fixture-autoload.php';
// Frozen independent cases; model selection is an explicit fixture, not a live-model score.
$cases=json_decode(file_get_contents(__DIR__.'/r5-holdout-cases.json'),true);
$projector=new app\services\ai\model\AiModelInputProjector();
$planner=new app\services\ai\execution\AiWorkflowPlanner();
$compiler=new app\services\ai\execution\AiRegisteredPlanCompiler();
$caps=app\services\ai\execution\AiAuthority::capabilities(true);$caps['current_store_bound']=true;
$counts=['compile'=>0,'ambiguity'=>0,'capability_refusal'=>0,'understanding_failure'=>0,'wrong_hit'=>0,'matched'=>0,'mismatched'=>0,'scope_adjudicated'=>0];
foreach($cases['cases'] as $case) {
    $reason='';$plan=null;
    try {
        $p=$projector->project($case['question']); $signals=$p['signals'];
        $shape='summary';foreach(['trend','ranking','comparison'] as $candidate)if(in_array($candidate,$signals,true))$shape=$candidate;
        if(array_intersect(['top_5','bottom_5'],$signals))$shape='ranking';
        $selection=['metric_codes'=>array_values(array_intersect($caps['metric_codes'],$signals)),'query_shape'=>$shape,'decision'=>'query','date_code'=>'UNSPECIFIED'];
        $result=$planner->compile($p,$selection,$caps,'screen','2026-09-09');
        if($result['kind']==='clarification')$actual='ambiguity';
        else {$plan=$compiler->compile($result['plan'],$caps);$actual='compile';}
    } catch(Throwable $e) {
        $reason=$e->getMessage();
        $actual=in_array($reason,['AI_INTENT_UNRESOLVED','AI_CONTEXT_REQUIRED','AI_UNSUPPORTED_CONDITION'],true)?'understanding_failure':'capability_refusal';
    }
    $match=$actual===$case['expected'];
    if($actual==='compile') {
        $q=$plan['query'];$metrics=$q===null?$plan['definition_metric_codes']:$q['metric_codes'];$expected=$case['metrics']??[];sort($metrics);sort($expected);
        $shape=$q===null?'explanation':$q['query_shape'];
        $match=$match && $metrics===$expected && $shape===($case['shape']??null);
        $ranges=['today'=>['2026-09-09','2026-09-09'],'this_month'=>['2026-09-01','2026-09-09'],'last_7_days'=>['2026-09-03','2026-09-09'],
            '2026-09-01/2026-09-08'=>['2026-09-01','2026-09-08'],'yesterday/today'=>['2026-09-08','2026-09-08'],'today/yesterday'=>['2026-09-09','2026-09-09']];
        if(isset($case['period']))$match=$match && isset($ranges[$case['period']]) && [$q['start_date'],$q['end_date']]===$ranges[$case['period']];
        if(($case['period']??'')==='yesterday/today')$match=$match && $q['compare_range']===['start'=>'2026-09-09','end'=>'2026-09-09'];
        if(($case['period']??'')==='today/yesterday')$match=$match && $q['compare_range']===['start'=>'2026-09-08','end'=>'2026-09-08'];
        if(isset($case['rank']))$match=$match && $q['ranking']===['direction'=>$case['rank'],'limit'=>$case['limit']];
        if(isset($case['output']))$match=$match && $plan['output_format']==='screen_and_xlsx';
        if(!$match)$actual='wrong_hit';
    }
    // Frozen expectations remain unchanged. Two scope differences are reported
    // separately and NEVER counted as raw holdout matches.
    $adjudicated=($case['id']==='H017' && $actual==='capability_refusal' && $reason==='AI_CAPABILITY_NOT_READY')
        || ($case['id']==='H037' && $actual==='ambiguity');
    ++$counts[$actual];++$counts[$match?'matched':'mismatched'];if($adjudicated)++$counts['scope_adjudicated'];
    echo $case['id'].' '.($match?'PASS':($adjudicated?'SCOPE_REVIEW':'GAP')).' expected='.$case['expected'].' actual='.$actual.($reason?' reason='.$reason:'')."\n";
}
echo 'HOLDOUT_RESULT='.json_encode($counts)."\n";
echo "BOUNDARY=Frozen semantic/compiler fixture cases; not live model, data reconciliation or product acceptance. No trusted preceding evidence injected.\n";
echo "SCOPE_REVIEW=H017: no partner-distribution capability; stop instead of pointless guidance. H037: device semantic history is not signed evidence; clarify missing date. Signed follow-up is tested separately in gateway-r5-guidance.\n";
exit($counts['mismatched']>$counts['scope_adjudicated']?1:0);
