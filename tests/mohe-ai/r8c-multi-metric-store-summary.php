<?php
// End-to-end synthetic gateway proof for the reported store/month question.
// It uses the normal permission catalogue, registered metric reader and answer
// renderer; only facts are synthetic, so no customer or production data moves.
require_once __DIR__.'/r6-gateway-harness.php';

$checks=0;
$assert=static function(bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};

$harness=new R6GatewayHarness(3,[1,2],'platform');
try {
    $answer=$harness->start('一号门店本月的业绩是多少?消耗多少?');
    $assert(($answer['status']??null)==='COMPLETED','reported wording completes');
    $assert($harness->models===0,'closed registered question does not wait for a model');
    $assert($harness->queries===2,'one authorized-store lookup and one registered metric query');
    $facts=(array)($answer['answer']['presentation']['facts']??[]);
    $labels=array_column($facts,'label');
    $assert($labels===['现金业绩','消耗业绩'],'unqualified performance and consume map to both requested metrics');
    $assert(strpos((string)($answer['answer']['summary']??''),'现金业绩')!==false
        && strpos((string)($answer['answer']['summary']??''),'消耗业绩')!==false,
        'answer text preserves every requested metric');
    $row=$harness->row($answer);
    $assert(strpos((string)($row['counters_json']??''),'deterministic_registered_store_summary_admitted')!==false,
        'store multi-metric admission is auditable without storing question text');

    $prior=$harness->start('今天现金业绩多少');
    $followup=$harness->start('一号门店本月的业绩是多少?消耗多少?',$prior['answer']['context_ref']);
    $assert(($followup['status']??null)==='COMPLETED' && ($followup['topic_boundary']??null)==='reset',
        'self-contained named-store question resets a previously signed scope');
    $evidence=$harness->private->read($harness->row($followup)['evidence_ref']);
    $assert(($evidence['query']['store_ids']??[])===[1]
        && ($evidence['query']['metric_codes']??[])===['cash_performance','consume_amount'],
        'reset query retains the exact authorized store and every stated metric');
    $assert($harness->models===0,'context reset also avoids a model call');
} finally {
    $harness->close();
}

echo 'R8C multi-metric store summary: '.$checks.' checks PASS'.PHP_EOL;
