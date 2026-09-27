<?php
/** Local R50 planner integration: existing registry only; no business writes. */
if (getenv('MOHE_R50_LOCAL_TEST')!=='LOCAL_READ_ONLY') exit("LOCAL_READ_ONLY required\n");
require getcwd().'/vendor/autoload.php';
(new think\App())->initialize();
$db=(array)config('database.connections.'.config('database.default'));
if (($db['hostname']??null)!=='mysql') throw new RuntimeException('LOCAL_DATABASE_REQUIRED');
$gateway=new app\services\ai\AiGatewayServices();$reflection=new ReflectionClass($gateway);
foreach (['initialize','analysisObjectVocabulary','compileExactRegisteredRankingCollection'] as $name) {
    $methods[$name]=$reflection->getMethod($name);$methods[$name]->setAccessible(true);
}
$methods['initialize']->invoke($gateway);
$cap=app\services\query\metric\MetricReadViewServices::metricCapabilities();
$caps=['metric_codes'=>array_keys($cap),'metric_readiness'=>$cap,'query_shapes'=>['summary','breakdown','ranking'],'output_formats'=>['screen']];
$vocabulary=$methods['analysisObjectVocabulary']->invoke($gateway,$caps,null);
$reason=null;
$args=['2026年3月到今天，最高营业额是哪个门店',$vocabulary,$caps,'screen','2026-09-27',&$reason,null];
$plan=$methods['compileExactRegisteredRankingCollection']->invokeArgs($gateway,$args);
if (!$plan) throw new RuntimeException('R50_ORIGINAL_FAST_PATH_MISSING');
$query=$plan['plan']['query'];
if ($query['start_date']!=='2026-03-01' || $query['end_date']!=='2026-09-27' || $query['metric_codes']!==['cash_performance']) throw new RuntimeException('R50_FULL_MEANING_LOST');
$coverageRejected=false;
try { app\services\query\metric\MetricQueryDatePolicy::assertExecutable(['start'=>$query['start_date'],'end'=>$query['end_date']],app\services\query\metric\MetricDefinitionRegistry::COVERAGE_START,'2026-09-27'); }
catch (app\services\query\metric\MetricQueryContractException $e) {$coverageRejected=$e->getErrorCode()==='METRIC_QUERY_COVERAGE_UNAVAILABLE';}
if (!$coverageRejected) throw new RuntimeException('R50_COVERAGE_BOUNDARY_LOST');
// The covered-period counterpart uses the real unified Reader, never fixture
// money or a model answer. Creating an expiring read view writes no business facts.
$resolver=new app\services\ai\execution\AiPlatformPrincipalResolver();
$id=(int)think\facade\Db::name('system_admin')->where('account','admin')->value('id');
$context=$resolver->authenticated($id);
$context['_refresh']=static function()use($resolver,$id):array{return $resolver->authenticated($id);};
$serviceMethod=$reflection->getMethod('queryService');$serviceMethod->setAccessible(true);
$service=$serviceMethod->invoke($gateway,$context);
$args[0]='2026年9月到今天，最高营业额是哪个门店';
$monthly=$methods['compileExactRegisteredRankingCollection']->invokeArgs($gateway,$args);
$compiled=(new app\services\ai\execution\AiRegisteredPlanCompiler())->compile($monthly['plan'],$caps);
$view=$service->create($context,$compiled['query']);
if ($view['query']['start_date']!=='2026-09-01' || $view['query']['end_date']!=='2026-09-27' || count($view['results'][0]['rows']['top'])!==1) throw new RuntimeException('R50_REAL_QUERY_FAILED');
echo json_encode(['result'=>'PASS','original_range'=>[$query['start_date'],$query['end_date']],'metric'=>$query['metric_codes'][0],'coverage_guard'=>'PASS','real_month_ranking'=>'PASS','business_data_mutated'=>false],JSON_UNESCAPED_UNICODE),PHP_EOL;
