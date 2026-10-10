<?php
declare(strict_types=1);

/** A voucher-funded sale line cannot receive a cash refund allocation. */
require_once __DIR__ . '/../../../后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php';

use app\services\cashier\v3\order\CashierV3OrderLifecycleServices;

$reflection = new ReflectionClass(CashierV3OrderLifecycleServices::class);
$method = $reflection->getMethod('allocateRefundComponent');
$service = $reflection->newInstanceWithoutConstructor();
$lines = [
    ['lineId' => 'voucher', 'lineNo' => 1, 'saleAmountCents' => 100000,
        'refundableAmountCents' => 0],
    ['lineId' => 'cash', 'lineNo' => 2, 'saleAmountCents' => 100000,
        'refundableAmountCents' => 100000],
];
$allocated = $method->invoke($service, $lines, 100000);
if ($allocated !== [0, 100000]) {
    fwrite(STDERR, 'Coupon-funded line incorrectly received cash refund.\n');
    exit(1);
}

$reversal = file_get_contents(__DIR__ . '/../../../后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php');
if (substr_count($reversal, '$coupons = [];') < 1
    || !str_contains($reversal, "\$coupons = \$action === 'void-sales-order'")) {
    fwrite(STDERR, 'Refund/void coupon restoration boundary is missing.\n');
    exit(1);
}
echo "R40_COUPON_REFUND_BOUNDARY=PASS\n";
