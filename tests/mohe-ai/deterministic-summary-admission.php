<?php
// The direct path must prove the whole sentence against registered language;
// ambiguous, additional and context-bearing wording continues to the model.
require_once __DIR__.'/fixture-autoload.php';

$gate=new \app\services\ai\semantic\AiDeterministicSummaryAdmission();
$entries=\app\services\query\metric\MetricSemanticCatalog::entries();
$checks=0;
$assert=function(bool $ok,string $case)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$case);
    ++$checks;
};

foreach ([
    '今天现金业绩多少'=>['cash_performance','TODAY'],
    '今天的现金业绩是多少？'=>['cash_performance','TODAY'],
    '本月销售额'=>['sales_amount','THIS_MONTH'],
    '这个月的现金业绩是多少'=>['cash_performance','THIS_MONTH'],
    '昨天现金业绩多少'=>['cash_performance','YESTERDAY'],
    '2026-09-23现金业绩多少'=>['cash_performance','EXPLICIT'],
] as $question=>[$metric,$date]) {
    $match=$gate->match($question,$entries);
    $assert(($match['metric_code']??null)===$metric && ($match['date_term']['code']??null)===$date,$question);
}
foreach ([
    '今日完成服务项目数量',              // One title has different store/person owners.
    '今天现金业绩和退款业绩是多少',      // A second independent metric cannot be omitted.
    '今天现金业绩比昨天高多少',          // Two periods plus a comparison.
    '今天现金业绩按门店分别是多少',      // A grouping changes the answer shape.
    '今天现金业绩只看一号门店',          // A store filter must be resolved.
    '今天现金业绩多少，顺便解释原因',    // Residual meaning cannot be stripped.
    '现金业绩多少',                    // Direct admission requires a period.
] as $question) $assert($gate->match($question,$entries)===null,$question);

$synthetic=[
    'long'=>['terms'=>['完整销售额'],'ai_query_ready'=>true],
    'short'=>['terms'=>['退款额'],'ai_query_ready'=>true],
];
$assert($gate->match('今天完整销售额和退款额多少',$synthetic)===null,'distinct shorter metric is not hidden by the longest phrase');
$synthetic['other']=['terms'=>['完整销售额'],'ai_query_ready'=>false];
$assert($gate->match('今天完整销售额多少',$synthetic)===null,'unready owner does not remove semantic ambiguity');

foreach (['这个月呢'=>'THIS_MONTH','改成昨天'=>'YESTERDAY','那上个月呢？'=>'LAST_MONTH'] as $question=>$date) {
    $assert(($gate->periodOnly($question)['code']??null)===$date,'closed signed-context period '.$question);
}
foreach (['这个月销售额呢？','今天和昨天','今天经营得怎么样','这个月按门店看'] as $question) {
    $assert($gate->periodOnly($question)===null,'period edit leaves business meaning to the model '.$question);
}

echo 'Deterministic summary admission: '.$checks.' checks PASS'.PHP_EOL;
