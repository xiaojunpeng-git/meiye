<?php
/** Offline guard for registry-vocabulary prompt reduction; no provider call. */
require_once __DIR__.'/fixture-autoload.php';

$method=new ReflectionMethod(app\services\ai\model\SiliconFlowClient::class,'focusedMeasurementVocabulary');
if (PHP_VERSION_ID<80100) $method->setAccessible(true);
$items=[
    ['measurement_label'=>'现金业绩','customer_terms'=>['现金业绩','营业额'],'meaning'=>'已收金额','analytical_object_kinds'=>['store']],
    ['measurement_label'=>'消耗业绩','customer_terms'=>['消耗业绩'],'meaning'=>'服务消耗','analytical_object_kinds'=>['store']],
    ['measurement_label'=>'退款业绩','customer_terms'=>['退款业绩'],'meaning'=>'现金退款','analytical_object_kinds'=>['store']],
    ['measurement_label'=>'服务人次','customer_terms'=>['服务人次','客次'],'meaning'=>'有效服务','analytical_object_kinds'=>['person']],
];
$call=static function(string $question,$prior=null)use($method,$items):array {
    return $method->invoke(null,$items,['question'=>$question,'prior_query'=>$prior]);
};
$check=static function(bool $ok,string $name):void {if (!$ok) throw new RuntimeException($name);};
$focused=$call('这个月各门店现金业绩、消耗业绩、退款业绩分别是什么');
$check(array_column($focused,'measurement_label')===['现金业绩','消耗业绩','退款业绩'],
    'all literal measurements remain while unrelated vocabulary is omitted');
$check(array_column($call('营业额最高的门店'),'measurement_label')===['现金业绩'],
    'a registered natural-language alias retains its owner');
$check($call('哪位员工做得最好')===$items,'unknown first-turn wording retains complete vocabulary');
$check($call('现金业绩呢',['operation'=>'ranking'])===$items,
    'a contextual continuation retains complete vocabulary instead of dropping inherited meaning');
echo "PASS model vocabulary focus: 4 checks\n";
