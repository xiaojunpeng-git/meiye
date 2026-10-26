<?php

declare(strict_types=1);

/** Balance fact codes stay immutable while member detail shows Chinese labels. */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require rtrim($backend, '/\\') . '/vendor/autoload.php';

use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;

$service = new CashierV3MemberDetailQueryServices();
$label = new ReflectionMethod(CashierV3MemberDetailQueryServices::class, 'balanceChangeTypeLabel');
$label->setAccessible(true);
$cases = [
    ['order_payment', 'forward', '余额支付'],
    ['order_payment', 'reversal', '余额支付冲销'],
    ['balance_payment', 'forward', '余额支付'],
    ['recharge', 'forward', '充值'],
    ['order_void', 'forward', '订单作废返还'],
];
foreach ($cases as [$type, $direction, $expected]) {
    $actual = $label->invoke($service, $type, $direction);
    if ($actual !== $expected) {
        throw new RuntimeException("Balance label mismatch: {$type}/{$direction}: {$actual}");
    }
}

echo "R18_MEMBER_BALANCE_LABEL=PASS\n";
