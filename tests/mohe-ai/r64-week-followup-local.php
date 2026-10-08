<?php
/** R64 local-only live regression: real model and authorised Reader, no result amounts printed. */
if (getenv('MOHE_R64_WEEK_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao'
    || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$config=$runtime['config']->read();
if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($config)) throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($id);
if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
$context['_refresh']=static function() use ($resolver,$id): array {return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$client='r64-week-'.bin2hex(random_bytes(8));
$conversation='r64-week-'.bin2hex(random_bytes(8));
$boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
$reference=null;
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$week=app\services\ai\execution\AiTemporalPeriodResolver::resolve(
    ['kind'=>'calendar_period','unit'=>'week','offset'=>-1,'count'=>1,'end_mode'=>'full'],$today);
foreach (['昨天收了多少钱','上周呢'] as $index=>$question) {
    // The same conversation and signed result reference are required: a
    // standalone week question would not exercise context inheritance.
    $input=['client_request_id'=>'r64-week-'.bin2hex(random_bytes(8)),
        'conversation_id'=>$conversation,'client_session_id'=>$client,'window_token'=>$boot['window_token'],
        'question'=>$question,'history'=>[],'output_format'=>'screen',
        'guidance_schema_version'=>'mohe-clarification-v2'];
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
    $range=$week['start'].' 至 '.$week['end'];
    $passed=($run['status']??null)==='COMPLETED'
        &&($index===0 || (str_contains($summary,'现金业绩') && str_contains($summary,$range)));
    echo json_encode(['turn'=>$index+1,'run_id'=>$run['run_id']??null,
        'status'=>$run['status']??null,'reason'=>$run['reason']??null,
        'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
        'expected_range'=>$index===1?$range:null,'range_matched'=>$index===1?str_contains($summary,$range):null,
        'diagnostic'=>$run['management_trace']['model_diagnostic']??null],JSON_UNESCAPED_UNICODE),PHP_EOL;
    if (!$passed) throw new RuntimeException('R64_LOCAL_WEEK_FOLLOWUP_FAILED_TURN_'.($index+1));
    $reference=$run['answer']['context_ref']??null;
    if (!is_string($reference) || $reference==='') throw new RuntimeException('CONTEXT_REF_MISSING');
}
