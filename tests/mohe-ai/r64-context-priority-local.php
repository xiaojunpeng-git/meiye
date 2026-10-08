<?php
/** R64 local-only randomized live regression for first-answer and verified-context priority. */
if (getenv('MOHE_R64_CONTEXT_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
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
$cases=[
    ['name'=>'cash-short-followup','turns'=>[
        ['question'=>'前天现金业绩是多少','metric'=>'cash_performance','shape'=>'summary'],
        ['question'=>'这个月呢，哪天最高','metric'=>'cash_performance','shape'=>'ranking','kind'=>'business_date'],
    ]],
    ['name'=>'cash-full-followup','turns'=>[
        ['question'=>'昨天收了多少钱','metric'=>'cash_performance','shape'=>'summary'],
        ['question'=>'这个月业绩最高是哪天','metric'=>'cash_performance','shape'=>'ranking','kind'=>'business_date'],
    ]],
    ['name'=>'date-first-answer','turns'=>[
        ['question'=>'这个月业绩最高是哪天','metric'=>'actual_performance','shape'=>'ranking','kind'=>'business_date'],
    ]],
    ['name'=>'date-to-store','turns'=>[
        ['question'=>'这个月按实际业绩看，哪天最高','metric'=>'actual_performance','shape'=>'ranking','kind'=>'business_date'],
        ['question'=>'哪个门店业绩最高哪个门店最低','metric'=>'actual_performance','shape'=>'ranking','kind'=>'store'],
    ]],
];
// Shuffle independent chains, never the order within one verified context.
mt_srand(6408); shuffle($cases);
$onlyCase=getenv('MOHE_R64_CASE');
if (is_string($onlyCase) && $onlyCase!=='') $cases=array_values(array_filter($cases,
    static fn(array $case):bool=>$case['name']===$onlyCase));
$failures=[];
foreach ($cases as $case) {
    $client='r64-context-'.bin2hex(random_bytes(8));
    $conversation='r64-context-'.bin2hex(random_bytes(8));
    $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
    $reference=null;
    foreach ($case['turns'] as $index=>$expected) {
        $input=['client_request_id'=>'r64-context-'.bin2hex(random_bytes(8)),
            'conversation_id'=>$conversation,'client_session_id'=>$client,'window_token'=>$boot['window_token'],
            'question'=>$expected['question'],'history'=>[],'output_format'=>'screen',
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
        $row=think\facade\Db::name('mohe_ai_run')->where('run_id',$run['run_id'])->find();
        $query=($run['status']??null)==='COMPLETED' && is_array($row)
            ? ($runtime['private']->read($row['evidence_ref'])['query']??[]) : [];
        $kind=$query['business_filters']['object_kind']??'store';
        $pass=($run['status']??null)==='COMPLETED'
            && ($query['metric_codes']??null)===[$expected['metric']]
            && ($query['query_shape']??null)===$expected['shape']
            && (!isset($expected['kind']) || $kind===$expected['kind']);
        echo json_encode(['case'=>$case['name'],'turn'=>$index+1,'question'=>$expected['question'],
            'run_id'=>$run['run_id'],'status'=>$run['status']??null,'reason'=>$run['reason']??null,
            'elapsed_ms'=>(int)((microtime(true)-$started)*1000),
            'actual_metric'=>$query['metric_codes']??null,'actual_kind'=>$kind,
            'actual_filters'=>$query['business_filters']??null,'pass'=>$pass],JSON_UNESCAPED_UNICODE),PHP_EOL;
        if (!$pass) {$failures[]=$case['name'].'#'.($index+1);break;}
        $reference=$run['answer']['context_ref']??null;
        if (!is_string($reference) || $reference==='') {$failures[]=$case['name'].'#missing_context';break;}
    }
}
if ($failures!==[]) throw new RuntimeException('R64_CONTEXT_PRIORITY_FAILED: '.implode(',',$failures));
