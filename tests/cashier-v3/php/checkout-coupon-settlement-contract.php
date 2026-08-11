<?php

$root = dirname(__DIR__, 3);
$coupon = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutCouponSettlementServices.php');
$workspace = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$preparation = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$kernel = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php');
$projection = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$rebuilder = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$saleOnly = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$execution = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php');
$salesPlan = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$reversal = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php');
$fact = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/fact/CashierV3CheckoutFactPlanV1.php');

$checks = [
    'coupon frozen fields reach immutable sales line' => strpos($salesPlan, "'coupon_user_id' => \$line['coupon_user_id']") !== false
        && strpos($salesPlan, "'coupon_discount_cents' => \$line['coupon_discount_cents']") !== false,
    'locked workspace coupon snapshot participates in checkout line fingerprint' => strpos($workspace, "\$source['couponUserId']") !== false
        && strpos($preparation, "'couponUserId' => (int)(\$line['couponUserId'] ?? 0)") !== false
        && strpos($kernel, "'couponNameSnapshot',") !== false
        && strpos($kernel, "'couponUserId' => self::nonNegativeInt(") !== false
        && strpos($kernel, "'couponDiscountCents' => self::money(") !== false,
    'checkout draft projection verifies and reads the same coupon snapshot' => strpos($projection, "'couponUserId' => \$couponUserId") !== false
        && strpos($projection, "'couponDiscountCents' => \$couponDiscount") !== false,
    'payment and submission rebuild preserve the frozen coupon snapshot' => strpos($rebuilder, "'couponUserId' => self::nonNegativeInt(") !== false
        && strpos($rebuilder, "'couponDiscountCents' => self::nonNegativeInt(") !== false,
    'upgrade coupon is sales discount not entitlement payment discount' => strpos($salesPlan, "\$saleLines[0]['discount_amount_cents'] = \$couponDiscount") !== false
        && strpos($salesPlan, "\$saleLines[0]['sale_amount_cents'] = \$credit['targetPriceCents'] - \$couponDiscount") !== false
        && strpos($salesPlan, "\$targetPrice !== (int)\$saleLines[0]['sale_amount_cents'] + \$amount + \$couponDiscount") !== false
        && strpos($salesPlan, "(int)\$saleLines[0]['discount_amount_cents'] !== \$amount + \$couponDiscount") !== false,
    'sale-only and orchestrated checkout consume coupon in final transaction' => false,
    'coupon use is member-bound CAS' => strpos($coupon, "->where('status', 0)->where('is_fail', 0)->where('use_time', 0)") !== false
        && strpos($coupon, "['status' => 1, 'use_time' => \$now]") !== false,
    'coupon trace reaches sale fact and the fact whitelist accepts it' => strpos($fact, "'couponUserId',") !== false
        && strpos($fact, "'couponNameSnapshot', 'couponDiscountCents'") !== false
        && strpos($fact, "'coupon_user_id' => self::nonNegativeInt(\$fact['couponUserId']") !== false
        && strpos($fact, "'coupon_discount_cents' => self::signedMoney(\$fact['couponDiscountCents']") !== false,
    'refund and void restore exact used coupon' => strpos($reversal, 'lockConsumedCoupons') !== false
        && strpos($reversal, 'restoreCoupons') !== false
        && strpos($reversal, "->update(['status' => 0, 'use_time' => 0])") !== false,
    'partial refund keeps coupon consumed' => strpos($reversal, "\$economicReversal === (int)\$source['amountCents']") !== false
        && strpos($reversal, '? $this->lockConsumedCoupons($source, $scope)') !== false,
];
$checks['sale-only and orchestrated checkout consume coupon in final transaction'] =
    strpos($saleOnly, 'CashierV3CheckoutCouponSettlementServices') !== false
    && strpos($execution, 'consumeInTx') !== false;

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo $failed === 0 ? "CHECKOUT_COUPON_SETTLEMENT_CONTRACT=PASS\n" : "CHECKOUT_COUPON_SETTLEMENT_CONTRACT=FAIL\n";
exit($failed === 0 ? 0 : 1);
