<?php
/** R49 local integration uses existing facts and expiring read views only.
 * It verifies dimension switches, shared rendering/export and frozen details;
 * it never calls a model or writes business data. */
if (getenv('MOHE_R49_LOCAL_TEST')!=='LOCAL_READ_ONLY') exit("LOCAL_READ_ONLY opt-in required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=$resolver->authenticated($id);
$context['_refresh']=static function()use($resolver,$id):array{return $resolver->authenticated($id);};
$gateway=new app\services\ai\AiGatewayServices();$reflection=new ReflectionClass($gateway);
foreach (['initialize','queryService','analysisObjectVocabulary','compileExactRegisteredRankingCollection'] as $name) {
    $methods[$name]=$reflection->getMethod($name);$methods[$name]->setAccessible(true);
}
$methods['initialize']->invoke($gateway);
$service=$methods['queryService']->invoke($gateway,$context);
$cap=app\services\query\metric\MetricReadViewServices::metricCapabilities();
$caps=['metric_codes'=>array_keys($cap),'metric_readiness'=>$cap,'query_shapes'=>['summary','breakdown','ranking'],'output_formats'=>['screen']];
$vocabulary=$methods['analysisObjectVocabulary']->invoke($gateway,$caps,null);
$compiler=new app\services\ai\execution\AiRegisteredPlanCompiler();
$renderer=new app\services\ai\presentation\AiAnswerRenderer();
$checks=0;$source=null;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};
foreach (['门店'=>'store','员工'=>'person','项目'=>'project','产品'=>'product','会员'=>'member'] as $label=>$kind) {
    $question=($source===null?'这个月':'').$label.'业绩最高的前五名详情';
    $reason=null;$args=[$question,$vocabulary,$caps,'screen',date('Y-m-d'),&$reason,$source];
    $plan=$methods['compileExactRegisteredRankingCollection']->invokeArgs($gateway,$args);
    $compiled=$compiler->compile($plan['plan'],$caps);
    $view=$service->create($context,$compiled['query']);
    $check($view['query']['start_date']===date('Y-m-01'),'MONTH_INHERITED_'.$kind);
    $check(($view['query']['business_filters']['object_kind']??'store')===$kind,'OBJECT_REPLACED_'.$kind);
    $answer=$renderer->render($view);$rows=$view['results'][0]['rows']['top'];
    $check(count($rows)>0 && count($rows)<=5,'BOUNDED_REAL_ROWS_'.$kind);
    $check(count($answer['table']['rows'])===count($rows),'RENDERED_IDENTITIES_'.$kind);
    $export=app\services\query\metric\MetricReadViewExportProvider::project($view);
    $check(count($export)===count($rows)*(1+count($view['results'][0]['ranking_presentation_metrics'])),'EXPORT_COLUMNS_'.$kind);
    if ($kind==='member') {
        $members=(new app\services\ai\context\MemberDetailContinuationResolver())->resolve($view['query'],$view,['target'=>'set']);
        $check(count($members['members'])===count($rows),'EXACT_MEMBER_SET');
    } else {
        $expanded=$service->rankingDetails($context,$view['query'],$view['read_consistency_ref']);
        $check($expanded['results'][0]['rows']===$view['results'][0]['rows'],'FROZEN_IDENTITIES_'.$kind);
        $check($expanded['expires_at']===$view['expires_at'],'NO_EXPIRY_EXTENSION_'.$kind);
    }
    $source=['query'=>$view['query'],'view'=>$view];
}
echo json_encode(['round'=>'R49','checks'=>$checks,'result'=>'PASS','business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
