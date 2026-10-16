<?php
// An unbound name may improve a clarification, but cannot become an identity
// or let the gateway drop a customer's other business conditions.
require_once __DIR__.'/fixture-autoload.php';

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};
$entries=\app\services\query\metric\MetricSemanticCatalog::entries();
$admission=new \app\services\ai\semantic\AiDeterministicSummaryAdmission();
$check($admission->unresolvedNamedPerformanceTarget('管慧的业绩是多少？',$entries)==='管慧',
    'complete named-performance question retains only the unbound name');
foreach (['今天的业绩是多少？','所有门店的业绩是多少？','管慧的劳动业绩是多少？',
    '管慧的业绩比昨天高多少？','管慧和李红的业绩是多少？'] as $question) {
    $check($admission->unresolvedNamedPerformanceTarget($question,$entries)===null,
        'additional or generic meaning remains on its established path: '.$question);
}

$gateway=(new ReflectionClass(\app\services\ai\AiGatewayServices::class))->newInstanceWithoutConstructor();
$cardMethod=new ReflectionMethod($gateway,'semanticClarification');
$meaning=['status'=>'needs_clarification','requirements'=>[
    ['values'=>['metric_terms'=>['业绩'],'object_kind'=>'person']]]];
$body=['question'=>'管慧的业绩是多少？','_platform_guidance'=>true];
$safe=['outbound'=>['question'=>'某员工的业绩是多少？'],'reference_values'=>[]];
$card=$cardMethod->invoke($gateway,$meaning,$safe,$body);
$check(($card['kind']??null)==='clarification'
    && strpos($card['question']??'','管慧')!==false
    && strpos($card['question']??'','所属门店')!==false,
    'platform card tells the customer which object needs correction');
$check(($card['fields'][0]['label']??null)==='查询对象'
    && ($card['fields'][0]['options'][0]['value']??null)==='__supplement__'
    && ($card['_semantic_recommendations']??null)===[],
    'same Run accepts text without inventing an executable identity');
$cashier=$cardMethod->invoke($gateway,$meaning,$safe,['question'=>$body['question'],'_platform_guidance'=>false]);
$check(($cashier['question']??null)==='我已理解部分条件，请选择您想查询的内容，或补充描述。',
    'unapproved cashier drawer remains unchanged');
$ordinary=$cardMethod->invoke($gateway,$meaning,$safe,['question'=>'今天的业绩是多少？','_platform_guidance'=>true]);
$check(($ordinary['fields'][0]['label']??null)==='查询内容',
    'ordinary summary does not become an unmatched-name card');

echo 'R8E unresolved named performance guidance: '.$checks.' checks PASS'.PHP_EOL;
