<?php
/** Round 27 contract: one ranking metric may add registry-owned evidence columns. */
require_once __DIR__ . '/fixture-autoload.php';

use app\services\ai\execution\AiRankingPresentationMetricResolver;
use app\services\ai\presentation\AiAnswerRenderer;
use app\services\query\metric\MetricReadViewServices;

$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $checks++;
};
$capabilities=MetricReadViewServices::metricCapabilities();
$project=AiRankingPresentationMetricResolver::resolve($capabilities,'sales_amount','project');
$check($project===['sales_amount','completed_service_item_count','sales_quantity'],
    'project sales ranking keeps sales amount as the sole sort and exposes registered service and quantity evidence');
$card=AiRankingPresentationMetricResolver::resolve($capabilities,'sales_amount','card');
$check($card===['sales_amount','sales_quantity'],
    'card sales ranking gains only its compatible registered quantity column');
$check(AiRankingPresentationMetricResolver::resolve($capabilities,'cash_performance','member')===['cash_performance'],
    'a metric without a compatible fact-batch display contract stays a one-metric ranking');
$narrative=new ReflectionMethod(AiAnswerRenderer::class,'rankingNarrative');
$rows=[];
for ($rank=1;$rank<=5;$rank++) $rows[]=['rank'=>'第'.$rank.'名','label'=>'对象'.$rank,
    'metric'=>'销售额','value'=>(string)$rank,'unit'=>'元'];
$summary=$narrative->invoke(new AiAnswerRenderer(),$rows);
$check(str_contains($summary,'共列出5条排名结果') && str_contains($summary,'第1名'),
    'five-row ranking summary announces the complete list size while the table retains all rows');
$both=[];
for ($rank=1;$rank<=20;$rank++) {
    $both[]=['rank'=>'第'.$rank.'名','rank_direction'=>'top','label'=>'高'.$rank,'metric'=>'现金业绩','value'=>(string)(100-$rank),'unit'=>'元'];
    $both[]=['rank'=>'倒数第'.$rank.'名','rank_direction'=>'bottom','label'=>'低'.$rank,'metric'=>'现金业绩','value'=>(string)$rank,'unit'=>'元'];
}
$bothSummary=$narrative->invoke(new AiAnswerRenderer(),$both);
$check(str_contains($bothSummary,'第1名是高1') && str_contains($bothSummary,'倒数第1名是低1'),
    'two-sided ranking states both verified extremes even when its full table exceeds the preview');
$preview=AiAnswerRenderer::limitTables(['summary'=>$bothSummary,'table'=>['rows'=>$both]]);
$check(count($preview['table']['rows'])===20 && ($preview['table']['rows'][0]['rank_direction']??null)==='top'
    && ($preview['table']['rows'][1]['rank_direction']??null)==='bottom'
    && ($preview['table']['total_rows']??null)===40 && ($preview['auto_export_required']??null)===true,
    'two-sided interleaved order retains both extremes in the bounded screen table and complete export');
echo "PASS r27 ranking presentation: {$checks} checks\n";
