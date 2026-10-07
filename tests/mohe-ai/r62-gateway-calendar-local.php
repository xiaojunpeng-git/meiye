<?php
/** R62 live local-only regression: actual model, authorized Reader, three de-identified business questions. */
if (getenv('MOHE_R62_GATEWAY_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
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
$context['_refresh']=static function()use($resolver,$id):array{return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$cases=[
    'last_quarter'=>['question'=>'上个季度营业额总的是多少','start'=>'2026-07-01','end'=>'2026-09-30'],
    'last_week'=>['question'=>'上周营业额是多少','start'=>'2026-09-28','end'=>'2026-10-04'],
    'weekday_extrema'=>['question'=>'上周营业额最高是周几，最低是周几','start'=>'2026-09-28','end'=>'2026-10-04'],
];
$only=getenv('MOHE_R62_CASE');
if ($only!==false && $only!=='') {
    if (!isset($cases[$only])) throw new RuntimeException('UNKNOWN_CASE');
    $cases=[$only=>$cases[$only]];
}
foreach ($cases as $name=>$case) {
    $client='r62-calendar-'.bin2hex(random_bytes(8));
    $boot=$gateway->handle('bootstrap',$context,['client_session_id'=>$client]);
    $input=['client_request_id'=>'r62-calendar-'.bin2hex(random_bytes(8)),
        'conversation_id'=>'r62-calendar-'.bin2hex(random_bytes(8)),
        'client_session_id'=>$client,'window_token'=>$boot['window_token'],
        'question'=>$case['question'],'history'=>[],'output_format'=>'screen',
        'guidance_schema_version'=>'mohe-clarification-v2'];
    $started=microtime(true);
    $run=$gateway->handle('create',$context,$input);
    if (!isset($run['run_id'],$run['generation'],$run['run_delivery_token'])) throw new RuntimeException('RUN_NOT_ADMITTED_'.($run['reason']??'unknown'));
    $delivery=['client_session_id'=>$client,'generation'=>$run['generation'],'run_delivery_token'=>$run['run_delivery_token']];
    $run=$gateway->handle('execute',$context,$input+$delivery,$run['run_id']);
    $until=microtime(true)+65;
    while (in_array($run['status']??null,['RECEIVED','WORKFLOW_EXECUTING','WAITING_EXPORT'],true) && microtime(true)<$until) {
        usleep(250000);
        $run=$gateway->handle('status',$context,$delivery,$run['run_id']);
    }
    $summary=(string)($run['answer']['summary']??'');
    $range=$case['start'].' 至 '.$case['end'];
    $rangeMatched=strpos($summary,$range)!==false;
    $rows=(array)($run['answer']['table']['rows']??[]);
    $weekdayLabels=[];
    if ($name==='weekday_extrema') foreach ($rows as $row) {
        $weekdayLabels[]=['label'=>$row['label']??null,'rank'=>$row['rank']??null];
    }
    $result=['case'=>$name,'status'=>$run['status']??null,'reason'=>$run['reason']??null,
        'elapsed_ms'=>(int)((microtime(true)-$started)*1000),'expected_range'=>$range,
        'range_matched'=>$rangeMatched,'row_count'=>count($rows),'weekday_labels'=>$weekdayLabels,
        'diagnostic'=>$run['management_trace']['model_diagnostic']??null];
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
    if (($run['status']??null)!=='COMPLETED' || !$rangeMatched
        || ($name==='weekday_extrema' && (!$rows || count($weekdayLabels)<2))) {
        throw new RuntimeException('R62_LIVE_CASE_FAILED_'.$name);
    }
}
