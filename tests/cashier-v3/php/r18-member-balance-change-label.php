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
    ['order_pay', 'forward', '余额支付'],
    ['order_payment', 'reversal', '余额支付冲销'],
    ['balance_payment', 'forward', '余额支付'],
    ['recharge', 'forward', '充值'],
    ['order_void', 'forward', '订单作废返还'],
    ['unknown_legacy_code', 'forward', '其他余额变动'],
];
foreach ($cases as [$type, $direction, $expected]) {
    $actual = $label->invoke($service, $type, $direction);
    if ($actual !== $expected) {
        throw new RuntimeException("Balance label mismatch: {$type}/{$direction}: {$actual}");
    }
}

// 010 全量现存类型及共用收银写入类型都不能把内部英文码直接露给会员。
$knownCodes = [
    'card_to_balance', 'order_pay', 'order_payment', 'order_void', 'recharge_credit',
    'system_add', 'system_sub', 'order_refund', 'balance_restored',
    'recharge_debt_repayment_credit', 'debt_repayment', 'refund_credit',
    'manual_adjustment', 'manual_refund_credit', 'manual_refund_debit',
];
foreach ($knownCodes as $type) {
    foreach (['forward', 'reversal'] as $direction) {
        $actual = $label->invoke($service, $type, $direction);
        if (preg_match('/[A-Za-z_]/', $actual) === 1) {
            throw new RuntimeException("Internal code leaked: {$type}/{$direction}: {$actual}");
        }
    }
}

echo "R18_MEMBER_BALANCE_LABEL=PASS\n";
