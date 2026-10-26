<?php
/** 纯权益消费单作废身份契约：单条服务也只能经整单标识进入写事务。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->initialize();
$policy = \app\services\cashier\v3\bootstrap\CashierV3Bootstrap::dispatcher()
    ->policies()->requirePolicy('void-service-record');

foreach ([
    [['checkoutRequestId' => 'CKR-one'], 'CKR-one'],
    [['orderId' => 'service:CKR-one'], 'CKR-one'],
    [['checkoutRequestId' => 'CKR-one', 'orderId' => 'service:CKR-one'], 'CKR-one'],
    // 历史预约直通服务必须仍按同一组作废，而不是按单条服务 ID 冲销。
    [['checkoutRequestId' => 'reservation:447', 'orderId' => 'service:reservation:447'], 'reservation:447'],
    [['orderId' => 'service:reservation:595'], 'reservation:595'],
] as $case) {
    $resolved = $policy->resolve($case[0] + ['reason' => '本地测试'], []);
    $normalized = $resolved['normalized_payload'] ?? [];
    if (($normalized['checkoutRequestId'] ?? '') !== $case[1] || isset($normalized['orderId'])) {
        throw new RuntimeException('pure entitlement group identity not normalized');
    }
}

foreach ([
    ['serviceFactId' => '3971'],
    ['orderId' => 'service:3971:other'],
    ['checkoutRequestId' => 'CKR-one', 'orderId' => 'service:CKR-other'],
    ['checkoutRequestId' => 'reservation:447', 'orderId' => 'service:reservation:448'],
    ['orderId' => 'service:reservation:0'],
    ['orderId' => 'service:reservation:447:other'],
] as $invalid) {
    try {
        $policy->resolve($invalid + ['reason' => '本地测试'], []);
        throw new RuntimeException('invalid or single-record identity accepted');
    } catch (\app\services\cashier\v3\CashierV3CommandException $expected) {
        // 身份不匹配在事务之前失败；不得生成服务冲销或权益返还事实。
    }
}
echo "PURE_ENTITLEMENT_VOID_POLICY=PASS\n";
