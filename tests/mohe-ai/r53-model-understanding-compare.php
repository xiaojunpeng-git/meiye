<?php
/**
 * Local, read-only comparison of the actual language-stage contract.
 * Sends only fixed, de-identified test questions through an already-authorized
 * local AI configuration. Never changes the configured model or prints secrets,
 * provider output, business rows, or customer identity.
 */
require getcwd().'/vendor/autoload.php';
$app=new think\App(); $app->initialize();
$db=(array)config('database.connections.'.config('database.default'));
$mode=getenv('MOHE_R53_MODEL_COMPARE');
if (($db['hostname']??'')!=='mysql' || !in_array($mode,['LOCAL_ONLY','SIZE_ONLY'],true)) exit("Local explicit opt-in required\n");
$runtime=app\services\ai\execution\AiRuntimeFactory::make();
$config=$runtime['config']->read(true);
if (!$config['enabled'] || !app\services\ai\config\AiConfigStore::allowsSanitizedQuestion($config)) exit("Existing authorization required\n");
$adminId=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=(new app\services\ai\execution\AiPlatformPrincipalResolver())->authenticated($adminId);
if (empty($context['can_use'])) exit("Local administrator unavailable\n");
$capabilities=app\services\ai\execution\AiAuthority::capabilities(false,$context);
$gateway=new app\services\ai\AiGatewayServices();
$objectMethod=new ReflectionMethod($gateway,'analysisObjectVocabulary');
$measureMethod=new ReflectionMethod($gateway,'analysisMeasurementVocabulary');
$objectMethod->setAccessible(true); $measureMethod->setAccessible(true);
$objects=$objectMethod->invoke($gateway,$capabilities);
$measurements=$measureMethod->invoke($gateway,$capabilities);
$skills=(new app\services\ai\registry\AiBusinessRegistry())->modelSkills('store_operations');
if ($mode==='SIZE_ONLY') {
    $matchSizes=[];
    foreach (['这个月每家门店现金业绩、消耗业绩、退款业绩分别是什么','近30天员工业绩达到5万元且服务次数达到24次的员工有多少'] as $index=>$question) {
        $matched=array_values(array_filter($measurements,static function(array $item)use($question):bool {
            foreach (array_merge([$item['measurement_label']],$item['customer_terms']) as $term) {
                if (mb_strlen($term,'UTF-8')>=2 && mb_strpos($question,$term,0,'UTF-8')!==false) return true;
            }
            return false;
        }));
        $matchSizes['case_'.$index]=['items'=>count($matched),'bytes'=>strlen(json_encode($matched,JSON_UNESCAPED_UNICODE))];
    }
    echo json_encode(['contract_bytes'=>strlen(app\services\ai\contract\AiIntentUnderstandingContract::modelInstruction()),
        'skill_bytes'=>strlen($skills['intent_understanding']['instructions']),
        'object_bytes'=>strlen(json_encode($objects,JSON_UNESCAPED_UNICODE)),
        'measurement_bytes'=>strlen(json_encode($measurements,JSON_UNESCAPED_UNICODE)),
        'literal_match_sizing'=>$matchSizes])."\n";
    exit;
}
$client=new app\services\ai\model\SiliconFlowClient();
$cases=[
    'three_metrics'=>'这个月每家门店现金业绩、消耗业绩、退款业绩分别是什么',
    'mixed_comparison'=>'这个月各门店现金业绩和退款业绩分别是多少，实际业绩最低的是哪家店',
    'employee_conditions'=>'近30天员工业绩达到5万元且服务次数达到24次的员工有多少',
];
$models=['Qwen/Qwen2.5-72B-Instruct','Qwen/Qwen2.5-32B-Instruct'];
foreach ($cases as $case=>$question) {
    $safe=['schema_version'=>'sanitized-question-v2','question'=>$question,'recent_questions'=>[],
        'evidence_messages'=>[['id'=>'current','text'=>$question]],'prior_query'=>null,
        'has_unresolved_conditions'=>false,'server_resolved_fields'=>[],'reference_date'=>'2026-09-28'];
    foreach ($models as $model) {
        $start=microtime(true); $result=['case'=>$case,'model'=>$model];
        try {
            $response=$client->understandMeaning($safe,$model,$config['api_key'],35000,static function(){},$skills,null,$objects,$measurements);
            $understanding=$response['understanding'];
            $result['status']=$understanding['status']??null;
            $result['requirements']=array_map(static function(array $item):array {return [
                'fields'=>$item['fields']??[], 'operation'=>$item['values']['operation']??null,
                'object_kind'=>$item['values']['object_kind']??null,
                'ranking'=>$item['values']['ranking']??null,
            ];},$understanding['requirements']??[]);
            $result['input_tokens']=$response['usage']['input_tokens']??null;
            $result['output_tokens']=$response['usage']['output_tokens']??null;
        } catch (Throwable $error) { $result['error']=$error->getMessage(); }
        $result['elapsed_ms']=(int)round((microtime(true)-$start)*1000);
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    }
}
