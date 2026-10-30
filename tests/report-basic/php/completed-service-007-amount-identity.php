<?php

declare(strict_types=1);

/** 007 消耗子单桥只返回完整逐服务金额和原核销身份，不把局部来源当成完整账。 */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3).'/后端代码').'/vendor/autoload.php';

use app\services\report\CompletedService007AmountIdentityReadServices;

$reader = new CompletedService007AmountIdentityReadServices();
$project = new ReflectionMethod($reader, 'project'); $project->setAccessible(true);
$range = ['start'=>'2026-10-01', 'end'=>'2026-10-30'];
$coverage = ['complete'=>true, 'legacy_candidates'=>12, 'mapped_candidates'=>12, 'gaps'=>[]];
$fact = ['service_fact_id'=>'service-1', 'store_id'=>105, 'source_line_id'=>'line-1',
    'project_id'=>300, 'quantity'=>'1.5'];
$snapshot = ['service_fact_id'=>'service-1', 'store_id'=>105, 'service_status'=>'completed',
    'business_date'=>'2026-10-20', 'source_document_type'=>'legacy_paid_project',
    'source_document_id'=>'456', 'document_id'=>'order-1', 'checkout_request_id'=>'checkout-1',
    'source_line_id'=>'line-1', 'project_id'=>300, 'quantity'=>'1.5', 'member_id'=>22,
    'service_object'=>'friend'];
$amount = ['fact_id'=>'amount-1', 'store_id'=>105, 'checkout_request_id'=>'checkout-1',
    'source_line_id'=>'line-1', 'amount_cents'=>1234];
$legacy = [456=>['id'=>456, 'oid'=>789, 'relation_id'=>105, 'uid'=>22,
    'product_id'=>300, 'writeoff_num'=>'1.5', 'status'=>0]];
$call = static function (array $facts, array $amounts, array $snapshots, array $gaps = [], ?array $old = null)
    use ($project, $reader, $range, $coverage, $legacy): array {
    return $project->invoke($reader, $facts, $amounts, $snapshots, [105], $range, $coverage, $gaps,
        $old ?? $legacy);
};
$good = $call([$fact], [$amount], ['service-1'=>$snapshot]);
if (!$good['complete'] || count($good['rows']) !== 1 ||
    $good['rows'][0]['amount_cents'] !== 1234 ||
    $good['rows'][0]['source_document_id'] !== '456' ||
    $good['rows'][0]['business_date'] !== '2026-10-20' ||
    $good['rows'][0]['service_object'] !== 'friend' ||
    $good['rows'][0]['legacy_origin_order_id'] !== 789)
    throw new RuntimeException('exact cents and stable legacy writeoff identity');
$missing = $call([$fact], [], ['service-1'=>$snapshot]);
if ($missing['complete'] || $missing['rows'] !== [] ||
    !in_array('CONSUMPTION_AMOUNT_FACT_MISSING', array_column($missing['gaps'], 'code'), true))
    throw new RuntimeException('missing amount must retract all rows');
$orphan = $amount; $orphan['fact_id']='orphan'; $orphan['source_line_id']='other';
$extra = $call([$fact], [$amount, $orphan], ['service-1'=>$snapshot]);
if ($extra['complete'] || $extra['rows'] !== [] ||
    !in_array('CONSUMPTION_ORPHAN_AMOUNT', array_column($extra['gaps'], 'code'), true))
    throw new RuntimeException('orphan amount must retract all rows');
$wrongDay = $snapshot; $wrongDay['business_date']='2026-09-30';
$crossDay = $call([$fact], [$amount], ['service-1'=>$wrongDay]);
if ($crossDay['complete'] || $crossDay['rows'] !== [] ||
    !in_array('SERVICE_BUSINESS_DATE_MISMATCH', array_column($crossDay['gaps'], 'code'), true))
    throw new RuntimeException('only V3 business date is report date');
$wrongQuantity = $snapshot; $wrongQuantity['quantity']='1';
if ($call([$fact], [$amount], ['service-1'=>$wrongQuantity])['complete'])
    throw new RuntimeException('service quantity mismatch cannot acquire money');
$duplicate = $fact; $duplicate['service_fact_id']='service-2';
$duplicateSnapshot = $snapshot; $duplicateSnapshot['service_fact_id']='service-2';
$ambiguous = $call([$fact, $duplicate], [$amount],
    ['service-1'=>$snapshot, 'service-2'=>$duplicateSnapshot]);
if ($ambiguous['complete'] || $ambiguous['rows'] !== [] ||
    !in_array('SERVICE_LINE_AMBIGUOUS', array_column($ambiguous['gaps'], 'code'), true))
    throw new RuntimeException('one amount line cannot be multiplied across services');
$unknown = $snapshot; $unknown['source_document_type']='guessed';
if ($call([$fact], [$amount], ['service-1'=>$unknown])['complete'])
    throw new RuntimeException('unrecognized source must fail closed');
$wrongOld = $legacy; $wrongOld[456]['relation_id'] = 106;
$mismatch = $call([$fact], [$amount], ['service-1'=>$snapshot], [], $wrongOld);
if ($mismatch['complete'] || $mismatch['rows'] !== [] ||
    !in_array('LEGACY_SERVICE_IDENTITY_MISMATCH', array_column($mismatch['gaps'], 'code'), true))
    throw new RuntimeException('legacy source ID must match original store and identity');
echo "completed-service-007-amount-identity: PASS\n";
