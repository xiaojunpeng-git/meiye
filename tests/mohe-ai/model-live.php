<?php
/** Opt-in live vendor test. Key only from stdin; no app env, DB, raw response or secrets logged. */
if (PHP_SAPI !== 'cli' || getenv('MOHE_AI_LIVE_TEST') !== 'explicit') {
    fwrite(STDERR,"Live test requires explicit opt-in.\n"); exit(2);
}
$root=dirname(__DIR__,2).'/后端代码/app/services/ai/';
require_once __DIR__.'/live-response-observer.php';
foreach(['contract/AiContractException.php','contract/AiStrictJson.php','model/AiModelInputProjector.php','model/SiliconFlowClient.php','execution/AiWorkflowPlanner.php'] as $file) require_once $root.$file;
$model=$argv[1]??'';
if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,127}$/D',$model))exit(2);
fwrite(STDOUT,"READY_FOR_SECRET_STDIN (terminal echo must be disabled)\n");
$key=trim((string)fgets(STDIN,2048));
if($key==='' || preg_match('/[\x00-\x20\x7f]/',$key))exit(2);
$projector=new app\services\ai\model\AiModelInputProjector();
$client=new app\services\ai\model\SiliconFlowClient();
$planner=new app\services\ai\execution\AiWorkflowPlanner();
$cap=['metric_codes'=>['cash_performance','consume_amount'],'query_shapes'=>['summary','trend','ranking','comparison']];
$cases=[
    ['Q001','今天收了多少钱？','plan',['cash_performance']],
    ['Q003','今天消耗业绩多少？','plan',['consume_amount']],
    ['AMBIGUOUS','今天业绩多少？','clarification',[]],
    ['BOTH','今天现金业绩和消耗业绩多少？','plan',['cash_performance','consume_amount']],
    ['TREND','本月现金业绩趋势','plan',['cash_performance']],
];
$start=microtime(true);$inputs=0;$outputs=0;$unknown=false;$passed=0;
foreach($cases as [$id,$question,$kind,$metrics]){
    $caseStart=microtime(true);
    try{
        $result=$client->select($projector->modelView($projector->validateConversation($question,[])),$cap['metric_codes'],$model,$key,20000,static function(){});
        $compiled=$planner->compile($projector->project($question),$result['selection'],$cap,'screen','2026-09-08');
        if($compiled['kind']!==$kind || ($kind==='plan' && $compiled['plan']['query']['metric_codes']!==$metrics))throw new RuntimeException('LIVE_PLAN_MISMATCH');
        $u=$result['usage'];$unknown=$unknown||$u['input_tokens']===null||$u['output_tokens']===null;
        $inputs+=(int)$u['input_tokens'];$outputs+=(int)$u['output_tokens'];$passed++;
        echo json_encode(['case'=>$id,'result'=>'PASS','duration_ms'=>(int)round((microtime(true)-$caseStart)*1000),'usage'=>$u],JSON_UNESCAPED_SLASHES)."\n";
    }catch(Throwable $e){
        $code=$e->getMessage();if(!preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D',$code))$code='LIVE_TEST_FAILED';
        $raw=json_decode($GLOBALS['moheLiveResponse']??'',true);$content=$raw['choices'][0]['message']['content']??'';
        $selection=is_string($content)?json_decode($content,true):null;
        // Only enum selections of synthetic cases; never print response/error bodies or prompt.
        $diagnostic=['finish_reason'=>$raw['choices'][0]['finish_reason']??null,'content_is_json'=>is_array($selection)];
        if(is_array($selection))foreach(['metric_codes','query_shape','date_code','decision'] as $field)$diagnostic[$field]=$selection[$field]??null;
        echo json_encode(['case'=>$id,'result'=>'STOPPED','error_code'=>$code,'usage'=>$raw['usage']??'UNKNOWN','diagnostic'=>$diagnostic,'passed'=>$passed])."\n";
        $key='';exit(1); // No resend on failure or unknown billing.
    }
}
$key='';
echo json_encode(['result'=>'PASS','cases'=>$passed,'model'=>$model,'duration_ms'=>(int)round((microtime(true)-$start)*1000),'known_input_tokens'=>$inputs,'known_output_tokens'=>$outputs,'usage_unknown'=>$unknown])."\n";
