<?php
require __DIR__.'/fixture-autoload.php';

use app\services\ai\AiGatewayServices;

$checks=0;
$check=static function(bool $value,string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('binding object capability boundary: '.$label);
    ++$checks;
};
$method=(new ReflectionClass(AiGatewayServices::class))->getMethod('bindingSummariesForUnderstanding');
$gateway=(new ReflectionClass(AiGatewayServices::class))->newInstanceWithoutConstructor();
$summaries=[
    ['metric_code'=>'cash_performance','name'=>'现金业绩','summary'=>'门店记账收款','object_contracts'=>[['object_kind'=>'store','action_codes'=>[]]]],
    ['metric_code'=>'staff_sales_yeji','name'=>'销售人业绩','summary'=>'销售人分配的人员现金业绩','object_contracts'=>[['object_kind'=>'person','action_codes'=>[]]]],
    ['metric_code'=>'staff_labor_yeji','name'=>'劳动业绩','summary'=>'手艺人劳动业绩','object_contracts'=>[['object_kind'=>'person','action_codes'=>[]]]],
    ['metric_code'=>'sales_amount','name'=>'销售额','summary'=>'完成销售明细金额','object_contracts'=>[['object_kind'=>'store','action_codes'=>[]],['object_kind'=>'project','action_codes'=>['sales']],['object_kind'=>'product','action_codes'=>['sales']]]],
    ['metric_code'=>'sales_quantity','name'=>'销售数量','summary'=>'完成销售明细数量','object_contracts'=>[['object_kind'=>'store','action_codes'=>[]],['object_kind'=>'project','action_codes'=>['sales']],['object_kind'=>'product','action_codes'=>['sales']]]],
];
$personUnderstanding=['goal'=>'找人员第一名','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较人员','fields'=>['object_kind'],'values'=>['object_kind'=>'person'],'evidence'=>[['message_id'=>'current','quote'=>'人员']]],
]];
$person=$method->invoke($gateway,$summaries,$personUnderstanding);
$check(array_column($person,'metric_code')===['staff_sales_yeji','staff_labor_yeji'],
    'accepted person object receives only registry-declared person metrics for binding');
$storeUnderstanding=['goal'=>'看门店','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较门店','fields'=>['object_kind'],'values'=>['object_kind'=>'store'],'evidence'=>[['message_id'=>'current','quote'=>'门店']]],
]];
$store=$method->invoke($gateway,$summaries,$storeUnderstanding);
$check($store===$summaries,'store understanding retains every store-capable registered metric');
$projectUnderstanding=['goal'=>'找项目第一名','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较项目','fields'=>['object_kind'],'values'=>['object_kind'=>'project'],'evidence'=>[['message_id'=>'current','quote'=>'项目']]],
]];
$project=$method->invoke($gateway,$summaries,$projectUnderstanding);
$check(array_column($project,'metric_code')===['sales_amount','sales_quantity'],
    'accepted project object receives only registry-declared project metrics for binding');
$productUnderstanding=['goal'=>'找产品第一名','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较产品','fields'=>['object_kind'],'values'=>['object_kind'=>'product'],'evidence'=>[['message_id'=>'current','quote'=>'产品']]],
]];
$product=$method->invoke($gateway,$summaries,$productUnderstanding);
$check(array_column($product,'metric_code')===['sales_amount','sales_quantity'],
    'accepted product object receives only registry-declared product metrics for binding');
$unsupportedUnderstanding=['goal'=>'找合作方第一名','status'=>'understood','requirements'=>[
    ['id'=>'r1','meaning'=>'比较合作方','fields'=>['object_kind'],'values'=>['object_kind'=>'partner'],'evidence'=>[['message_id'=>'current','quote'=>'合作方']]],
]];
$unsupported=$method->invoke($gateway,$summaries,$unsupportedUnderstanding);
$check($unsupported===$summaries,'an unregistered object relation keeps the full catalogue for the controlled capability boundary');
$unknown=$method->invoke($gateway,$summaries,['goal'=>'不确定','status'=>'needs_clarification','requirements'=>[]]);
$check($unknown===$summaries,'unclear object meaning is never narrowed by server code');
echo 'PASS binding object capability boundary: '.$checks." checks (offline)\n";
