<?php

require dirname(__DIR__, 3) . '/后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php';

use app\services\cashier\v3\order\CashierV3OrderCenterRecordQueryServices;

// Invoke the read-only mapper without a database: fact rows must win over an
// older service snapshot, while absent historical facts may use that snapshot.
$reader = new CashierV3OrderCenterRecordQueryServices(static function (): array { return []; });
$method = new ReflectionMethod($reader, 'craftsmenListAllocations');
$snapshot = json_encode([[
    'employeeId' => 12, 'name' => '许长娥', 'isPointCustomer' => false,
    'performanceAmountCents' => 0, 'laborFeeCents' => 4500, 'projectCount' => '1',
]], JSON_UNESCAPED_UNICODE);
$fallback = $method->invoke($reader, [], $snapshot);
if (($fallback[0]['employeeName'] ?? null) !== '许长娥'
    || ($fallback[0]['amount'] ?? null) !== '0.00'
    || ($fallback[0]['laborFeeAmount'] ?? null) !== '45.00'
    || ($fallback[0]['projectCount'] ?? null) !== '1') {
    throw new RuntimeException('service_snapshot_fallback_invalid');
}
$effective = $method->invoke($reader, [[
    'employeeId' => 12, 'employeeName' => '许长娥', 'isPointCustomer' => true,
    'amount' => 100, 'laborFeeAmount' => 0, 'projectCount' => '0', 'hasExplicitProjectCount' => true,
]], $snapshot);
if (($effective[0]['amount'] ?? null) !== 100
    || ($effective[0]['isPointCustomer'] ?? null) !== true
    || ($effective[0]['projectCount'] ?? null) !== '0') {
    throw new RuntimeException('effective_fact_overwritten_by_snapshot');
}
$legacyCount = $method->invoke($reader, [[
    'employeeId' => 12, 'employeeName' => '许长娥', 'amount' => 0,
    'laborFeeAmount' => 45, 'projectCount' => '0', 'hasExplicitProjectCount' => false,
]], $snapshot);
if (($legacyCount[0]['projectCount'] ?? null) !== '1') {
    throw new RuntimeException('legacy_project_count_not_recovered');
}
$twoPersonSnapshot = json_encode([
    ['employeeId' => 12, 'name' => '许长娥', 'isPointCustomer' => false, 'projectCount' => '1'],
    ['employeeId' => 13, 'name' => '汤静静', 'isPointCustomer' => true,
        'performanceAmountCents' => 0, 'laborFeeCents' => 0, 'projectCount' => '0'],
], JSON_UNESCAPED_UNICODE);
$multi = $method->invoke($reader, [[
    'employeeId' => 12, 'employeeName' => '许长娥', 'amount' => '100.00',
    'laborFeeAmount' => '45.00', 'projectCount' => '1', 'hasExplicitProjectCount' => true,
]], $twoPersonSnapshot);
if (count($multi) !== 2 || ($multi[1]['employeeName'] ?? null) !== '汤静静'
    || ($multi[1]['amount'] ?? null) !== '0.00') {
    throw new RuntimeException('all_zero_colleague_hidden');
}
$adjusted = $method->invoke($reader, [[
    'employeeId' => 12, 'employeeName' => '许长娥', 'amount' => '0.00',
    'laborFeeAmount' => '0.00', 'projectCount' => '0', 'hasExplicitProjectCount' => true,
    'ruleCodeSnapshot' => 'SERVICE-RECORD-CRAFTSMAN-ADJUST-V1',
]], $twoPersonSnapshot);
if (count($adjusted) !== 1 || ($adjusted[0]['projectCount'] ?? null) !== '0') {
    throw new RuntimeException('adjusted_staff_set_repopulated_from_old_snapshot');
}
echo "order center personnel display contract: PASS\n";
