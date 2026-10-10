<?php

declare(strict_types=1);

$root = is_dir('/var/www/html/app') ? '/var/www/html' : dirname(__DIR__, 3) . '/后端代码';
require_once $root . '/app/services/cashier/v3/settlement/CashierV3CouponTenderAmounts.php';

use app\services\cashier\v3\settlement\CashierV3CouponTenderAmounts;

$line = CashierV3CouponTenderAmounts::fromNetSale(698000, 648000, 50000);
if ($line !== [
    'originalCents' => 698000,
    'discountCents' => 0,
    'saleCents' => 698000,
    'couponPaymentCents' => 50000,
]) {
    throw new RuntimeException('Voucher tender must not reduce the card purchase value.');
}
if (CashierV3CouponTenderAmounts::receivable(698000, 50000, 0) !== 648000
    || CashierV3CouponTenderAmounts::receivable(698000, 50000, 100000) !== 548000) {
    throw new RuntimeException('Only cash and debt may settle the post-voucher receivable.');
}
try {
    CashierV3CouponTenderAmounts::receivable(698000, 50000, 650000);
    throw new RuntimeException('Debt must not consume voucher value.');
} catch (InvalidArgumentException $expected) {
}
echo "R40_COUPON_TENDER_AMOUNTS=PASS\n";
