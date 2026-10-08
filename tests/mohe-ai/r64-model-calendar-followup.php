<?php
// R64 synthetic end-to-end regression: one signed query, model-understood
// time ellipses, and a topic-change counterexample. No customer DB or API.
require __DIR__.'/r6-gateway-harness.php';

$checks=0;
$check=static function(bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('R64 calendar follow-up: '.$label);
    $checks++;
};
$understanding=static function(string $question,string $unit,int $offset): array {
    return ['goal'=>'更换统计时间','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>$question,'fields'=>['periods'],
        'values'=>['periods'=>[['kind'=>'calendar_period','unit'=>$unit,
            'offset'=>$offset,'count'=>1,'end_mode'=>'full']]],
        'evidence'=>[['message_id'=>'current','quote'=>$question]],
    ]]];
};
$harness=new R6GatewayHarness(3,[1,2],'platform');
try {
    $first=$harness->start('昨天现金业绩是多少');
    $check(($first['status']??null)==='COMPLETED' && !empty($first['answer']['context_ref']),
        'the first answer provides one verified cash-performance context');
    $harness->understandingOverride=$understanding('上周呢','week',-1);
    $week=$harness->start('上周呢',$first['answer']['context_ref']);
    $reference=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    $weekRange=\app\services\ai\execution\AiTemporalPeriodResolver::resolve(
        ['kind'=>'calendar_period','unit'=>'week','offset'=>-1,'count'=>1,'end_mode'=>'full'],$reference);
    $weekSummary=(string)($week['answer']['summary']??'');
    $check(($week['status']??null)==='COMPLETED' && str_contains($weekSummary,'现金业绩')
        && str_contains($weekSummary,$weekRange['start'].' 至 '.$weekRange['end']),
        'a model-understood week changes only the date and returns the inherited metric');
    $check($harness->models===1 && $harness->queries===2,
        'the accepted time continuation avoids a second binding-model round');

    $harness->understandingOverride=$understanding('上个季度呢','quarter',-1);
    $quarter=$harness->start('上个季度呢',$week['answer']['context_ref']??null);
    $quarterRange=\app\services\ai\execution\AiTemporalPeriodResolver::resolve(
        ['kind'=>'calendar_period','unit'=>'quarter','offset'=>-1,'count'=>1,'end_mode'=>'full'],$reference);
    $quarterSummary=(string)($quarter['answer']['summary']??'');
    $check(($quarter['status']??null)==='COMPLETED' && str_contains($quarterSummary,'现金业绩')
        && str_contains($quarterSummary,$quarterRange['start'].' 至 '.$quarterRange['end']),
        'a second model-understood time follow-up preserves the same verified metric');

    // A provider that drops a newly stated member subject must fail before
    // querying, even if it returns a well-formed calendar-period carrier.
    $beforeUnsafeQueryCount=$harness->queries;
    $harness->understandingOverride=$understanding('上周会员有多少','week',-1);
    $unsafe=$harness->start('上周会员有多少',$quarter['answer']['context_ref']??null);
    $check(($unsafe['status']??null)!=='COMPLETED' && $harness->queries===$beforeUnsafeQueryCount,
        'a new member question cannot silently reuse the old cash-performance query');
} finally {
    $harness->close();
}
echo "PASS R64 model calendar follow-up: {$checks} checks\n";
