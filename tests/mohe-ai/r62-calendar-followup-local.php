<?php
/** R62 local read-only gateway regression: quarter summary followed by weekday extrema. */
if (getenv('MOHE_R62_FOLLOWUP_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao'
    || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$config=$runtime['config']->read();
if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($config)) {
    throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
}
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($id);
if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
$context['_refresh']=static function() use ($resolver,$id): array {return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$client='r62-followup-'.bin2hex(random_bytes(8));
$conversation='r62-followup-'.bin2hex(random_bytes(8));
$boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
$reference=null;
foreach ([
    ['question'=>'上个季度营业额总的是多少','range'=>'2026-07-01 至 2026-09-30'],
    ['question'=>'上周营业额最高是周几，最低是周几','range'=>'2026-09-28 至 2026-10-04'],
] as $index=>$case) {
    $input=['client_request_id'=>'r62-followup-'.bin2hex(random_bytes(8)),
        'conversation_id'=>$conversation,'client_session_id'=>$client,
        'window_token'=>$boot['window_token'],'question'=>$case['question'],'history'=>[],
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
    $summary=(string)($run['answer']['summary']??'');
    $rows=(array)($run['answer']['table']['rows']??[]);
    $result=['turn'=>$index+1,'status'=>$run['status']??null,'reason'=>$run['reason']??null,
        'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
        'range_matched'=>strpos($summary,$case['range'])!==false,'row_count'=>count($rows),
        'diagnostic'=>$run['management_trace']['model_diagnostic']??null];
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
    if (($run['status']??null)!=='COMPLETED' || !$result['range_matched']
        || ($index===1 && count($rows)<2)) throw new RuntimeException('R62_FOLLOWUP_FAILED_TURN_'.($index+1));
    $reference=$run['answer']['context_ref']??null;
    if (!is_string($reference)||$reference==='') throw new RuntimeException('CONTEXT_REF_MISSING');
}
