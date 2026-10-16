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

$stores=[
    ['ref'=>'store:179','kind'=>'store','label'=>'扬州大润发店','aliases'=>[]],
    ['ref'=>'store:203','kind'=>'store','label'=>'霍山文峰店','aliases'=>[]],
];
foreach ([
    '扬州大润发店本月的业绩是多少?消耗多少?'=>['cash_performance','consume_amount'],
    '扬州大润发本月的业绩是多少?消耗多少?'=>['cash_performance','consume_amount'],
    '扬州大润发店本月业绩和消耗多少？'=>['cash_performance','consume_amount'],
    '扬州大润发店本月现金业绩和消耗业绩分别是多少？'=>['cash_performance','consume_amount'],
    '扬州大润发店本月现金业绩、消耗业绩和退款业绩分别是多少？'=>['cash_performance','consume_amount','refund_performance'],
] as $question=>$metrics) {
    $match=$gate->matchQuery($question,$entries,$stores,12);
    $assert(($match['metric_codes']??[])===$metrics && ($match['date_term']['code']??null)==='THIS_MONTH'
        && ($match['store_id']??null)===179,'closed named-store multi metric '.$question);
}
$compound=$gate->matchQuery('本月实际业绩多少',$entries,[],12);
$assert(($compound['metric_codes']??[])===['actual_performance'],'longer actual performance owns its complete title');
$labor=$gate->matchQuery('本月劳动业绩多少',$entries,[],12);
$assert(($labor['metric_codes']??[])===['staff_labor_yeji'],'longer labor performance owns its complete title');
foreach ([
    '扬州大润本月现金业绩和消耗业绩是多少？', // Arbitrary partial names are not executable.
    '无权门店本月现金业绩和消耗业绩是多少？', // Unknown stores stay unresolved.
    '扬州大润发店今天现金业绩和昨天消耗业绩是多少？', // Separate windows need semantic binding.
    '扬州大润发店本月现金业绩和消耗业绩环比是多少？', // Residual operators are not ignored.
] as $question) $assert($gate->matchQuery($question,$entries,$stores,12)===null,'multi metric fail closed '.$question);
$duplicateStores=array_merge($stores,[['ref'=>'store:299','kind'=>'store','label'=>'扬州大润发店','aliases'=>[]]]);
$assert($gate->matchQuery('扬州大润发店本月现金业绩多少',$entries,$duplicateStores,12)===null,
    'duplicate authorized store labels require clarification');
$shortCollision=array_merge($stores,[['ref'=>'store:299','kind'=>'store','label'=>'扬州大润发门店','aliases'=>[]]]);
$assert($gate->matchQuery('扬州大润发本月现金业绩多少',$entries,$shortCollision,12)===null,
    'duplicate suffix-less store labels require clarification');
$aliasCollision=array_merge($stores,[['ref'=>'store:299','kind'=>'store','label'=>'三号门店','aliases'=>['扬州大润发']]]);
$assert($gate->matchQuery('扬州大润发本月现金业绩多少',$entries,$aliasCollision,12)===null,
    'configured alias ambiguity outranks a derived suffix-less label');

$missing=$gate->matchMissingPeriod('扬州大润发的业绩是多少？',$entries,$stores,12);
$assert(($missing['metric_codes']??[])===['cash_performance'] && ($missing['store_id']??null)===179,
    'unique authorized suffix-less store preserves metric while requesting period');
foreach ([
    '现金业绩是多少？',                 // A store is mandatory for this bounded card.
    '扬州大润发今天的业绩是多少？',     // An explicit period belongs to the executable matcher.
    '扬州大润的业绩是多少？',           // Arbitrary partial names remain model work.
    '无权门店的业绩是多少？',           // Unknown stores never become executable identifiers.
    '扬州大润发的业绩环比是多少？',     // A second missing business condition is not discarded.
] as $question) $assert($gate->matchMissingPeriod($question,$entries,$stores,12)===null,
    'missing-period admission fails closed '.$question);
$assert($gate->matchMissingPeriod('扬州大润发的业绩是多少？',$entries,$shortCollision,12)===null,
    'ambiguous suffix-less store cannot open a period card');
$assert($gate->mayContainNamedObject('扬州大润发的业绩是多少？',$entries),
    'leading suffix-less store opens an authorized catalogue proof');
$assert($gate->mayContainNamedObject('扬州大润发本月业绩是多少？',$entries),
    'leading store before an explicit period opens an authorized catalogue proof');
foreach (['业绩怎么样','今天现金业绩多少','现金业绩是多少？'] as $question) {
    $assert(!$gate->mayContainNamedObject($question,$entries),'ordinary metric question skips store catalogue '.$question);
}
$assert($gate->mayContainNamedObject('本月扬州大润发业绩是多少？',$entries),
    'leading calendar still permits exact named-object proof');
$assert($gate->mayContainNamedObject('本月业绩，扬州大润发是多少？',$entries),
    'name after metric still permits exact authorized-object proof');

$people=[
    ['ref'=>'person:714','kind'=>'person','label'=>'管慧','aliases'=>[]],
    ['ref'=>'person:922','kind'=>'person','label'=>'李红（甲店）','aliases'=>['李红']],
    ['ref'=>'person:923','kind'=>'person','label'=>'李红（乙店）','aliases'=>['李红']],
];
foreach (['管慧的业绩是多少？'=>null,'本月管慧的业绩是多少？'=>'THIS_MONTH',
    '管慧本月现金业绩是多少？'=>'THIS_MONTH','本月管慧销售业绩是多少？'=>'THIS_MONTH',
    '本月业绩，管慧是多少？'=>'THIS_MONTH'] as $question=>$period) {
    $named=$gate->matchNamedPerson($question,$entries,$people);
    $assert(($named['metric_codes']??[])===['staff_sales_yeji']
        && ($named['objects'][0]['ref']??null)==='person:714'
        && ($named['date_term']['code']??null)===$period,'named employee cash performance '.$question);
}
$duplicate=$gate->matchNamedPerson('李红的业绩是多少？',$entries,$people);
$assert(count($duplicate['objects']??[])===2,'duplicate authorized names require an employee choice');
foreach (['管慧的业绩比昨天高多少？','管慧的劳动业绩是多少？','无权员工的业绩是多少？',
    '管慧和李红的业绩是多少？'] as $question) {
    $assert($gate->matchNamedPerson($question,$entries,$people)===null,
        'named employee proof rejects additional meaning '.$question);
}

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
