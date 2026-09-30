<?php
/** R56 local gateway acceptance: real async Run, no business-data writes. */
if (getenv('MOHE_R56_GATEWAY_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
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
$client='r56-'.bin2hex(random_bytes(8));$conversation='r56-'.bin2hex(random_bytes(8));
$boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
$contextRef=null;$out=[];$firstCount=null;
foreach(['2026年9月来源A的会员有几个','会员详情','第一个会员的权益明细给我看一下',
    '来源B的会员有几个','这个月呢','今天现金业绩多少'] as $index=>$question) {
    $input=['client_request_id'=>'r56-'.bin2hex(random_bytes(8)),'conversation_id'=>$conversation,
        'client_session_id'=>$client,'window_token'=>$boot['window_token'],'question'=>$question,
        'history'=>[],'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2'];
    if ($contextRef!==null) $input['context_ref']=$contextRef;
    $started=microtime(true);
    $run=$gateway->handle('create',$context,$input);
    if (!isset($run['run_id'],$run['generation'],$run['run_delivery_token'])) {
        echo json_encode($out,JSON_UNESCAPED_UNICODE),PHP_EOL;
        throw new RuntimeException('AI_RUN_NOT_ADMITTED_'.(string)($run['reason']??$run['status']??'unknown'));
    }
    $delivery=['client_session_id'=>$client,'generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];
    $run=$gateway->handle('execute',$context,$input+$delivery,$run['run_id']);
    $until=microtime(true)+75;
    while (in_array($run['status']??null,['RECEIVED','WORKFLOW_EXECUTING','WAITING_EXPORT'],true)
        && microtime(true)<$until) {
        usleep(250000);
        $run=$gateway->handle('status',$context,$delivery,$run['run_id']);
    }
    $elapsed=(int)((microtime(true)-$started)*1000);
    $detailRows=(array)($run['answer']['sections'][1]['answer']['table']['rows']??[]);
    $out[]=['turn'=>$index+1,'status'=>$run['status']??'unknown','reason'=>$run['reason']??null,
        'elapsed_ms'=>$elapsed,'detail_rows'=>count($detailRows)];
    if (($run['status']??null)!=='COMPLETED') {
        echo json_encode($out,JSON_UNESCAPED_UNICODE),PHP_EOL;
        throw new RuntimeException('AI_SOURCE_GATEWAY_FAILED');
    }
    $contextRef=$run['answer']['context_ref']??null;
    if (!is_string($contextRef)||$contextRef==='') throw new RuntimeException('AI_SOURCE_CONTEXT_MISSING');
    if ($index===0 && strpos((string)($run['answer']['summary']??''),'首次疗程来源为')===false) {
        throw new RuntimeException('AI_SOURCE_COUNT_ANSWER_INVALID');
    }
    if ($index===0) {
        if (!preg_match('/共有([0-9]+)人/u',(string)$run['answer']['summary'],$match)) throw new RuntimeException('AI_SOURCE_COUNT_MISSING');
        $firstCount=(int)$match[1];
    }
    if ($index===1 && count($detailRows)!==$firstCount) throw new RuntimeException('AI_SOURCE_DETAILS_MISMATCH');
    // Single-member rights use the existing presentation facts rather than
    // the multi-member section layout; assert the protected fields are there
    // without printing the member's identity or balances into test evidence.
    if ($index===2 && (strpos((string)($run['answer']['summary']??''),'会员权益')===false
        ||!in_array('所属门店',array_column((array)($run['answer']['presentation']['facts']??[]),'label'),true)
        ||!in_array('账户余额',array_column((array)($run['answer']['presentation']['facts']??[]),'label'),true))) {
        throw new RuntimeException('AI_SOURCE_MEMBER_RIGHTS_NOT_RENDERED');
    }
    if ($index===3 && (strpos((string)$run['answer']['summary'],'来源为B')===false
        ||strpos((string)$run['answer']['summary'],'2026-09-01 至 2026-09-30')===false)) {
        throw new RuntimeException('AI_SOURCE_SWAP_FAILED');
    }
    if ($index===4 && strpos((string)$run['answer']['summary'],'2026-10-01 至 2026-10-01')===false) {
        throw new RuntimeException('AI_SOURCE_PERIOD_SWAP_FAILED');
    }
    if ($index===5 && (strpos((string)$run['answer']['summary'],'现金业绩')===false
        ||strpos((string)$run['answer']['summary'],'首次疗程来源')!==false)) {
        throw new RuntimeException('AI_SOURCE_TOPIC_RESET_FAILED');
    }
}
echo json_encode(['result'=>'PASS','turns'=>$out,'business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
