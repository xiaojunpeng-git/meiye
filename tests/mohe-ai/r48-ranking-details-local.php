<?php
/** R48 local integration: reads existing facts only; writes only expiring
 * encrypted read-view artifacts. Never calls a model or changes business data. */
if (getenv('MOHE_R48_LOCAL_TEST')!=='LOCAL_READ_ONLY') exit("LOCAL_READ_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=$resolver->authenticated($id);
$context['_refresh']=static function()use($resolver,$id):array {return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();
$reflection=new ReflectionClass($gateway);
foreach (['initialize','queryService','analysisObjectVocabulary','compileExactRegisteredRankingCollection'] as $name) {
    $methods[$name]=$reflection->getMethod($name);$methods[$name]->setAccessible(true);
}
$methods['initialize']->invoke($gateway);
$service=$methods['queryService']->invoke($gateway,$context);
$cap=app\services\query\metric\MetricReadViewServices::metricCapabilities();
$capabilities=['metric_codes'=>array_keys($cap),'metric_readiness'=>$cap,'query_shapes'=>['summary','breakdown','ranking'],'output_formats'=>['screen']];
$vocabulary=$methods['analysisObjectVocabulary']->invoke($gateway,$capabilities,null);
$compiler=new app\services\ai\execution\AiRegisteredPlanCompiler();
$renderer=new app\services\ai\presentation\AiAnswerRenderer();
$checks=0;
$check=static function(bool $pass,string $label)use(&$checks):void {if(!$pass)throw new RuntimeException($label);++$checks;};
foreach (['这个月门店业绩前三的门店详情','这个月员工业绩前三名详情'] as $question) {
    $reason=null;$args=[$question,$vocabulary,$capabilities,'screen',date('Y-m-d'),&$reason];
    $plan=$methods['compileExactRegisteredRankingCollection']->invokeArgs($gateway,$args);
    $compiled=$compiler->compile($plan['plan'],$capabilities);
    $view=$service->create($context,$compiled['query']);
    $rows=$view['results'][0]['rows']['top'];
    $check(count($rows)===3,'THREE_RANKED_OBJECTS');
    $details=$view['results'][0]['ranking_presentation_metrics'];
    $check(count($details)>=2,'REAL_DETAIL_COLUMNS');
    $kind=$view['query']['business_filters']['object_kind']??'store';
    foreach ($rows as $row) {
        $entityId=$row[$kind==='person'?'employee_id':'store_id'];
        // Independent selected-object query confirms every displayed amount
        // belongs to the same authorised object and period, including primary.
        $q=$view['query'];$q['query_shape']='summary';$q['ranking']=null;$q['ranking_presentation_metrics']=[];
        $q['metric_codes']=array_merge([$q['metric_codes'][0]],array_column($details,'metric_code'));
        if ($kind==='person') $q['business_filters']=['object_kind'=>'person','selection_ref'=>'person:'.$entityId];
        else $q['store_ids']=[$entityId];
        $single=$service->create($context,$q);
        $check(($single['results'][0]['amount_cents']??$single['results'][0]['count'])===$row['amount_cents'],'PRIMARY_PARITY');
        foreach ($details as $index=>$detail) {
            $values=array_column($detail['values'],'metric_value','entity_id');
            $result=$single['results'][$index+1];
            $check(($result['amount_cents']??$result['count'])===$values[$entityId],'DETAIL_PARITY');
        }
    }
    $answer=$renderer->render($view);
    $check(count($answer['table']['rows'])===3,'THREE_VISIBLE_ROWS');
    $export=app\services\query\metric\MetricReadViewExportProvider::project($view);
    $check(count($export)===3*(count($details)+1),'EXPORT_ALL_DETAIL_METRICS');
    // A plain ranking can later reveal its original detail snapshot without
    // replacing the set, rounding storage values or extending expiration.
    $plain=$view['query'];$plain['ranking_presentation_metrics']=[];
    $plainView=$service->create($context,$plain);
    $expanded=$service->rankingDetails($context,$plainView['query'],$plainView['read_consistency_ref']);
    $check($expanded['results'][0]['rows']===$plainView['results'][0]['rows'],'FOLLOWUP_FROZEN_IDENTITIES');
    $check($expanded['expires_at']===$plainView['expires_at'],'FOLLOWUP_NO_EXPIRY_EXTENSION');
    $check($expanded['results'][0]['ranking_presentation_metrics']===$plainView['results'][0]['ranking_detail_metrics'],'FOLLOWUP_FROZEN_VALUES');
    $bad=$view['query'];$bad['ranking_presentation_metrics']=['cash_performance','staff_labor_yeji'];
    try {$service->create($context,$bad);throw new LogicException('FORGED_PROFILE_ACCEPTED');}
    catch (app\services\query\metric\MetricQueryContractException $e) {$check(true,'FORGED_PROFILE_REJECTED');}
}
echo json_encode(['round'=>'R48','checks'=>$checks,'result'=>'PASS','business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
