<?php

/**
 * 权益服务快照只读回归：新旧格式都能展示，人员事实优先，坏数据不得悄悄清空。
 * 无数据库写入，也不调用订单作废或人员修改命令。
 */
$backend = dirname(__DIR__, 3) . '/后端代码';
require $backend . '/app/services/cashier/v3/CashierV3PersonnelIdentity.php';
require $backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutCraftsmenSnapshot.php';
require $backend . '/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php';

$reader = new \app\services\cashier\v3\order\CashierV3SalesOrderQueryServices();
$method = new ReflectionMethod($reader, 'mapEntitlementServiceLine');
$service = [
    'id' => 1, 'quantity' => 1, 'project_name_snapshot' => '护理项目',
    'actual_entitlement_amount_cents' => 3000, 'service_fact_id' => 'ESF-1',
    'service_status' => 'completed',
];
$legacy = [[
    'staff_id' => 11, 'employee_id' => 22, 'staff_name_snapshot' => '历史技师',
    'employee_type_snapshot' => '正式', 'store_id' => 8, 'sequence' => 1,
    'is_primary' => 1, 'is_point_customer' => true, 'labor_weight' => 100,
    'amount_cents' => 2500, 'labor_fee_cents' => 300, 'project_count_decimal' => '1',
]];
$legacyResult = $method->invoke($reader, array_merge($service, [
    'craftsmen_snapshot_json' => json_encode($legacy, JSON_UNESCAPED_UNICODE),
]), []);
if (($legacyResult['craftsmen'][0]['name'] ?? null) !== '历史技师'
    || ($legacyResult['craftsmen'][0]['employeeId'] ?? null) !== 22
    || ($legacyResult['craftsmenListAllocations'][0]['employeeName'] ?? null) !== '历史技师'
    || ($legacyResult['craftsmenListAllocations'][0]['amount'] ?? null) !== 25.0
    || ($legacyResult['craftsmenListAllocations'][0]['laborFeeAmount'] ?? null) !== 3.0
    || ($legacyResult['craftsmenListAllocations'][0]['projectCount'] ?? null) !== '1') {
    throw new RuntimeException('legacy_service_snapshot_read_invalid');
}

$canonical = [[
    'id' => 11, 'staffId' => 11, 'employeeId' => 22, 'storeId' => 8,
    'name' => '标准技师', 'isPrimary' => true, 'sequence' => 1,
    'laborWeight' => 100, 'isPointCustomer' => false,
]];
$canonicalResult = $method->invoke($reader, array_merge($service, [
    'craftsmen_snapshot_json' => json_encode($canonical, JSON_UNESCAPED_UNICODE),
]), []);
if (($canonicalResult['craftsmen'][0]['name'] ?? null) !== '标准技师') {
    throw new RuntimeException('canonical_service_snapshot_read_invalid');
}

$identityOnly = [[
    'staffId' => 11, 'employeeId' => 22, 'name' => '早期技师',
    'employeeType' => '正式', 'employeeTypeVersion' => 1, 'isPrimary' => true,
]];
$identityResult = $method->invoke($reader, array_merge($service, [
    'craftsmen_snapshot_json' => json_encode($identityOnly, JSON_UNESCAPED_UNICODE),
]), []);
if (($identityResult['craftsmen'][0]['name'] ?? null) !== '早期技师'
    || isset($identityResult['craftsmen'][0]['laborWeight'])
    || !array_key_exists('amount', $identityResult['craftsmenListAllocations'][0])
    || $identityResult['craftsmenListAllocations'][0]['amount'] !== null) {
    throw new RuntimeException('identity_only_service_snapshot_read_invalid');
}

$fact = [[
    'fact_id' => 'LPF-1', 'employee_id' => 33, 'employee_name_snapshot' => '调整后技师',
    'role_snapshot' => 'round', 'amount_cents' => 1200, 'labor_fee_amount_cents' => 200,
]];
$adjusted = $method->invoke($reader, array_merge($service, [
    'craftsmen_snapshot_json' => json_encode($legacy, JSON_UNESCAPED_UNICODE),
]), $fact);
if (($adjusted['craftsmen'][0]['name'] ?? null) !== '调整后技师'
    || ($adjusted['craftsmen'][0]['employeeId'] ?? null) !== 33) {
    throw new RuntimeException('service_fact_not_authoritative');
}

$broken = $legacy;
unset($broken[0]['staff_name_snapshot']);
try {
    $method->invoke($reader, array_merge($service, [
        'craftsmen_snapshot_json' => json_encode($broken, JSON_UNESCAPED_UNICODE),
    ]), []);
    throw new RuntimeException('broken_service_snapshot_was_silenced');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'sales_order_service_craftsmen_snapshot_invalid') {
        throw $exception;
    }
}

echo "order-center service snapshot read contract: PASS\n";
