<?php
// Registry-only contract checks: no application boot, database or model call.
require_once __DIR__ . '/fixture-autoload.php';

use app\services\ai\execution\AiOverviewMetricResolver;
use app\services\query\metric\MetricDefinitionRegistry;

$passed=0; $failed=[];
$check=static function(bool $ok,string $label) use (&$passed,&$failed): void { if ($ok) ++$passed; else $failed[]=$label; };
$capabilities=['metric_readiness'=>MetricDefinitionRegistry::capabilities()];
$codes=static function(string $objectKind) use ($capabilities): array {
    return array_column(AiOverviewMetricResolver::resolve($capabilities,$objectKind),'metric_code');
};

$store=$codes('store');
$check(in_array('cash_performance',$store,true) && in_array('actual_performance',$store,true)
    && in_array('sales_amount',$store,true) && in_array('completed_service_item_count',$store,true),
    'store overview contains only registry-declared operational facts');
$check(!in_array('staff_sales_yeji',$store,true),'person-grain metrics never leak into a store overview');
$project=$codes('project'); sort($project);
$check($project===['completed_service_item_count','sales_amount','sales_quantity'],
    'project overview uses only registered project-source facts in declaration order');
$product=$codes('product'); sort($product);
$check($product===['sales_amount','sales_quantity'],
    'product overview excludes metrics without a registered product contract');
$check($codes('category')===[],'an object without a declared overview profile remains unavailable');
$check(MetricDefinitionRegistry::overviewObjectLabel('store')==='经营'
    && MetricDefinitionRegistry::overviewObjectLabel('project')==='项目'
    && MetricDefinitionRegistry::overviewObjectLabel('product')==='产品'
    && MetricDefinitionRegistry::overviewObjectLabel('category')===null,
    'overview headings use the registered object label instead of renderer object branches');
$salesAmount=MetricDefinitionRegistry::capabilities()['sales_amount'];
$salesAmountSections=AiOverviewMetricResolver::sectionNamesForMetric($salesAmount);
$check(in_array('销售结果',$salesAmountSections,true) && in_array('项目销售',$salesAmountSections,true)
    && count($salesAmountSections)===4,
    'overview section language is projected from the registered metric contract');

$tooMany=$capabilities;
for ($i=0;$i<=AiOverviewMetricResolver::MAX_METRICS;$i++) {
    $tooMany['metric_readiness']['fixture_'.$i]=[
        'ai_query_ready'=>true,'query_shapes'=>['summary'],'filter_grain'=>'store','business_filters'=>[],
        'overview'=>[['object_kind'=>'store','section'=>'测试','order'=>$i+1]],'analysis_dimension_contracts'=>[],
    ];
}
try { AiOverviewMetricResolver::resolve($tooMany,'store'); $check(false,'profile capacity rejects excess declarations'); }
catch (RuntimeException $error) { $check($error->getMessage()==='AI_OVERVIEW_CAPACITY_EXCEEDED','profile capacity is bounded before execution'); }

foreach ($failed as $label) echo 'FAIL '.$label."\n";
echo 'overview-metric-resolver: '.$passed.' passed, '.count($failed)." failed\n";
exit($failed?1:0);
