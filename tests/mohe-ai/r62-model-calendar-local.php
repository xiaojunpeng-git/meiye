<?php
/** R62 read-only local model probe; prints typed intent and timings, never credentials or business rows. */
if (getenv('MOHE_R62_MODEL_TEST')!=='LOCAL_ONLY') exit("LOCAL_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql' || ($db['database']??null)!=='ruihao') throw new RuntimeException('LOCAL_INSTANCE_MISMATCH');
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$config=$runtime['config']->read(true);
if (!$config['enabled'] || !app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($config)) {
    echo json_encode(['local_enabled'=>(bool)$config['enabled'],
        'external_authorized'=>(bool)$config['external_processing_authorized'],
        'scope_supported'=>(bool)$config['external_scope_supported'],
        'scope_authorized'=>app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($config),
        'has_api_key'=>(bool)$config['has_api_key'],'provider'=>$config['provider'],'version'=>$config['version']]),PHP_EOL;
    throw new RuntimeException('LOCAL_AI_AUTH_REQUIRED');
}
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=(new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($id);
if (empty($context['can_use'])) throw new RuntimeException('LOCAL_ADMIN_AUTH_REQUIRED');
$capabilities=app\services\ai\execution\AiAuthority::capabilities(false,$context);
$gateway=new app\services\ai\AiGatewayServices();
$objectMethod=new ReflectionMethod($gateway,'analysisObjectVocabulary');$objectMethod->setAccessible(true);
$metricMethod=new ReflectionMethod($gateway,'analysisMeasurementVocabulary');$metricMethod->setAccessible(true);
$objects=$objectMethod->invoke($gateway,$capabilities);$metrics=$metricMethod->invoke($gateway,$capabilities);
$skills=(new app\services\ai\registry\AiBusinessRegistry())->modelSkills('store_operations');
// The local config owns the provider endpoint; the historical client class
// defaults to SiliconFlow and would misroute an OpenLux key.
$client=new app\services\ai\model\SiliconFlowClient($config['provider']);
$cases=[
    'last_quarter'=>'上个季度营业额总的是多少',
    'last_week'=>'上周营业额是多少',
    'weekday_extrema'=>'上周营业额最高是周几，最低是周几',
    'previous_week_variant'=>'前一周营业额是多少',
    'two_weeks_ago'=>'上上周营业额是多少',
    'previous_year'=>'去年营业额是多少',
    'year_before_last'=>'前年营业额是多少',
];
$expectedPeriods=[
    'last_quarter'=>['unit'=>'quarter','offset'=>-1],
    'last_week'=>['unit'=>'week','offset'=>-1],
    'weekday_extrema'=>['unit'=>'week','offset'=>-1],
    'previous_week_variant'=>['unit'=>'week','offset'=>-1],
    'two_weeks_ago'=>['unit'=>'week','offset'=>-2],
    'previous_year'=>['unit'=>'year','offset'=>-1],
    'year_before_last'=>['unit'=>'year','offset'=>-2],
];
$only=getenv('MOHE_R62_CASE');
if ($only!==false && $only!=='') {
    if (!isset($cases[$only])) throw new RuntimeException('UNKNOWN_CASE');
    $cases=[$only=>$cases[$only]];
}
$failures=[];
foreach ($cases as $name=>$question) {
    $safe=['schema_version'=>'sanitized-question-v2','question'=>$question,'recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>$question]],'prior_query'=>null,
        'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-10-07'];
    if ($name==='weekday_extrema' && getenv('MOHE_R62_PRIOR_QUARTER')==='1') {
        $safe['recent_questions']=['上个季度营业额总的是多少'];
        $safe['evidence_messages'][]=['id'=>'recent_1','text'=>'上个季度营业额总的是多少'];
        $safe['prior_query']=['metric_codes'=>['cash_performance'],'operation'=>'summary',
            'periods'=>[['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'full']],
            'ranking'=>['direction'=>'unspecified','limit'=>null],'scope'=>'authorized','object_kind'=>'store',
            'has_business_filter'=>false,'has_object_selection'=>false,'has_store_scope_restriction'=>false,
            'presentation_origin'=>'customer_or_verified_context'];
    }
    $started=microtime(true);
    try {
        $attempts=1;
        try {
            $result=$client->understandMeaning($safe,$config['model'],$config['api_key'],35000,static function(){},$skills,null,$objects,$metrics);
        } catch (app\services\ai\contract\AiContractException $first) {
            $predicate=$first->diagnostic()['predicate']??'';
            if (!app\services\ai\contract\AiIntentUnderstandingContract::repairable($predicate)) throw $first;
            $attempts=2;
            // Mirror the gateway's single bounded structural repair; no
            // business meaning or date is supplied by this test harness.
            $result=$client->understandMeaning($safe,$config['model'],$config['api_key'],35000,static function(){},$skills,$predicate,$objects,$metrics);
        }
        $understanding=$result['understanding'];$periods=[];$ranking=[];
        foreach ((array)($understanding['requirements']??[]) as $requirement) {
            foreach ((array)($requirement['values']['periods']??[]) as $period) $periods[]=$period;
            if (isset($requirement['values']['ranking'])) $ranking[]=$requirement['values']['ranking'];
        }
        $matched=false;
        foreach ($periods as $period) {
            if (($period['kind']??null)==='calendar_period'
                && ($period['unit']??null)===$expectedPeriods[$name]['unit']
                && ($period['offset']??null)===$expectedPeriods[$name]['offset']) $matched=true;
        }
        if (!$matched) throw new RuntimeException('CALENDAR_MEANING_MISMATCH_'.$name);
        if ($name==='weekday_extrema' && (count($ranking)!==1
            || ($ranking[0]['direction']??null)!=='top_and_bottom')) {
            throw new RuntimeException('RANKING_MEANING_MISMATCH');
        }
        echo json_encode(['case'=>$name,'status'=>$understanding['status']??null,'attempts'=>$attempts,'periods'=>$periods,
            'ranking'=>$ranking,'elapsed_ms'=>(int)((microtime(true)-$started)*1000)],JSON_UNESCAPED_UNICODE),PHP_EOL;
    } catch (Throwable $error) {
        $failures[]=$name;
        $diagnostic=$error instanceof app\services\ai\contract\AiContractException ? $error->diagnostic() : [];
        echo json_encode(['case'=>$name,'error'=>$error->getMessage(),'diagnostic'=>$diagnostic,
            'elapsed_ms'=>(int)((microtime(true)-$started)*1000)],JSON_UNESCAPED_UNICODE),PHP_EOL;
    }
}
if ($failures!==[]) throw new RuntimeException('R62_MODEL_CALENDAR_FAILED_'.implode(',',$failures));
