<?php
/**
 * R56 real local gateway regression for paraphrased result continuations.
 * Uses five independent signed conversations against the pinned local copy;
 * outputs only statuses, row counts and timings, never member identities.
 */
if (getenv('MOHE_R56_NATURAL_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql'||($db['database']??null)!=='ruihao'
    ||(think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}
$adminId=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($adminId);
$context['_refresh']=static function()use($resolver,$adminId):array{return $resolver->authenticated($adminId);};
if (empty($context['can_use'])||empty($context['member_data_authorized'])) throw new RuntimeException('LOCAL_AUTH_REQUIRED');
$gateway=new app\services\ai\AiGatewayServices();
$cases=['会员详情','明细呢','把这批会员列出来','具体有哪些会员','把这些会员逐个展开看看'];
$monthSequence=getenv('MOHE_R56_MONTH_SEQUENCE')==='1';
$only=getenv('MOHE_R56_NATURAL_CASE');
if ($only!==false && $only!=='') {
    if (!in_array($only,$cases,true)) throw new RuntimeException('UNKNOWN_CASE');
    $cases=[$only];
}
$results=[];
foreach ($cases as $phrase) {
    $client='r56-natural-'.bin2hex(random_bytes(8));
    $conversation='r56-natural-'.bin2hex(random_bytes(8));
    $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
    $reference=null;$turns=[];$expectedCount=null;
    $questions=$monthSequence?['这个月来源A的会员有几个','上个月呢',$phrase]
        :['2026年9月来源A的会员有几个',$phrase];
    foreach ($questions as $index=>$question) {
        $input=['client_request_id'=>'r56-natural-'.bin2hex(random_bytes(8)),
            'conversation_id'=>$conversation,'client_session_id'=>$client,
            'window_token'=>$boot['window_token'],'question'=>$question,'history'=>[],
            'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
        if ($reference!==null) $input['context_ref']=$reference;
        $started=microtime(true);
        $run=$gateway->handle('create',$context,$input);
        if (!isset($run['run_id'],$run['generation'],$run['run_delivery_token'])) {
            throw new RuntimeException('RUN_NOT_ADMITTED_'.(string)($run['reason']??'unknown'));
        }
        $delivery=['client_session_id'=>$client,'generation'=>$run['generation'],
            'run_delivery_token'=>$run['run_delivery_token']];
        $run=$gateway->handle('execute',$context,$input+$delivery,$run['run_id']);
        $until=microtime(true)+75;
        while (in_array($run['status']??null,['RECEIVED','WORKFLOW_EXECUTING','WAITING_EXPORT'],true)
            && microtime(true)<$until) {
            usleep(250000);
            $run=$gateway->handle('status',$context,$delivery,$run['run_id']);
        }
        // A list answer uses the root evidence table; a detail answer uses
        // the selected-set section. Both must retain the signed cohort.
        $listRows=(array)($run['answer']['table']['rows']??[]);
        $detailRows=(array)($run['answer']['sections'][1]['answer']['table']['rows']??[]);
        $turns[]=['status'=>$run['status']??'unknown','reason'=>$run['reason']??null,
            'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
            'list_rows'=>count($listRows),'detail_rows'=>count($detailRows)];
        if (($run['status']??null)!=='COMPLETED') {
            echo json_encode(['completed'=>$results,'failed'=>['phrase'=>$phrase,'turn'=>$index+1,'result'=>end($turns)]],JSON_UNESCAPED_UNICODE),PHP_EOL;
            throw new RuntimeException('NATURAL_FOLLOWUP_FAILED');
        }
        $reference=$run['answer']['context_ref']??null;
        if (!is_string($reference)||$reference==='') throw new RuntimeException('CONTEXT_REF_MISSING');
        $summary=(string)($run['answer']['summary']??'');
        if ($index<count($questions)-1) {
            if (!preg_match('/共有([0-9]+)人/u',$summary,$match)) throw new RuntimeException('COUNT_MISSING');
            $expectedCount=(int)$match[1];
            if ($monthSequence && $index===1
                && strpos($summary,'2026-09-01 至 2026-09-30')===false) {
                throw new RuntimeException('MONTH_CONTEXT_NOT_REPLACED');
            }
        } elseif (max(count($listRows),count($detailRows))!==$expectedCount
            ||strpos($summary,'2026-09-01 至 2026-09-30')===false) {
            echo json_encode(['phrase'=>$phrase,'result'=>end($turns),'expected_count'=>$expectedCount,
                'summary_length'=>mb_strlen($summary)],JSON_UNESCAPED_UNICODE),PHP_EOL;
            throw new RuntimeException('INHERITED_LIST_OR_DETAIL_MISMATCH');
        }
    }
    $results[]=['phrase'=>$phrase,'turns'=>$turns];
}
echo json_encode(['result'=>'PASS','cases'=>$results,'business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
