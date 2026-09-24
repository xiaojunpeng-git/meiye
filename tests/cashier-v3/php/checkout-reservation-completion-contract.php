<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3CheckoutReservationClosureServices.php');
$module = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
$manifest = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3C3ServiceModule.php');
$events = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php');
$detail = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php');
$partition = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php');
$migration = (string)file_get_contents($root . '/后端代码/database/upgrades/2026-09-24-结账项目同步预约实际服务/02-正式升级.sql');

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
    '每条当天预约关联同一批结账服务事实且不重复生成服务' =>
        str_contains($service, "private const SERVICE_LINK_TABLE = 'cashier_v3_reservation_checkout_service_link'")
        && str_contains($service, "->where('document_id', (string)\$order['order_id'])")
        && str_contains($service, "->where('service_status', 'completed')")
        && str_contains($service, "'service_fact_id' => (string)(\$service['service_fact_id'] ?? '')")
        && !str_contains($service, 'CashierV3SaleProjectServiceCompletionServices'),
    '预约列表和详情优先展示结账实际项目及手艺人快照' =>
        str_contains($detail, "Db::name('cashier_v3_reservation_checkout_service_link')")
        && str_contains($detail, 'checkoutActualCraftsmen')
        && str_contains($partition, "Db::name('cashier_v3_reservation_checkout_service_link')")
        && str_contains($partition, "'projectSource' => isset(\$checkoutLinksByReservation[\$id]) ? '本次结账' : '本次预约'"),
    '关联表保留计划与实际边界并按预约服务事实唯一' =>
        str_contains($migration, 'uk_tenant_reservation_service_fact')
        && str_contains($migration, '`craftsmen_snapshot_json` text NOT NULL')
        && str_contains($migration, '`immutable_fingerprint` char(64)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('FAIL ' . $label);
    }
    echo 'PASS ' . $label . PHP_EOL;
}
