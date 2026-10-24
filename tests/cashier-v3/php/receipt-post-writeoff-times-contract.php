<?php
// Pure receipt mapping and replay-source checks: no framework, DB, or checkout execution.
$root = getenv('RECEIPT_BACKEND_ROOT') ?: __DIR__ . '/../../../后端代码';
require_once $root . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php';
require_once $root . '/app/services/cashier/v3/settlement/CashierV3CheckoutResultReadRepository.php';
require_once $root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutResultReadRepository.php';
use app\services\cashier\v3\checkout\persistence\ThinkPhpCashierV3EntitlementCompletionWriter;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResultReadRepository;
$map = new ReflectionMethod(ThinkPhpCashierV3EntitlementCompletionWriter::class, 'receiptEntitlementLines');
$map->setAccessible(true);
$deduction = ['holder_id'=>2077055,'source_detail_id'=>4485821,'entitlement_instance_type'=>'card_holder',
    'entitlement_instance_id'=>2077055,'line_ids'=>['persisted-line']];
$passed = 0;
$check = static function ($name, $actual, $expected) use (&$passed): void {
    if ($actual !== $expected) throw new RuntimeException($name . ': ' . json_encode($actual));
    $passed++; echo "PASS {$name}\n";
};
$read = static function ($deductions, $details, $rules = []) use ($map): array {
    return $map->invoke(null, $deductions, $details, $rules);
};
$check('persisted detail balance 8 is projected unchanged', $read([$deduction],[4485821=>8])[0]['remainingTimesAfter'],8);
$check('zero remainder is retained', $read([$deduction],[4485821=>0])[0]['remainingTimesAfter'],0);
$check('shared pool wins over stale component projection',
    $read([$deduction],[4485821=>8],[2077055=>[4485821=>['remainingTimes'=>6]]])[0]['remainingTimesAfter'],6);
$check('replacement cannot exceed its own remaining times',
    $read([$deduction],[4485821=>1],[2077055=>[4485821=>['remainingTimes'=>6]]])[0]['remainingTimesAfter'],1);
$multi = $deduction; $multi['line_ids']=['first','second'];
$check('same source rows use one final remainder', array_column($read([$multi],[4485821=>7]),'remainingTimesAfter'),[7,7]);
$gift=$deduction; $gift['holder_id']=0; $gift['entitlement_instance_type']='independent_gift';
$check('independent gift uses its persisted detail count',$read([$gift],[4485821=>8])[0]['remainingTimesAfter'],8);
$check('missing legacy data is unknown, never invented',$read([$deduction],[])[0]['remainingTimesAfter'],null);
$method = new ReflectionMethod(ThinkPhpCashierV3CheckoutResultReadRepository::class,'entitlementReceiptResult');
$method->setAccessible(true);
$repo=(new ReflectionClass(ThinkPhpCashierV3CheckoutResultReadRepository::class))->newInstanceWithoutConstructor();
$rows=$read([$deduction],[4485821=>8]);
$receipt=['receipt_id'=>'ECR-test','status'=>'completed','plan_fingerprint'=>'test','result_json'=>json_encode(['receiptEntitlements'=>$rows])];
$check('query reuses receipt JSON without querying a later card balance', $method->invoke($repo,$receipt)['receiptEntitlements'],$rows);
$receipt['result_json']='{}';
$check('historical receipt is not rewritten',$method->invoke($repo,$receipt)['receiptEntitlements'],[]);
$repositorySource=file_get_contents($root . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutResultReadRepository.php');
$check('scoped receipt query includes the frozen result JSON',strpos($repositorySource,'plan_fingerprint,status,settled_at,completed_at,result_json') !== false,true);
echo "ASSERT_PASSED={$passed}\n";
