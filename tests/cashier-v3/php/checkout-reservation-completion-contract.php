<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3CheckoutReservationClosureServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$manifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C3ServiceModule.php');
$events = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');

$checks = [
    '查询和写入都从已结算正向销售订单反推边界' =>
        str_contains($service, "->where('order_status', 'settled')")
        && str_contains($service, "->where('order_direction', 'forward')")
        && str_contains($service, "->where('business_date', (string)\$order['business_date'])")
        && str_contains($service, "->where('member_id', (int)\$order['member_id'])")
        && str_contains($service, "->where('store_id', (int)\$order['store_id'])"),
    '只有三种未结束状态会被改为已完成' =>
        str_contains($service, "['PENDING_CONFIRMATION', 'UNSTARTED', 'IN_SERVICE']")
        && str_contains($service, "'status' => self::COMPLETED_STATUS")
        && str_contains($service, "->whereIn('status', self::UNFINISHED_STATUSES)"),
    '预约收口不调用旧结束服务或权益消耗链路' =>
        !str_contains($service, 'consumeInTx(')
        && !str_contains($service, 'releaseInTx(')
        && !str_contains($service, 'CashierV3ReservationModule::end'),
    '批量写入使用行锁、版本 CAS 和独立审计' =>
        str_contains($service, '->lock(true)')
        && str_contains($service, "->where('version', \$beforeVersion)")
        && str_contains($service, 'reservation.checkout_completed')
        && str_contains($service, "'operation_type' => 'CHECKOUT_COMPLETE'"),
    '结账后动作使用收银权限且不依赖浏览器预约列表 context' =>
        str_contains($manifest, "\$command['complete-checkout-reservations'] = self::FEATURE_CASHIER")
        && str_contains($manifest, "\$projection['query-checkout-unfinished-reservations'] = self::FEATURE_CASHIER")
        && str_contains($module, "new CashierV3ContextPolicy('complete-checkout-reservations', [], [], null, [], [], [], true)"),
    '实际状态变更有事件合同，并允许并发下零变更幂等成功' =>
        str_contains($events, "'complete-checkout-reservations' => [")
        && str_contains($events, "'allowed_event_types' => ['reservation.checkout_completed']")
        && str_contains($events, "'min_count' => 0"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}

