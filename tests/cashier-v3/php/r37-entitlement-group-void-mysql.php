<?php
/** 本地真实库事务测试：所有作废写入最终回滚，保留用户验收记录。 */
require __DIR__ . '/r37-entitlement-sales-query-mysql.php';
$org = (string)\think\facade\Db::name('organization_store')->where('store_id',$store)->value('org_id');
$scope = new \app\services\cashier\v3\CashierV3DataScopeContext(71,701,$store,$tenant,$org,[$store],\app\services\cashier\v3\CashierV3DataScopeContext::MODE_ALL,[],true,'test-super','permission-v2',['cashier.v3.order_center','cashier.v3.order.service_void'],['id'=>71,'name'=>'事务回滚测试']);
$operator = new \app\services\cashier\v3\CashierV3OperatorScope($store,71,$org,$tenant);
$target = null;
foreach ($full['records'] as $row) {
    if (!empty($row['entitlementOnly']) && count($row['items']) === 3 && $row['orderStatus'] !== '已作废') { $target = $row; break; }
}
if (!$target) throw new RuntimeException('three line fixture missing');
$detail = $reader->salesOrderDetail(['orderId' => $target['id']], $operator, $scope);
if (!$detail || count($detail['items']) !== 3 || empty($detail['entitlementOnly'])) throw new RuntimeException('detail failed');
// 核销金额必须来自冻结事实，不随手艺人业绩调整变化，也不进入销售应付。
foreach ($detail['items'] as $item) {
    $service = \think\facade\Db::name('cashier_v3_entitlement_service_fact')->where('id',$item['serviceFactId'])->find();
    $actual = \think\facade\Db::name('cashier_v3_entitlement_writeoff_fact')->where('tenant_id',$tenant)->where('checkout_request_id',$service['checkout_request_id'])->where('source_line_id',$service['source_line_id'])->value('actual_entitlement_amount_cents');
    if ((int)round($item['entitlementAmount'] * 100) !== (int)$actual || $item['unitPrice'] === null || $item['payableAmount'] !== null) throw new RuntimeException('entitlement amount contract failed');
}
echo 'ENTITLEMENT AMOUNTS=' . json_encode(array_column($detail['items'],'entitlementAmount')) . PHP_EOL;
$recorder = new \app\services\cashier\v3\event\CashierV3BusinessEventRecorder();
$writer = new \app\services\cashier\v3\order\CashierV3ServiceRecordVoidServices();
$contract = \app\services\cashier\v3\manifest\CashierV3ActionManifest::eventContractFor('void-service-record');
$key = 'r37-rollback-' . bin2hex(random_bytes(8));
$request = substr($target['id'], strlen('service:'));
\think\facade\Db::startTrans();
try {
    $execution = $recorder->newExecution('void-service-record', $key, $operator, $scope, 'r37-test');
    $input = ['operator_scope'=>$operator,'data_scope'=>$scope,'event_recorder'=>$recorder,'event_execution'=>$execution,
        'event_contract'=>$contract,'idempotency_key'=>$key,'payload'=>['checkoutRequestId'=>$request,'reason'=>'R37事务回滚验收']];
    // 模拟一条已先单独作废，整组操作只能再冲销其余两条。
    $single = $input;
    $single['payload'] = ['serviceFactId'=>$target['items'][0]['serviceFactId'],'reason'=>'R37先作废一条'];
    $writer->executeInTx('void-service-record', $single);
    $result = $writer->executeInTx('void-service-record', $input);
    $recorder->assertRequiredPersistedInTx($execution, $contract);
    if (count($result['records']) !== 2) throw new RuntimeException('already voided service not skipped');
    $count = \think\facade\Db::name('cashier_v3_service_record_void_operation')->where('checkout_request_id',$request)->count();
    if ($count !== 3) throw new RuntimeException('incorrect void count');
    $after = $reader->salesOrderDetail(['orderId'=>$target['id']],$operator,$scope);
    if ($after['orderStatus'] !== '已作废') throw new RuntimeException('status not refreshed');
    try {
        $writer->executeInTx('void-service-record', $input);
        throw new RuntimeException('duplicate group accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
        if (strpos($expected->getMessage(),'已全部作废') === false) throw $expected;
    }
    echo "R37 detail/group void PASS; records=3; rollback required\n";
} finally {
    \think\facade\Db::rollback();
}
if (\think\facade\Db::name('cashier_v3_service_record_void_operation')->where('checkout_request_id',$request)->count() !== 0) throw new RuntimeException('rollback failed');
echo "ROLLBACK PASS; user records preserved\n";
