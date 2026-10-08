<?php
/** R64 local-only live regression of a date-ranking to store-ranking follow-up. */
if (getenv('MOHE_R64_STORE_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao'
    || (think\facade\Db::query('SELECT @@server_uuid uuid')[0]['uuid']??null)!=='3506c91f-98cb-11f1-af5f-9e1a52451c2a') {
    throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
}
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
if (!app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($runtime['config']->read())) {
    throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
}
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$context=$resolver->authenticated($id);
if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
$context['_refresh']=static function() use ($resolver,$id): array {return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$client='r64-store-'.bin2hex(random_bytes(8));
$conversation='r64-store-'.bin2hex(random_bytes(8));
$boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
$reference=null;
foreach (['这个月按实际业绩看，哪天最高','哪个门店业绩最高哪个门店最低','那按现金业绩看，哪个门店最高最低'] as $index=>$question) {
    // Use the same conversation and signed result reference; a standalone
    // store ranking would not verify object and metric inheritance.
    $input=['client_request_id'=>'r64-store-'.bin2hex(random_bytes(8)),
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
    $row=think\facade\Db::name('mohe_ai_run')->where('run_id',$run['run_id'])->find();
    $query=($run['status']??null)==='COMPLETED'
        ? ($runtime['private']->read($row['evidence_ref'])['query']??[]) : [];
    $passed=($run['status']??null)==='COMPLETED'
        &&($index!==1 || (
            str_contains($summary,'第1名') && str_contains($summary,'倒数')
            &&($query['query_shape']??null)==='ranking'
            &&($query['ranking']['direction']??null)==='top_and_bottom'
            &&($query['metric_codes']??null)===['actual_performance']
            &&($query['business_filters']??null)===[]
        ))
        &&($index!==2 || (
            ($query['query_shape']??null)==='ranking'
            &&($query['ranking']['direction']??null)==='top_and_bottom'
            &&($query['metric_codes']??null)===['cash_performance']
            &&($query['business_filters']??null)===[]
        ));
    echo json_encode(['turn'=>$index+1,'run_id'=>$run['run_id']??null,
        'status'=>$run['status']??null,'reason'=>$run['reason']??null,
        'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
        'matched_store_extrema'=>$index>0?$passed:null,
        'diagnostic'=>$run['management_trace']['model_diagnostic']??null],JSON_UNESCAPED_UNICODE),PHP_EOL;
    if (!$passed) throw new RuntimeException('R64_LOCAL_STORE_FOLLOWUP_FAILED_TURN_'.($index+1));
    $reference=$run['answer']['context_ref']??null;
    if (!is_string($reference) || $reference==='') throw new RuntimeException('CONTEXT_REF_MISSING');
}
